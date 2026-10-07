<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Montants
    |--------------------------------------------------------------------------
    |
    | Les montants sont figés en configuration et non calculés à la volée : une
    | commande doit rester rejouable. Si le tarif de port change au milieu du
    | festival, une commande deja enregistrée conserve ce qui a été payé, et
    | seules les nouvelles commandes voient le nouveau tarif.
    |
    | Aucun centime n'est calculé à partir d'une valeur envoyée par le client.
    | Le total d'une commande est toujours recalculé côté serveur, à partir du
    | prix en base ; le payload ne peut porter que des identifiants et des
    | quantités.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Frais de livraison
    |--------------------------------------------------------------------------
    |
    | Montant applique a une commande livree, en francs CFA. Le retrait au
    | stand n'en a pas : ces frais decrivent un transport que le client ne
    | vient pas chercher, et non la vente du produit.
    |
    | Ce montant vient de la configuration et jamais du payload de commande.
    | La regle suit celle du reste du fichier : aucun montant payable n'est
    | calcule a partir d'une valeur envoye par le client, sinon le total a
    | encaisser serait ecrit par celui qui paie.
    |
    | Comme tous les montants, il est fige sur la commande a sa creation : une
    | commande passee sous un ancien tarif le conserve, et seules les nouvelles
    | commandes voient le nouveau.
    |
    */

    'delivery_fee' => (int) env('ORDER_DELIVERY_FEE', 0),

    /*
    |--------------------------------------------------------------------------
    | Numérotation des commandes
    |--------------------------------------------------------------------------
    |
    | Le préfixe précède l'année pour que le guichet puisse lire la date de
    | vente à l'oral sans décoder : c'est le format retenu par la factory.
    |
    */

    'numbering' => [
        'prefix' => env('ORDER_NUMBER_PREFIX', 'TDEV'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Retrait au stand
    |--------------------------------------------------------------------------
    |
    | Le QR Code de retrait est la preuve du droit a servi. Il porte l'identifiant
    | de la commande et une empreinte de securite, comme le prevoit le plan de
    | tracking.
    |
    | L'empreinte n'est pas tiree au hasard : elle est derivee de l'identifiant
    | de commande par HMAC, avec le secret ci-dessous. Un jeton aleatoire
    | stocke sous forme d'empreinte conviendrait a une verification, mais pas a
    | un affichage : le client doit pouvoir retrouver son QR le jour du festival,
    | alors que le jeton en clair n'existerait plus apres la premiere emission.
    | Derive, il se recalcule a l'identique, et la colonne sert alors de preuve
    | d'octroi autant que d'index de recherche.
    |
    | La securite repose donc entierement sur ce secret : il doit rester
    | confidentiel et hors du depot. Un secret absent ou vide rend le retrait
    | inutilisable, et la route de scan refuse alors toute demande plutot que
    | d'accepter un QR qu'elle ne peut pas authentifier.
    |
    | Un secret modifie invalide les QR deja distribues, donc ceux des clients
    | ayant deja recu leur pass. Il ne se change qu'en fin de campagne.
    |
    */

    'pickup' => [
        'secret' => env('PICKUP_TOKEN_SECRET'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Signature des webhooks de paiement
    |--------------------------------------------------------------------------
    |
    | Chaque agrégateur dispose de son propre secret, fourni par l'opérateur et
    | propre à l'environnement. La signature attendue est un HMAC-SHA256 calculé
    | sur le corps brut de la requête, comparé en temps constant.
    |
    | Un secret vide n'est pas une configuration valide : le webhook est alors
    | refusé, parce que sans secret on ne peut pas distinguer l'opérateur de
    | n'importe quel client. La route est donc fermée tant que le secret n'est
    | pas renseigné, plutôt qu'ouverte à quiconque.
    |
    | FedaPay fait exception à la règle générale. Sa signature porte sur
    | l'horodatage de l'évènement suivi du corps, et non sur le corps seul :
    | `tolerance` est la fenêtre, en secondes, pendant laquelle une notification
    | reste acceptable. Passé ce délai la notification est refusée même si sa
    | signature est parfaite, ce qui empêche de rejouer une ancienne confirmation
    | pour faire payer deux fois une commande. Elle vaut cinq minutes par défaut,
    | assez pour absorber le décalage d'horloge entre deux machines et les envois
    | rattrapés après une coupure, sans laisser une fenêtre exploitable.
    |
    */

    'webhooks' => [
        'fedapay' => [
            'secret' => env('PAYMENT_WEBHOOK_SECRET_FEDAPAY'),
            'tolerance' => (int) env('FEDAPAY_WEBHOOK_TOLERANCE', 300),
        ],
        'kkiapay' => [
            'secret' => env('PAYMENT_WEBHOOK_SECRET_KKIAPAY'),
        ],
        'paygate' => [
            'secret' => env('PAYMENT_WEBHOOK_SECRET_PAYGATE'),
        ],
    ],

];
