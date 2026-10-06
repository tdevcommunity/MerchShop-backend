<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\PaymentStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Api\V1\Admin\Concerns\GuardsBackofficeAction;
use App\Http\Resources\PaymentResource;
use App\Models\Payment;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Tentatives de paiement, vues par le back-office.
 *
 * Cette liste n'est pas un doublon de celle des commandes : elle se lit par
 * transactions, pas par ventes. Un guichetier qui cherche pourquoi une commande
 * reste « en attente de paiement » regarde les tentatives — combien, laquelle a
 * echoue, avec quel motif — alors que la liste des commandes ne montre que le
 * resultat, c'est-a-dire « pas encore payee », quatre fois de suite.
 *
 * Elle est aussi la seule vue qui expose la reference operateur sans passer par
 * une commande, ce qui est ce qu'on cherche lors d'un rapprochement : la
 * reference FedaPay lue sur le recu du client, retrouvee dans une transaction.
 *
 * Les montants et les motifs d'echec sont lisibles, mais rien ici ne permet de
 * les modifier : un back-office qui declarait un encaissement partirait de la
 * declaration d'un tiers, ce que le service de paiement refuse par construction.
 * Cette route est donc une lecture, sans `POST` ni `PATCH`.
 */
final class AdminPaymentController extends ApiController
{
    use GuardsBackofficeAction;

    /**
     * Les tentatives, de la plus recente a la plus ancienne.
     *
     * L'ordre est celui du rapprochement, pas celui d'un historique de commande :
     * on cherche le dernier essai d'une commande qui vient d'echouer, et l'ordre
     * inverse obligerait a parcourir toute la liste pour en atteindre la fin.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->assertCanManage($request, 'payments', 'consulter les paiements');

        $validated = $request->validate([
            'status' => ['nullable', 'integer', 'in:'.implode(',', array_column(PaymentStatus::cases(), 'value'))],
            'method' => ['nullable', 'string', 'max:32'],
            'provider' => ['nullable', 'string', 'max:32'],
            'q' => ['nullable', 'string', 'max:120'],
        ], [
            'status.in' => 'Cet état de paiement n\'existe pas.',
        ]);

        return PaymentResource::collection($this->search($validated, $this->perPage($request)));
    }

    /**
     * Une tentative par son identifiant.
     *
     * La commande est chargee avec, parce que la seule question que l'on pose en
     * arrivant ici est « de quelle commande s'agit-il ? ». La separer de son
     * achat, dans un ecran de rapprochement, obligerait a un aller-retour par
     * ligne.
     *
     * La recherche porte explicitement sur `uuid` et non sur `findOrFail()` : le
     * route model binding resout le chemin depuis la route, et cette route n'y
     * est pas declaree — l'uuid arrive en parametre de methode. La table porte
     * `uuid`, c'est donc par elle qu'on cherche.
     */
    public function show(Request $request, string $uuid): PaymentResource
    {
        $this->assertCanManage($request, 'payments', 'consulter les paiements');

        $payment = Payment::query()
            ->with(['order.user'])
            ->where('uuid', $uuid)
            ->first();

        if ($payment === null) {
            throw new ApiException('Paiement introuvable.', 404, 'PAYMENT_NOT_FOUND');
        }

        return PaymentResource::make($payment);
    }

    /**
     * La liste filtree.
     *
     * La commande est chargee sur chaque ligne, comme dans `show` : la colonne
     * « commande » du tableau affiche son numero, et une liste de paiements sans
     * leur commande ne peut pas etre relue seule.
     *
     * @param  array{status?: string|null, method?: string|null, provider?: string|null, q?: string|null}  $filters
     * @return LengthAwarePaginator<int, Payment>
     */
    private function search(array $filters, int $perPage): LengthAwarePaginator
    {
        $status = isset($filters['status']) && $filters['status'] !== ''
            ? PaymentStatus::from((int) $filters['status'])
            : null;

        $term = isset($filters['q']) ? trim((string) $filters['q']) : '';

        return Payment::query()
            ->with(['order'])
            ->when($status, fn (Builder $query, PaymentStatus $wanted): Builder => $query->where('status', $wanted))
            ->when($filters['method'] ?? null, fn (Builder $query, string $method): Builder => $query->where('method', $method))
            ->when($filters['provider'] ?? null, fn (Builder $query, string $provider): Builder => $query->where('provider', $provider))

            /*
             * La recherche porte sur trois colonnes, et il est volontaire qu'elle
             * ne porte pas sur plus.
             *
             * `transaction_id` est la reference FedaPay : c'est par elle qu'on
             * rapproche un versement mobile money d'une commande, et le guichet
             * la lit sur le recu du client. Le numero de commande couvre le cas
             * « ma commande MS-0417 », et l'uuid du paiement celui d'un lien de
             * retour partage par accident.
             *
             * Le nom du client n'y figure pas : on cherche une transaction, pas
             * une personne, et l'ajouter ferait passer la requete par la commande
             * pour rien.
             *
             * La comparaison est insensible a la casse parce qu'une reference
             * operateur est une suite de caracteres alphanumeriques dont personne
             * ne garantit d'avoir recopie la casse. Elle se fait sur les deux
             * cotes en minuscules : `ilike` ferait la meme chose sur PostgreSQL
             * mais n'existe pas sur SQLite, la base des tests, et une ecriture
             * qui ne marche que sur l'une des deux est une faute qui n'apparait
             * que dans un environnement.
             *
             * L'uuid se compare par egalite et non par fragment : c'est une
             * valeur exacte qu'on colle depuis un lien, donc un fragment
             * selectionnerait des dizaines de paiements sans dire lequel.
             */
            ->when($term !== '', fn (Builder $query): Builder => $query->where(function (Builder $inner) use ($term): void {
                $inner->whereRaw('lower(transaction_id) like ?', ['%'.mb_strtolower($term).'%'])
                    ->orWhereRaw('lower(uuid) = lower(?)', [$term])
                    ->orWhereHas('order', fn (Builder $orders): Builder => $orders->whereRaw('lower(order_number) like ?', ['%'.mb_strtolower($term).'%']));
            }))

            /*
             * Tri par creation decroissante, avec `id` en second critere.
             *
             * Deux tentatives ouvertes dans la meme milliseconde portent la meme
             * date ; sans le second critere, la page affichee pourrait changer
             * d'ordre d'un appel a l'autre, et le guichet qui reclique verrait une
             * ligne disparaitre puis revenir.
             */
            ->latest('created_at')
            ->latest('id')
            ->paginate($perPage);
    }
}