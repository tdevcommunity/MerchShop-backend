<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\PickupStatus;
use App\Exceptions\ApiException;
use App\Models\Order;
use App\Repositories\Contracts\OrderRepositoryInterface;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use RuntimeException;

/**
 * QR Code de retrait au stand.
 *
 * Le QR est la seule preuve du droit a servi, et c'est aussi la seule chose
 * qu'un stand peut verifier sans reseau. Il porte donc l'identifiant de la
 * commande, l'identifiant du participant quand il est connu, et une empreinte de
 * securite.
 *
 * L'empreinte est derivee de l'identifiant de commande par HMAC et n'est donc
 * jamais tiree au sort. Ce choix merite d'etre explique, car l'inverse parait
 * plus evident : un jeton aleatoire ne serait lui-meme pas necessaire, puisque
 * le secret signe deja la valeur. Il le serait si l'empreinte devait etre
 * impossible a recalculer, ce qui n'est pas une propriete recherchee ici. En
 * revanche, le client doit pouvoir retrouver son QR le jour J : un jeton
 * aleatoire conserve sous forme d'empreinte ne serait plus affichable apres sa
 * premiere emission, et le client se retrouverait sans pass.
 *
 * Le QR ne donne aucun droit sur le compte de l'acheteur : il prouve une commande,
 * rien d'autre. Il n'est d'ailleurs accepte que par le stand, jamais par l'API
 * du client.
 */
final class PickupQrCodeService
{
    /**
     * Taille en pixels de l'image generee.
     *
     * Assez grand pour etre lu par une camera d'entree en mattere a bout de bras,
     * ou l'ecran d'un telephone a faible densite. Un QR plus petit gagnerait de
     * la bande passante pour perdre en lisibilite.
     */
    private const IMAGE_SIZE = 480;

    public function __construct(
        private readonly OrderRepositoryInterface $orders,
    ) {}

    /**
     * Accorde le droit de retrait a une commande payee.
     *
     * Appele au moment du reglement, jamais avant : le stand ne sert pas une
     * commande dont l'argent n'est pas encaisse, et un QR expedition par
     * anticipation laisserait croire qu'un droit de retrait existe alors
     * qu'aucun guichet n'en a besoin.
     *
     * L'octroi est idempotent : recalculer l'empreinte redonne la meme valeur,
     * donc un second appel n'altere rien.
     */
    public function grant(Order $order): Order
    {
        if ($order->fulfillment_method?->requiresPickupQrCode() !== true) {
            return $order;
        }

        $order->update(['pickup_token_hash' => $this->tokenFor($order)]);

        return $order;
    }

    /**
     * Un QR existe-t-il pour cette commande ?
     *
     * Distinct de `assertServable()` : ici on veut une reponse en booleen, pour
     * ne pas lever une exception dans une ressource qui decide simplement
     * d'afficher ou non un champ. Une commande impayee n'a pas de QR a
     * afficher, ce qui est une absence et non une erreur.
     */
    public function hasPickupRight(Order $order): bool
    {
        return $order->fulfillment_method?->requiresPickupQrCode() === true
            && $order->pickup_token_hash !== null
            && $order->pickup_token_hash === $this->tokenFor($order);
    }

    /**
     * Image PNG du QR d'une commande.
     *
     * Le contenu est signe et non seulement encode : un tiers qui lirait le
     * contenu verrait l'identifiant de la commande, ce qui suffirait a un
     * guichetier distrait. La signature est donc incluse, pour qu'un contenu
     * fabrique a la main soit rejete sans appel a la base.
     */
    public function renderPng(Order $order): string
    {
        $this->assertGranted($order);

        $qrCode = new QrCode(
            data: $this->encodePayload($order),
            size: self::IMAGE_SIZE,
            /*
             * Correction basse : elle sert a tolerer les taches et les plis sur
             * un papier imprime, pas a contenir un logo au centre. Une
             * correction elevee encoderait plus de donnees, donc plus de modules,
             * donc un QR plus serre et plus difficile a scanner.
             */
            errorCorrectionLevel: ErrorCorrectionLevel::Low,
        );

        return (new PngWriter)->write($qrCode)->getString();
    }

