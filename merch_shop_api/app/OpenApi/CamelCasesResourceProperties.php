<?php

namespace App\OpenApi;

use App\Support\Api\CamelCase;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;

/**
 * Aligne la spécification OpenAPI sur le JSON réellement renvoyé.
 *
 * Les ressources déclarent leurs clés en snake_case (`products_count`), comme
 * toute la couche métier. La réponse publique, elle, est en camelCase : la
 * conversion est appliquée à la sérialisation, par le trait
 * NormalizesResponseKeys.
 *
 * Mais cette conversion n'apparaît dans aucun code que la génération analyse :
 * elle a lieu à l'exécution, dans une couche que l'analyse statique ne traverse
 * pas. Les schémas sont donc déduits de `toArray()` et restent en snake_case,
 * décrivant un payload que l'API n'émettra jamais — un client généré depuis
 * cette spécification chercherait `products_count` et ne trouverait rien.
 *
 * Ce transformateur réapplique donc la même règle que le trait, en fin de
 * génération. Il ne touche qu'aux schémas de composants : les requêtes restent
 * en snake_case, conformément au contrat d'entrée, et les enveloppes de
 * réponse sont décrites explicitement par les contrôleurs.
 */
final class CamelCasesResourceProperties
{
    /**
     * Suffixe des schémas dont les clés doivent être converties.
     *
     * Seules les ressources de l'API sont concernées, ce qui évite de retoucher
     * par mégarde un schéma tiers déclaré dans le projet.
     */
    private const RESOURCE_SCHEMA_SUFFIX = 'Resource';

    public function __invoke(OpenApi $openApi): void
    {
        foreach ($openApi->components->schemas as $name => $schema) {
            if (! str_ends_with($name, self::RESOURCE_SCHEMA_SUFFIX)) {
                continue;
            }

            $this->camelCaseKeys($schema->type);
        }
    }

    /**
     * Convertit les clés d'un type et de tous ses types imbriqués.
     */
    private function camelCaseKeys(mixed $type): void
    {
        if ($type instanceof ObjectType) {
            $properties = [];

            foreach ($type->properties as $key => $property) {
                $properties[CamelCase::key($key)] = $property;
            }

            $type->properties = $properties;
            $type->required = array_map(
                fn (string $key): string => (string) CamelCase::key($key),
                $type->required,
            );

            $this->dropNullableFromRequired($type);
        }

        foreach ($this->nestedTypes($type) as $nested) {
            $this->camelCaseKeys($nested);
        }
    }

    /**
     * Retire des obligatoires les propriétés dont la valeur peut être nulle.
     *
     * L'inférence liste dans `required` toute propriété présente dans le
     * tableau retourné, y compris quand elle vaut null. Une propriété à la fois
     * obligatoire et nullable est une contradiction : le client ne peut plus
     * distinguer « le champ est absent » de « le champ est vide », alors que la
     * ressource renvoie toujours la clé, parfois à null.
     */
    private function dropNullableFromRequired(ObjectType $type): void
    {
        $type->required = array_values(array_filter(
            $type->required,
            fn (string $key): bool => ! ($type->properties[$key] ?? null)?->nullable,
        ));
    }

    /**
     * Types imbriqués contenus dans un type, quel que soit son genre.
     */
    private function nestedTypes(mixed $type): array
    {
        if (! is_object($type)) {
            return [];
        }

        $nested = [];

        foreach (get_object_vars($type) as $value) {
            if ($value instanceof ObjectType) {
                $nested[] = $value;
            } elseif (is_iterable($value)) {
                foreach ($value as $item) {
                    if (is_object($item)) {
                        $nested[] = $item;
                    }
                }
            }
        }

        return $nested;
    }
}
