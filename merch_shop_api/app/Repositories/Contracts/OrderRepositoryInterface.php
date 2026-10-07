<?php

namespace App\Repositories\Contracts;

use App\Enums\FulfillmentMethod;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Acces aux commandes.
 *
 * @extends RepositoryInterface<Order>
 */
interface OrderRepositoryInterface extends RepositoryInterface
{
    /**
     * Une commande et tout ce qu'elle expose dans l'API, en une requete.
     *
     * La ressource renvoie le client, les lignes, les paiements et la facture :
     * sans ce chargement, chaque ligne d'une liste emettrait quatre requetes
     * supplementaires, et la page se retrouverait chargee en N+1.
     *
     * @param  array<string, string>  $with  Relations a charger, au choix du service
     */
    public function findWithRelations(int|string $id, array $with = []): ?Order;

    /**
     * Commandes d'un client, de la plus recente a la plus ancienne.
     *
     * La liste est volontairement triee par date de creation et non par numero :
     * le numero porte la date de vente du jour ou la commande a ete passee,
     * alors que la colonne `created_at` est la seule qui exprime l'ordre reel
     * d'un achat a l'autre.
     *
     * @param  array{status?: OrderStatus|null}  $filters
     * @return LengthAwarePaginator<int, Order>
     */
    public function paginateForUser(User $user, int $perPage, ?OrderStatus $status = null): LengthAwarePaginator;

    /**
     * Toutes les commandes retraits, pour la file du stand.
     *
     * Sert au guichet : la file de retrait se lit par ordre d'arrivee, donc
     * par date de creation croissante. Le tri inverse de `paginateForUser` est
     * volontaire, il correspond a deux usages opposes.
     *
     * @return LengthAwarePaginator<int, Order>
     */
    public function paginateForPickup(int $perPage): LengthAwarePaginator;

    /**
     * Toutes les commandes, tous clients confondus.
     *
     * Reservee au back-office, et c'est la seule requete de cette interface qui
     * ne filtre pas sur un proprietaire. Les trois autres ont chacune leur
     * justification — un client, une file de stand — et celle-ci en a une aussi :
     * le guichet doit repondre a « ou est la commande MS-0402 ? » alors qu'elle
     * a ete passee sans compte, donc sans proprietaire a filtrer.
     *
     * Les filtres sont volontairement transmis tels quels plutot que traduits
     * ici : le service a deja resolu les valeurs en enum, et c'est a lui que
     * revient la lecture du vocabulaire demande. Un filtre inconnu ne doit pas
     * etreignore en silence, et il ne peut pas l'etre ici.
     *
     * `q` ne porte que sur des colonnes de la commande — numero, nom et numero
     * de l'acheteur. Il ne cherche pas dans les lignes du panier : une commande
     * ne se retrouve pas par « le tee-shirt » au guichet, on la retrouve par son
     * numero ou par le nom de la personne qui l'a passee. Ajouter la jointure
     * qui rendrait cette recherche possible coûterait un `distinct` sur la liste
     * complete, pour une recherche que personne ne fait au stand.
     *
     * @param  array{status?: OrderStatus|null, paymentStatus?: PaymentStatus|null, fulfillment?: FulfillmentMethod|null, q?: string|null}  $filters
     * @return LengthAwarePaginator<int, Order>
     */
    public function paginateForBackoffice(int $perPage, array $filters = []): LengthAwarePaginator;

    /**
     * Commande designee par l'empreinte de son jeton de retrait.
     *
     * Recherche volontairement restreinte aux commandes de retrait : un jeton
     * n'a de sens que pour une commande servie au stand. La colonne est unique,
     * donc la requete renvoie au plus une commande et le service peut compter
     * dessus pour distinguer « jeton inconnu » de « commande deja servie ».
     */
    public function findByPickupTokenHash(string $hash): ?Order;

    /**
     * Le numero de commande est-il deja pris ?
     *
     * Interroge y compris les lignes supprimees : le numero est la reference
     * lue au guichet et recopiee sur les pieces comptables, il ne peut donc pas
     * etre reutilise, meme par une commande annulee.
     */
    public function orderNumberExists(string $orderNumber): bool;
}
