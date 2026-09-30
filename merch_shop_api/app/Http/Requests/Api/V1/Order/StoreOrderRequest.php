<?php

namespace App\Http\Requests\Api\V1\Order;

use App\Enums\FulfillmentMethod;
use App\Enums\PaymentMethod;
use App\Http\Requests\ApiRequest;
use Illuminate\Validation\Rule;

/**
 * Payload de creation d'une commande.
 *
 * Le payload ne contient volontairement aucun prix. Le client envoie des
 * identifiants de variante et des quantites, et le serveur calcule les
 * montants : accepter un prix ici rendrait chaque front capable d_ecrire le
 * chiffre a encaisser.
 *
 * La commande est publique — un festival vend a des visiteurs qui n'ont pas de
 * compte — donc `authorize()` ne verifie rien. La session est lue ensuite par le
 * controleur, qui rattache la commande au compte lorsqu'il y en a un, et laisse
 * `user_id` nul sinon.
 */
final class StoreOrderRequest extends ApiRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1', 'max:20'],

            /*
             * `distinct` refuse deux lignes du meme article. Sans cette regle, le
             * service les fusionnerait, ce qui marche mais masque une saisie
             * erronee : deux lignes « 1 t-shirt, taille M » et « 2 t-shirts,
             * taille M » dans la meme commande sont presque toujours une faute
             * de l'utilisateur, et il vaut mieux le lui dire.
             */
            'items.*.uuid' => ['required', 'string', 'uuid', 'distinct'],

            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:20'],

            'fulfillment_method' => ['required', Rule::enum(FulfillmentMethod::class)],

            /*
             * L'adresse n'est exigee que pour une livraison. Une adresse
             * facultative en retrait accepterait un retrait sans adresse, ce
             * qui est correct, et une adresse obligatoire en retrait
             * bloquerait un retrait par erreur de saisie.
             */
            'shipping_address' => [
                'nullable',
                'required_if:fulfillment_method,'.FulfillmentMethod::DELIVERY->value,
                'string',
                'min:5',
                'max:500',
            ],

            'payment_method' => ['required', Rule::enum(PaymentMethod::class)],

            /*
             * Reference du participant servie au stand. Sert au controle du
             * nombre d'articles par personne, pas a l'identification de la
             * commande : elle n'accorde aucun droit a elle seule.
             */
            'participant_id' => ['nullable', 'string', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'items.required' => 'La commande doit contenir au moins un article.',
            'items.min' => 'La commande doit contenir au moins un article.',
            'items.max' => 'Une commande ne peut pas contenir plus de 20 lignes.',
            'items.*.uuid.required' => 'Chaque ligne doit designer une variante.',
            'items.*.uuid.distinct' => 'Une variante ne peut apparaitre qu’une seule fois dans la commande.',
            'items.*.quantity.required' => 'Chaque ligne doit indiquer une quantite.',
            'items.*.quantity.min' => 'La quantite doit etre d’au moins 1.',
            'items.*.quantity.max' => 'La quantite maximale par article est de 20.',
            'fulfillment_method.required' => 'Le mode de retrait est obligatoire.',
            'shipping_address.required_if' => 'Une adresse de livraison est obligatoire pour une livraison.',
            'shipping_address.min' => 'L’adresse de livraison est trop courte.',
            'payment_method.required' => 'Le moyen de paiement est obligatoire.',
            'participant_id.max' => 'La reference participant est trop longue.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'items' => 'articles',
            'items.*.uuid' => 'variante',
            'items.*.quantity' => 'quantite',
            'fulfillment_method' => 'mode de retrait',
            'shipping_address' => 'adresse de livraison',
            'payment_method' => 'moyen de paiement',
            'participant_id' => 'reference participant',
        ];
    }
}
