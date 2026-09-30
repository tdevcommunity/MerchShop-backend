<?php

namespace Tests\Feature\Models;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class UserTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_generates_a_uuid_on_creation(): void
    {
        $this->assertTrue(Str::isUuid(User::factory()->create()->uuid));
    }

    public function test_it_casts_status_and_role_to_enums(): void
    {
        $user = User::factory()->staff()->create(['status' => UserStatus::ACTIVE]);

        $this->assertSame(UserStatus::ACTIVE, $user->status);
        $this->assertSame(UserRole::STAFF, $user->role);
    }

    public function test_it_hashes_the_password(): void
    {
        $user = User::factory()->create(['password' => 'mot-de-passe-secret']);

        // Jamais le mot de passe en clair, et jamais lisible par accident.
        $this->assertNotSame('mot-de-passe-secret', $user->password);
        $this->assertTrue(Hash::check('mot-de-passe-secret', $user->password));
    }

    public function test_it_hides_the_password_from_serialization(): void
    {
        $user = User::factory()->create();

        $serialized = $user->toArray();

        $this->assertArrayNotHasKey('password', $serialized);
        $this->assertArrayNotHasKey('remember_token', $serialized);
    }

    public function test_it_rejects_duplicate_email_and_phone(): void
    {
        $user = User::factory()->create();

        $this->expectException(UniqueConstraintViolationException::class);

        User::factory()->create(['email' => $user->email]);
    }

    public function test_it_has_many_orders(): void
    {
        $user = User::factory()->create();
        $orders = Order::factory()->count(2)->create(['user_id' => $user->id]);

        $this->assertCount(2, $user->orders);
        $this->assertEqualsCanonicalizing(
            $orders->pluck('id')->all(),
            $user->orders->pluck('id')->all()
        );
    }

    public function test_it_keeps_orders_when_the_account_is_deleted(): void
    {
        // nullOnDelete : une commande et sa facture sont des pieces
        // comptables, elles ne peuvent pas disparaitre avec le compte.
        $user = User::factory()->create();
        $order = Order::factory()->create(['user_id' => $user->id]);

        $user->forceDelete();

        $this->assertNull(User::find($user->id));
        $this->assertNotNull(Order::find($order->id));
        $this->assertNull(Order::find($order->id)->user_id);
    }

    public function test_it_filters_active_users_and_staff(): void
    {
        $customer = User::factory()->create();
        $staff = User::factory()->staff()->create();
        User::factory()->inactive()->create();

        $this->assertCount(3, User::all());
        $this->assertEqualsCanonicalizing(
            [$customer->id, $staff->id],
            User::active()->pluck('id')->all()
        );
        $this->assertSame([$staff->id], User::staff()->pluck('id')->all());
    }
}
