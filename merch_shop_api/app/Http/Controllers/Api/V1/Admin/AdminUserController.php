<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Api\V1\Admin\Concerns\GuardsBackofficeAction;
use App\Http\Resources\BackofficeUserResource;
use App\Models\User;
use App\Rules\PhoneNumber;
use App\Services\AuditLogger;
use App\Support\Api\AuditAction;
use Closure;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

/**
 * Les comptes du festival.
 *
 * Trois Decisions portent ce controleur, et chacune merite d'etre lue comme une
 * decision et non comme une commodite.
 *
 * La premiere est qu'aucun mot de passe n'est lu, jamais. `BackofficeUserResource`
 * ne renvoie pas le hash, et aucune route ici ne permet de l'obtenir : un
 * administrateur qui veut reinitialiser le mot de passe d'un compte passe par
 * l'action ecrite dans le journal d'audit, pas en relisant un hash pour le
 * recomposer.
 *
 * La deuxieme est qu'un administrateur ne peut pas se retirer le role ni se
 * desactiver lui-meme. Ces deux actions sont acceptees sur les autres comptes,
 * sans condition. Elles ne le sont pas sur soi : un administrateur qui se retire
 * le role laisse le back-office sans administrateur, et il n'a plus de compte
 * pour revenir. Le verrou est pose sur l'identite, pas sur le role demande, ce
 * qui couvre les deux cas d'un coup — y compris un `admin` qui se met lui-meme en
 * `staff`.
 *
 * La troisieme est qu'un compte n'est pas supprime. Il est desactive. Une
 * suppression emporte les commandes qu'il a passees, les retraits qu'il a servis
 * et les traces qu'il a laissees, et aucune de ces trois ne se deduit d'un compte
 * qui n'existe plus. La commande garde son nom, la ligne de journal garde son
 * auteur recopie, et l'adresse du compte reste lisible dans le journal.
 */
final class AdminUserController extends ApiController
{
    use GuardsBackofficeAction;

    /**
     * Les mots de passe qu'on refuse de proposer.
     *
     * Une liste courte et explicite, non un score : au stand, un mot de passe
     * temporaire doit pouvoir se dire a voix haute et se taper sur un telephone,
     * donc il doit etre dicible et memorable — ce que refuse justement un score
     * eleve. `password` est exclu pour la meme raison qu'il est partout ailleurs :
     * il echouerait sur la plupart des regles de complexite sans jamais assez
     * proteger quoi que ce soit.
     *
     * @var array<int, string>
     */
    private const FORBIDDEN_PASSWORDS = ['password', 'merchshop', 'festival', 'admin', '12345678', 'qwertyui'];

    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Les comptes du festival.
     *
     * La liste ne montre que les comptes qui gerent le merch. Les clients sont
     * exclus : un back-office n'a rien a faire de la liste des acheteurs, et la
     * faire apparaitre melangerait deux populations sans lien — un guichet qui
     * voit un client dans sa liste de comptes cherchera un role a lui donner.
     *
     * Ils ne sont pas pour autant invisibles : le rapprochement d'un client avec
     * ses commandes passe par les commandes, qui portent son nom et son numero.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->assertCanManage($request, 'users', 'gerer les comptes');

        $validated = $request->validate([
            'role' => ['nullable', 'string', Rule::enum(UserRole::class)],
            'status' => ['nullable', 'integer', Rule::in(array_column(UserStatus::cases(), 'value'))],
            'q' => ['nullable', 'string', 'max:120'],
        ]);

