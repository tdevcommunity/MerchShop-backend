<?php

namespace App\Services;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Models\Variant;

/**
 * Ce qui demande une decision au stand, maintenant.
 *
 * Ces notifications ne sont pas stockees : elles sont recalculees a chaque appel
 * depuis l'etat reel des commandes, des paiements et du stock. C'est ce qui les
 * rend fiables. Une alerte de rupture qui survit apres un reapprovisionnement est
 * pire qu'une alerte absente, parce qu'elle apprend au guichetier d'ignorer les
 * alertes.
 *
 * Une table de notifications aurait l'inverse de cette propriete. Pour que
 * « rupture » disparaisse apres un reapprovisionnement, il faudrait un balayage
 * qui la ferme, et ce balayage est du code de plus a maintenir — avec une fenetre
 * pendant laquelle il n'a pas tourne et l'alerte reste affichee a tort. Un etat
 * derive ne peut pas etre perime : il n'est jamais sauvegarde, donc il ne peut pas
 * mentir sur l'instant present.
 *
 * Ce que ce choix coute est explicite, et il vaut mieux le dire que le cacher.
 * Une notification n'a pas d'historique de lecture et ne peut pas etre accusee
 * individuellement : une alerte de stock que personne n'a vue le mardi se repose
 * le mercredi, tant qu'elle reste vraie. Pour un festival d'une journee ou deux
 * c'est le comportement utile — l'etat ne change pas pendant que le stand est
 * ouvert, et ce qui compte est que l'alerte soit la quand on la regarde.
 *
 * Chaque notification porte donc un identifiant stable, derive de ce qu'elle
 * decrit : la meme rupture porte le meme identifiant d'un appel a l'autre. Ce
 * n'est pas un identifiant de ligne, c'est une clef de deduplication, et elle
 * permet au front de reconnaitre une alerte deja affichee plutot que de la
 * redessiner comme nouvelle a chaque rechargement.
 */
final class AlertService
{
    /**
     * Nombre d'alertes rendues par categorie.
     *
     * Cinq plutot que « toutes » : une liste d'alertes qui s'allonge sans fin
     * n'est plus lue, et l'objet de ces alertes est d'etre traitees avant que le
     * stand ne se remplisse. Au-dela de cinq de la meme espece, il n'y a plus de
     * decision a prendre, il y a une penurie — et une penurie se traite en
     * reapprovisionnant, pas en deroulant une liste.
     *
     * Ce qui est laisse de cote n'est pas cache : le tableau de bord porte les
     * chiffres exhaustifs, donc un guichetier qui veut le nombre exact a un
     * endroit honnete ou le lire.
     */
    private const PER_KIND = 5;

    /**
     * Age au-dela duquel un paiement en attente devient une alerte.
     *
     * La collecte FedaPay expire apres vingt-quatre heures : un paiement encore
     * en attente au bout de deux heures ne aboutira donc pas tout seul. Soit le
     * client n'a pas fini de son cote, soit il a fini et que la notification a
     * ete perdue — et dans les deux cas, deux heures c'est le moment ou le stand
     * ne retient plus l'article : le client est probablement parti, et l'article
     * vaut mieux remis a quelqu'un qui le paie.
     *
     * Cette borne est volontairement plus courte que l'expiration du
     * prestataire, et elle doit le rester. Une borne a l'expiration, ou au-dela,
     * alerterait sur une collecte qui a deja echoue ailleurs : l'alerte
     * arriverait pour annoncer une decision deja prise.
     */
    private const STALE_PAYMENT_HOURS = 2;

    /**
     * Les alertes, les plus urgentes d'abord.
     *
     * Le tri par categorie, et non par date, est deliberé. Une liste d'alertes
     * triee par moment place en tete la plus recente — or la plus recente est
     * generalement la moins importante : une alerte qui traine depuis une heure
     * est une decision deja prise ou un message deja lu. Ce qui demande a etre
     * regardé, c'est la categorie qui vient de bloquer une vente.
     *
     * L'ordre est donc : ce qui ne peut plus se vendre, puis ce qui n'a pas ete
     * paye, puis ce qui s'épuise. Chaque groupe est trie par date decroissante,
     * parce qu'a l'interieur d'une espece la plus recente est celle qui vient
     * d'apparaitre.
     *
     * @return array<int, array{id: string, tone: string, message: string, createdAt: string}>
     */
    public function alerts(): array
    {
        return [
            ...$this->outOfStock(),
            ...$this->stalePayments(),
            ...$this->lowStock(),
        ];
    }

