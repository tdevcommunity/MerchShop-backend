<?php

namespace Tests\Feature\Api\V1\Admin;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Les comptes du guichet.
 *
 * Les invites et les reinitialisations de mot de passe sont les deux seules
 * actions du back-office qui donnent un acces a quelqu'un d'autre. Elles sont
 * donc testees sur trois points, toujours dans le meme ordre : qui peut le
 * faire, ce que cela produit, et ce que cela laisse dans le journal.
 *
 * Le troisieme point est le plus facile a oublier et le plus difficile a
 * rattraper apres coup. Un changement de role sans trace ne se voit pas quand
 * il est correct — et c'est precisement pour cela qu'il ne se verrait pas non
 * plus quand il ne l'est pas.
 */
class AdminAccountsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
    }

    // ---------------------------------------------------------------------
    // Qui peut inviter.
    // ---------------------------------------------------------------------

    public function test_a_staff_cannot_invite_anyone(): void
    {
        $before = User::query()->count();

        $this->actingAs(User::factory()->staff()->create())
            ->postJson('/api/v1/admin/users', $this->invite())
            ->assertForbidden()
            ->assertJsonPath('error.code', 'BACKOFFICE_FORBIDDEN');

        $this->assertSame($before + 1, User::query()->count(), 'Seul le compte du guichetier a ete cree.');
    }

    public function test_the_list_shows_only_the_counter_accounts(): void
    {
        $staff = User::factory()->staff()->create();
        $client = User::factory()->create();

        $emails = $this->actingAs($this->admin)
            ->getJson('/api/v1/admin/users')
            ->assertOk()
            ->json('data.*.email');

        $this->assertContains($staff->email, $emails);
        $this->assertNotContains(
            $client->email,
            $emails,
            'Un back-office n.a rien a faire de la liste des acheteurs.',
        );
    }

    // ---------------------------------------------------------------------
    // Ce qu'une invite produit.
    // ---------------------------------------------------------------------

    public function test_it_invites_a_counter_account_that_can_work_immediately(): void
    {
        $response = $this->actingAs($this->admin)
            ->postJson('/api/v1/admin/users', $this->invite([
                'email' => 'guichet@merchshop.test',
                'firstname' => 'Ama',
                'lastname' => 'Koffi',
                'phone' => '90112233',
                'role' => UserRole::STAFF->value,
            ]))
            ->assertCreated();

        $created = User::query()->where('email', 'guichet@merchshop.test')->sole();

        $this->assertTrue(
            $created->isBackoffice(),
            'Un invite doit pouvoir travailler au stand sans seconde intervention.',
        );

        $this->assertSame('Ama Koffi', $response->json('data.fullName'));
        $this->assertTrue($response->json('data.canOperate'));
    }

    public function test_the_password_it_sets_is_the_one_it_was_given(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/api/v1/admin/users', $this->invite(['password' => 'Guichet2026']))
            ->assertCreated();

        $created = User::query()->where('email', 'guichet@merchshop.test')->sole();

        $this->assertTrue(
            Hash::check('Guichet2026', $created->password),
            'Un mot de passe dicte au stand doit etre celui qui fonctionne.',
        );
    }

    public function test_it_refuses_a_customer_role(): void
    {
        /*
         * Un role `customer` n'est pas « moins de permissions » : c'est une
         * population differente, qui n'a pas de telephone unique ni de raison
         * d'etre au back-office. L'accepter laisserait creer un compte qui
         * echoue silencieusement a chaque ecran.
         */
        $this->actingAs($this->admin)
            ->postJson('/api/v1/admin/users', $this->invite(['role' => UserRole::CUSTOMER->value]))
            ->assertStatus(422)
            ->assertJsonPath('error.details.fields.role.0', 'Un compte de guichet est admin ou staff.');
    }

    public function test_it_refuses_a_phone_that_is_already_taken(): void
    {
        /*
         * Le numero est unique en base. Un doublon qui passerait la validation
         * echouerait sur la contrainte de base — une 500 qui ne dit pas quel
         * champ pose probleme, donc un guichetier ne saurait pas quoi corriger.
         *
         * Le conflit est cree par un compte existant, et non par un second
         * appel : c'est la seule facon de tester qu'une regle s'applique au
         * moment de la lecture, et non apres coup.
         */
        User::factory()->create(['phone' => '90112233']);

        $before = User::query()->count();

        $this->actingAs($this->admin)
            ->postJson('/api/v1/admin/users', $this->invite(['phone' => '90112233']))
            ->assertStatus(422)
            ->assertJsonPath('error.details.fields.phone', fn (array $messages): bool => $messages !== []);

        $this->assertSame($before, User::query()->count(), 'Un refus ne doit creer aucun compte.');
    }

    // ---------------------------------------------------------------------
    // Ce que le mot de passe doit etre.
    // ---------------------------------------------------------------------

    /**
     * Les mots de passe refuses, et pourquoi.
     *
     * Chaque cas est verifie pour une raison distincte, et c'est la liste
     * entiere qui doit tenir : une regle ajoutee puis non verifiee ici
     * continuerait de s'appliquer sans que personne ne le sache, et une regle
     * supprimee laisserait passer un mot de passe faible sans qu'aucun test ne
     * bronche.
     *
     * @return array<string, array{string}>
     */
    public static function weakPasswords(): array
    {
        return [
            'trop court' => ['Guichet26'],
            'sans chiffre' => ['Guichetmotdepasse'],
            'mot courant' => ['Password2026'],
            'mot courant suivi de chiffres' => ['festival2026'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('weakPasswords')]
    public function test_it_refuses_a_weak_password(string $password): void
    {
        $this->actingAs($this->admin)
            ->postJson('/api/v1/admin/users', $this->invite(['password' => $password]))
            ->assertStatus(422)
            ->assertJsonPath('error.details.fields.password', fn (array $messages): bool => $messages !== []);

        $this->assertSame(1, User::query()->count());
    }

    // ---------------------------------------------------------------------
    // Les verous.
    // ---------------------------------------------------------------------

    public function test_an_administrator_cannot_remove_their_own_role(): void
    {
        /*
         * Le verrou porte sur l'identite de l'appelant et non sur le role
         * cible : c'est la seule forme qui interdit le cas reel. Un garde-fou
         * « on ne peut pas demettre le dernier administrateur » laisserait
         * passer deux administrateurs qui se demettent tour a tour, et le
         * back-office deviendrait inaccessible au second.
         */
        $this->actingAs($this->admin)
            ->patchJson("/api/v1/admin/users/{$this->admin->uuid}", ['role' => UserRole::STAFF->value])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'WOULD_LOCK_OUT_LAST_ADMIN');

        $this->assertSame(UserRole::ADMIN, $this->admin->refresh()->role);
    }

    public function test_an_administrator_can_demote_someone_else(): void
    {
        $other = User::factory()->admin()->create();

        $this->actingAs($this->admin)
            ->patchJson("/api/v1/admin/users/{$other->uuid}", ['role' => UserRole::STAFF->value])
            ->assertOk()
            ->assertJsonPath('data.role', UserRole::STAFF->value);

        $this->assertTrue($other->refresh()->isBackoffice(), 'La personne reste au stand, avec moins de droits.');
    }

    public function test_it_deactivates_without_deleting(): void
    {
        $staff = User::factory()->staff()->create();

        $this->actingAs($this->admin)
            ->patchJson("/api/v1/admin/users/{$staff->uuid}", ['status' => 0])
            ->assertOk();

        $this->assertFalse($staff->refresh()->isBackoffice(), 'Desactiver doit couper l.acces immediatement.');

        $this->assertDatabaseHas('users', ['id' => $staff->id, 'deleted_at' => null]);
    }

    public function test_a_correction_of_one_field_does_not_collide_with_the_account_itself(): void
    {
        /*
         * La regle d'unicite doit exclure le compte modifie. Sans cela, corriger
         * la faute de frappe dans un nom bloquerait l'enregistrement — non pas
         * parce que le numero est change, mais parce qu'il ne l'est pas et que la
         * regle le voit malgre tout.
         *
         * Le numero est renvoye a l'identique, ce qui est le geste le plus banal
         * du formulaire de modification : celui ou l'on ne touche qu'au nom.
         */
        $staff = User::factory()->staff()->create();

        $this->actingAs($this->admin)
            ->patchJson("/api/v1/admin/users/{$staff->uuid}", [
                'firstname' => 'Ama',
                'phone' => $staff->phone,
            ])
            ->assertOk()
            ->assertJsonPath('data.firstname', 'Ama');
    }

    // ---------------------------------------------------------------------
    // Ce qui reste.
    // ---------------------------------------------------------------------

    public function test_it_traces_a_role_change_with_its_before_and_after(): void
    {
        $other = User::factory()->admin()->create();

        $this->actingAs($this->admin)
            ->patchJson("/api/v1/admin/users/{$other->uuid}", ['role' => UserRole::STAFF->value])
            ->assertOk();

        $log = AuditLog::query()->sole();

        $this->assertSame('user_role_changed', $log->action);
        $this->assertSame($this->admin->id, $log->user_id);
        $this->assertSame(['role' => UserRole::ADMIN->value], $log->old_value);
        $this->assertSame(['role' => UserRole::STAFF->value], $log->new_value);
    }

    public function test_it_traces_a_password_reset_even_though_there_is_no_old_value(): void
    {
        /*
         * Une reinitialisation n'a pas de valeur precedente — c'est tout l'objet
         * de l'action. La trace existe malgre cela, avec une seule entree, parce
         * que la question a laquelle elle repond (« qui a change le mot de passe
         * de ce compte, et quand ») ne porte pas sur les valeurs mais sur le
         * fait. Une trace sans « avant » vaut mieux qu aucune trace : ce que
         * l'on cherche ici est un evenement, pas un diff.
         */
        $staff = User::factory()->staff()->create();

        $this->actingAs($this->admin)
            ->postJson("/api/v1/admin/users/{$staff->uuid}/reset-password", ['password' => 'Nouveau2026'])
            ->assertOk();

        $log = AuditLog::query()->sole();

        $this->assertSame('user_password_reset', $log->action);
        $this->assertNull($log->old_value);
        $this->assertNull($log->new_value);

        $this->assertTrue(
            Hash::check('Nouveau2026', $staff->refresh()->password),
            'Le nouveau mot de passe doit etre celui qui fonctionne.',
        );
    }

    /**
     * Une invitation valide, surchargeable champ par champ.
     *
     * Les valeurs par defaut sont deja valides : le test qui surcharge un seul
     * champ doit pouvoir le faire sans redoubler l'invitation entiere, sinon le
     * test lu ne dit plus quel champ il verifie.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function invite(array $overrides = []): array
    {
        return $overrides + [
            'firstname' => 'Ama',
            'lastname' => 'Koffi',
            'email' => 'guichet@merchshop.test',
            'phone' => '90112233',
            'role' => UserRole::STAFF->value,
            'password' => 'Guichet2026',
        ];
    }
}
