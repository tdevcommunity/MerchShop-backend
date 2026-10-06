<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Http\Resources\OrderResource;
use App\Http\Resources\PaymentResource;
use App\Http\Resources\ProductResource;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * La recherche unique du back-office.
 *
 * Une seule requete pour trois populations, et c'est la seule forme qui serve
 * l'usage reel. Un guichet qui cherche « MS-0417 » ne sait pas a l'avance si ce
 * qu'il cherche est une commande, un paiement ou un produit : il tape, et il veut
 * voir ce qui existe sous ce mot dans les trois tables. Trois champs de recherche
 * distincts, l'un par population, obligeraient a deviner dans quel champ
 * taper — c'est-a-dire a essayer, trois fois, en ratant deux fois sur trois.
 *
 * Les trois groupes sont rendus dans un seul objet plutot qu'en trois listes
 * paginees, parce qu'une recherche n'a pas de pages : elle a un resultat ou elle
 * n'en a pas. Une recherche paginee obligerait a cliquer « page suivante » pour
 * verifier qu'il n'y a rien d'autre, et le compteur « 1 sur 4 » n'a pas de sens
 * quand on cherche un client.
 *
 * La recherche porte sur des colonnes differentes selon la table, et c'est
 * volontaire : `MS-0417` est un numero de commande, `SKU-TSH-M` est une
 * reference d'article, et une reference FedaPay est une chaine alphanumerique
 * que personne ne garantit d'avoir recopiee au bon endroit. Chercher le meme
 * fragment partout produirait des resultats plausibles et faux — un produit dont
 * le nom contient « MS-0417 » n'existe pas, mais une description le pourrait.
 *
 * Chaque groupe est donc borne a un nombre petit de resultats. Le nombre n'est
 * pas une pagination cachee : au-dela, la liste deroule hors de l'ecran sans
 * que le guichet sache qu'il y en a plus, ce qui est pire qu'un resultat
 * incomplet visible.
 */
final class AdminSearchController extends ApiController
{
    /**
     * Nombre de resultats rendus par population.
     *
     * Assez pour couvrir un ecran de guichet — un nom de produit se repere
     * dans la liste — et assez peu pour que le retour soit immediat. La
     * recherche est un raccourci vers une fiche, pas un inventaire : quand le
     * guichet cherche « tee », il cherche un article precis, il ne recense pas
     * les textiles du festival.
     */
    private const PER_GROUP = 5;

    /**
     * Longueur minimale d'une recherche.
     *
     * Deux caracteres et non un : avec un seul, « a » rapporte la moitie du
     * catalogue et n'aide a rien. En dessous de deux, il n'y a pas de
     * recherche — la reponse est un objet vide plutot qu'une erreur, parce que
     * l'utilisateur est en train de taper et ne demande rien encore.
     */
    private const MIN_TERM_LENGTH = 2;

