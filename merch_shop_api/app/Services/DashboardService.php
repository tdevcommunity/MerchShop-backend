<?php

namespace App\Services;

use App\Enums\CatalogStatus;
use App\Enums\FulfillmentMethod;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Models\Variant;

/**
 * Ce que le tableau de bord du festival affiche.
 *
 * Toutes les valeurs de cette classe sont des agregats lus en base, jamais des
 * compteurs maintenus dans une colonne ni des nombres reconstitues en
 * additionnant des collections. La raison n'est pas la simplicite : c'est qu'un
 * compteur stocke se desynchronise de ce qu'il compte des la premiere ecriture
 * oubliee, et qu'un ecart entre « 47 commandes » dans le tableau de bord et
 * « 46 » dans la liste est le genre de constat qui fait perdre confiance a tout
 * l'ecran.
 *
 * Une consequence identique vaut pour les totaux : le revenu additionne est la
 * somme des lignes payees, pas la difference entre deux instantanes d'un
 * compteur. Une commande annulee apres paiement disparait des deux cotes, ce qui
 * est faux — elle a ete encaissee puis rendue, et le chiffre de la journee doit
 * le dire.
 *
 * Chaque agregat est donc pose sur une definition ecrite dans le nom de sa
 * methode. Une methode dont le nom ne dit pas ce qu'elle compte est un chiffre
 * qui divergera de son usage.
 */
final class DashboardService
{
    /**
     * Les compteurs d'encaissement, en un seul passage.
     *
     * Regroupes parce qu'ils se lisent ensemble et se comparent entre eux : c'est
     * une seule question — « combien ont ete payees, et combien restent a payer »
     * — et six requetes pour y repondre donneraient des chiffres captures a des
     * instants differents, donc potentiellement incomparables sur une journee ou
     * les commandes tombent vite.
     *
     * Un seul `group by` suffit, parce que ces quatre nombres sont quatre
     * partitions de la meme population : toutes les commandes. Un `whereIn`
     * sur les etats interessants et un regroupement par etat donnent les quatre
     * lignes, sans qu'aucune ne puisse compter deux fois.
     *
     * @return array{pendingPayment: int, paid: int, pickedUp: int, cancelled: int}
     */
    public function orderCounters(): array
    {
        $counts = Order::query()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return [
            'pendingPayment' => (int) ($counts[OrderStatus::PENDING_PAYMENT->value] ?? 0),
            'paid' => (int) ($counts[OrderStatus::PAID->value] ?? 0),
            'pickedUp' => (int) ($counts[OrderStatus::PICKED_UP->value] ?? 0),
            'cancelled' => (int) ($counts[OrderStatus::CANCELLED->value] ?? 0),
        ];
    }

    /**
     * L'argent reellement encaisse.
     *
     * Somme des paiements confirms, et non des commandes payees : une commande
     * payee en deux fois compte pour deux paiements, et c'est la somme des
     * paiements qui est le chiffre que le festival encaisse — celle des commandes
     * compterait le solde une fois de plus.
     *
     * Les rembourses sont deduits. Un paiement `REFUNDED` est un paiement dont
     * l'argent est sorti : le garder dans le revenu afficherait une recette qui
     * n'a pas ete gagnee, ce qui est la seule erreur de chiffre qu'un festival ne
     * rattrape pas.
     *
     * Un paiement `PENDING` n'entre pas non plus, meme si la commande est deja
     * « payee » : l'ordre a change, donc l'argent est confirme. C'est pourquoi la
     * somme porte sur `payments.status` et non sur `orders.status`.
     */
    public function revenue(): int
    {
        return (int) Payment::query()
            ->where('status', PaymentStatus::SUCCESS->value)
            ->sum('amount')
            - (int) Payment::query()
                ->where('status', PaymentStatus::REFUNDED->value)
                ->sum('amount');
    }

