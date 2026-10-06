<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Http\Resources\OrderResource;
use App\Services\DashboardService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * L'ecran d'accueil du back-office.
 *
 * Une seule route qui rend tout, plutot que six appels depuis le front. La
 * raison est la coherence des chiffres : un ecran d'accueil recompose ses
 * compteurs a partir de plusieurs reponses independantes peut afficher « 12 en
 * attente » et « 5 encaissees » qui n'ont jamais ete vrais ensemble, si un
 * encaissement est passe entre les deux appels. Rendus par un seul agregat, ils
 * le sont par construction.
 *
 * Le compteur d'encaissements echoues est le seul qui depend du moment, et il
 * se regle donc sur une fenetre demandee par le client et bornee par la route.
 * Aucune fenetre par defaut n'est imposee : une fenetre inventee rendrait le
 * chiffre faux sans que personne ne puisse dire pourquoi, alors qu'une absence de
 * fenetre signifie visiblement « depuis le debut ».
 */
final class AdminDashboardController extends ApiController
{
    /**
     * Fenetre maximale d'analyse des echecs de paiement.
     *
 * Borne superieure plutot que minimum : un back-office qui demande un an
     * d'historique d'echecs recoit ce qu'il demande, jusqu'a la borne. Ce n'est
     * pas un cout notable — un `count` indexe sur `created_at` — mais c'est une
     * convention, et une convention qu'on n'ecrit nulle part est une convention
     * que deux developpeurs decouvrent differemment.
     */
    private const MAX_FAILURES_WINDOW_DAYS = 90;

    public function __construct(
        private readonly DashboardService $dashboard,
    ) {}

    /**
     * Le tableau de bord.
     *
     * `recentOrders` est rendu en ordre de creation decroissante, avec l'identifiant
     * interne en second critere : deux commandes passees dans la meme milliseconde
     * ont la meme date, et sans le second critere la liste pourrait changer
     * d'ordre entre deux rechargements — le guichet recliquerait pour actualiser et
     * verrait une ligne bouger sans qu'aucune nouvelle commande soit arrivee.
     */
    public function show(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'failures_since' => ['nullable', 'date'],
            'failures_days' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_FAILURES_WINDOW_DAYS],
        ]);

        $since = isset($validated['failures_since'])
            ? CarbonImmutable::parse((string) $validated['failures_since'])
            : CarbonImmutable::now()
                ->subDays((int) ($validated['failures_days'] ?? 0))
                ->startOfDay();

        return response()->json([
            'data' => [
                ...$this->dashboard->summary($since),
                'recentOrders' => OrderResource::collection($this->dashboard->recentOrders())->resolve(),
            ],
        ]);
    }
}