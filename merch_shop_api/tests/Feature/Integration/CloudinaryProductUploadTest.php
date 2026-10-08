<?php

namespace Tests\Feature\Integration;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Vérifie le vrai upload Cloudinary pendant la création d'un produit.
 *
 * Ce test est opt-in : il contacte un service externe et ne doit pas s'exécuter
 * dans la suite standard.
 */
final class CloudinaryProductUploadTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_creation_uploads_chemise_bleu_to_cloudinary(): void
    {
        if (getenv('RUN_CLOUDINARY_INTEGRATION_TESTS') !== 'true') {
            $this->markTestSkipped('Test Cloudinary désactivé.');
        }

        $imagePath = public_path('chemise_bleu.png');

        $this->assertFileExists($imagePath);
        $this->assertGreaterThan(0, filesize($imagePath));

        $admin = User::factory()->admin()->create();
        $category = Category::factory()->create();

        $response = $this->actingAs($admin)->post('/api/v1/products', [
            'name' => 'Chemise bleue Cloudinary',
            'description' => 'Produit créé par le test d’intégration Cloudinary.',
            'category_id' => $category->id,
            'image' => new UploadedFile(
                $imagePath,
                'chemise_bleu.png',
                'image/png',
                null,
                true,
            ),
            'default_variant' => [
                'price' => 15000,
                'stock' => 10,
            ],
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.name', 'Chemise bleue Cloudinary');

        $imageUrl = $response->json('data.imageUrl');

        $this->assertIsString($imageUrl);
        $this->assertStringStartsWith('https://res.cloudinary.com/', $imageUrl);
        $this->assertStringContainsString('merch-shop/products', $imageUrl);

        $this->assertDatabaseHas('products', [
            'name' => 'Chemise bleue Cloudinary',
            'image_url' => $imageUrl,
        ]);
    }
}
