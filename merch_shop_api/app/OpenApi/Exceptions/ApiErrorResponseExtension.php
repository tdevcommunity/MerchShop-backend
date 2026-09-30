<?php

namespace App\OpenApi\Exceptions;

use App\OpenApi\ApiErrorEnvelope;
use Dedoc\Scramble\Extensions\ExceptionToResponseExtension;
use Dedoc\Scramble\Support\Generator\Reference;
use Dedoc\Scramble\Support\Type\ObjectType;
use Dedoc\Scramble\Support\Type\Type;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

/**
 * Remplace les réponses d'erreur de Scramble par l'enveloppe de l'API.
 *
 * Une seule classe pour toutes les exceptions traitées : le contrat d'erreur de
 * l'API est unique, et le décrire à six endroits le ferait diverger. Seule la
 * description lisible et la présence de `details.fields` changent selon
 * l'exception.
 *
 * L'extension est enregistrée après celles du package, qui leur accordent donc
 * une priorité plus faible (voir TypeTransformer) : c'est ce qui permet de
 * remplacer, et non d'ajouter, la description `{message}` par défaut.
 */
final class ApiErrorResponseExtension extends ExceptionToResponseExtension
{
    /**
     * Statut, description lisible, et présence du détail `fields`.
     *
     * @var array<class-string<Throwable>, array{0: int, 1: string, 2: bool}>
     */
    private const RESPONSES = [
        ValidationException::class => [422, 'Données envoyées invalides. `details.fields` nomme chaque champ refusé.', true],
        AuthenticationException::class => [401, 'Authentification requise. Ouvrir une session via `POST /auth/login`.', false],
        AuthorizationException::class => [403, 'Rôle insuffisant pour cette opération. La session est valide, le compte ne l\'est pas.', false],
        NotFoundHttpException::class => [404, 'Ressource introuvable. Une ressource masquée du catalogue répond également 404, pour ne pas en révéler l\'existence.', false],
        TooManyRequestsHttpException::class => [429, 'Quota de débit dépassé. Réessayer plus tard.', false],
    ];

    public function shouldHandle(Type $type)
    {
        if (! $type instanceof ObjectType) {
            return false;
        }

        // `isInstanceOf()` n'accepte qu'un nom de classe : passer la liste en
        // variadique ne lèverait aucune erreur, PHP ignorerait les arguments
        // supplémentaires, et seule la première exception serait testée.
        foreach (array_keys(self::RESPONSES) as $exception) {
            if ($type->isInstanceOf($exception)) {
                return true;
            }
        }

        return false;
    }

    public function toResponse(Type $type)
    {
        [$status, $description, $withFields] = $this->describe($type);

        return ApiErrorEnvelope::response($status, $description, $withFields);
    }

    public function reference(ObjectType $type)
    {
        return new Reference('responses', Str::start($type->name, '\\'), $this->components);
    }

    /**
     * Retrouve la description correspondant à l'exception la plus spécifique
     * déclarée par l'action.
     *
     * @return array{0: int, 1: string, 2: bool}
     */
    private function describe(Type $type): array
    {
        foreach (self::RESPONSES as $exception => $response) {
            if ($type->isInstanceOf($exception)) {
                return $response;
            }
        }

        return [500, 'Erreur interne.', false];
    }
}
