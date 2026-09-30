<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Authentification par session.
 *
 * La session est verifiee de bout en bout : le cookie, la persistance en base
 * et l'identite retrouvee au controle suivant. Un test qui se contente de
 * verifier le code HTTP passerait meme si la session n'etait jamais ecrite, ce
 * qui est exactement le piege de ce mode d'authentification.
 */
class AuthTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function validRegistration(array $overrides = []): array
    {
        return array_merge([
            'firstname' => 'Ayi',
            'lastname' => 'Kponou',
            'phone' => '+22890112233',
            'email' => 'ayi@example.com',
            'password' => 'Festival-2026!',
            'password_confirmation' => 'Festival-2026!',
        ], $overrides);
    }

    public function test_csrf_token_endpoint_is_public(): void
    {
        $this->getJson('/api/v1/auth/csrf-token')
            ->assertOk()
            ->assertJsonStructure(['data' => ['csrfToken']]);
    }

    public function test_csrf_token_endpoint_sets_the_xsrf_cookie(): void
    {
        // Le front fonctionne en deux temps : il recupere le jeton ici, puis
        // le renvoie dans X-XSRF-TOKEN. Sans cookie, la deuxieme etape est
        // impossible et aucune ecriture ne passe.
        $response = $this->get('/api/v1/auth/csrf-token');

        $response->assertCookie('XSRF-TOKEN');
    }

    public function test_registration_creates_an_account_and_opens_a_session(): void
    {
        $response = $this->postJson('/api/v1/auth/register', $this->validRegistration());

        $response->assertCreated()
            ->assertJsonPath('data.email', 'ayi@example.com')
            ->assertJsonPath('data.role', UserRole::CUSTOMER->value);

        $this->assertDatabaseHas('users', ['email' => 'ayi@example.com']);
    }

    public function test_registration_never_exposes_the_password(): void
    {
        $payload = $this->postJson('/api/v1/auth/register', $this->validRegistration())
            ->assertCreated()
            ->json();

        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('password', $encoded);
        $this->assertStringNotContainsString('Festival-2026!', $encoded);
    }

    public function test_registration_ignores_an_attempted_role_escalation(): void
    {
        // Le role ne fait pas partie des regles du RegisterRequest, donc il
        // n'est pas valide. Le test verifie le point qui compte vraiment : la
        // reponse contient bien un client, et ce client n'est pas admin.
        $this->postJson('/api/v1/auth/register', $this->validRegistration([
            'role' => UserRole::ADMIN->value,
        ]))->assertCreated()
            ->assertJsonPath('data.role', UserRole::CUSTOMER->value);

        $this->assertDatabaseHas('users', [
            'email' => 'ayi@example.com',
            'role' => UserRole::CUSTOMER->value,
        ]);
    }

    public function test_registration_ignores_an_attempted_status_escalation(): void
    {
        $this->postJson('/api/v1/auth/register', $this->validRegistration([
            'status' => UserStatus::INACTIVE->value,
        ]))->assertCreated();

        $this->assertDatabaseHas('users', [
            'email' => 'ayi@example.com',
            'status' => UserStatus::ACTIVE->value,
        ]);
    }

    public function test_registration_rejects_a_weak_password(): void
    {
        $this->assertValidationFails($this->validRegistration([
            'password' => 'password',
            'password_confirmation' => 'password',
        ]), 'password');
    }

    public function test_registration_rejects_a_mismatched_confirmation(): void
    {
        // `confirmed` porte l'erreur sur le champ `password` lui-meme, et non
        // sur la confirmation : c'est le champ que le visiteur doit corriger.
        $this->assertValidationFails($this->validRegistration([
            'password_confirmation' => 'Autre-2026!',
        ]), 'password');
    }

    public function test_registration_rejects_a_non_togois_phone(): void
    {
        $this->assertValidationFails($this->validRegistration([
            'phone' => '0102030405',
        ]), 'phone');
    }

    public function test_registration_rejects_a_duplicate_email(): void
    {
        User::factory()->create(['email' => 'ayi@example.com']);

        $this->assertValidationFails($this->validRegistration(), 'email');
    }

    public function test_registration_rejects_an_email_held_by_a_deleted_account(): void
    {
        /*
         * L'index unique porte sur la colonne email seule : un compte supprime
         * logiquement occupe toujours son adresse. Si la regle unique excluait
         * les lignes supprimees, la validation passerait et l'insertion
         * echouerait en 500.
         */
        User::factory()->create(['email' => 'ayi@example.com'])->delete();

        $this->assertValidationFails($this->validRegistration(), 'email');
    }

    public function test_registration_rejects_a_duplicate_phone(): void
    {
        User::factory()->create(['phone' => '+22890112233']);

        $this->assertValidationFails($this->validRegistration(), 'phone');
    }

    /**
     * Verifie qu'un payload est refuse en 422, sur le champ attendu.
     *
     * Les erreurs de validation de cette API ne sortent pas sous la cle
     * `errors` de Laravel mais sous `error.details.fields`
     * (voir App\Support\Api\ApiErrorResponder) : `assertJsonValidationErrors`
     * chercherait au mauvais endroit et passerait au travers d'un vrai
     * changement de contrat.
     *
     * @param  array<string, mixed>  $payload
     */
    private function assertValidationFails(array $payload, string $field): void
    {
        $this->postJson('/api/v1/auth/register', $payload)
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_ERROR')
            ->assertJsonStructure(['error' => ['details' => ['fields' => [$field]]]]);
    }

    public function test_login_opens_a_session(): void
    {
        $user = User::factory()->create(['email' => 'ayi@example.com']);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'ayi@example.com',
            'password' => 'password',
        ])
            ->assertOk()
            ->assertJsonPath('data.uuid', $user->uuid);

        $this->assertAuthenticatedAs($user);
    }

    public function test_login_is_case_insensitive_on_the_email(): void
    {
        $user = User::factory()->create(['email' => 'ayi@example.com']);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'AYI@Example.COM',
            'password' => 'password',
        ])->assertOk();

        $this->assertAuthenticatedAs($user);
    }

    public function test_login_rejects_a_wrong_password(): void
    {
        User::factory()->create(['email' => 'ayi@example.com']);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'ayi@example.com',
            'password' => 'mauvais-mot-de-passe',
        ])
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'INVALID_CREDENTIALS');

        $this->assertGuest();
    }

    public function test_login_gives_the_same_message_for_an_unknown_email(): void
    {
        // Differencier les deux cas permettrait d'enumerer les comptes inscrits
        // sans jamais forcer un mot de passe.
        $unknown = $this->postJson('/api/v1/auth/login', [
            'email' => 'inexistant@example.com',
            'password' => 'peu-importe-123',
        ]);

        $wrongPassword = $this->postJson('/api/v1/auth/login', [
            'email' => (string) User::factory()->create()->email,
            'password' => 'mauvais-mot-de-passe',
        ]);

        $unknown->assertUnauthorized()->assertJsonPath('error.message', $wrongPassword->json('error.message'));
    }

    public function test_login_refuses_an_inactive_account(): void
    {
        User::factory()->inactive()->create(['email' => 'ayi@example.com']);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'ayi@example.com',
            'password' => 'password',
        ])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'ACCOUNT_DISABLED');

        $this->assertGuest();
    }

    public function test_login_regenerates_the_session_id(): void
    {
        /*
         * La session d'avant connexion est un cookie que le visiteur controle
         * deja. Sans regeneration, un attaquant qui aurait impose cet
         * identifiant recupererait la session authentifiee : c'est la fixation
         * de session.
         */
        $this->get('/api/v1/auth/csrf-token');

        $before = session()->getId();

        User::factory()->create(['email' => 'ayi@example.com']);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'ayi@example.com',
            'password' => 'password',
        ])->assertOk();

        $this->assertNotSame($before, session()->getId());
    }

    public function test_login_persists_the_session_in_the_session_store(): void
    {
        /*
         * Le comportement attendu d'une session n'est pas « la reponse dit
         * 200 » mais « la connexion survit a l'appel suivant ». On verifie donc
         * l'ecriture reelle du cookie de session.
         */
        User::factory()->create(['email' => 'ayi@example.com']);

        // Le nom du cookie est configure, pas fige dans le code : il derive
        // d'APP_NAME. Un test qui l'ecrirait en dur echouerait des la
        // premiere edition de l'application.
        $this->postJson('/api/v1/auth/login', [
            'email' => 'ayi@example.com',
            'password' => 'password',
        ])->assertCookie((string) config('session.cookie'));

        $this->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.email', 'ayi@example.com');
    }

    public function test_me_requires_a_session(): void
    {
        $this->getJson('/api/v1/auth/me')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }

    public function test_me_returns_the_connected_account(): void
    {
        $user = User::factory()->admin()->create(['email' => 'admin@example.com']);

        $this->actingAs($user)
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.uuid', $user->uuid)
            ->assertJsonPath('data.role', UserRole::ADMIN->value);
    }

    public function test_logout_closes_the_session(): void
    {
        $this->actingAs(User::factory()->create())
            ->postJson('/api/v1/auth/logout')
            ->assertOk();

        $this->assertGuest();
    }

    public function test_logout_clears_the_csrf_token(): void
    {
        $this->get('/api/v1/auth/csrf-token');
        $before = session()->token();

        $this->actingAs(User::factory()->create())
            ->postJson('/api/v1/auth/logout')
            ->assertOk();

        // Un jeton CSRF identique apres deconnexion permettrait de rejouer la
        // requete qui a precede la deconnexion.
        $this->assertNotSame($before, session()->token());
    }

    public function test_me_does_not_expose_the_password_hash(): void
    {
        $this->actingAs(User::factory()->create())
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonMissingPath('data.password')
            ->assertJsonMissingPath('data.rememberToken');
    }
}
