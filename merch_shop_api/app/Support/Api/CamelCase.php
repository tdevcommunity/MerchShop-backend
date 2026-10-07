<?php

namespace App\Support\Api;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Str;
use JsonSerializable;

/**
 * Normalisation des cles d'une charge utile JSON en camelCase.
 *
 * Le contrat de l'API (docs/NAMING_CONVENTIONS.md section 5) impose du
 * camelCase sur le fil, alors que la base de donnees reste en snake_case. La
 * conversion vit dans la couche presentation uniquement, ce qui evite de
 * contaminer les modeles, les files d'attente et le cache.
 *
 * Utilise par App\Http\Resources\ApiResource (sorties) et
 * App\Support\Api\ApiErrorResponder (details d'erreur).
 */
final class CamelCase
{
    /**
     * Recopie le tableau en convertissant recursivement toutes les cles en camelCase.
     *
     * Les cles numeriques des listes sont preservees telles quelles. Les valeurs
     * deja structurees (Arrayable, JsonSerializable) sont deroulees avant conversion.
     *
     * @param  array<array-key, mixed>  $payload
     * @return array<array-key, mixed>
     */
    public static function keys(array $payload): array
    {
        $converted = [];

        foreach ($payload as $key => $value) {
            $key = self::key($key);

            $converted[$key] = match (true) {
                is_array($value) => self::keys($value),
                $value instanceof Arrayable => self::keys($value->toArray()),
                $value instanceof JsonSerializable => self::keys((array) $value->jsonSerialize()),
                default => $value,
            };
        }

        return $converted;
    }

    /**
     * Convertit une seule clé en camelCase.
     *
     * Exposé séparément de `keys()` parce que la même règle sert aussi à la
     * génération de la spécification OpenAPI, qui doit renommer les clés d'un
     * schéma sans pouvoir les parcourir comme un tableau. Les deux chemins
     * partagent alors cette implémentation : une divergence entre la règle
     * appliquée au JSON et celle appliquée à sa documentation rendrait le
     * contrat décrit faux.
     *
     * Les clés numériques des listes sont préservées telles quelles.
     */
    public static function key(string|int $key): string|int
    {
        return is_string($key) ? Str::camel($key) : $key;
    }
}
