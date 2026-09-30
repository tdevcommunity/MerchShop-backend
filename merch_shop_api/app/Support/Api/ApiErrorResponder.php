<?php

namespace App\Support\Api;

use App\Exceptions\ApiException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Throwable;

/**
 * Traduit une exception en reponse JSON homogenous pour toutes les routes /api/*.
 *
 * Le contrat de reponse est unique et documente dans docs/ARCHITECTURE.md :
 *
 *   {"error": {"code": "VALIDATION_ERROR", "message": "...", "details": {}}}
 *
 * Le code HTTP porte le statut de l'operation, `code` porte la cause metier.
 * Les messages restent generiques : le detail technique (trace, SQL, chemin
 * interne) part dans les logs serveur, jamais dans la reponse.
 */
final class ApiErrorResponder
{
    public static function make(Throwable $exception): JsonResponse
    {
        ['status' => $status, 'code' => $code, 'message' => $message, 'details' => $details] = self::describe($exception);

        return response()->json(
            [config('api.envelope.error_key', 'error') => self::normalise($code, $message, $details)],
            $status,
            $exception instanceof HttpExceptionInterface ? $exception->getHeaders() : [],
        );
    }

    /**
     * Applique la normalisation camelCase a l'enveloppe, en laissant intactes
     * les cles de `details.fields`.
     *
     * Ces cles ne sont pas de la donnee a presenter mais une reference aux
     * champs que le client a envoyes : il s'en sert pour surligner le mauvais
     * input. Les normaliser casserait cette correspondance — un client qui
     * envoie `category_id` recevrait `categoryId`, qu'il ne retrouve nulle part
     * dans sa propre requete, et devrait reconstruire la conversion a l'envers
     * pour afficher l'erreur au bon endroit. Le contrat d'entree reste donc
     * identique a l'aller et au retour, y compris pour la pagination
     * (`?per_page=`).
     *
     * @param  array<string, mixed>  $details
     * @return array<string, mixed>
     */
    private static function normalise(string $code, string $message, array $details): array
    {
        $envelope = CamelCase::keys([
            'code' => $code,
            'message' => $message,
            'details' => $details,
        ]);

        if (isset($envelope['details']['fields']) && is_array($details['fields'] ?? null)) {
            $envelope['details']['fields'] = $details['fields'];
        }

        return $envelope;
    }

    /**
     * @return array{status: int, code: string, message: string, details: array<string, mixed>}
     */
    private static function describe(Throwable $exception): array
    {
        // L'ordre des cas est significatif : les exceptions metier et de
        // validation sont testees avant le cas generique HttpExceptionInterface.
        //
        // Le cas 403 liste deux classes : Laravel reemballe
        // `AuthorizationException` dans une `AccessDeniedHttpException` avant
        // que l'exception n'atteigne ce rendu. Ne garder que la premiere
        // rendrait la branche inatteignable, et tout refus d'acces partirait en
        // `HTTP_ERROR`. Le front perdrait alors la distinction entre 401
        // (« pas connecte ») et 403 (« connecte mais role insuffisant »), qui
        // appellent deux corrections opposees.
        return match (true) {
            $exception instanceof ApiException => [
                'status' => $exception->status(),
                'code' => $exception->errorCode(),
                'message' => $exception->getMessage(),
                'details' => $exception->details(),
            ],

            $exception instanceof ValidationException => [
                'status' => 422,
                'code' => 'VALIDATION_ERROR',
                'message' => 'Les données envoyées sont invalides.',
                'details' => ['fields' => $exception->errors()],
            ],

            $exception instanceof ModelNotFoundException,
            $exception instanceof NotFoundHttpException => [
                'status' => 404,
                'code' => 'NOT_FOUND',
                'message' => 'La ressource demandee est introuvable.',
                'details' => [],
            ],

            $exception instanceof MethodNotAllowedHttpException => [
                'status' => 405,
                'code' => 'METHOD_NOT_ALLOWED',
                'message' => 'Cette methode HTTP n\'est pas autorisee sur cette ressource.',
                'details' => [],
            ],

            $exception instanceof TooManyRequestsHttpException => [
                'status' => 429,
                'code' => 'RATE_LIMIT_EXCEEDED',
                'message' => 'Trop de requetes, reessayez plus tard.',
                'details' => [],
            ],

            $exception instanceof AuthenticationException => [
                'status' => 401,
                'code' => 'UNAUTHENTICATED',
                'message' => 'Authentification requise.',
                'details' => [],
            ],

            $exception instanceof AuthorizationException,
            $exception instanceof AccessDeniedHttpException => [
                'status' => 403,
                'code' => 'FORBIDDEN',
                'message' => 'Vous n\'avez pas les droits necessaires pour cette operation.',
                'details' => [],
            ],

            $exception instanceof HttpExceptionInterface => [
                'status' => $exception->getStatusCode(),
                'code' => 'HTTP_ERROR',
                'message' => 'La requete n\'a pas pu etre traitee.',
                'details' => [],
            ],

            default => [
                'status' => 500,
                'code' => 'INTERNAL_ERROR',
                'message' => 'Une erreur interne est survenue.',
                'details' => config('app.debug')
                    ? ['exception' => $exception::class, 'reason' => $exception->getMessage()]
                    : [],
            ],
        };
    }
}
