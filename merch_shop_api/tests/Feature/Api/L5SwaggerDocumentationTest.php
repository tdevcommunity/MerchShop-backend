<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class L5SwaggerDocumentationTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_generated_document_contains_the_api_contract(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get('/docs')
            ->assertOk()
            ->assertJsonPath('info.title', 'MerchShop API')
            ->assertJsonPath('components.securitySchemes.sessionCookie.type', 'apiKey')
            ->assertJsonStructure([
                'openapi',
                'info',
                'paths' => [
                    '/health',
                    '/auth/login',
                    '/auth/register',
                    '/categories',
                    '/products',
                    '/orders',
                    '/pickup/scan',
                    '/payments/webhooks/{provider}',
                ],
            ]);
    }
}
