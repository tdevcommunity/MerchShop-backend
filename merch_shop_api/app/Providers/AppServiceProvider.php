<?php

namespace App\Providers;

use App\Enums\UserRole;
use App\Models\User;
use App\OpenApi\CamelCasesResourceProperties;
use App\Repositories\Contracts\CategoryRepositoryInterface;
use App\Repositories\Contracts\HealthRepositoryInterface;
use App\Repositories\Contracts\InvoiceRepositoryInterface;
use App\Repositories\Contracts\OrderRepositoryInterface;
use App\Repositories\Contracts\PaymentRepositoryInterface;
use App\Repositories\Contracts\ProductRepositoryInterface;
use App\Repositories\Contracts\UserRepositoryInterface;
use App\Repositories\Contracts\VariantRepositoryInterface;
use App\Repositories\Eloquent\EloquentCategoryRepository;
use App\Repositories\Eloquent\EloquentHealthRepository;
use App\Repositories\Eloquent\EloquentInvoiceRepository;
use App\Repositories\Eloquent\EloquentOrderRepository;
use App\Repositories\Eloquent\EloquentPaymentRepository;
use App\Repositories\Eloquent\EloquentProductRepository;
use App\Repositories\Eloquent\EloquentUserRepository;
use App\Repositories\Eloquent\EloquentVariantRepository;
use Dedoc\Scramble\Scramble;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        /*
         * Liaison contrat -> implementation.
         *
         * Les services injectent toujours l'interface (Contracts), jamais la
         * classe concrete : la source de donnees reste substituable. Les
         * liaisons sont ecrites explicitement plutot que resolues par
         * convention de nommage, pour qu'une mauvaise liaison soit visible a la
         * lecture du fichier et non a l'execution.
         */
        $this->app->bind(HealthRepositoryInterface::class, EloquentHealthRepository::class);
        $this->app->bind(UserRepositoryInterface::class, EloquentUserRepository::class);
        $this->app->bind(CategoryRepositoryInterface::class, EloquentCategoryRepository::class);
        $this->app->bind(ProductRepositoryInterface::class, EloquentProductRepository::class);
        $this->app->bind(VariantRepositoryInterface::class, EloquentVariantRepository::class);
        $this->app->bind(OrderRepositoryInterface::class, EloquentOrderRepository::class);
        $this->app->bind(PaymentRepositoryInterface::class, EloquentPaymentRepository::class);
        $this->app->bind(InvoiceRepositoryInterface::class, EloquentInvoiceRepository::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureRateLimiting();
        $this->configureApiDocsAccess();
        $this->configureOpenApiGeneration();
    }

    /**
     * Aligne la spécification générée sur le JSON réellement renvoyé.
     *
     * La conversion camelCase des réponses est appliquée à la sérialisation par
     * le trait NormalizesResponseKeys, donc après l'analyse statique des
     * ressources : sans ce correctif, la spécification décrirait les clés
     * internes en snake_case, et un client généré chercherait des champs que
     * l'API n'envoie pas.
     */
    private function configureOpenApiGeneration(): void
    {
        Scramble::configure()
            ->withDocumentTransformers([
                new CamelCasesResourceProperties,
            ]);
    }

    /**
     * Autorise l'UI de documentation OpenAPI.
     *
     * La règle reprend celle des écritures catalogue, volontairement : la
     * spécification expose le détail des routes d'écriture du back-office,
     * leurs payloads, leurs contraintes de validation et leurs codes d'erreur.
     * La lire doit donc exiger le même pouvoir que de les appeler — un rôle
     * `staff` s'en servirait pour apprendre à appeler ce qu'il ne peut pas
     * appeler.
     *
     * Le statut du compte est vérifié comme ailleurs : un administrateur
     * désactivé ne garde pas l'accès, même avec une session encore ouverte.
     */
    private function configureApiDocsAccess(): void
    {
        Gate::define('viewApiDocs', fn (User $user): bool => $user->role === UserRole::ADMIN && $user->status->isActive());
    }

    /**
     * Plafonds de debit appliques par le middleware throttle:api.
     *
     * L'identification se fait par adresse IP tant que le visiteur n'est pas
     * authentifié, puis par identifiant de compte : plusieurs personnes derrière
     * la même adresse publique (relais mobile, réseau d'entreprise) ne doivent
     * pas se partager le même quota. L'identifiant est précédé du nom du
     * limiteur, sans quoi un utilisateur connecté et une IP partageant le même
     * quota consommeraient le même seau.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(
            (int) config('api.throttle.per_minute'),
        )->by($this->rateLimitKey($request, 'api')));

        /*
         * Connexion : plafond volontairement plus bas et severe que celui de
         * l'API generale. Le login est la surface la plus rentable pour une
         * attaque par force brute sur un mot de passe reutilise ailleurs, donc
         * on limite par IP, independamment du fait que la session existe ou non.
         */
        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute((int) config('api.throttle.login_per_minute'))->by('login|'.$request->ip()),
            Limit::perHour((int) config('api.throttle.login_per_hour'))->by('login|'.$request->ip()),
        ]);

        /*
         * Inscription : limite en place pour que le formulaire public ne
         * devienne un script de creation de comptes en masse.
         */
        RateLimiter::for('register', fn (Request $request) => Limit::perHour(
            (int) config('api.throttle.register_per_hour'),
        )->by('register|'.$request->ip()));

        /*
         * Passage de commande : c'est la seule route d'ecriture appelable sans
         * session, et elle touche au stock. Le seau suit l'IP tant qu'aucune
         * session n'existe — un script anonyme ne peut pas tourner de compte
         * pour repartir de zero — puis l'identifiant de compte, pour qu'un
         * festivalier qui commande depuis la meme adresse qu'un collegue ne
         * soit pas bloque par le quota de celui-ci.
         */
        RateLimiter::for('checkout', fn (Request $request) => Limit::perMinute(
            (int) config('api.throttle.checkout_per_minute'),
        )->by($this->rateLimitKey($request, 'checkout')));

        /*
         * Notifications d'operateur : plafond par IP, plus large que celui de
         * l'API generale. Un agregateur rattrape en rafale ses notifications
         * apres une coupure reseau, et il doit pouvoir les rejouer sans etre
         * coupe au moment ou il cherche a se remettre en synchronisation.
         */
        RateLimiter::for('webhook', fn (Request $request) => Limit::perMinute(
            (int) config('api.throttle.webhook_per_minute'),
        )->by('webhook|'.$request->ip()));
    }

    /**
     * Identifiant de seau de debit, par utilisateur connecte si possible.
     */
    private function rateLimitKey(Request $request, string $limiter): string
    {
        $userId = $request->user()?->getAuthIdentifier();

        return $userId !== null
            ? $limiter.'|'.$userId
            : $limiter.'|'.$request->ip();
    }
}
