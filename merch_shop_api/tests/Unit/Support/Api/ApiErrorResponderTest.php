<?php

namespace Tests\Unit\Support\Api;

use App\Exceptions\ApiException;
use App\Support\Api\ApiErrorResponder;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Tests\TestCase;

class ApiErrorResponderTest extends TestCase
{
    public function test_it_keeps_the_code_and_details_of_a_domain_exception(): void
    {
        $response = ApiErrorResponder::make(new ApiException(
            'Stock insuffisant pour la variante demandee.',
            409,
            'INSUFFICIENT_STOCK',
            ['variant_id' => 42, 'available_quantity' => 0],
        ));

        $this->assertSame(409, $response->getStatusCode());
        $this->assertSame([
            'error' => [
                'code' => 'INSUFFICIENT_STOCK',
                'message' => 'Stock insuffisant pour la variante demandee.',
                'details' => ['variantId' => 42, 'availableQuantity' => 0],
            ],
        ], $response->getData(true));
    }

    public function test_it_exposes_validation_errors_field_by_field(): void
    {
        $validator = Validator::make(
            ['quantity' => -1],
            ['quantity' => ['required', 'integer', 'min:1']],
        );

        $response = ApiErrorResponder::make(new ValidationException($validator));

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('VALIDATION_ERROR', $response->getData(true)['error']['code']);
        $this->assertArrayHasKey('quantity', $response->getData(true)['error']['details']['fields']);
    }

    public function test_it_maps_framework_http_exceptions_to_stable_codes(): void
    {
        $this->assertSame('NOT_FOUND', $this->codeFor(new NotFoundHttpException));
        $this->assertSame('RATE_LIMIT_EXCEEDED', $this->codeFor(new TooManyRequestsHttpException));
        $this->assertSame('UNAUTHENTICATED', $this->codeFor(new AuthenticationException));
    }

    public function test_it_hides_internals_of_unexpected_exceptions_when_debug_is_off(): void
    {
        config()->set('app.debug', false);

        $response = ApiErrorResponder::make(
            new \RuntimeException('SQLSTATE[42S02]: Base table or view not found (merchshop.products)'),
        );

        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame([
            'error' => [
                'code' => 'INTERNAL_ERROR',
                'message' => 'Une erreur interne est survenue.',
                'details' => [],
            ],
        ], $response->getData(true));
    }

    public function test_it_exposes_the_cause_of_unexpected_exceptions_when_debug_is_on(): void
    {
        config()->set('app.debug', true);

        $response = ApiErrorResponder::make(new \RuntimeException('boom'));

        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame(\RuntimeException::class, $response->getData(true)['error']['details']['exception']);
    }

    private function codeFor(\Throwable $exception): string
    {
        return ApiErrorResponder::make($exception)->getData(true)['error']['code'];
    }
}
