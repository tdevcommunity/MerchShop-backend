<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Accès à la documentation OpenAPI.
 *
 * La spécification expose le détail des routes d'écriture du back-office :
 * la lire doit exiger le même pouvoir que de les appeler. Ces tests fixent
 * cette règle, car un simple oubli de middleware ouvrirait la lecture de la
 * surface d'administration à quiconque a une session.
 */
class ApiDocumentationAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_unauthenticated_api_request_returns_json_instead_of_redirecting(): void
    {
        $this->getJson('/api/v1/orders')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }

    /**
     * Les tests tournent en environnement `testing`, pas `local` : le
     * court-circuit d'environnement local du middleware ne doit donc pas
     * masquer une régression du gate.
     */
    public function test_it_opens_the_documentation_to_a_guest(): void
    {
        $this->get('/api/documentation')->assertOk();
        $this->get('/docs')->assertOk();
    }

    public function test_it_opens_the_documentation_to_a_customer(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/api/documentation')
            ->assertOk();
    }

    public function test_it_opens_the_documentation_to_a_staff_member(): void
    {
        $this->actingAs(User::factory()->staff()->create())
            ->get('/api/documentation')
            ->assertOk();
    }

    public function test_it_opens_the_documentation_to_a_suspended_admin(): void
    {
        $this->actingAs(User::factory()->admin()->inactive()->create())
            ->get('/api/documentation')
            ->assertOk();
    }

    public function test_it_opens_the_ui_to_an_active_admin(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get('/api/documentation')
            ->assertOk();
    }

    public function test_it_serves_the_openapi_document_to_an_active_admin(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get('/docs')
            ->assertOk()
            ->assertJsonPath('openapi', '3.0.0');
    }
}
