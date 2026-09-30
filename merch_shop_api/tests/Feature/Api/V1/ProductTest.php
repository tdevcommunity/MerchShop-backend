<?php

namespace Tests\Feature\Api\V1;

use App\Enums\CatalogStatus;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Models\Variant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * CRUD des produits et cycle de vie de leurs variantes.
 *
 * La synchronisation des declinaisons est le cœur de cet ensemble : la
 * majorite des tests couvrent donc la question « que fait l'API quand la liste
 * de variantes est complete, partielle, ou absente », qui est le contrat que le
 * back-office va dependre.
 */
class ProductTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    /**
     * Produit vendable minimal.
     *
     * Il porte une variante par defaut : un produit sans declinaison doit
     * malgre tout indiquer son prix et son stock, sinon il n'a rien a vendre.
     * Les tests qui veulent des declinaisons multiples surchargent `variants`,
     * ce qui remplace la variante par defaut.
     */
    private function validProduct(array $overrides = []): array
    {
        $product = array_merge([
            'name' => 'T-shirt TDEV',
            'description' => 'Coton bio',
            'category_id' => Category::factory()->create()->id,
        ], $overrides);

        /*
         * Un produit doit toujours porter un prix. Lorsque le test fournit ses
         * propres declinaisons, il s'en charge ; sinon l'aide en ajoute une par
         * defaut, pour que les tests centres sur un autre aspect n'aient pas a
         * repeter le prix a chaque appel.
         */
        if (! array_key_exists('variants', $product)) {
            $product['default_variant'] ??= ['price' => 5000, 'stock' => 12];
        }

        return $product;
    }

    /**
     * @return array<string, mixed>
     */
    private function validVariant(array $overrides = []): array
    {
        return array_merge([
            'sku' => 'TDEV-TEE-M',
            'name' => 'Taille M',
            'price' => 5000,
            'stock' => 12,
        ], $overrides);
    }

    // ---------------------------------------------------------------- lecture

    public function test_list_is_public(): void
    {
        Product::factory()->count(2)->create();

        $this->getJson('/api/v1/products')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [[
                    'uuid', 'name', 'description', 'slug', 'status',
                    'category', 'variants', 'variantsCount', 'priceFrom', 'isAvailable',
                ]],
                'meta' => ['currentPage', 'perPage', 'total'],
            ]);
    }

    public function test_list_hides_inactive_products(): void
    {
        Product::factory()->inactive()->create();

        $this->getJson('/api/v1/products')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_list_filters_by_category(): void
    {
        $category = Category::factory()->create();
        Product::factory()->count(2)->create(['category_id' => $category->id]);
        Product::factory()->create();

        $this->getJson('/api/v1/products?category_id='.$category->id)
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_list_filters_by_search(): void
    {
        Product::factory()->create(['name' => 'T-shirt TDEV Edition 2026']);
        Product::factory()->create(['name' => 'Casquette Broderie']);

        $this->getJson('/api/v1/products?search=T-shirt')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'T-shirt TDEV Edition 2026');
    }

    public function test_search_treats_wildcards_literally(): void
    {
        Product::factory()->create(['name' => 'T-shirt']);
        Product::factory()->create(['name' => 'Casquette']);

        // Un `%` en provenance du client ne doit pas se comporter comme un
        // joker SQL, sinon la recherche renverrait tout le catalogue.
        $this->getJson('/api/v1/products?search=%25')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_list_rejects_a_malformed_category_filter(): void
    {
        $this->getJson('/api/v1/products?category_id=abc')
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'INVALID_FILTER');
    }

    public function test_list_ignores_an_array_search_parameter(): void
    {
        // `?search[]=a` enverrait un tableau ; le filtre doit l'ignorer au
        // lieu de le laisser atteindre la clause LIKE.
        Product::factory()->count(2)->create();

        $this->getJson('/api/v1/products?search[]=T-shirt')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_show_is_public(): void
    {
        $product = Product::factory()->create();

        $this->getJson('/api/v1/products/'.$product->uuid)
            ->assertOk()
            ->assertJsonPath('data.uuid', $product->uuid);
    }

    public function test_show_includes_variants(): void
    {
        $product = Product::factory()->create();
        Variant::factory()->count(2)->create(['product_id' => $product->id]);

        $this->getJson('/api/v1/products/'.$product->uuid)
            ->assertOk()
            ->assertJsonCount(2, 'data.variants')
            ->assertJsonPath('data.variantsCount', 2);
    }

    public function test_show_returns_404_for_an_unknown_product(): void
    {
        $this->getJson('/api/v1/products/00000000-0000-4000-8000-000000000000')
            ->assertNotFound();
    }

    public function test_variants_endpoint_lists_variants_of_a_product(): void
    {
        $product = Product::factory()->create();
        Variant::factory()->create(['product_id' => $product->id, 'sku' => 'AAA-111111']);

        $this->getJson('/api/v1/products/'.$product->uuid.'/variants')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.sku', 'AAA-111111');
    }

    public function test_variants_endpoint_returns_404_for_an_unknown_product(): void
    {
        $this->getJson('/api/v1/products/00000000-0000-4000-8000-000000000000/variants')
            ->assertNotFound();
    }

    public function test_price_from_ignores_out_of_stock_variants(): void
    {
        // Un prix « a partir de » calcule sur une declinaison epuisee
        // afficherait un montant que le visiteur ne peut pas commander.
        $product = Product::factory()->create();
        Variant::factory()->outOfStock()->create(['product_id' => $product->id, 'price' => 1000]);
        Variant::factory()->withStock(3)->create(['product_id' => $product->id, 'price' => 5000]);

        $this->getJson('/api/v1/products/'.$product->uuid)
            ->assertOk()
            ->assertJsonPath('data.priceFrom', 5000)
            ->assertJsonPath('data.isAvailable', true);
    }

    public function test_a_product_without_available_variant_is_not_available(): void
    {
        $product = Product::factory()->create();
        Variant::factory()->outOfStock()->create(['product_id' => $product->id]);

        $this->getJson('/api/v1/products/'.$product->uuid)
            ->assertOk()
            ->assertJsonPath('data.isAvailable', false)
            ->assertJsonPath('data.priceFrom', null);
    }

    public function test_list_loads_variants_without_a_query_per_product(): void
    {
        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        /*
         * Le test compare deux tailles de catalogue plutot que de compter
         * des requetes : le nombre exact depend des relations chargees, et un
         * total absolut devient faux des qu'on ajoute une colonne ou une
         * relation. Ce qui doit rester invariable, c'est l'absence de
         * croissance avec le nombre de lignes — c'est exactement la
         * signature d'un N+1.
         */
        Product::factory()->count(2)->create();
        $queries = 0;
        $this->getJson('/api/v1/products')->assertOk();
        $withTwoProducts = $queries;

        Product::factory()->count(8)->create();
        $queries = 0;
        $this->getJson('/api/v1/products')->assertOk();
        $withEightProducts = $queries;

        $this->assertSame(
            $withTwoProducts,
            $withEightProducts,
            'Le nombre de requetes croit avec le nombre de produits : une relation n\'est pas eager-loadee.',
        );
    }

    // -------------------------------------------------------------- creation

    public function test_admin_can_create_a_product_without_variants(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->postJson('/api/v1/products', $this->validProduct())
            ->assertCreated()
            ->assertJsonPath('data.name', 'T-shirt TDEV')
            ->assertJsonCount(1, 'data.variants')
            ->assertJsonPath('data.variants.0.price', 5000)
            ->assertJsonPath('data.variants.0.stock', 12)
            ->assertJsonPath('data.isAvailable', true);
    }

    public function test_the_default_variant_takes_its_reference_and_name_from_the_product(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->postJson('/api/v1/products', $this->validProduct(['name' => 'Casquette TDEV']))
            ->assertCreated()
            ->assertJsonPath('data.variants.0.sku', 'CASQUETTE-TDEV-DEF')
            ->assertJsonPath('data.variants.0.name', 'Casquette TDEV');
    }

    public function test_the_default_variant_keeps_an_explicit_reference(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->postJson('/api/v1/products', $this->validProduct([
                'default_variant' => [
                    'sku' => 'TDEV-CAS-UNIQUE',
                    'name' => 'Taille unique',
                    'price' => 3000,
                    'stock' => 4,
                ],
            ]))
            ->assertCreated()
            ->assertJsonPath('data.variants.0.sku', 'TDEV-CAS-UNIQUE')
            ->assertJsonPath('data.variants.0.name', 'Taille unique');

        $this->assertDatabaseHas('product_variants', ['sku' => 'TDEV-CAS-UNIQUE', 'stock' => 4]);
    }

    public function test_creation_refuses_a_product_with_no_price_at_all(): void
    {
        $payload = $this->validProduct();
        unset($payload['default_variant']);

        $this->actingAs(User::factory()->admin()->create())
            ->postJson('/api/v1/products', $payload)
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['default_variant']]]]);

        $this->assertDatabaseCount('products', 0);
    }

    public function test_creation_refuses_a_default_variant_without_price(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->postJson('/api/v1/products', $this->validProduct([
                'default_variant' => ['stock' => 4],
            ]))
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['default_variant.price']]]]);
    }

    public function test_creation_refuses_a_default_variant_without_stock(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->postJson('/api/v1/products', $this->validProduct([
                'default_variant' => ['price' => 3000],
            ]))
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['default_variant.stock']]]]);
    }

    public function test_creation_refuses_variants_and_a_default_variant_together(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->postJson('/api/v1/products', $this->validProduct([
                'variants' => [$this->validVariant()],
                'default_variant' => ['price' => 3000, 'stock' => 4],
            ]))
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['default_variant']]]]);
    }

    public function test_admin_can_create_a_product_with_variants(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->postJson('/api/v1/products', $this->validProduct([
                'variants' => [$this->validVariant(), $this->validVariant(['sku' => 'TDEV-TEE-L', 'name' => 'Taille L'])],
            ]))
            ->assertCreated()
            ->assertJsonCount(2, 'data.variants')
            ->assertJsonPath('data.variantsCount', 2);

        $this->assertDatabaseHas('product_variants', ['sku' => 'TDEV-TEE-M']);
        $this->assertDatabaseHas('product_variants', ['sku' => 'TDEV-TEE-L']);
    }

    public function test_creation_derives_the_slug(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->postJson('/api/v1/products', $this->validProduct(['name' => 'T-Shirt TDEV']))
            ->assertCreated()
            ->assertJsonPath('data.slug', 't-shirt-tdev')
            ->assertJsonPath('data.variants.0.sku', 'T-SHIRT-TDEV-DEF');
    }

    public function test_creation_rejects_an_unknown_category(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->postJson('/api/v1/products', $this->validProduct(['category_id' => 999999]))
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['category_id']]]]);
    }

    public function test_creation_rejects_a_category_archived_logically(): void
    {
        // La ligne existe encore, donc `exists` seul laisserait passer, et le
        // produit deviendrait invisible du catalogue public.
        $category = Category::factory()->create();
        $category->delete();

        $this->actingAs(User::factory()->admin()->create())
            ->postJson('/api/v1/products', $this->validProduct(['category_id' => $category->id]))
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['category_id']]]]);
    }

    public function test_creation_rejects_a_negative_stock(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->postJson('/api/v1/products', $this->validProduct([
                'variants' => [$this->validVariant(['stock' => -1])],
            ]))
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['variants.0.stock']]]]);
    }

    public function test_creation_rejects_a_fractional_price(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->postJson('/api/v1/products', $this->validProduct([
                'variants' => [$this->validVariant(['price' => '10.999'])],
            ]))
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['variants.0.price']]]]);
    }

    public function test_creation_refuses_a_price_that_the_currency_cannot_represent(): void
    {
        /*
         * 2500,50 FCFA n'est pas un prix aberrant a corriger : c'est un montant
         * qui n'existe pas, le franc CFA n'ayant pas de subdivision. Le refus doit
         * le dire, sinon l'administrateur chercherait une erreur de frappe la ou
         * il y a une regle de devise.
         */
        $response = $this->actingAs(User::factory()->admin()->create())
            ->postJson('/api/v1/products', $this->validProduct([
                'variants' => [$this->validVariant(['price' => '2500.50'])],
            ]))
            ->assertUnprocessable();

        $fields = $response->json('error.details.fields');

        $this->assertArrayHasKey('variants.0.price', $fields);
        $this->assertStringContainsString("le franc CFA n'a pas de subdivision", $fields['variants.0.price'][0]);
    }

    public function test_creation_refuses_a_fractional_price_on_the_default_variant(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->postJson('/api/v1/products', $this->validProduct([
                'variants' => [],
                'default_variant' => ['price' => '2500.50', 'stock' => 3],
            ]))
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['default_variant.price']]]]);
    }

    public function test_creation_accepts_every_writing_of_a_whole_price(): void
    {
        /*
         * Un back-office saisit au clavier : « 2500,00 » et « 2500.00 » sont la
         * meme somme que « 2500 ». Les refuser ferait echouer une saisie
         * correcte, alors que seul le montant compte.
         */
        $this->actingAs(User::factory()->admin()->create())
            ->postJson('/api/v1/products', $this->validProduct([
                'variants' => [$this->validVariant(['price' => '2500,00'])],
            ]))
            ->assertCreated()
            ->assertJsonPath('data.variants.0.price', 2500);
    }

    public function test_creation_accepts_a_price_sent_as_a_json_number(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->postJson('/api/v1/products', $this->validProduct([
                'variants' => [$this->validVariant(['price' => 2500])],
            ]))
            ->assertCreated()
            ->assertJsonPath('data.variants.0.price', 2500);
    }

    public function test_creation_rejects_an_incomplete_variant(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->postJson('/api/v1/products', $this->validProduct([
                'variants' => [['sku' => 'TDEV-TEE-M']],
            ]))
            ->assertUnprocessable()
            ->assertJsonStructure([
                'error' => ['details' => ['fields' => [
                    'variants.0.name', 'variants.0.price', 'variants.0.stock',
                ]]],
            ]);
    }

    public function test_creation_refuses_a_sku_already_used_by_another_product(): void
    {
        Variant::factory()->create(['sku' => 'TDEV-TEE-M']);

        // Sans controle prealable, l'index unique produirait une erreur 500.
        $this->actingAs(User::factory()->admin()->create())
            ->postJson('/api/v1/products', $this->validProduct([
                'variants' => [$this->validVariant()],
            ]))
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'SKU_ALREADY_EXISTS');
    }

    public function test_creation_refuses_a_sku_duplicated_inside_the_payload(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->postJson('/api/v1/products', $this->validProduct([
                'variants' => [$this->validVariant(), $this->validVariant()],
            ]))
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'DUPLICATED_VARIANT_SKU');
    }

    public function test_creation_is_rolled_back_when_a_variant_fails(): void
    {
        // Le produit et ses variantes forment un tout : un echec en cours de
        // route ne doit pas laisser un produit sans declinaison en base.
        $this->actingAs(User::factory()->admin()->create())
            ->postJson('/api/v1/products', $this->validProduct([
                'variants' => [$this->validVariant()],
            ]))
            ->assertCreated();

        $before = Product::count();

        $this->actingAs(User::factory()->admin()->create())
            ->postJson('/api/v1/products', $this->validProduct([
                'variants' => [
                    $this->validVariant(['sku' => 'NEW-000001']),
                    $this->validVariant(['sku' => 'TDEV-TEE-M']),
                ],
            ]))
            ->assertStatus(409);

        $this->assertSame($before, Product::count(), 'Le produit du second appel aurait du etre annule.');
        $this->assertDatabaseMissing('product_variants', ['sku' => 'NEW-000001']);
    }

    public function test_creation_requires_admin(): void
    {
        $this->actingAs(User::factory()->staff()->create())
            ->postJson('/api/v1/products', $this->validProduct())
            ->assertForbidden();

        // Le refus doit survenir avant toute ecriture : le personnel ne cree
        // pas de produit, meme provisoire.
        $this->assertDatabaseCount('products', 0);
    }

    // -------------------------------------------------------------- mise a jour

    public function test_admin_can_update_a_product_without_touching_variants(): void
    {
        $product = Product::factory()->create(['name' => 'Ancien nom']);
        Variant::factory()->create(['product_id' => $product->id]);

        // L'absence de la cle `variants` signifie « variantes inchangees ».
        $this->actingAs(User::factory()->admin()->create())
            ->putJson('/api/v1/products/'.$product->uuid, ['name' => 'Nouveau nom'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Nouveau nom')
            ->assertJsonCount(1, 'data.variants');
    }

    public function test_update_can_move_a_product_to_another_category(): void
    {
        $product = Product::factory()->create();
        $target = Category::factory()->create(['name' => 'Nouvel emplacement']);

        $this->actingAs(User::factory()->admin()->create())
            ->putJson('/api/v1/products/'.$product->uuid, ['category_id' => $target->id])
            ->assertOk()
            ->assertJsonPath('data.category.name', 'Nouvel emplacement');
    }

    public function test_update_rejects_a_duplicate_explicit_slug(): void
    {
        Product::factory()->create(['slug' => 'tee-ancien']);
        $product = Product::factory()->create();

        $this->actingAs(User::factory()->admin()->create())
            ->putJson('/api/v1/products/'.$product->uuid, ['slug' => 'tee-ancien'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'SLUG_ALREADY_EXISTS');
    }

    public function test_update_allows_a_product_to_keep_its_own_slug(): void
    {
        $product = Product::factory()->create(['slug' => 'tee-stable']);

        // Le controle d'unicite doit ignorer la ligne en cours de
        // modification, sinon se renommer vers son propre slug echouerait.
        $this->actingAs(User::factory()->admin()->create())
            ->putJson('/api/v1/products/'.$product->uuid, ['slug' => 'tee-stable', 'name' => 'Nouveau nom'])
            ->assertOk()
            ->assertJsonPath('data.slug', 'tee-stable');
    }

    public function test_update_can_hide_a_product(): void
    {
        $product = Product::factory()->create();

        $this->actingAs(User::factory()->admin()->create())
            ->putJson('/api/v1/products/'.$product->uuid, ['status' => CatalogStatus::INACTIVE->value])
            ->assertOk()
            ->assertJsonPath('data.status', CatalogStatus::INACTIVE->value);

        $this->getJson('/api/v1/products')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_update_requires_admin(): void
    {
        $product = Product::factory()->create(['name' => 'Original']);

        $this->actingAs(User::factory()->create())
            ->putJson('/api/v1/products/'.$product->uuid, ['name' => 'Piraté'])
            ->assertForbidden();

        $this->assertDatabaseHas('products', ['uuid' => $product->uuid, 'name' => 'Original']);
    }

    // ------------------------------------------- synchronisation des variantes

    public function test_update_synchronises_variants_by_uuid(): void
    {
        $product = Product::factory()->create();
        $keep = Variant::factory()->create(['product_id' => $product->id, 'sku' => 'KEEP-000001', 'price' => 1000]);
        $drop = Variant::factory()->create(['product_id' => $product->id, 'sku' => 'DROP-000001']);

        $this->actingAs(User::factory()->admin()->create())
            ->putJson('/api/v1/products/'.$product->uuid, [
                'variants' => [[
                    'uuid' => $keep->uuid,
                    'sku' => 'KEEP-000001',
                    'name' => 'Taille M',
                    'price' => 2000,
                    'stock' => 5,
                ]],
            ])
            ->assertOk()
            ->assertJsonCount(1, 'data.variants')
            ->assertJsonPath('data.variants.0.price', 2000);

        $this->assertDatabaseHas('product_variants', ['uuid' => $keep->uuid, 'price' => 2000]);
        $this->assertSoftDeleted('product_variants', ['uuid' => $drop->uuid]);
    }

    public function test_update_synchronises_variants_by_sku_when_uuid_is_absent(): void
    {
        // Cas le plus courant depuis un back-office : un formulaire qui
        // reconstruit la liste a partir d'un tableur n'a pas conserve les
        // identifiants, mais les SKU, si.
        $product = Product::factory()->create();
        $variant = Variant::factory()->create(['product_id' => $product->id, 'sku' => 'REF-000001', 'stock' => 1]);

        $this->actingAs(User::factory()->admin()->create())
            ->putJson('/api/v1/products/'.$product->uuid, [
                'variants' => [[
                    'sku' => 'REF-000001',
                    'name' => 'Taille M',
                    'price' => 1500,
                    'stock' => 7,
                ]],
            ])
            ->assertOk()
            ->assertJsonCount(1, 'data.variants');

        $this->assertDatabaseCount('product_variants', 1);
        $this->assertDatabaseHas('product_variants', [
            'uuid' => $variant->uuid,
            'stock' => 7,
            'price' => 1500,
        ]);
    }

    public function test_update_creates_variants_absent_from_the_product(): void
    {
        $product = Product::factory()->create();
        $existing = Variant::factory()->create(['product_id' => $product->id, 'sku' => 'OLD-000001']);

        $this->actingAs(User::factory()->admin()->create())
            ->putJson('/api/v1/products/'.$product->uuid, [
                'variants' => [
                    ['uuid' => $existing->uuid, 'sku' => 'OLD-000001', 'name' => 'S', 'price' => 1000, 'stock' => 2],
                    ['sku' => 'NEW-000001', 'name' => 'L', 'price' => 1000, 'stock' => 4],
                ],
            ])
            ->assertOk()
            ->assertJsonCount(2, 'data.variants');

        $this->assertDatabaseHas('product_variants', ['sku' => 'NEW-000001', 'stock' => 4]);
    }

    public function test_update_removes_variants_missing_from_the_payload(): void
    {
        $product = Product::factory()->create();
        $kept = Variant::factory()->create(['product_id' => $product->id, 'sku' => 'KEEP-000001']);
        $removed = Variant::factory()->create(['product_id' => $product->id, 'sku' => 'GONE-000001']);

        $this->actingAs(User::factory()->admin()->create())
            ->putJson('/api/v1/products/'.$product->uuid, [
                'variants' => [[
                    'uuid' => $kept->uuid, 'sku' => 'KEEP-000001', 'name' => 'S', 'price' => 1000, 'stock' => 1,
                ]],
            ])->assertOk();

        // Suppression logique : une variante peut etre referencee par des
        // commandes passees, dont l'historique doit rester consultable.
        $this->assertSoftDeleted('product_variants', ['uuid' => $removed->uuid]);
    }

    public function test_an_empty_variant_list_removes_every_variant(): void
    {
        $product = Product::factory()->create();
        Variant::factory()->count(2)->create(['product_id' => $product->id]);

        $this->actingAs(User::factory()->admin()->create())
            ->putJson('/api/v1/products/'.$product->uuid, ['variants' => []])
            ->assertOk()
            ->assertJsonCount(0, 'data.variants');

        $this->assertSame(0, Variant::query()->count());
    }

    public function test_omitting_the_variants_key_keeps_them_untouched(): void
    {
        $product = Product::factory()->create();
        $variant = Variant::factory()->create(['product_id' => $product->id, 'sku' => 'KEEP-000001', 'stock' => 3]);

        $this->actingAs(User::factory()->admin()->create())
            ->putJson('/api/v1/products/'.$product->uuid, ['description' => 'Nouveau texte'])
            ->assertOk();

        $this->assertDatabaseHas('product_variants', [
            'uuid' => $variant->uuid,
            'stock' => 3,
            'deleted_at' => null,
        ]);
    }

    public function test_update_refuses_a_variant_belonging_to_another_product(): void
    {
        $product = Product::factory()->create();
        $other = Product::factory()->create();
        $foreignVariant = Variant::factory()->create(['product_id' => $other->id]);

        /*
         * Detruire une variante d'un autre produit depuis cette route serait
         * une escalade : un admin d'un produit ne gere pas le catalogue entier
         * par cette porte.
         */
        $this->actingAs(User::factory()->admin()->create())
            ->putJson('/api/v1/products/'.$product->uuid, [
                'variants' => [[
                    'uuid' => $foreignVariant->uuid,
                    'sku' => $foreignVariant->sku,
                    'name' => 'Piratée',
                    'price' => 1,
                    'stock' => 0,
                ]],
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'VARIANT_NOT_IN_PRODUCT');

        $this->assertDatabaseHas('product_variants', [
            'uuid' => $foreignVariant->uuid,
            'deleted_at' => null,
        ]);
    }

    public function test_update_refuses_an_unknown_variant_uuid(): void
    {
        $product = Product::factory()->create();

        $this->actingAs(User::factory()->admin()->create())
            ->putJson('/api/v1/products/'.$product->uuid, [
                'variants' => [[
                    'uuid' => '00000000-0000-4000-8000-000000000000',
                    'sku' => 'GHOST-000001',
                    'name' => 'Fantome',
                    'price' => 1000,
                    'stock' => 1,
                ]],
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'VARIANT_NOT_IN_PRODUCT');
    }

    public function test_update_restores_a_variant_reintroduced_with_its_sku(): void
    {
        /*
         * Aller-retour courant au back-office : on supprime une declinaison par
         * erreur, on la remet le lendemain. L'index unique porte sur le SKU
         * seul, donc la ligne supprimee occupe toujours sa reference et une
         * creation ordinaire echouerait en 500.
         */
        $product = Product::factory()->create();
        $variant = Variant::factory()->create(['product_id' => $product->id, 'sku' => 'BACK-000001']);
        $variant->delete();

        $this->actingAs(User::factory()->admin()->create())
            ->putJson('/api/v1/products/'.$product->uuid, [
                'variants' => [[
                    'sku' => 'BACK-000001', 'name' => 'Rétablie', 'price' => 3000, 'stock' => 9,
                ]],
            ])
            ->assertOk()
            ->assertJsonCount(1, 'data.variants');

        $this->assertDatabaseHas('product_variants', [
            'sku' => 'BACK-000001',
            'name' => 'Rétablie',
            'deleted_at' => null,
        ]);
    }

    public function test_update_refuses_a_sku_belonging_to_another_product(): void
    {
        $product = Product::factory()->create();
        $foreign = Variant::factory()->create();

        $this->actingAs(User::factory()->admin()->create())
            ->putJson('/api/v1/products/'.$product->uuid, [
                'variants' => [[
                    'sku' => $foreign->sku, 'name' => 'Piratée', 'price' => 1, 'stock' => 0,
                ]],
            ])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'SKU_ALREADY_EXISTS');
    }

    public function test_a_variant_is_published_by_default_whatever_the_product_status(): void
    {
        /*
         * La variante n'herite pas du statut du produit. Un brouillon peut
         * ainsi etre prepare avec ses declinaisons, et la publication reste
         * une decision portant sur le produit : si le statut heredait, publier
         * un produit dont les variantes sont encore inactives les laisserait
         * invisibles, un etat a moitie publie que personne ne demande.
         */
        $this->actingAs(User::factory()->admin()->create())
            ->postJson('/api/v1/products', $this->validProduct([
                'status' => CatalogStatus::INACTIVE->value,
                'variants' => [$this->validVariant()],
            ]))
            ->assertCreated()
            ->assertJsonPath('data.variants.0.status', CatalogStatus::ACTIVE->value);
    }

    public function test_a_variant_can_be_masked_individually(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->postJson('/api/v1/products', $this->validProduct([
                'variants' => [
                    $this->validVariant(),
                    $this->validVariant([
                        'sku' => 'TDEV-TEE-XL',
                        'status' => CatalogStatus::INACTIVE->value,
                    ]),
                ],
            ]))
            ->assertCreated()
            ->assertJsonCount(1, 'data.variants')
            ->assertJsonPath('data.variants.0.sku', 'TDEV-TEE-M');

        $this->assertDatabaseHas('product_variants', [
            'sku' => 'TDEV-TEE-XL',
            'status' => CatalogStatus::INACTIVE->value,
        ]);
    }

    public function test_a_masked_variant_is_hidden_from_the_public_reads(): void
    {
        $product = Product::factory()->create();
        Variant::factory()->create([
            'product_id' => $product->id, 'sku' => 'SELL-000001', 'price' => 1000, 'stock' => 4,
        ]);
        Variant::factory()->inactive()->create([
            'product_id' => $product->id, 'sku' => 'HIDE-000001', 'price' => 1, 'stock' => 99,
        ]);

        // Sans filtre, la declinaison masquee resterait listee avec son stock,
        // et `price_from` afficherait un prix que le visiteur ne peut pas
        // commander.
        $this->getJson('/api/v1/products/'.$product->uuid)
            ->assertOk()
            ->assertJsonCount(1, 'data.variants')
            ->assertJsonPath('data.variants.0.sku', 'SELL-000001')
            ->assertJsonPath('data.variantsCount', 1)
            ->assertJsonPath('data.priceFrom', 1000);

        $this->getJson('/api/v1/products/'.$product->uuid.'/variants')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.sku', 'SELL-000001');
    }

    public function test_a_masked_variant_is_still_reachable_by_the_management_sync(): void
    {
        $product = Product::factory()->create();
        $hidden = Variant::factory()->inactive()->create([
            'product_id' => $product->id, 'sku' => 'HIDE-000001', 'stock' => 1,
        ]);

        // La lecture de synchronisation ne filtre pas le statut : sans elle, la
        // variante masquee passerait pour inconnue et la suppression du
        // produit la laisserait orpheline.
        $this->actingAs(User::factory()->admin()->create())
            ->deleteJson('/api/v1/products/'.$product->uuid)
            ->assertOk();

        $this->assertSoftDeleted('product_variants', ['uuid' => $hidden->uuid]);
    }

    public function test_synchronisation_is_rolled_back_on_failure(): void
    {
        $product = Product::factory()->create(['name' => 'Avant']);
        $variant = Variant::factory()->create([
            'product_id' => $product->id, 'sku' => 'ROLL-000001', 'stock' => 1,
        ]);

        // La variante de la deuxieme entree echoue, la premiere a deja ete
        // traitee : rien ne doit subsister de cet appel.
        $this->actingAs(User::factory()->admin()->create())
            ->putJson('/api/v1/products/'.$product->uuid, [
                'name' => 'Apres',
                'variants' => [
                    ['sku' => 'FRESH-000001', 'name' => 'Neuve', 'price' => 1000, 'stock' => 5],
                    ['sku' => Variant::factory()->create()->sku, 'name' => 'Conflit', 'price' => 1000, 'stock' => 1],
                ],
            ])
            ->assertStatus(409);

        $this->assertDatabaseHas('products', ['uuid' => $product->uuid, 'name' => 'Avant']);
        $this->assertDatabaseHas('product_variants', ['uuid' => $variant->uuid, 'stock' => 1]);
        $this->assertDatabaseMissing('product_variants', ['sku' => 'FRESH-000001']);
    }

    public function test_a_masked_product_is_hidden_from_the_public_reads(): void
    {
        $product = Product::factory()->inactive()->create();

        // Le catalogue le retire de la liste ; son URL doit suivre, sinon un
        // lien partage ou devine continuerait de servir une fiche non publiee.
        $this->getJson('/api/v1/products/'.$product->uuid)
            ->assertNotFound()
            ->assertJsonPath('error.code', 'PRODUCT_NOT_FOUND');

        $this->getJson('/api/v1/products/'.$product->uuid.'/variants')
            ->assertNotFound();
    }

    public function test_a_masked_product_can_still_be_republished(): void
    {
        $product = Product::factory()->inactive()->create();

        // Le back-office doit pouvoir atteindre ce qu'il a masque. Si la
        // relecture d'ecriture filtrait sur le statut, le produit deviendrait
        // introuvable et ne pourrait plus jamais etre republie.
        $this->actingAs(User::factory()->admin()->create())
            ->putJson('/api/v1/products/'.$product->uuid, ['status' => CatalogStatus::ACTIVE->value])
            ->assertOk()
            ->assertJsonPath('data.status', CatalogStatus::ACTIVE->value);

        $this->getJson('/api/v1/products/'.$product->uuid)->assertOk();
    }

    public function test_a_masked_product_can_still_be_deleted(): void
    {
        $product = Product::factory()->inactive()->create();

        $this->actingAs(User::factory()->admin()->create())
            ->deleteJson('/api/v1/products/'.$product->uuid)
            ->assertOk();

        $this->assertSoftDeleted('products', ['uuid' => $product->uuid]);
    }

    // -------------------------------------------------------------- suppression

    public function test_admin_can_delete_a_product_and_its_variants(): void
    {
        $product = Product::factory()->create();
        $variants = Variant::factory()->count(3)->create(['product_id' => $product->id]);

        $this->actingAs(User::factory()->admin()->create())
            ->deleteJson('/api/v1/products/'.$product->uuid)
            ->assertOk();

        $this->assertSoftDeleted('products', ['uuid' => $product->uuid]);

        foreach ($variants as $variant) {
            // Pas de variante orpheline : elle ne doit plus designer un
            // produit qui n'existe plus.
            $this->assertSoftDeleted('product_variants', ['uuid' => $variant->uuid]);
        }
    }

    public function test_deletion_is_soft(): void
    {
        $product = Product::factory()->create();
        $productId = $product->id;

        $this->actingAs(User::factory()->admin()->create())
            ->deleteJson('/api/v1/products/'.$product->uuid)
            ->assertOk();

        $this->assertNotNull(
            $this->app['db']->table('products')->where('id', $productId)->first(),
            'La ligne doit rester en base pour l\'historique des commandes.',
        );
    }

    public function test_a_deleted_product_disappears_from_the_catalogue(): void
    {
        $product = Product::factory()->create();
        $product->delete();

        $this->getJson('/api/v1/products/'.$product->uuid)->assertNotFound();
    }

    public function test_deletion_requires_admin(): void
    {
        $product = Product::factory()->create();

        $this->actingAs(User::factory()->create())
            ->deleteJson('/api/v1/products/'.$product->uuid)
            ->assertForbidden();

        $this->assertDatabaseHas('products', ['uuid' => $product->uuid, 'deleted_at' => null]);
    }

    public function test_deletion_requires_a_session(): void
    {
        $product = Product::factory()->create();

        $this->deleteJson('/api/v1/products/'.$product->uuid)
            ->assertUnauthorized();

        $this->assertDatabaseHas('products', ['uuid' => $product->uuid, 'deleted_at' => null]);
    }
}
