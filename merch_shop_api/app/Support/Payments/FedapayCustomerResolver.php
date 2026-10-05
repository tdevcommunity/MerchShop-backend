<?php

namespace App\Support\Payments;

use App\Exceptions\ApiException;
use App\Models\Order;
use FedaPay\Customer;
use FedaPay\Error\Base;
use Illuminate\Support\Facades\Log;

/**
 * La fiche de l'acheteur chez FedaPay, creee une fois par commande.
 *
 * FedaPay attend une fiche client, pas une simple chaine, sur ses transactions
 * comme sur ses depots : c'est elle qui porte le nom et le numero, et c'est
 * elle que le guichet retrouve ensuite lorsqu'un acheteur dit « c'est moi ».
 * La creer a la commande aurait mis un appel distant dans une transaction base
 * de donnees et fait dependre la reservation de stock de la disponibilite de
 * l'operateur ; elle est donc creee au moment du paiement, qui est deja hors
 * transaction.
 *
 * Le nom est transmis en `firstname`/`lastname` comme FedaPay les nomme. Le
 * nom complet de l'acheteur n'a pas besoin d'etre coupe en deux : ces deux
 * champs sont libres chez lui, et le separer sur un guichetaisien a trois
 * noms − ou sur un nom a particule − mutilerait une donnee qui n'a pas a l'etre. Le premier mot occupe
 * `firstname` et le reste `lastname`.
 */
final class FedapayCustomerResolver
{
    public function __construct(
        private readonly FedapayClient $client,
    ) {}

    /**
     * L'identifiant de la fiche cliente de cette commande, en la creant si
     * besoin.
     *
     * Une commande qui possede deja son identifiant ne declenche aucun appel :
     * c'est ce qui evite qu'un second essai de paiement de la meme commande
     * multiplie les fiches chez l'operateur, et donc que l'acheteur y apparaisse
     * plusieurs fois.
     *
     * @throws ApiException si l'operateur refuse la creation.
     */
    public function resolve(Order $order): int
    {
        if ($order->fedapay_customer_id !== null) {
            return (int) $order->fedapay_customer_id;
        }

        $this->client->configure();

        [$firstname, $lastname] = $this->splitName($order->customer_name);

        Log::info('Creation de la fiche client FedaPay.', [
            'order_number' => $order->order_number,
            'phone_country' => $order->customer_phone_country,
        ]);

        try {
            $customer = Customer::create([
                'firstname' => $firstname,
                'lastname' => $lastname,
                'phone_number' => [
                    'number' => $order->customer_phone_number,
                    'country' => $order->customer_phone_country,
                ],
            ]);
        } catch (Base $error) {
            /*
             * L'echec est signale, pas masque. Ne pas pouvoir rattacher l'acheteur
             * a une fiche empeche le suivi de ses paiements, mais ne doit pas
             * faire echouer la commande pour autant : elle est enregistree, et
             * le guichet pourra la traiter manuellement. C'est pourquoi
             * l'exception est lancee sans interrompre le checkout — l'appelant
             * decide, et le journal retient l'incident.
             */
            Log::error('Creation de la fiche client FedaPay refusee.', [
                'order_number' => $order->order_number,
                'reason' => $error->getMessage(),
            ]);

            throw new ApiException(
                'Le prestataire de paiement n’a pas pu enregistrer l’acheteur.',
                502,
                'PAYMENT_CUSTOMER_REJECTED',
                ['order' => $order->uuid],
            );
        }

        $id = $customer->id ?? null;

        if (! is_int($id) && ! is_string($id)) {
            throw new ApiException(
                'Le prestataire de paiement n’a pas pu enregistrer l’acheteur.',
                502,
                'PAYMENT_CUSTOMER_REJECTED',
                ['order' => $order->uuid],
            );
        }

        $order->update(['fedapay_customer_id' => (int) $id]);

        return (int) $id;
    }

    /**
     * Repartit un nom complet sur les deux champs de FedaPay.
     *
     * Le premier mot occupe `firstname` et le reste `lastname`, ce qui laisse
     * « Jean-Baptiste Kouassi N’Guessan » se lire entierement plutot que de
     * perdre deux mots dans un trou.
     *
     * @return array{0: string, 1: string}
     */
    private function splitName(?string $name): array
    {
        $name = trim((string) $name);

        if ($name === '') {
            return ['', ''];
        }

        $parts = preg_split('/\s+/u', $name) ?: [$name];
        $firstname = array_shift($parts);

        return [$firstname, implode(' ', $parts)];
    }
}
