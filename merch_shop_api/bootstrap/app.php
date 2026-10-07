<?php

use App\Exceptions\ApiException;
use App\Support\Api\ApiErrorResponder;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // En production, l'API n'est joignable qu'a travers le proxy HTTPS du
        // serveur (Caddy) : on lui fait confiance pour X-Forwarded-Proto/For,
        // sinon Laravel croit etre en HTTP (URL generees, cookies secure).
        $middleware->trustProxies(at: '*');

        $middleware->redirectGuestsTo(
            fn (Request $request): ?string => $request->is('api/*') ? null : route('login'),
        );

        // Active le middleware throttle:api sur le groupe "api". Le plafond est
        // defini dans config/api.php et enregistre dans AppServiceProvider.
        $middleware->throttleApi();

        /*
         * | Authentification par session
         * |----------------------------------------------------------------------
         * | L'API est consommee par un front Next.js heberge sur un autre port,
         * | donc par une autre origine. L'authentification retenue est la session
         * | Laravel : l'identite transite par un cookie HttpOnly, jamais par un
         * | jeton lisible depuis le JavaScript du front.
         * |
         * | Laravel 13 ne fournit pas ce montage pour un groupe "api" sans
         * | Sanctum (`statefulApi()` delegue a Sanctum, non installe ici). On
         * | recompose donc a la main les memes briques, dans cet ordre :
         * |
         * |  - EncryptCookies / AddQueuedCookiesToResponse : le cookie de session
         * |    passe par le middleware de chiffrement, sans quoi un client pourrait
         * |    forger la valeur et usurper un compte ;
         * |  - StartSession : ouvre la session et l'ecrit en base (SESSION_DRIVER) ;
         * |  - PreventRequestForgery : NON NEGOCIABLE. Une authentification par
         * |    cookie est automatiquement vulnerable au CSRF : le navigateur
         * |    joint le cookie a toute requete, y compris depuis un site tiers.
         * |    Sans ce middleware, un site externe pourrait modifier le
         * |    catalogue avec la session d'un admin. Le front doit donc renvoyer
         * |    le header X-XSRF-TOKEN lu dans le cookie XSRF-TOKEN, expose par
         * |    GET /api/v1/auth/csrf-token.
         * |
         * | (PreventRequestForgery, et non ValidateCsrfToken : ce dernier nom
         * | n'est plus qu'un alias conserve pour compatibilite.)
         * |
         * | Conséquence à connaître : toute route d'écriture de l'API est
         * | desormais protegee, y compris celles ajoutees plus tard. C'est le
         * | prix de l'absence de jeton porteur, et c'est preferable a un cookie
         * | de session sans protection.
         * |
         * | ReadOnlyMethods limite en revanche la surface : GET/HEAD ne sont pas
         * | concernes, donc la lecture publique du catalogue reste utilisable
         * | sans jeton.
         */
        $middleware->prependToGroup('api', [
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            StartSession::class,
            PreventRequestForgery::class,
        ]);

        /*
         * Alias du contrôle d'accès au back-office.
         *
         * Enregistré comme alias et non appliqué directement dans les fichiers de
         * routes, parce que la question qu'il pose — « ce compte entre-t-il dans
         * le back-office ? » — est la meme partout, alors que les permissions
         * individuelles, elles, dépendent de la route et donc du contrôleur.
         * L'alias garantit qu'aucune route d'administration ne puisse être
         * ajoutée sans lui : un oubli serait un endpoint public.
         */
        $middleware->alias([
            'backoffice' => \App\Http\Middleware\EnsureBackoffice::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Une erreur metier attendue (stock insuffisant, panier vide) n'est pas
        // un incident technique : la remonter en erreur polluerait les alertes.
        $exceptions->dontReport([
            ApiException::class,
        ]);

        // Contrat d'erreur unique pour toutes les routes /api/*. On retourne
        // null sur le reste de l'application pour conserver le comportement
        // par defaut de Laravel (pages web, redirections, vues).
        $exceptions->render(function (Throwable $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return ApiErrorResponder::make($exception);
        });
    })->create();
