<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Services\AlertService;
use Illuminate\Http\JsonResponse;

/**
 * Les alertes du stand.
 *
 * Une seule route, en lecture seule, et c'est l'ensemble de ce que l'API offre
 * sur ce sujet. Il n'y a ni `POST` ni `PATCH`, donc aucun moyen de creer une
 * notification ni de la marquer comme lue.
 *
 * Cette absence est la consequence du fonctionnement, pas un oubli. Les alertes
 * sont derivees de l'etat reel par `AlertService`, donc une notification creee a
 * la main serait un second systeme de notifications, avec une duree de vie qui
 * ne dependrait d'aucun fait observable — et disparaitrait au premier
 * reapprovisionnement qui ne l'aurait pas concernee. Une alerte « les tickets
 * autonomes sont presqu'epuises » posee a la main serait vraie jusqu'a l.epuise,
     * puis encore longtemps apres, ce qui est l'inverse de l'alerte de stock.
 *
 * Elle ne peut pas non plus etre marquee comme lue. Ce qui est lu n'est pas un
 * etat du systeme mais une action d'une personne, et une action ne se derive pas
 * d'une lecture de donnees : il faudrait une table, donc une fois de plus une
 * copie de l'etat, avec le meme probleme de perimption et la meme consequence
 * — une alerte « vue » qui reapparait parce que le balayage n'a pas tourne.
 *
 * Ce que le front peut faire, et qui est utile : afficher le nombre d'alertes et
 * les laisser toutes visibles tant qu'elles sont vraies. L'equipe voit ainsi
 * deux fois une alerte de rupture, ce qui est correct pour un festival — la
 * seconde fois lui rappeele qu'un article manque toujours en rayon.
 */
final class AdminNotificationController extends ApiController
{
    public function __construct(
        private readonly AlertService $alerts,
    ) {}

    /**
     * Les alertes, les plus urgentes d'abord.
     *
     * Le tri est fait par `AlertService`, qui classe par gravite et non par
     * date. Le controller ne fait donc aucun tri : le reordonner ici
     * reproduirait la regle a un second endroit, ou divergerait.
     */
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => $this->alerts->alerts(),
        ]);
    }
}