    /**
     * La recherche.
     *
     * Le terme est envoye tel quel a chaque table, avec les jokers `%` poses par
     * Eloquent. Il ne contient donc aucune donnee de l'utilisateur qu'il ne
     * contenant lui-meme, et la comparaison se fait sur les deux cotes en
     * minuscules — `ilike` sur PostgreSQL, mais cette base-la n'a pas d'egal
     * portable dans SQLite, qui est la base des tests
     * couvre la recopie approximative d'une reference operateur.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $term = trim((string) $request->query('q', ''));

        if (mb_strlen($term) < self::MIN_TERM_LENGTH) {
            return response()->json([
                'data' => ['products' => [], 'orders' => [], 'payments' => []],
            ]);
        }

        return response()->json([
            'data' => [
                'products' => ProductResource::collection($this->products($term))->resolve(),
                'orders' => OrderResource::collection($this->orders($term))->resolve(),
                'payments' => PaymentResource::collection($this->payments($term))->resolve(),
            ],
        ]);
    }

    /**
     * Les produits dont le nom, le SKU ou la description contient le terme.
     *
     * La description est comprise parce qu'un guichet cherche souvent par ce que
     * l'article est plutot que par ce qu'il s'appelle : « hoodie », « casquette
     * », « tote bag ». La categorie ne l'est pas, parce qu'elle est un mot unique
     * qui ne distingue rien — chercher par elle est chercher par le rayon, ce
     * qu'un filtre de catalogue fait deja et mieux.
     *
     * Le statut n'est pas filtre : un guichet qui cherche une reference doit
     * pouvoir tomber sur un article desactive, parce que c'est precisement
     * l'article qu'on cherche quand on veut le reactiver ou constater pourquoi
     * il a disparu du catalogue. Le statut est porte par la ressource.
     */
    private function products(string $term): array
    {
        return Product::query()
            ->with(['category', 'variants'])
            ->where(fn (Builder $query): Builder => $query
                ->whereRaw('lower(name) like ?', ['%'.mb_strtolower($term).'%'])
                ->orWhereRaw('lower(description) like ?', ['%'.mb_strtolower($term).'%'])
                ->orWhereHas(
                    'variants',
                    fn (Builder $variants): Builder => $variants->whereRaw('lower(sku) like ?', ['%'.mb_strtolower($term).'%']),
                ))
            ->latest('id')
            ->limit(self::PER_GROUP)
            ->get()
            ->all();
    }

    /**
     * Les commandes dont le numero, le nom ou le numero de telephone contient le
     * terme.
     *
     * Les trois colonnes parce que les trois sont ce qu'un client tient en main :
     * il a le numero de commande sur son mail, il donne son nom au guichet, et il
     * dit parfois son numero parce qu'il ne l'a plus. Le telephone est recherche
     * par fragment de chiffres, ce qui suppose que le guichet tape le numero
     * sans separateur — ce que fait un appelant, en dictant.
     *
     * La commande est chargee avec ses lignes et son client pour que la ligne du
     * resultat soit cliquable vers une fiche affichee, et non un identifiant nu.
     */
    private function orders(string $term): array
    {
        return Order::query()
            ->with(['items.variant.product', 'user', 'payments', 'invoice', 'pickupAgent'])
            ->where(fn (Builder $query): Builder => $query
                ->whereRaw('lower(order_number) like ?', ['%'.mb_strtolower($term).'%'])
                ->orWhereRaw('lower(customer_name) like ?', ['%'.mb_strtolower($term).'%'])

                /*
                 * Seuls les chiffres du terme sont compares au telephone.
                 *
                 * Un terme alphabetique ne peut pas designer un numero, donc le
                 * tenter ne selectionnerait rien et — pire — pourrait faire
                 * apparaitre une commande par coincidence, pour un nom qui contient
                 * le meme fragment. Le test sur `ctype_digit` evite cela.
                 */
                ->when(
                    ctype_digit(preg_replace('/\D/', '', $term) ?? ''),
                    fn (Builder $phones): Builder => $phones->orWhere(
                        'customer_phone_number',
                        'like',
                        '%'.preg_replace('/\D/', '', $term).'%',
                    ),
                ))
            ->latest('id')
            ->limit(self::PER_GROUP)
            ->get()
            ->all();
    }

    /**
     * Les paiements dont la reference operateur contient le terme.
     *
     * Un seul critere, et c'est le seul qui identifie un paiement de l'exterieur :
     * la reference FedaPay lue sur le recu du client. Le numero de commande
     * n'y est pas parce qu'un client qui a son numero cherche une commande, pas un
     * paiement — et il cherchera dans le groupe des commandes, ou la ligne
     * affiche le statut de paiement de toute facon.
     */
    private function payments(string $term): array
    {
        return Payment::query()
            ->with('order')
            ->whereRaw('lower(transaction_id) like ?', ['%'.mb_strtolower($term).'%'])
            ->latest('id')
            ->limit(self::PER_GROUP)
            ->get()
            ->all();
    }
}