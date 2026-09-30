<?php

namespace Tests\Unit\OpenApi;

use App\OpenApi\CamelCasesResourceProperties;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Dedoc\Scramble\Support\Generator\Types\StringType;
use Tests\TestCase;

/**
 * Le contrat de réponse est en camelCase, les ressources le déclarent en
 * snake_case. Ces tests verrouillent le fait que la spécification suit le
 * contrat : sans le transformateur, la spec décrirait un payload que l'API
 * n'émettra jamais, et un client généré chercherait des champs inexistants.
 */
class CamelCasesResourcePropertiesTest extends TestCase
{
    public function test_it_renames_resource_properties_to_camel_case(): void
    {
        $openApi = $this->openApiWith([
            'CategoryResource' => $this->resource([
                'uuid' => new StringType,
                'products_count' => new StringType,
                'created_at' => new StringType,
            ]),
        ]);

        (new CamelCasesResourceProperties)($openApi);

        $properties = $openApi->components->schemas['CategoryResource']->type->properties;

        $this->assertSame(['uuid', 'productsCount', 'createdAt'], array_keys($properties));
    }

    public function test_it_renames_required_keys_alongside_properties(): void
    {
        $openApi = $this->openApiWith([
            'CategoryResource' => $this->resource(['products_count' => new StringType], required: ['products_count']),
        ]);

        (new CamelCasesResourceProperties)($openApi);

        $this->assertSame(['productsCount'], $openApi->components->schemas['CategoryResource']->type->required);
    }

    public function test_it_renames_nested_resource_properties(): void
    {
        $nested = $this->resource(['is_available' => new StringType]);
        $product = $this->resource(['best_variant' => $nested]);

        $openApi = $this->openApiWith(['ProductResource' => $product]);
        (new CamelCasesResourceProperties)($openApi);

        $type = $openApi->components->schemas['ProductResource']->type;

        $this->assertSame(['bestVariant'], array_keys($type->properties));
        $this->assertSame(
            ['isAvailable'],
            array_keys($type->properties['bestVariant']->type->properties),
        );
    }

    public function test_it_removes_nullable_properties_from_required(): void
    {
        $description = (new StringType)->nullable(true);

        $openApi = $this->openApiWith([
            'CategoryResource' => $this->resource(
                ['name' => new StringType, 'description' => $description],
                required: ['name', 'description'],
            ),
        ]);
        (new CamelCasesResourceProperties)($openApi);

        $this->assertSame(['name'], $openApi->components->schemas['CategoryResource']->type->required);
    }

    public function test_it_leaves_request_schemas_untouched(): void
    {
        $openApi = $this->openApiWith([
            'StoreProductRequest' => $this->resource(['category_id' => new StringType]),
        ]);

        (new CamelCasesResourceProperties)($openApi);

        $this->assertSame(
            ['category_id'],
            array_keys($openApi->components->schemas['StoreProductRequest']->type->properties),
        );
    }

    public function test_it_leaves_unrelated_component_schemas_untouched(): void
    {
        $openApi = $this->openApiWith([
            'HealthPayload' => $this->resource(['api_version' => new StringType]),
        ]);

        (new CamelCasesResourceProperties)($openApi);

        $this->assertSame(
            ['api_version'],
            array_keys($openApi->components->schemas['HealthPayload']->type->properties),
        );
    }

    /**
     * @param  array<string, Schema>  $schemas
     */
    private function openApiWith(array $schemas): OpenApi
    {
        $openApi = OpenApi::make('3.1.0');
        $openApi->components->schemas = $schemas;

        return $openApi;
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function resource(array $properties, array $required = []): Schema
    {
        $type = new ObjectType;
        $type->properties = $properties;
        $type->required = $required;

        return Schema::fromType($type);
    }
}
