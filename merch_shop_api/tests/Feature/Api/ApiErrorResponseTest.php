<?php

namespace Tests\Feature\Api;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ApiErrorResponseTest extends TestCase
{
    public function test_unknown_api_routes_return_the_json_error_envelope(): void
    {
        $this->getJson('/api/v1/does-not-exist')
            ->assertNotFound()
            ->assertExactJson([
                'error' => [
                    'code' => 'NOT_FOUND',
                    'message' => 'La ressource demandee est introuvable.',
                    'details' => [],
                ],
            ]);
    }

    public function test_unsupported_http_methods_return_the_json_error_envelope(): void
    {
        $this->postJson('/api/v1/health')
            ->assertStatus(405)
            ->assertJsonPath('error.code', 'METHOD_NOT_ALLOWED');
    }

    public function test_unexpected_exceptions_return_a_generic_envelope_without_internals(): void
    {
        config()->set('app.debug', false);

        Route::get('/api/__testing__/boom', function (): never {
            throw new \RuntimeException('SQLSTATE[42S02]: Base table or view not found (merchshop.products)');
        });

        $this->getJson('/api/__testing__/boom')
            ->assertStatus(500)
            ->assertExactJson([
                'error' => [
                    'code' => 'INTERNAL_ERROR',
                    'message' => 'Une erreur interne est survenue.',
                    'details' => [],
                ],
            ]);
    }

    public function test_non_api_routes_keep_the_laravel_default_behaviour(): void
    {
        $this->get('/')->assertOk();
    }
}
