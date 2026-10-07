<?php

namespace Tests\Unit\Support\Orders;

use App\Models\Order;
use App\Models\User;
use App\Support\Orders\OrderAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regles d'ecretage du jeton d'acces des commandes invitees.
 *
 * Ce jeton est la seule voie d'acces d'un client sans compte, donc sa seule
 * frontiere est sa validite. Ces tests visent les etats qui pourraient laisser
 * passer quelqu'un : un jeton vide, un jeton d'une autre commande, un ancien jeton devenu inutile.
 */
class OrderAccessTest extends TestCase
{
    use RefreshDatabase;

    private OrderAccess $access;

    protected function setUp(): void
    {
        parent::setUp();

        $this->access = new OrderAccess;
    }

    public function test_it_issues_a_long_enough_token(): void
    {
        $token = $this->access->issueFor($this->guestOrder());

        $this->assertIsString($token);
        $this->assertSame(64, strlen($token));
    }

    public function test_it_never_stores_the_token_in_clear(): void
    {
        $order = $this->guestOrder();

        $token = $this->access->issueFor($order);

        $this->assertNotSame($token, $order->refresh()->guest_access_token_hash);
        $this->assertSame(hash('sha256', $token), $order->guest_access_token_hash);
    }

    public function test_the_issued_token_opens_that_order(): void
    {
        $order = $this->guestOrder();

        $this->assertTrue($this->access->grantsAccessTo($order, $this->access->issueFor($order)));
    }

    public function test_it_refuses_a_null_or_empty_token(): void
    {
        $order = $this->guestOrder();

        $this->access->issueFor($order);

        $this->assertFalse($this->access->grantsAccessTo($order, null));
        $this->assertFalse($this->access->grantsAccessTo($order, ''));
    }

    public function test_it_refuses_a_token_that_was_never_issued(): void
    {
        $order = $this->guestOrder();

        $this->assertFalse($this->access->grantsAccessTo($order, 'a'.str_repeat('0', 63)));
    }

    public function test_it_refuses_the_token_of_another_order(): void
    {
        $mine = $this->guestOrder();
        $other = $this->guestOrder();

        $theirs = $this->access->issueFor($other);

        $this->assertFalse($this->access->grantsAccessTo($mine, $theirs));
    }

    public function test_it_refuses_a_token_of_a_shorter_length(): void
    {
        $order = $this->guestOrder();

        $token = $this->access->issueFor($order);

        // Un prefixe valide ne doit pas suffire : la comparaison porte sur
        // l'empreinte entiere, donc sur la longueur du jeton d'origine.
        $this->assertFalse($this->access->grantsAccessTo($order, substr((string) $token, 0, 32)));
    }

    public function test_it_issues_nothing_to_an_order_owned_by_an_account(): void
    {
        $order = Order::factory()->create(['user_id' => User::factory()->create()->id]);

        $this->assertNull($this->access->issueFor($order));
        $this->assertNull($order->refresh()->guest_access_token_hash);
    }

    public function test_a_token_stops_working_once_the_order_belongs_to_an_account(): void
    {
        $order = $this->guestOrder();

        $token = $this->access->issueFor($order);

        $this->assertTrue($this->access->grantsAccessTo($order, $token));

        /*
         * Rattacher la commande a un compte transfere la propriete. Le jeton
         * devient alors une identite fantome : il doit cesser d'ouvrir quoi que
         * ce soit, et non survivre comme un acces de secours a la commande
         * d'autrui.
         */
        $order->forceFill(['user_id' => User::factory()->create()->id])->save();

        $this->assertFalse($this->access->grantsAccessTo($order, $token));
    }

    public function test_reissuing_replaces_the_previous_token(): void
    {
        $order = $this->guestOrder();

        $previous = $this->access->issueFor($order);
        $current = $this->access->issueFor($order);

        $this->assertNotSame($previous, $current);
        $this->assertFalse($this->access->grantsAccessTo($order, $previous));
        $this->assertTrue($this->access->grantsAccessTo($order, $current));
    }

    private function guestOrder(): Order
    {
        return Order::factory()->create(['user_id' => null]);
    }
}
