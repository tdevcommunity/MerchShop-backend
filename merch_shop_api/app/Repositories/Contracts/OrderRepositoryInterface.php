<?php

namespace App\Repositories\Contracts;

use App\Enums\OrderStatus;
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