    /**
     * Les articles qui ne peuvent plus se vendre.
     *
     * Actifs seulement : une declinaison desactivee est sortie du catalogue par
     * decision, et la lister ici mettrait dans les alertes un article que l'equipe
     * a choisi de masquer. Son stock se reconcile depuis l'ecran d'inventaire,
     * qui liste les declinaisons desactivees sur demande.
     */
    private function outOfStock(): array
    {
        return Variant::query()
            ->with('product')
            ->where('status', 1)
            ->where('stock', '<=', 0)
            ->orderBy('id')
            ->limit(self::PER_KIND)
            ->get()
            ->map(fn (Variant $variant): array => $this->alert(
                'out-of-stock:'.$variant->uuid,
                'warning',
                sprintf(
                    'Rupture : %s (%s).',
                    $variant->product?->name ?? 'Article sans nom',
                    $variant->name,
                ),
                $variant,
            ))
            ->all();
    }

    /**
     * Les paiements qui attendent depuis trop longtemps.
     *
     * Enumeres depuis les paiements et non depuis les commandes, parce que
     * c'est le paiement qui attend : la commande peut avoir une seconde tentative
     * reussie et etre reglee, alors que sa premiere reste en attente indefiniment
     * et ferait passer une commande deja payee pour non payee. Filtrer sur l'etat
     * courant des commandes produirait donc une alerte sur de l'argent deja
     * recu.
     *
     * Le numero de commande est lu sur le paiement pour que l'alerte soit
     * actionnable sans seconde requete : le guichet entend « MS-0417 », et c'est
     * ce numero qu'il doit donner au comptoir pour retrouver la commande.
     */
    private function stalePayments(): array
    {
        return Payment::query()
            ->with('order')
            ->where('status', PaymentStatus::PENDING->value)
            ->where('created_at', '<=', now()->subHours(self::STALE_PAYMENT_HOURS))
            ->orderBy('id')
            ->limit(self::PER_KIND)
            ->get()
            ->map(fn (Payment $payment): array => $this->alert(
                'stale-payment:'.$payment->uuid,
                'warning',
                sprintf(
                    'Paiement en attente depuis plus de %d h pour %s.',
                    self::STALE_PAYMENT_HOURS,
                    $payment->order?->order_number ?? 'commande inconnue',
                ),
                $payment,
            ))
            ->all();
    }

    /**
     * Les declinaisons dont le stock est passe sous leur propre seuil.
     *
     * Le seuil est lu dans la colonne et non dans une constante, pour la meme
     * raison que partout ailleurs : chaque declinaison a le sien, pose au stand,
     * et comparer a un nombre ecrit ici produirait une liste d'alertes en
     * desaccord avec l'ecran d'inventory affiche a cote.
     *
     * Stock strictement positif, pour qu'une declinaison deja presente dans le
     * premier groupe n'apparaisse pas aussi ici. Les deux se lisent dans la meme
     * liste, et un doublon ferait passer le nombre de decisions distinctes pour
     * faux.
     */
    private function lowStock(): array
    {
        return Variant::query()
            ->with('product')
            ->where('status', 1)
            ->where('stock', '>', 0)
            ->whereColumn('stock', '<=', 'low_stock_threshold')
            ->orderBy('id')
            ->limit(self::PER_KIND)
            ->get()
            ->map(fn (Variant $variant): array => $this->alert(
                'low-stock:'.$variant->uuid,
                'info',
                sprintf(
                    'Stock bas : %s (%s), %d restant(s).',
                    $variant->product?->name ?? 'Article sans nom',
                    $variant->name,
                    $variant->stock,
                ),
                $variant,
            ))
            ->all();
    }

    /**
     * Une alerte, a identifiant stable.
     *
     * L'identifiant est derive de ce qu'elle decrit et non de la ligne, pour que
     * la meme shortage porte le meme identifiant d'un appel a l'autre. C'est ce
     * qui permet au front de reconnaitre une alerte qu'il a deja montree, et
     * donc de la garder en place au lieu de la traiter comme nouvelle a chaque
     * rechargement.
     *
     * La date est celle du fait decrit — le dernier mouvement de la declinaison,
     * la creation du paiement — et non le moment de la requete. Une alerte derivee
     * reconstruite maintenant porterait l'heure courante, et la liste
     * presenterait chaque shortage comme venant d'apparaitre, ce qui est
     * exactement l'inverse de ce que signifie une alerte.
     *
     * `tone` vaut `warning` ou `info`. Il n'y a pas de `success` ici : rien dans
     * ce service ne decrit un evenement favorable. Ce cas serait une absence
     * d'alerte, et le rendre par une ligne reviendrait a inventer une
     * notification pour annoncer qu'il n'y en a pas.
     *
     * @return array{id: string, tone: string, message: string, createdAt: string}
     */
    private function alert(string $id, string $tone, string $message, Payment|Variant $subject): array
    {
        return [
            'id' => $id,
            'tone' => $tone,
            'message' => $message,
            'createdAt' => $subject->created_at?->toIso8601String() ?? now()->toIso8601String(),
        ];
    }
}