<?php

namespace Tests\Feature\Api\V1\Admin;

use App\Enums\CatalogStatus;
use App\Enums\FulfillmentMethod;
use App\Enums\OrderStatus;
use App\Enums\PickupStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\AuditLog;
use App\Models\InventoryAdjustment;
use App\Models\Order;
use App\Models\User;
use App\Models\Variant;
use App\Support\Api\AuditAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * L'acces au back-office.
 *
 * Ces tests ne verifient pas que les pages s'affichent : ils verifient que la
 * question d'entree — « ce compte entre-t-il dans le back-office ? » — est posee
 * avant toute autre, et qu'elle ne laisse passer que ce qu'elle doit.
 *
 * C'est le seul endroit ou la matrice de permissions est verifiee en bloc, et
 * c'est volontaire : elle est portee par `User::BACKOFFICE_PERMISSIONS`, donc
 * ajouter une permission sans ajouter de role se voit ici, ou nulle part.
 */
class AdminAccessTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Les routes que toute personne du back-office peut lire.
     *
     * Une liste d'URL figee sur une famille laisse passer les omissions :
     * ajouter une route et oublier de la tester ne se voit pas. Cette liste
     * couvre donc l'ecran d'accueil, les lectures, les ecritures et la recherche,
     * ce qui correspond aux quatre manieres de poser la question.
     *
     * Elle ne contient ni les comptes ni le journal, et c'est volontaire — voir
     * `adminOnlyRoutes` pour la raison.
     *
     * @return array<string, array{string}>
     */
    public static function protectedRoutes(): array
    {
        return [
            'tableau de bord' => ['/api/v1/admin/dashboard'],
            'commandes' => ['/api/v1/admin/orders'],
            'paiements' => ['/api/v1/admin/payments'],
            'stock' => ['/api/v1/admin/inventory'],
            'alertes' => ['/api/v1/admin/notifications'],
            'recherche' => ['/api/v1/admin/search?q=MS'],
        ];
    }

    /**
     * Les routes reservees a l'administrateur.
     *
     * Ces trois familles sont separees des precedentes parce que la question
     * n'y est plus la meme. « Entre-t-il dans le back-office » a une seule
     * reponse pour tous ses veritables concerns ; « a-t-il le droit de voir qui a
     * reinitialise quel mot de passe » n'a pas la meme reponse pour un
     * guichetier et pour un administrateur.
     *
     * Elles sont donc listees a part, ce qui evite le piege de la liste unique ou
     * le guichetier passe partout — ou alors le test ne verrait plus rien, parce
     * qu'un acces trop large et un acces trop etroit se compensent dans le
     * meme `assertOk`.
     *
     * @return array<string, array{string}>
     */
    public static function adminOnlyRoutes(): array
    {
        return [
            'journal d.audit' => ['/api/v1/admin/audit'],
            'journal de stock' => ['/api/v1/admin/inventory/adjustments'],
            'comptes' => ['/api/v1/admin/users'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('protectedRoutes')]
    public function test_it_refuses_an_anonymous_caller_on(string $url): void
    {
        /*
         * 401 et non 403 : il n'y a pas de session, donc rien a autorise. Un 403
         * ferait afficher « acces refuse » a un guichetier dont la session a
         * expire, qui recliquerait sans se reconnecter.
         */
        $this->getJson($url)
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('protectedRoutes')]
    public function test_it_refuses_a_customer_on(string $url): void
    {
        $this->actingAs(User::factory()->create())
            ->getJson($url)
            ->assertForbidden()
            ->assertJsonPath('error.code', 'BACKOFFICE_FORBIDDEN');
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('protectedRoutes')]
    public function test_it_admits_a_staff_on(string $url): void
    {
        $this->actingAs(User::factory()->staff()->create())->getJson($url)->assertOk();
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('adminOnlyRoutes')]
    public function test_it_refuses_an_anonymous_caller_on_the_admin_routes(string $url): void
    {
        $this->getJson($url)
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('adminOnlyRoutes')]
    public function test_it_refuses_a_customer_on_the_admin_routes(string $url): void
    {
        $this->actingAs(User::factory()->create())
            ->getJson($url)
            ->assertForbidden();
    }

    /**
     * Le cas que la matrice existe pour empecher.
     *
     * Un guichetier entre dans le back-office — c'est son travail — mais il ne
     * gere pas les comptes et ne lit pas le journal. Ce sont deux populations,
     * pas deux niveaux d'un meme escalier : au comptoir, on vend et on compte
     * les pieces ; on ne reconfigure pas qui a le droit d'entrer.
     *
     * Le test existe parce que l'echec de cet acces serait invisible de partout
     * ailleurs : le guichetier verrait le journal s'ouvrir sans erreur, et rien
     * dans la suite ne le rattraperait.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('adminOnlyRoutes')]
    public function test_it_refuses_a_staff_on_the_admin_routes(string $url): void
    {
        $this->actingAs(User::factory()->staff()->create())
            ->getJson($url)
            ->assertForbidden()
            ->assertJsonPath('error.code', 'BACKOFFICE_FORBIDDEN');
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('adminOnlyRoutes')]
    public function test_it_admits_an_admin_on_the_admin_routes(string $url): void
    {
        $this->actingAs(User::factory()->admin()->create())->getJson($url)->assertOk();
    }

    public function test_it_refuses_a_deactivated_account_even_as_admin(): void
    {
        /*
         * Le role seul laisserait passer : ce compte est bien administrateur.
         * C'est l'activite qui le bloque, et c'est exactement ce que la
         * desactivation d'un compte doit faire — couper l'acces immediatement,
         * sans avoir a desactiver cinquante comptes un par un.
         */
        $this->actingAs(User::factory()->admin()->inactive()->create())
            ->getJson('/api/v1/admin/dashboard')
            ->assertForbidden()
            ->assertJsonPath('error.message', 'Ce compte est désactivé.');
    }

    public function test_it_names_the_permission_matrix_and_what_it_hides(): void
    {
        /*
         * Un role qui peut tout voir ne distingue plus rien. La matrice est donc
         * verifiee sur ses deux moities : `staff` lit sans gerer les comptes,
         * `admin` fait les deux. Si une permission changeait de role, ce test
         * echouerait — et il le doit, parce que la consequence est un guichetier
         * qui peut reinitialiser le mot de passe de l'administrateur.
         */
        $staff = User::factory()->staff()->create();
        $admin = User::factory()->admin()->create();

        $this->assertTrue($staff->canManage('orders'), 'Un guichetier doit lire les commandes.');
        $this->assertTrue($staff->canManage('inventoryAdjust'), 'Un guichetier doit corriger le stock.');
        $this->assertFalse($staff->canManage('users'), 'Un guichetier ne doit pas gerer les comptes.');
        $this->assertFalse($staff->canManage('audit'), 'Un guichetier ne doit pas lire le journal.');

        $this->assertTrue($admin->canManage('users'));
        $this->assertTrue($admin->canManage('audit'));
        $this->assertTrue($admin->canManage('catalog'));

        $this->assertFalse($staff->isBackoffice() === false, 'Un guichetier actif entre dans le back-office.');
        $this->assertFalse(User::factory()->create()->isBackoffice(), 'Un client n\'y entre pas.');
    }
}