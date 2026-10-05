<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Prestataire de paiement
    |--------------------------------------------------------------------------
    |
    | L'agregateur qui encaisse est une decision de l'API, pas du client.
    |
    | Le client choisit son moyen de paiement — mobile money, carte — mais pas
    | l'infrastructure qui le traite. Lui laisser designer l'agregateur
    | reviendrait a lui faire choisir la ou notre cle d'API est engagee, sur la
    | seule foi d'une valeur qu'il invente. C'est la meme raison qui fait que le
    | chemin du webhook est fige par agregateur plutôt que lu dans le corps de la
    | notification.
    |
    | Un seul agregateur est configure par deploiement. LeAjouter n'est donc pas
    | un changement de configuration mais une evolution : un nouveau prestataire
    | suppose sa propre passerelle, son propre format de notification et son
    | propre rythme de rejeu.
    |
    */

    'provider' => env('PAYMENT_PROVIDER', 'fedapay'),

    /*
    |--------------------------------------------------------------------------
    | Retour après paiement
    |--------------------------------------------------------------------------
    |
    | Adresse a laquelle l'acheteur est ramene une fois le reglement tente chez
    | l'operateur. Elle est configuree, et non deduite du pays de l'appelant :
    | FedaPay y redirige le navigateur depuis son propre domaine, et une
    | adresse reconstruite ici pourrait pointer vers un autre que celui qui a
    | emis la commande.
    |
    | Cette adresse est un confort de parcours, jamais une preuve de paiement :
    | elle ne decide de rien. Le reglement est etabli par le webhook, donc une
    | adresse manquante ou erronee ne permet pas de recevoir une commande sans
    | avoir paye — elle ferait seulement perdre a l'acheteur le chemin de retour.
    |
    */

    'callback_url' => env('PAYMENT_CALLBACK_URL'),

];