    /**
     * Contenu du QR, lisible par l'application de scan.
     *
     * Le format est un JSON compact et non une URL : le scan n'a besoin que de
     * lire la valeur, et une URL suggererait un appel reseau que l'application
     * ne fait pas, puisqu'elle doit pouvoir servir une file d'attente sans
     * couverture.
     */
    public function encodePayload(Order $order): string
    {
        return json_encode([
            'order_uuid' => $order->uuid,
            'order_number' => $order->order_number,
            'participant_id' => $order->participant_id,
            'token' => $this->tokenFor($order),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Retrouve la commande designee par un jeton lu au scan.
     *
     * La recherche se fait par l'empreinte stockee, donc sans jamais comparer un
     * jeton en base : une injection SQL n'a rien a viser, et la ligne trouvee est
     * forcement celle dont le droit a ete accorde.
     */
    public function resolveOrder(string $token): Order
    {
        $order = $this->orders->findByPickupTokenHash($token);

        if ($order === null) {
            /*
             * Meme message et meme statut qu'un QR deja consomme. Un jeton
             * inconnu et un QR rejoue sont le meme probleme pour le guichetier,
             * et les distinguer lui indiquerait quels QR ont deja servi, c'est-a-dire
             * l'etat de la file.
             */
            throw new ApiException(
                'Ce QR Code de retrait n\'est pas reconnu.',
                404,
                'PICKUP_TOKEN_INVALID',
            );
        }

        return $order;
    }

    /**
     * Retrouve la commande designee par le contenu brut d'un QR.
     *
     * Le contenu lu par l'application de scan est le JSON produit par
     * `encodePayload()`. Un contenu qui n'est pas ce JSON est rejete ici, avec
     * le meme message qu'un jeton inconnu : un guichetier devant un QR
     * etranger n'a pas a savoir s'il est mal forme ou simplement inconnu.
     */
    public function resolveOrderFromPayload(string $payload): Order
    {
        $decoded = json_decode($payload, true);

        $token = is_array($decoded) && is_string($decoded['token'] ?? null)
            ? $decoded['token']
            : null;

        if ($token === null || $token === '') {
            throw new ApiException(
                'Ce QR Code de retrait n\'est pas reconnu.',
                404,
                'PICKUP_TOKEN_INVALID',
            );
        }

        return $this->resolveOrder($token);
    }

    /**
     * La commande peut-elle etre servie au stand ?
     *
     * Le retrait suppose une commande payee, non servie et de stand. La commande
     * chargee par le scan est relue pour que la reponse reflecte l'etat reel
     * et non celui lu avant la verification.
     */
    public function assertServable(Order $order): void
    {
        if ($order->status !== OrderStatus::READY_FOR_PICKUP || $order->pickup_status !== PickupStatus::PENDING) {
            throw new ApiException(
                'Cette commande n\'est pas en attente de retrait.',
                409,
                'ORDER_NOT_PICKABLE',
                ['status' => $order->status->value, 'pickup_status' => $order->pickup_status?->value],
            );
        }
    }

    /**
     * Empreinte de securite d'une commande.
     *
     * Le nom du plan est prefixe au materiel signe : sans lui, la meme cle
     * signerait des valeurs interpretees differemment ailleurs, et un jeton
     * obtenu dans un contexte pourrait etre rejoue dans l'autre.
     */
    private function tokenFor(Order $order): string
    {
        return hash_hmac('sha256', 'pickup:'.$order->uuid, $this->secret());
    }

    private function assertGranted(Order $order): void
    {
        if ($order->pickup_token_hash === null) {
            throw new ApiException(
                'Cette commande n\'a pas de QR de retrait : elle n\'est pas encore payee, ou son retrait a deja eu lieu.',
                409,
                'PICKUP_NOT_AVAILABLE',
            );
        }
    }

    /**
     * Secret de signature des QR de retrait.
     *
     * Un secret absent est une erreur de configuration, pas un cas degrade : sans
     * lui, toute empreinte calculable par un tiers serait acceptee au scan. On
     * echoue donc bruyamment plutot que de servir un QR qui ne prouve rien.
     */
    private function secret(): string
    {
        $secret = (string) config('orders.pickup.secret');

        if ($secret === '') {
            throw new RuntimeException(
                'Le secret de retrait (PICKUP_TOKEN_SECRET) n\'est pas renseigne : impossible de generer un QR de retrait fiable.',
            );
        }

        return $secret;
    }
}
