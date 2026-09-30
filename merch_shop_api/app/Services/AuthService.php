<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Exceptions\ApiException;
use App\Models\User;
use App\Repositories\Contracts\UserRepositoryInterface;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Regle metier de l'authentification par session.
 *
 * Le service ne connait pas HTTP : il expose une tentative de connexion, une
 * inscription et une deconnexion. Le stockage effectif de la session est
 * delegue au garde d'authentification, et l'identifiant de session est
 * regenere ici parce que c'est une decision de securite, pas un detail de
 * transport.
 */
final class AuthService
{
    /**
     * Message volontairement identique pour toute connexion refusee.
     *
     * Differencier « email inconnu » de « mot de passe incorrect » permet
     * d'énumerer les comptes enregistres sans avoir a forcer le mot de passe.
     * La distinction utile (compte desactive) est traitee separement, car elle
     * ne revele rien : elle ne s'applique qu'a un email deja connu de son
     * detenteur.
     */
    private const string LOGIN_FAILED_MESSAGE = 'Email ou mot de passe incorrect.';

    /**
     * Hachage factice servant a egaliser le temps de reponse.
     *
     * Verifie contre un hash dont personne ne connait le mot de passe : le
     * cout du bcrypt est celui d'un email existant, donc un attaquant ne peut
     * pas deduire de la latence quels emails sont enregistres.
     */
    private ?string $dummyHash = null;

    public function __construct(
        private readonly UserRepositoryInterface $users,
        private readonly AuthFactory $auth,
    ) {}

    /**
     * Inscrit un compte et ouvre sa session.
     *
     * Le role et le statut sont forces ici, jamais lus dans la requete : une
     * inscription ne peut pas produire un administrateur. Les comptes
     * d'exploitation se creent hors de ce flux.
     *
     * @param  array{firstname: string, lastname: string, phone: string, email: string, password: string}  $data
     */
    public function register(Request $request, array $data): User
    {
        // Le hachage est fait par le service et non par le modele : le
        // « hashed » du cast est une commodite pour les factories et les
        // tests, il ne doit pas etre le mecanisme de securite d'un
        // enregistrement declare par un visiteur.
        $user = $this->users->create([
            'firstname' => $data['firstname'],
            'lastname' => $data['lastname'],
            'phone' => $data['phone'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'status' => UserStatus::ACTIVE,
            'role' => UserRole::CUSTOMER,
        ]);

        $this->logAuthenticationEvent($request, 'registered', $user);

        $this->openSession($request, $user);

        return $user;
    }

    /**
     * Verifie les identifiants et ouvre la session.
     *
     * @throws ApiException 401 si la connexion est refusee
     */
    public function attempt(Request $request, string $email, string $password, bool $remember = false): User
    {
        $user = $this->users->findByEmail($email);

        /*
         * Meme quand aucun compte ne correspond, on execute une verification de
         * mot de passe. Sans cela, le temps de reponse distingue un email
         * inconnu d'un mot de passe errone, ce qui suffit pour enumerer les
         * comptes inscrits.
         */
        if ($user === null) {
            Hash::check($password, $this->dummyHash());

            $this->logAuthenticationAttempt($request, $email, false, 'unknown_email');

            throw $this->loginFailed();
        }

        if (! Hash::check($password, (string) $user->password)) {
            $this->logAuthenticationAttempt($request, $email, false, 'bad_password');

            throw $this->loginFailed();
        }

        if ($user->status !== UserStatus::ACTIVE) {
            // Un compte desactive ne doit pas etre traite comme un echec de
            // mot de passe : le message est distinct parce que seule la personne
            // concernee peut le declencher.
            $this->logAuthenticationAttempt($request, $email, false, 'inactive_account');

            throw new ApiException(
                'Ce compte est desactive. Contactez un administrateur.',
                403,
                'ACCOUNT_DISABLED',
            );
        }

        $this->logAuthenticationAttempt($request, $email, true);
        $this->logAuthenticationEvent($request, 'logged_in', $user);

        $this->openSession($request, $user, $remember);

        return $user;
    }

    /**
     * Ferme la session courante.
     *
     * Une demande sur un visiteur non connecte n'est pas une erreur : le
     * comportement attendu d'une deconnexion est deja atteint.
     */
    public function logout(Request $request): void
    {
        $user = $request->user();

        if ($user !== null) {
            $this->logAuthenticationEvent($request, 'logged_out', $user);
        }

        $guard = $this->auth->guard();

        if ($user instanceof User) {
            $guard->logout();
        }

        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }

    /**
     * Ouvre la session en regenérant l'identifiant.
     *
     * La regenération est la protection contre la fixation de session : si
     * l'identifiant ne changeait pas, un attaquant ayant impose un identifiant
     * connu avant la connexion recupererait la session authentifiee. Elle
     * concernerait aussi la deconnexion, d'ou invalidate() plus regenerateToken()
     * pour purger le contenu et le jeton CSRF.
     *
     * @param  bool  $remember  Persiste l'identifiant au-dela de la session : le
     *                          cookie de memorisation est lui aussi une donnee
     *                          sensible, il ne doit donc jamais etre pose sur
     *                          un simple POST de connexion.
     */
    private function openSession(Request $request, User $user, bool $remember = false): void
    {
        $this->auth->guard()->login($user, $remember);

        $request->session()->regenerate();
    }

    private function loginFailed(): ApiException
    {
        return new ApiException(self::LOGIN_FAILED_MESSAGE, 401, 'INVALID_CREDENTIALS');
    }

    /**
     * Hachage bidon, fabrique une seule fois par instance du service.
     *
     * La fabrication elle-meme est aussi couteuse qu'une verification, mais
     * elle n'est faite qu'une fois : sans ce cache, chaque rejet d'email
     * inconnu coûterait deux hachages au lieu d'un, et l'egalisation de
     * latence serait fausse.
     */
    private function dummyHash(): string
    {
        return $this->dummyHash ??= Hash::make(Str::random(32));
    }

    /**
     * Journalise une tentative de connexion refusee.
     *
     * Seul l'email est note, jamais le mot de passe ni sa longueur : un journal
     * d'authentification se recopie facilement dans un ticket de support ou un
     * commit, et un mot de passe qui y atterrit est un mot de passe compromis.
     * L'echec reste utile pour detecter une attaque en cours.
     */
    private function logAuthenticationAttempt(
        Request $request,
        string $email,
        bool $successful,
        ?string $reason = null,
    ): void {
        Log::info('auth.login.attempt', [
            'email' => $email,
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'successful' => $successful,
            'reason' => $reason,
        ]);
    }

    /**
     * Journalise un evenement d'authentification reussi.
     */
    private function logAuthenticationEvent(Request $request, string $event, User $user): void
    {
        Log::info('auth.'.$event, [
            'user_uuid' => $user->uuid,
            'role' => $user->role->value,
            'ip' => $request->ip(),
        ]);
    }
}
