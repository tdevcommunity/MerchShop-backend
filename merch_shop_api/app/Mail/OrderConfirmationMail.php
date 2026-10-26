<?php

namespace App\Mail;

use App\Enums\FulfillmentMethod;
use App\Models\Order;
use App\Services\PickupQrCodeService;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Confirmation de commande apres paiement.
 *
 * Un seul mailable couvre les deux modes de retrait, parce que le contenu
 * differe d'un seul point — la presence du QR — et que tout le reste
 * (destinataire, numero, lignes, total) est commun. Deux classes seuls
 * auraient duplique ce socle pour diverger sur une condition.
 *
 * Le QR est attache en piece jointe et aussi embarque dans le corps : la
 * piece jointe sert a l'enregistrer, l'embarque permet de le presenter au
 * stand sans ouvrir une piece jointe sur un telephone. Les deux usages sont
 * reels, ils ne se remplacent pas.
 *
 * L'image n'est generee qu'une seule fois, a l'execution du job : la
 * generation a dependance — le secret de signature — et il serait errone de
 * la lancer au moment du dispatch, dans la transaction du webhook.
 */
final class OrderConfirmationMail extends Mailable
{
    /** Image PNG du QR, generee paresseusement, une seule fois. */
    private ?string $qrPng = null;

    public function __construct(
        public readonly Order $order,
    ) {}

    public function envelope(): Envelope
    {
        /*
         * Pas de `to` ici : le destinataire est pose par l'appelant
         * (`Mail::to()` dans le job). Le fixer a deux endroits laisserait
         * deux sources possibles pour la meme adresse.
         */
        return new Envelope(
            subject: sprintf('Commande %s confirmée', $this->order->order_number),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.order-confirmation',
            with: [
                'order' => $this->order,
                'isPickup' => $this->order->fulfillment_method === FulfillmentMethod::PICKUP,
                'qrInline' => $this->qrImage(),
            ],
        );
    }

    /**
     * Piece jointe : le PNG du QR, pour une commande de retrait.
     *
     * Une commande livree n'a pas de QR : la liste est vide, et non chargee
     * d'une image qui ne designerait aucun droit.
     *
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        $png = $this->qrImage();

        if ($png === null) {
            return [];
        }

        return [
            Attachment::fromData(
                fn (): string => $png,
                sprintf('qr-retrait-%s.png', $this->order->order_number),
            )->withMime('image/png'),
        ];
    }

    /**
     * Image du QR, ou null pour une commande livree.
     *
     * La memoire evite de genere deux fois le PNG : une pour la piece
     * jointe, une pour le corps, qui auraient ete deux renders du meme
     * contenu pour la meme commande.
     */
    private function qrImage(): ?string
    {
        if ($this->order->fulfillment_method !== FulfillmentMethod::PICKUP) {
            return null;
        }

        return $this->qrPng ??= app(PickupQrCodeService::class)->renderPng($this->order);
    }
}
