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

    /**
     * Les tests tournent en environnement `testing`, pas `local` : le
     * court-circuit d'environnement local du middleware ne doit donc pas
     * masquer une régression du gate.
     */
    public function test_it_refuses_the_documentation_to_a_guest(): void
    {
        $this->get('/docs/api')->assertForbidden();
        $this->get('/docs/api.json')->assertForbidden();
    }

    public function test_it_refuses_the_documentation_to_a_customer(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/docs/api')
            ->assertForbidden();
    }

    public function test_it_refuses_the_documentation_to_a_staff_member(): void
    {
        $this->actingAs(User::factory()->staff()->create())
            ->get('/docs/api')
            ->assertForbidden();
    }

    public function test_it_refuses_the_documentation_to_a_suspended_admin(): void
    {
        $this->actingAs(User::factory()->admin()->inactive()->create())
            ->get('/docs/api')
            ->assertForbidden();
    }

    public function test_it_opens_the_ui_to_an_active_admin(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get('/docs/api')
            ->assertOk();
    }

    public function test_it_serves_the_openapi_document_to_an_active_admin(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get('/docs/api.json')
            ->assertOk()
            ->assertJsonPath('openapi', '3.1.0');
    }
}
