<?php

namespace Database\Seeders;

use App\Enums\CatalogStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Models\Variant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class TestDataSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedUsers();
        $this->seedCatalog();
    }

    private function seedUsers(): void
    {
        $this->saveUser('admin@merchshop.test', [
                'firstname' => 'Admin',
                'lastname' => 'MerchShop',
                'phone' => '+22890000001',
                'password' => Hash::make('password'),
                'status' => UserStatus::ACTIVE,
                'role' => UserRole::ADMIN,
                'email_verified_at' => now(),
        ]);

        $this->saveUser('staff@merchshop.test', [
                'firstname' => 'Staff',
                'lastname' => 'MerchShop',
                'phone' => '+22890000002',
                'password' => Hash::make('password'),
                'status' => UserStatus::ACTIVE,
                'role' => UserRole::STAFF,
                'email_verified_at' => now(),
        ]);

        $this->saveUser('client@merchshop.test', [
                'firstname' => 'Client',
                'lastname' => 'Test',
                'phone' => '+22890000003',
                'password' => Hash::make('password'),
                'status' => UserStatus::ACTIVE,
                'role' => UserRole::CUSTOMER,
                'email_verified_at' => now(),
        ]);
    }

    private function seedCatalog(): void
    {
        $clothing = $this->saveModel(Category::class, ['slug' => 'vetements'], [
                'name' => 'Vetements',
                'description' => 'T-shirts et tenues officielles du festival.',
                'status' => CatalogStatus::ACTIVE,
        ]);

        $accessories = $this->saveModel(Category::class, ['slug' => 'accessoires'], [
                'name' => 'Accessoires',
                'description' => 'Accessoires officiels du festival.',
                'status' => CatalogStatus::ACTIVE,
        ]);

        $tshirt = $this->saveModel(Product::class, ['slug' => 't-shirt-officiel'], [
                'category_id' => $clothing->id,
                'name' => 'T-shirt officiel',
                'description' => 'T-shirt officiel du festival TDEV.',
                'status' => CatalogStatus::ACTIVE,
        ]);

        $cap = $this->saveModel(Product::class, ['slug' => 'casquette-tdev'], [
                'category_id' => $accessories->id,
                'name' => 'Casquette TDEV',
                'description' => 'Casquette brodee aux couleurs du festival.',
                'status' => CatalogStatus::ACTIVE,
        ]);

        $this->seedVariant($tshirt, 'TSHIRT-NOIR-M', 'Taille M - Noir', 7500, 25);
        $this->seedVariant($tshirt, 'TSHIRT-NOIR-L', 'Taille L - Noir', 7500, 25);
        $this->seedVariant($tshirt, 'TSHIRT-BLANC-M', 'Taille M - Blanc', 7500, 20);
        $this->seedVariant($cap, 'CAP-TDEV-UNIQUE', 'Taille unique', 5000, 30);
    }

    private function seedVariant(Product $product, string $sku, string $name, int $price, int $stock): void
    {
        $this->saveModel(Variant::class, ['sku' => $sku], [
                'product_id' => $product->id,
                'name' => $name,
                'price' => $price,
                'stock' => $stock,
                'status' => CatalogStatus::ACTIVE,
        ]);
    }

    /**
     * Persiste un compte avec un UUID explicite pour les installations dont
     * les événements Eloquent de génération d'identifiant sont désactivés.
     *
     * @param  array<string, mixed>  $values
     */
    private function saveUser(string $email, array $values): User
    {
        return $this->saveModel(User::class, ['email' => $email], $values);
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     * @param  class-string<TModel>  $modelClass
     * @param  array<string, mixed>  $identity
     * @param  array<string, mixed>  $values
     * @return TModel
     */
    private function saveModel(string $modelClass, array $identity, array $values): object
    {
        $model = $modelClass::withTrashed()->firstOrNew($identity);

        if (! $model->exists) {
            $model->uuid = (string) Str::uuid();
        }

        $model->fill($values);
        $model->save();

        if (method_exists($model, 'trashed') && $model->trashed()) {
            $model->restore();
        }

        return $model;
    }
}