    /**
     * Le panier moyen des commandes encaissees.
     *
     * Divise par le nombre de paiements confirmes, et non par le nombre de
     * commandes payees : les deux denominateurs sont differents des qu'un
     * solde a ete paye, et c'est le nombre d'encaissements qui decrit ce que le
     * guichet a traite.
     *
     * Divise par le nombre de commandes payees plutot que par le nombre de
     * paiements quand aucun paiement n'existe : la moyenne d'une population vide
     * est nulle et non une erreur, parce qu'un festival qui n'a pas encore vendu
     * n'a pas de panier moyen, il en a zéro.
     */
    public function averageBasket(): int
    {
        $confirmed = (int) Payment::query()->where('status', PaymentStatus::SUCCESS->value)->count();

        if ($confirmed === 0) {
            return 0;
        }

        return (int) round($this->revenue() / $confirmed);
    }

    /**
     * Les articles qui demandent une decision maintenant.
     *
     * Trois groupes, et ils ne se recouvrent pas parce que leur suite est
     * differente : une rupture demande une reception, un stock bas demande un
     * arbitrage, une desactivation demande un nettoyage. Les regrouper en un
     * « nombre de problemes » les rendrait indistincts, alors que c'est
     * precisement la distinction qui dit au guichetier par quoi commencer.
     *
     * Le seuil de stock est compare colonne a colonne, et non a une constante
     * recopiee : chaque declinaison a son seuil, pose au stand. Une comparaison
     * faite ici avec un « 5 » en dur produirait un tableau de bord qui ne
     * correspond pas au tableau de stock a cote.
     *
     * Les declinaisons desactivees sont comptees a part et non dans les ruptures :
     * une declinaison masquee n'est pas une rupture, elle est sortie du catalogue,
     * et la reunion des deux ferait croire a un manque d'article.
     *
     * @return array{outOfStock: int, lowStock: int, disabled: int}
     */
    public function inventoryCounters(): array
    {
        return [
            'outOfStock' => Variant::query()
                ->where('status', CatalogStatus::ACTIVE->value)
                ->where('stock', '<=', 0)
                ->count(),

            /*
             * `stock > 0` est indispensable : sans lui, une declinaison en rupture
             * serait aussi « stock bas », puisque zero est inferieur au seuil. Le
             * groupe « bas » ne doit contenir que ce qui peut encore se vendre —
             * les deux compteurs se lisent l'un apres l'autre.
             */
            'lowStock' => Variant::query()
                ->where('status', CatalogStatus::ACTIVE->value)
                ->where('stock', '>', 0)
                ->whereColumn('stock', '<=', 'low_stock_threshold')
                ->count(),

            'disabled' => Variant::query()
                ->where('status', CatalogStatus::INACTIVE->value)
                ->count(),
        ];
    }

    /**
     * La file de retrait.
     *
     * Deux groupes, pour deux decisions : ce qui est paye et pret a partir, et ce
     * qui est paye mais pas encore en rayon. Le second est celui qui demande une
     * action au stand, et le dire par un chiffre separe evite qu'un guichetier
     * cherche un article qui n'a pas encore ete mis en rayon.
     *
     * Les commandes livrees sont exclus : elles n'ont pas de retrait, donc les
     * compter les ferait grossir la file d'un travail qui n'existe pas.
     */
    public function pickupCounters(): array
    {
        return [
            'ready' => (int) Order::query()
                ->where('fulfillment_method', FulfillmentMethod::PICKUP->value)
                ->where('status', OrderStatus::READY_FOR_PICKUP->value)
                ->count(),

            'awaitingPreparation' => (int) Order::query()
                ->where('fulfillment_method', FulfillmentMethod::PICKUP->value)
                ->where('status', OrderStatus::PAID->value)
                ->count(),
        ];
    }