        return BackofficeUserResource::collection($this->search($validated, $this->perPage($request)));
    }

    /**
     * Un compte.
     */
    public function show(Request $request, string $uuid): BackofficeUserResource
    {
        $this->assertCanManage($request, 'users', 'gerer les comptes');

        return BackofficeUserResource::make($this->findOrFail($uuid));
    }

    /**
     * Inviter un compte de guichet.
     *
     * Le mot de passe est exige plutot que genere. Un mot de passe genere et
     * envoye par courriel serait communique a un canal que le festival ne maitrise
     * pas — la boite d'un guichetier personnel — et que personne ne garantit
     * qu'il lira. Saisi au stand, il est connu de la personne qui le recoit et de
     celle qui l'invite, et il est change au premier usage si l'on veut.
     *
     * L'adresse est obligatoire et propre au role : un `customer` cree par cette
     * route n'aurait aucun back-office, et un `admin` sans adresse ne pourrait
     * plus etre retrouvee dans la liste des comptes.
     */
    public function store(Request $request): BackofficeUserResource
    {
        $this->assertCanManage($request, 'users', 'gerer les comptes');

        $actor = $this->actor($request);

        $validated = $request->validate([
            'firstname' => ['required', 'string', 'max:120'],
            'lastname' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:180', 'unique:users,email'],
            'phone' => ['required', 'string', new PhoneNumber, 'unique:users,phone'],
            'role' => ['required', Rule::enum(UserRole::class), Rule::in([UserRole::ADMIN->value, UserRole::STAFF->value])],
            'password' => $this->passwordRules(),
        ], [
            'role.in' => 'Un compte de guichet est admin ou staff.',
            'email.unique' => 'Cette adresse est déjà celle d\'un compte.',

            /*
             * Le telephone est unique en base, et la regle doit le dire avant
             * l'ecriture. Sans elle, un doublon echouerait sur la contrainte —
             * une erreur serveur qui ne nomme pas le champ, donc un guichetier
             * qui ne sait pas quoi corriger et qui retente le meme formulaire.
             */
            'phone.unique' => 'Ce numéro est déjà celui d\'un compte.',
        ]);

        /*
         * Le compte est cree actif.
         *
         * L'inactivation existe pour la desactivation ulterieure et pour un import
         * qui ne connait pas encore le mot de passe. Inviter quelqu'un qui doit
         * encore etre active est un etat transitoire qu'aucun guichetier n'a de
         * raison de creer : il cree un compte que personne ne peut utiliser et que
         * quelqu'un forgetting d'activer.
         */
        $user = User::query()->create([
            'firstname' => $validated['firstname'],
            'lastname' => $validated['lastname'],
            'email' => $validated['email'],
            'phone' => $validated['phone'],
            'role' => UserRole::from($validated['role']),
            'status' => UserStatus::ACTIVE,
            'password' => $validated['password'],
        ]);

        $this->audit->created(AuditAction::USER_CREATED, $user, $actor);

        return BackofficeUserResource::make($user);
    }

    /**
     * Modifier un compte.
     *
     * Le mot de passe est absent des champs, et non exclu : c'est une autre
     * route qui le change, parce que le changer demande une action a part entiere
     * — il reinitialise le mot de passe d'une personne, ce qui se voit et se
     * journalise. Le mettre dans un `PATCH` de profil le ferait passer en
     * sourdine, a la suite d'une correction de nom.
     *
     * Un `PUT` sends only what it sends : les champs absents ne sont pas effaces.
     * Un back-office qui veut retirer un numero le poserait a `null` explicitement,
     * plutot que de le voir disparaitre parce qu'un formulaire a omis le champ.
     */
    public function update(Request $request, string $uuid): BackofficeUserResource
    {
        $this->assertCanManage($request, 'users', 'gerer les comptes');

        $actor = $this->actor($request);

        $user = $this->findOrFail($uuid);

        $validated = $request->validate([
            'firstname' => ['sometimes', 'required', 'string', 'max:120'],
            'lastname' => ['sometimes', 'required', 'string', 'max:120'],

            /*
             * L'adresse est unique, mais elle peut redevenir celle d'un autre
             * compte : la regle verifie donc l'unicite en excluant explicitement
             * le compte modifie. Sans cette exclusion, renommer un compte — ou
             * corriger une faute de frappe dans son adresse — echouerait sur sa
             * propre adresse actuelle.
             */
            'email' => ['sometimes', 'required', 'email:rfc', 'max:180', Rule::unique('users', 'email')->ignore($user->id)],
            'phone' => ['sometimes', 'required', 'string', new PhoneNumber, Rule::unique('users', 'phone')->ignore($user->id)],

            'role' => ['sometimes', 'required', Rule::enum(UserRole::class)],
            'status' => ['sometimes', 'required', 'integer', Rule::in(array_column(UserStatus::cases(), 'value'))],
        ]);

        $this->guardAgainstSelfLockout($user, $actor, $validated);

        /*
         * Le role et le statut sont convertis avant d'ecrire.
         *
         * Les regles ont deja refuse toute valeur hors nomenclature, mais la
         * colonne porte l'entier du backing type : ecrire la chaine `staff`
         * dedans enregistrerait « 0 » pour un compte que l'admin voulait nommer
         * `staff`, et ce compte deviendrait client.
         */
        $changes = array_intersect_key($validated, array_flip(['firstname', 'lastname', 'email', 'phone']));

        if (array_key_exists('role', $validated)) {
            $changes['role'] = UserRole::from($validated['role']);
        }

        if (array_key_exists('status', $validated)) {
            $changes['status'] = UserStatus::from((int) $validated['status']);
        }

        $before = ['role' => $user->role->value, 'status' => $user->status->value];

        $user->update($changes);

        /*
         * Deux traces plutot qu'une, parce que deux questions differentes.
         *
         * « Qui a change le role de ce compte ? » et « qui a desactive ce
         * compte ? » n'ont pas la meme importance : le second est la decision
         * qui coupe l'acces, et il doit se lire seul dans le journal. Une trace
         * unique qui contient les deux obliged a comparer deux champs pour
         * savoir lequel a bouge.
         */
        if (array_key_exists('role', $changes) && $before['role'] !== $user->role->value) {
            $this->audit->record(
                AuditAction::USER_ROLE_CHANGED,
                $user,
                $actor,
                ['role' => $before['role']],
                ['role' => $user->role->value],
            );
        }

        if (array_key_exists('status', $changes) && $before['status'] !== $user->status->value) {
            $this->audit->record(
                AuditAction::USER_STATUS_CHANGED,
                $user,
                $actor,
                ['status' => $before['status']],
                ['status' => $user->status->value],
            );
        }

        return BackofficeUserResource::make($user->refresh());
    }

    /**
     * Reinitialiser le mot de passe d'un compte.
     *
     * Le nouveau mot de passe est saisi et renvoye au guichetier, qui le
     * communique a la personne concernée. Il n'est pas envoyé par courriel : la
     * boite d'un guichetier est personnelle, hors du perimetre du festival, et
     * personne ne garantit qu'il l consulted — donc que le mot de passe de
     * premiere main ne se retrouve jamais dans cette boite.
     *
     * Aucune session n'est revoquee. Une session ouverte ailleurs reste valide
     * jusqu'a son expiration, ce qui est un choix : un festival dure une journee,
     * et invalider une session en cours parce qu'un mot de passe a ete reinitialise
     * pourrait interrompre un guichetier qui encaisse une commande a cet instant.
     * Le mot de passe ne changeant qu'ici, changer le role ou le statut d'un
     * compte ne le change pas non plus — c'est ce qui rend la desactivation
     * immediate alors que le mot de passe ne l'est pas.
     */
    public function resetPassword(Request $request, string $uuid): BackofficeUserResource
    {
        $this->assertCanManage($request, 'users', 'gerer les comptes');

        $actor = $this->actor($request);

        $user = $this->findOrFail($uuid);

        $validated = $request->validate([
            'password' => $this->passwordRules(),
        ]);

        $user->update(['password' => Hash::make($validated['password'])]);

        $this->audit->record(AuditAction::USER_PASSWORD_RESET, $user, $actor);

        return BackofficeUserResource::make($user->refresh());
    }

    /**
     * Les regles de mot de passe du back-office.
     *
     * `Password::min(10)` avec lettres et chiffres : dix caracteres est la
     * longueur qui resiste encore a une liste de mots de passe usuels sans
     * devenir illisible au clavier d'un telephone au stand. Les majuscules et
     * les symboles sont volontairement exclus — dicter « Motdepasse1! » a quelqu'un
     * devant un stand est la source des mots de passe `Motdepasse1` ecrits sans
     * le point d'exclamation.
     *
     * La liste des mots interdits est explicite plutot que Deleguee a un score :
     * un mot de passe temporaire doit se dire a voix haute, donc il doit rester
     * lisible, ce qu'un score eleve refuse.
     *
     * @return array<int, mixed>
     */
    private function passwordRules(): array
    {
        return [
            'required',
            'string',
            'min:10',
            'max:120',

            /*
             * Au moins une lettre et un chiffre.
             *
             * Exprime par une regex a assertions au lieu de deux regles
             * `regex` distinctes : deux regles « contient au moins... » ne
             * pourraient pas non plus etre satisfaites ensemble sans dire pourquoi,
             * alors que la forme retenue se lit et se verifie en un seul endroit.
             */
            'regex:/^(?=.*[a-z])(?=.*[0-9])/',

            /*
             * Aucun mot de la liste interdite, meme suivi de chiffres.
             *
             * Le motif est volontairement prefixe et non egalite : `password1` et
             * `password123` sont aussi evidents que `password`, et c'est
             * precisement l'ajout de chiffres qu'on fait pour « renforcer » un mot
             * de passe faible. Comparer le mot entier laisserait passer les deux.
             *
             * Passe par une fermeture et non par une chaine
             * `not_regex:/^(a|b)/i`, car le separateur d'alternance `|` est aussi
             * celui qui separe deux regles pour le validateur : ecrit en chaine,
             * le motif aurait ete decoupe en regles distinctes — et le mot de
             * passe aurait alors ete refuse pour une raison absente du motif.
             * Une fermeture n'est pas decoupee. `Rule::notRegex()` ferait
             * l'affaire aussi, mais la classe n'expose pas cette fabrique dans
             * cette version de Laravel.
             */
            function (string $attribute, mixed $value, Closure $fail): void {
                foreach (self::FORBIDDEN_PASSWORDS as $word) {
                    if (preg_match('/^'.preg_quote($word, '/').'/i', (string) $value) === 1) {
                        $fail('Ce mot de passe est trop courant à deviner.');

                        return;
                    }
                }
            },
        ];
    }

    /**
     * Un administrateur ne peut pas se verrouiller lui-meme dehors.
     *
     * La regle porte sur l'identite, pas sur le role demande, ce qui couvre les
     * deux issues d'un coup : un administrateur qui se retire le role, et un
     * administrateur qui se desactive. Dans les deux cas il resterait un
     * back-office sans administrateur, et le compte vient de perdre le seul
     * acces qui permettrait de revenir en arriere.
     *
     * Le refus est explicite et dit pourquoi, parce que la consequence n'est pas
     * evidente : sans le message, un administrateur qui essaie de se retirer le
     * role pour « faire plus simple » ne verrait qu'une erreur sans sujet.
     */
    private function guardAgainstSelfLockout(User $user, User $actor, array $validated): void
    {
        if ($user->id !== $actor->id) {
            return;
        }

        $demotes = array_key_exists('role', $validated)
            && UserRole::from($validated['role']) !== UserRole::ADMIN;

        $deactivates = array_key_exists('status', $validated)
            && UserStatus::from((int) $validated['status'])->isActive() === false;

        if ($demotes || $deactivates) {
            throw new ApiException(
                'Un administrateur ne peut pas retirer son propre rôle ni se désactiver lui-même.',
                409,
                'WOULD_LOCK_OUT_LAST_ADMIN',
            );
        }
    }

    /**
     * Un compte par son identifiant, s'il fait partie du festival.
     *
     * La restriction aux comptes de guichet est appliquee ici plutot qu'avec une
     * policy, parce qu'elle est une question de perimetre et non de droit : un
     * client existe, mais n'appartient pas a la liste que cette route affiche. Un
     * administrateur qui cherche un client par son uuid ne doit pas le trouver
     * ici — il doit le trouver dans la commande qui porte son nom.
     */
    private function findOrFail(string $uuid): User
    {
        $user = User::query()->where('uuid', $uuid)->first();

        if ($user === null || ! $user->role->canOperateMerch()) {
            throw new ApiException('Compte introuvable.', 404, 'USER_NOT_FOUND');
        }

        return $user;
    }

    /**
     * La liste filtree.
     *
     * @param  array{role?: string|null, status?: string|null, q?: string|null}  $filters
     * @return LengthAwarePaginator<int, User>
     */
    private function search(array $filters, int $perPage): LengthAwarePaginator
    {
        $term = isset($filters['q']) ? trim((string) $filters['q']) : '';

        return User::query()
            ->whereIn('role', [UserRole::ADMIN->value, UserRole::STAFF->value])
            ->when($filters['role'] ?? null, fn (Builder $query, string $role): Builder => $query->where('role', $role))
            ->when($filters['status'] ?? null, fn (Builder $query, string $status): Builder => $query->where('status', $status))

            /*
             * La recherche porte sur le nom et l'adresse, pas sur le numero.
             *
             * Le numero est saisi sans indicatif et avec des separateurs au choix,
             * donc une recherche par fragment de chiffre ne le retrouve pas de facon
             * fiable ; le laisser hors de la recherche est honnete — la liste reste
             * filtree par role et par statut, qui sont les deux filtres du stand.
             */
            ->when($term !== '', fn (Builder $query): Builder => $query->where(function (Builder $inner) use ($term): void {
                $inner->whereRaw('lower(firstname) like ?', ['%'.mb_strtolower($term).'%'])
                    ->orWhereRaw('lower(lastname) like ?', ['%'.mb_strtolower($term).'%'])
                    ->orWhereRaw('lower(email) like ?', ['%'.mb_strtolower($term).'%']);
            }))

            /*
             * Les administrateurs d'abord, puis par nom.
             *
             * L'ordre par role n'est pas un tri alphabétique des valeurs : il met
             * l'administrateur en tete de liste, parce que c'est le compte qu'on
             * cherche en premier quand on ouvre l'ecran des comptes.
             */
            ->orderByRaw('case when role = ? then 0 else 1 end', [UserRole::ADMIN->value])
            ->orderBy('lastname')
            ->orderBy('firstname')
            ->paginate($perPage);
    }

    /**
     * Le compte qui demande.
     */
}