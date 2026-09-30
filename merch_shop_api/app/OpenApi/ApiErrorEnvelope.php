<?php

namespace App\OpenApi;

use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types as OpenApiTypes;

/**
 * Décrit l'enveloppe d'erreur commune à toutes les routes `/api/*`.
 *
 * Scramble sait qu'une action peut lever une exception, mais il décrit alors la
 * forme que Laravel rend *par défaut* (`{"message": "..."}`, ou
 * `{"message", "errors"}` pour une validation). Cette API ne rend pas cette
 * forme : `ApiErrorResponder` renvoie un contrat unique, documenté dans
 * `docs/ARCHITECTURE.md` § 4.3 :
 *
 *   {"error": {"code": "VALIDATION_ERROR", "message": "...", "details": {}}}
 *
 * Sans cette classe, la spécification annonce au client un `message` à la
 * racine et un `errors` qui n'existe pas — il chercherait la cause de l'échec
 * au mauvais endroit, et n'accèderait jamais au `code`, qui est la seule partie
 * stable de l'erreur et donc la seule sur laquelle un client peut brancher une
 * traduction.
 *
 * Les schémas sont donc décrits ici à la main, mais les codes d'erreur reprennent
 * ceux de `ApiErrorResponder::describe()` : la documentation et le rendu
 * énumèrent la même liste.
 */
final class ApiErrorEnvelope
{
    /**
     * Codes émis par l'API, par statut. Sert à annoncer les `enum` possibles
     * d'un `code` : un client peut alors distinguer « identifiants invalides »
     * de « quota dépassé » sans analyser le message, qui est libre d'évoluer.
     *
     * @var array<int, list<string>>
     */
    private const CODES = [
        400 => ['BAD_REQUEST'],
        401 => ['UNAUTHENTICATED'],
        403 => ['FORBIDDEN'],
        404 => ['NOT_FOUND'],
        405 => ['METHOD_NOT_ALLOWED'],
        409 => ['CONFLICT', 'DUPLICATE_SKU', 'STOCK_CONFLICT'],
        422 => ['VALIDATION_ERROR', 'INVALID_FILTER'],
        429 => ['RATE_LIMIT_EXCEEDED'],
        500 => ['INTERNAL_ERROR'],
    ];

    /**
     * Enveloppe d'erreur pour un statut donné.
     *
     * @param  bool  $withFields  ajoute `details.fields`, présent uniquement
     *                            pour une erreur de validation
     */
    public static function response(int $status, string $description, bool $withFields = false): Response
    {
        return Response::make($status)
            ->setDescription($description)
            ->setContent('application/json', Schema::fromType(self::type($status, $withFields)));
    }

    public static function type(int $status, bool $withFields = false): OpenApiTypes\ObjectType
    {
        $details = (new OpenApiTypes\ObjectType)
            ->setDescription('Précisions sur la cause. Objet vide, ou porteur de `fields` pour une erreur de validation.')
            ->addProperty('fields', (new OpenApiTypes\ObjectType)
                ->setDescription('Messages de validation, indexés par le nom du champ tel que le client l\'a envoyé. Les clés ne sont pas converties en camelCase, afin de rester retrouvables dans la requête d\'origine.')
                ->additionalProperties((new OpenApiTypes\ArrayType)->setItems(new OpenApiTypes\StringType))
            );

        $error = (new OpenApiTypes\ObjectType)
            ->setDescription('Cause de l\'échec, exposée pour que le client puisse la traiter sans analyser le message.')
            ->addProperty('code', (new OpenApiTypes\StringType)
                ->setDescription('Code d\'erreur stable, indépendant du texte du message.')
                ->enum(self::CODES[$status] ?? ['HTTP_ERROR'])
            )
            ->addProperty('message', (new OpenApiTypes\StringType)
                ->setDescription('Message destiné à l\'affichage, en français. Le texte est libre d\'évoluer : seul le `code` est stable et doit être utilisé pour brancher une logique.')
            )
            ->addProperty('details', $details)
            ->setRequired(['code', 'message', 'details']);

        if (! $withFields) {
            // `details.fields` n'existe que pour une validation : le laisser en
            // optionnel everywhere ferait annoncer au client une clé absente de
            // la réponse qu'il recevra.
            $details->setRequired([]);
        }

        return (new OpenApiTypes\ObjectType)
            ->addProperty('error', $error)
            ->setRequired(['error']);
    }
}