    /**
     * Les encaissements qui n'ont pas abouti.
     *
     * Compte sur la periode demandee et non sur toute la vie du festival : le
     * nombre de tentatives ayant echoue est un indicateur de debit, pas un
     * constat. Un festival de trois jours ne se juge pas sur son cumulu depuis
     * le premier jour, et le compteur cumulu ferait du bruit une fois le debit
     * retombe.
     *
     * La periode est un parametre et non une constante, parce que la question
     * posee change — « aujourd'hui » sur l'accueil, « depuis une heure » sur
     * l'ecran d'incident — et qu'une seule de ces deux questions seraitposable
     * sans que ce soit la bonne.
     */
    public function failedPaymentsSince(\DateTimeInterface $since): int
    {
        return (int) Payment::query()
            ->where('status', PaymentStatus::FAILED->value)
            ->where('created_at', '>=', $since)
            ->count();
    }

    /**
     * Les comptes de guichet, par role.
     *
     * Les comptes desactives sont comptes avec les actifs : la question « avons
     * nous assez de monde au stand ? » se pose sur les gens presents, et la
     * presence se verifie par le statut. Le nombre d'actifs vient donc premier,
     * et le total avec, pour que l'ecart se voie.
     *
     * @return array{active: int, inactive: int, byRole: array<string, int>}
     */
    public function staffCounters(): array
    {
        $rows = User::query()
            ->whereIn('role', [UserRole::ADMIN->value, UserRole::STAFF->value])
            ->selectRaw('role, status, count(*) as total')
            ->groupBy('role', 'status')
            ->get();

        $byRole = [];
        $active = 0;
        $inactive = 0;

        /*
         * Le role et le statut sont lus par `->value` quand ils sont des enums.
         *
         * Les deux sont declares comme des enum sur le modele, donc `selectRaw`
         * les renvoie deja castes — et un enum n'est pas une cle de tableau
         * acceptable. Lire `$row->role` comme cle leverait « cannot access
         * offset of type UserRole on array », l'erreur la moins parlante de la
         * liste pour un simple comptage.
         */
        foreach ($rows as $row) {
            $role = $row->role instanceof UserRole ? $row->role->value : $row->role;

            $byRole[$role] = ($byRole[$role] ?? 0) + (int) $row->total;

            $status = $row->status instanceof UserStatus ? $row->status->value : $row->status;

            if ($status === UserStatus::ACTIVE->value) {
                $active += (int) $row->total;
            } else {
                $inactive += (int) $row->total;
            }
        }

        return ['active' => $active, 'inactive' => $inactive, 'byRole' => $byRole];
    }

    /**
     * Les dernieres commandes, pour la liste d'accueil.
     *
     * La liste la plus recente, sans pagination : c'est une vue d'ensemble, et une
     * vue d'ensemble qui affiche « page 1 sur 12 » invite a cliquer. Le nombre de
     * commandes reelles est ailleurs, dans `orderCounters()`.
     *
     * @return array<int, Order>
     */
    public function recentOrders(int $limit = 8): array
    {
        return Order::query()
            ->with(['items.variant.product', 'user'])
            ->latest('created_at')
            ->latest('id')
            ->limit($limit)
            ->get()
            ->all();
    }

    /**
     * Le tableau de bord complet, en une reponse.
     *
     * Regroupe parce que les compteurs se comparent entre eux : « 12 en attente »
     * n'a pas de sens sans « 5 encaissees », et les faire charger par deux
     * appels laisserait entre les deux un encaissement que personne ne verrait
     * dans aucun des deux.
     *
     * La periode des echecs de paiement est demandee par le client et bornee
     * par la validation de la route. Elle est appliquee telle quelle, sans
     * defaut : une valeur absente signifie « depuis le debut », ce qui est une
     * reponse honnete pour un festival d'une journee, alors qu'une valeur par
     * defaut inventee une fenetre que le client n'a pas demandee.
     *
     * @return array<string, mixed>
     */
    public function summary(?\DateTimeInterface $failuresSince = null): array
    {
        $orders = $this->orderCounters();

        return [
            'orders' => $orders,
            'revenue' => $this->revenue(),
            'averageBasket' => $this->averageBasket(),
            'inventory' => $this->inventoryCounters(),
            'pickup' => $this->pickupCounters(),
            'staff' => $this->staffCounters(),
            'failedPayments' => $this->failedPaymentsSince($failuresSince ?? now()->startOfDay()),
        ];
    }
}