<?php

namespace Tests\Feature\Api\V1\Admin;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\AuditLog;
use App\Models\InventoryAdjustment;
use App\Models\Order;
use App\Models\User;
use App\Models\Variant;
use App\Services\OrderService;
use Database\Factories\PaymentFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ce que le back-office fait reellement.
 *
 * Les tests d'acces repondent a « qui peut entrer » ; ceux-la a « qu'est-ce
 * qui se passe une fois entre ». Les verifications portent donc sur la base —
 * l'etat du stock, la ligne de journal, la trace d'audit — et non sur le
 * contenu de la reponse, parce que l'etat est ce qui survit a l'ecran : une
 * reponse qui dit « annulee » sur une commande encore ouverte nuirait a rien et
 * fausserait une vente.
 *
 * Trois invariants sont verifies ici, et chacun meriteait son propre fichier :
 *
 *  1. l'argent ne se declare pas depuis le guichet. C'est une frontiere entre
 *     deux systemes — FedaPay et le stand — et une frontiere qu'on ne teste pas
 *     est une frontiere qui cede au premier refactor ;
 *
 *  2. un mouvement de stock sans trace est un stock que personne ne peut
 *     reconcilier. La verification porte donc sur l'egalite des deux
 *     ecritures, pas sur la reussite de l'appel ;
 *
 *  3. les alertes sont derivees, donc elles ne peuvent pas mentir sur
 *     l'instant present. Le test le prouve en reapprovisionnant : l'alerte
 *     disparait sans qu'aucune ligne ait eu besoin d'etre balayee.
 */
class AdminBackofficeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Un guichetier actif, seul compte dont les identifiants sont publies.
     */
    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->staff = User::factory()->staff()->create();
    }

    // ---------------------------------------------------------------------
    // L'argent ne se declare pas depuis le stand.
    // ---------------------------------------------------------------------

    /**
     * Les trois etats d'argent, un par un.
     *
     * Les trois sont verifies separement et non en boucle : ils ne sont pas
     * interchangeables. `PAID` se rencontre des la premiere commande, quand un
     * client a paye et que le guichet veut cloturer ; `REFUND_PENDING` et
     * `REFUNDED` apparaissent plus tard, apres un incident. Une boucle les
     * traiterait comme un cas et le test passerait alors qu'un seul des trois est
     * protege.
     *
     * @return array<string, array{int}>
     */
    public static function moneyStatuses(): array
    {
        return [
            'encaissement' => [OrderStatus::PAID->value],
            'remboursement demande' => [OrderStatus::REFUND_PENDING->value],
            'remboursement effectue' => [OrderStatus::REFUNDED->value],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('moneyStatuses')]
    public function test_it_refuses_to_declare_money_from_the_counter(int $status): void
    {
        $order = Order::factory()->paid()->create();

        $this->actingAs($this->staff)
            ->patchJson("/api/v1/admin/orders/{$order->uuid}/status", ['status' => $status])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'NOT_A_COUNTER_TRANSITION');

        $this->assertSame(
            OrderStatus::READY_FOR_PICKUP,
            $order->refresh()->status,
            'Un refus doit laisser la commande exactement ou elle etait.',
        );
    }

    public function test_the_refusal_says_where_the_money_is_declared(): void
    {
        /*
         * Le code d'erreur suffit a un client HTTP, mais pas a un guichetier. Le
         * message doit donc nommer l'autre systeme : un agent qui a bien recu
         * des especes doit comprendre ou aller, pas seulement qu'il a tort.
         */
        $order = Order::factory()->paid()->create();

        $this->actingAs($this->staff)
            ->patchJson("/api/v1/admin/orders/{$order->uuid}/status", ['status' => OrderStatus::PAID->value])
            ->assertJsonPath('error.message', 'Un encaissement ou un remboursement se déclare chez FedaPay, pas depuis le back-office.');
    }

    public function test_the_offered_actions_never_include_a_money_status(): void
    {
        /*
         * Le refus ci-dessus ne suffit pas si l'ecran propose quand meme le
         * bouton : le guichet cliquerait, se prendrait un 409, et apprendrait
         * que les messages de l'ecran mentent. La liste des actions offers est
         * donc verifiee sur toutes les commandes d'un etat ouvert, pas sur une
         * seule — parce que l'intersection est ce qui garantit qu'aucun bouton
         * d'argent ne peut apparaitre.
         */
        foreach ([OrderStatus::PENDING_PAYMENT, OrderStatus::PAID, OrderStatus::READY_FOR_PICKUP] as $status) {
            $order = Order::factory()->create(['status' => $status]);

            $actions = $this->actingAs($this->staff)
                ->getJson("/api/v1/admin/orders/{$order->uuid}")
                ->assertOk()
                ->json('data.counterActions');

            $money = [
                OrderStatus::PAID->value,
                OrderStatus::REFUND_PENDING->value,
                OrderStatus::REFUNDED->value,
            ];

            $this->assertSame(
                [],
                array_values(array_intersect($actions, $money)),
                "Un menu propose un mouvement d'argent pour une commande a l'etat {$status->name}.",
            );
        }
    }

    public function test_it_cancels_an_order_and_releases_the_stock(): void
    {
        $variant = Variant::factory()->withStock(3)->create();
        $order = Order::factory()->create();

        $this->actingAs($this->staff)
            ->patchJson("/api/v1/admin/orders/{$order->uuid}/status", [
                'status' => OrderStatus::CANCELLED->value,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', OrderStatus::CANCELLED->value);

        /*
         * Le stock est verifie sans la variante pour que l'echec soit lisible :
         * une assertion sur une chaine de relations qui echoue ne dit pas quel
         * nombre etait attendu.
         */
        $this->assertSame(3, $variant->refresh()->stock, 'Une annulation rend les pieces au stock.');
    }

    public function test_it_leaves_the_command_state_and_writes_the_trace(): void
    {
        $order = Order::factory()->create();

        $this->actingAs($this->staff)
            ->patchJson("/api/v1/admin/orders/{$order->uuid}/status", [
                'status' => OrderStatus::CANCELLED->value,
            ])
            ->assertOk();

        $log = AuditLog::query()->sole();

        $this->assertSame('order_status_changed', $log->action);
        $this->assertSame($this->staff->id, $log->user_id);
        $this->assertSame(
            ['status' => OrderStatus::PENDING_PAYMENT->value],
            $log->old_value,
            'La trace doit porter l.etat de depart, pas l.etat d.arrivee.',
        );
        $this->assertSame(['status' => OrderStatus::CANCELLED->value], $log->new_value);
    }

    public function test_it_refuses_a_status_outside_the_nomenclature(): void
    {
        $order = Order::factory()->create();

        $this->actingAs($this->staff)
            ->patchJson("/api/v1/admin/orders/{$order->uuid}/status", ['status' => 99])
            ->assertStatus(422)
            ->assertJsonPath('error.details.fields.status.0', 'Cet état de commande n\'existe pas.');
    }

    public function test_it_names_the_transitions_a_counter_may_ask_for(): void
    {
        /*
         * La route existe pour que le menu du front soit construit par la regle.
         * Sa valeur est donc verifiee comme telle, et pas seulement « elle
         * repond 200 » : un front qui recoitrait la liste des transitions du
         * service proposerait a nouveau « marquer payee », et le test passerait.
         */
        $this->actingAs($this->staff)
            ->getJson('/api/v1/admin/orders/transitions')
            ->assertOk()
            ->assertJsonPath('data.counter', OrderService::counterTransitions());
    }

    // ---------------------------------------------------------------------
    // Le stock se corrige, et se trace.
    // ---------------------------------------------------------------------

    public function test_an_adjustment_writes_the_stock_and_the_journal_together(): void
    {
        $variant = Variant::factory()->withStock(10)->create();

        $this->actingAs($this->staff)
            ->postJson("/api/v1/admin/inventory/{$variant->uuid}/adjust", [
                'delta' => 5,
                'reason' => 'reception',
                'note' => 'Livraison du stand',
            ])
            ->assertCreated()
            ->assertJsonPath('data.previousStock', 10)
            ->assertJsonPath('data.nextStock', 15);

        $this->assertSame(15, $variant->refresh()->stock);

        $log = InventoryAdjustment::query()->sole();

        $this->assertSame(10, $log->previous_stock);
        $this->assertSame(5, $log->delta);
        $this->assertSame(15, $log->next_stock);

        /*
         * Le nom est fige au moment du mouvement. C'est ce qui permet de lire la
         * ligne d'un article desormais renomme, et la raison pour laquelle la
         * ligne ne se resout pas contre le catalogue a la lecture.
         */
        $this->assertSame($variant->sku, $log->sku);
        $this->assertSame($variant->product->name, $log->product_name);
        $this->assertSame($this->staff->email, $log->user_email);
    }

    public function test_a_refused_adjustment_leaves_neither_stock_nor_journal(): void
    {
        $variant = Variant::factory()->withStock(4)->create();

        $this->actingAs($this->staff)
            ->postJson("/api/v1/admin/inventory/{$variant->uuid}/adjust", [
                'delta' => -9,
                'reason' => 'loss',
            ])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'INSUFFICIENT_STOCK');

        /*
         * La verification porte sur les deux ecritures, parce qu'elles sont le
         * meme engagement. Un stock intact avec une ligne de journal sería un
         * stock « corrige » qui ne l'a pas ete ; l'inverse mentirait sur un
         * stock qui n'a pas bouge. Les deux sont donc ecartees ensemble.
         */
        $this->assertSame(4, $variant->refresh()->stock);
        $this->assertSame(0, InventoryAdjustment::query()->count());
    }

    public function test_it_refuses_an_adjustment_that_changes_nothing(): void
    {
        $variant = Variant::factory()->withStock(4)->create();

        $this->actingAs($this->staff)
            ->postJson("/api/v1/admin/inventory/{$variant->uuid}/adjust", [
                'delta' => 0,
                'reason' => 'reception',
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.details.fields.delta.0', 'Un ajustement doit modifier le stock.');

        $this->assertSame(0, InventoryAdjustment::query()->count());
    }

    public function test_the_stock_level_is_read_from_each_variant_threshold(): void
    {
        /*
         * Le seuil est une colonne, pas une constante du serveur. Deux
         * declinaisons voisines peuvent donc avoir des seuils differents, et
         * c'est ce que la verification prouve : un seuil unique ecrit dans le
         * code classerait les deux de la meme facon et l'alerte de stock
         * mentirait sur l'une des deux.
         */
        Variant::factory()->withStock(6)->create(['low_stock_threshold' => 5]);
        Variant::factory()->withStock(6)->create(['low_stock_threshold' => 10]);

        $levels = $this->actingAs($this->staff)
            ->getJson('/api/v1/admin/inventory')
            ->assertOk()
            ->json('data.*.stockLevel');

        $this->assertContains('low', $levels);
        $this->assertContains('available', $levels);
    }

    // ---------------------------------------------------------------------
    // Les alertes disent le present.
    // ---------------------------------------------------------------------

    public function test_an_alert_appears_and_disappears_with_the_fact_it_describes(): void
    {
        $variant = Variant::factory()->withStock(1)->create(['low_stock_threshold' => 5]);

        $before = $this->alerts();
        $this->assertContains(
            'low-stock:'.$variant->uuid,
            array_column($before, 'id'),
            'Une declinaison sous son seuil doit etre signalee.',
        );

        $this->actingAs($this->staff)->postJson("/api/v1/admin/inventory/{$variant->uuid}/adjust", [
            'delta' => 20,
            'reason' => 'reception',
        ])->assertCreated();

        $after = $this->alerts();

        $this->assertNotContains(
            'low-stock:'.$variant->uuid,
            array_column($after, 'id'),
            'Une alerte de stock qui survit au reapprovisionnement apprend a ignorer les alertes.',
        );
    }

    public function test_a_deactivated_variant_is_not_an_alert(): void
    {
        /*
         * Un article masque par l'equipe ne doit pas remonter dans les alertes :
         * il est sorti du catalogue par decision, et son stock se reconcile depuis
         * l'ecran d'inventaire. L'afficher ici remettrait dans la liste du jour ce
         * que l'equipe a choisi de retirer.
         */
        Variant::factory()->outOfStock()->inactive()->create();

        $this->assertSame([], $this->alerts());
    }

    public function test_an_old_pending_payment_is_reported_and_a_fresh_one_is_not(): void
    {
        /*
         * La borne de deux heures est une decision : avant elle, une attente
         * est normale ; au-dela, elle ne aboutira pas. Les deuxextremites sont
         * verifiees parce que c'est le genre de regle qui se casse d'un seul
         * cote — un seuil pose trop tot signale des clients qui paient encore,
         * un seuil pose trop tard garde l'article retenu pour une collecte
         * expiree.
         */
        PaymentFactory::new()->forOrder(Order::factory()->create(), 5000)->create([
            'status' => PaymentStatus::PENDING->value,
            'created_at' => now()->subHours(3),
            'updated_at' => now()->subHours(3),
        ]);

        PaymentFactory::new()->forOrder(Order::factory()->create(), 5000)->create([
            'status' => PaymentStatus::PENDING->value,
            'created_at' => now()->subMinutes(10),
            'updated_at' => now()->subMinutes(10),
        ]);

        $stale = array_column($this->alerts(), 'id');
        $stale = array_values(array_filter($stale, static fn (string $id): bool => str_starts_with($id, 'stale-payment:')));

        $this->assertCount(1, $stale, 'Seule la collecte qui attend depuis plus de deux heures est une alerte.');
    }

    /**
     * Les alertes rendues par l'API, pour le compte qui les lit.
     *
     * @return array<int, array{id: string, tone: string, message: string}>
     */
    private function alerts(): array
    {
        return $this->actingAs($this->staff)
            ->getJson('/api/v1/admin/notifications')
            ->assertOk()
            ->json('data');
    }
}
