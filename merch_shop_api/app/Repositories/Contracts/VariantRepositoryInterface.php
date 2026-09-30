<?php

namespace App\Repositories\Contracts;

use App\Models\Variant;
use Illuminate\Database\Eloquent\Collection;

/**
 * Acces aux variantes de produit.
 *
 * @extends RepositoryInterface<Variant>
 */
interface VariantRepositoryInterface extends RepositoryInterface
{
    /**
     * Toutes les variantes d'un produit, dans un ordre stable.
     *
     * Contexte de gestion : aucune restriction de statut. La synchronisation
     * d'un produit doit pouvoir voir ses variantes masquees pour les remettre a
     * jour ou les supprimer ; les filtrer ici les rendrait introuvables. Pour la
     * lecture publique, utiliser `forPublicCatalog`.
     *
     * L'ordre porte sur l'identifiant interne et non sur le prix ou le nom :
     * l'ordre d'affichage d'un lot de declined est une decision de
     * presentation, pas une regle d'integrite, et il doit etre identique a
     * l'arrivee pour que la synchronisation soit deterministe.
     *
     * @return Collection<int, Variant>
     */
    public function forProduct(int $productId): Collection;

    /**
     * Variantes d'un produit telles qu'elles doivent etre vues par un visiteur.
     *
     * Seules les variantes actives sont rendues. Sans ce filtre, la colonne
     * `status` d'une variante n'aurait aucun effet : une declinaison masquee
     * par le back-office resterait listee publiquement, avec son stock et son
     * prix. Le statut d'une variante n'est donc utile que si la lecture
     * publique en tient compte.
     *
     * @return Collection<int, Variant>
     */
    public function forPublicCatalog(int $productId): Collection;

    /**
     * Variante d'un produit identifiee par son uuid.
     *
     * Le controle du produit parent est volontaire : une variante ne doit
     * jamais etre modifiee ou supprimee via le produit auquel elle n'appartient
     * pas, meme si son uuid est connu (guessable, ou fuite d'un ancien payload).
     */
    public function findForProduct(int $productId, string $uuid): ?Variant;

    /**
     * Variante portant un SKU donne, quel que soit son produit.
     *
     * Le SKU est la reference vente au guichet et il est unique en base : cette
     * methode sert de garde-fou avant toute creation pour renvoyer une erreur
     * metier comprehensible au lieu d'une violation de contrainte unique.
     */
    public function findBySku(string $sku): ?Variant;

    /**
     * Variante supprimee logiquement d'un produit, portee par un SKU donne.
     *
     * RechercheVolontairement dans les lignes supprimees : l'index unique porte
     * sur la colonne sku, pas sur un couple (sku, deleted_at). Une variante
     * retiree du catalogue occupe donc toujours sa reference, et la recherche
     * d'un moyen de la restaurer evite une erreur 500 a la re-creation.
     */
    public function findTrashedForProductBySku(int $productId, string $sku): ?Variant;

    /**
     * Supprime logiquement toutes les variantes d'un produit.
     *
     * Utilise a la suppression d'un produit, pour ne pas laisser des variantes
     * orphelines correspondant a un produit qui n'existe plus.
     *
     * @return int Nombre de variantes supprimees
     */
    public function deleteAllForProduct(int $productId): int;

    /**
     * Verrouille des variantes pour la vente et les rend avec leur stock fige.
     *
     * Cette lecture n'a de sens qu'a l'interieur d'une transaction deja
     * ouverte, et c'est la seule qui autorise la vente : `find` et
     * `forPublicCatalog` lisent un stock qui peut changer dans la milliseconde
     * suivante, donc ne peuvent pas fonder une decision de decrement.
     *
     * Le verrou est pose par identifiant croissant, dans un seul `SELECT`. Cet
     * ordre est ce qui evite les interblocages : deux commandes portant les
     * memes articles les verrouillent dans la meme sequence, alors qu'un ordre
     * dependant de l'ordre d'arrivee du client les verrouillerait dans des
     * sens opposes et se bloqueraient mutuellement.
     *
     * Les variantes sont rendues avec `product` charge : la ligne de commande
     * fige le nom du produit vendu, et le catalogue n'est pas relu a cet
     * endroit.
     *
     * L'identification se fait par `uuid`, le seul identifiant que le
     * catalogue expose. L'identifiant interne n'est jamais accepte du client :
     * il n'a aucune raison d'etre devinable, et un tunnel qui le manipulerait
     * pourrait commander une declinaison retiree du catalogue.
     *
     * @param  array<int, string>  $uuids  Identifiants publics des variantes
     * @return Collection<int, Variant>
     */
    public function lockForSale(array $uuids): Collection;

    /**
     * Rend le stock d'une variante, apres annulation ou remboursement.
     *
     * L'ordre des appels est inverse de celui de la vente pour que deux
     * mouvements ne se croisent pas.
     */
    public function restock(Variant $variant, int $quantity): void;
}
