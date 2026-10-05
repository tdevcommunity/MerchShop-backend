<?php

namespace App\OpenApi;

use OpenApi\Attributes as OA;

#[OA\Info(
    version: '1.0.0',
    title: 'MerchShop API',
    description: 'API du shop merch du festival TDEV. Les requetes d\'ecriture utilisent une session Laravel et le jeton CSRF.',
)]
#[OA\Server(url: '/api/v1', description: 'API v1')]
#[OA\SecurityScheme(
    securityScheme: 'sessionCookie',
    type: 'apiKey',
    in: 'cookie',
    name: 'laravel_session',
    description: 'Cookie de session Laravel. Les requetes d\'ecriture doivent aussi envoyer X-XSRF-TOKEN.',
)]
#[OA\Tag(name: 'Sante', description: 'Disponibilite de l\'API')]
#[OA\Tag(name: 'Authentification', description: 'Session et profil')]
#[OA\Tag(name: 'Catalogue', description: 'Categories, produits et variantes')]
#[OA\Tag(name: 'Commandes', description: 'Creation et suivi des commandes')]
#[OA\Tag(name: 'Retrait', description: 'Operations du guichet')]
#[OA\Tag(name: 'Paiements', description: 'Notifications des fournisseurs')]
#[OA\Tag(name: 'Analytics', description: 'Evenements comportementaux et acquisition')]
#[OA\Schema(
    schema: 'User',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'firstname', type: 'string'),
        new OA\Property(property: 'lastname', type: 'string'),
        new OA\Property(property: 'phone', type: 'string', nullable: true),
        new OA\Property(property: 'email', type: 'string', format: 'email'),
        new OA\Property(property: 'role', type: 'string', example: 'customer'),
        new OA\Property(property: 'status', type: 'string', example: 'active'),
        new OA\Property(property: 'createdAt', type: 'string', format: 'date-time'),
    ],
)]
#[OA\Schema(
    schema: 'Category',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'slug', type: 'string'),
        new OA\Property(property: 'description', type: 'string', nullable: true),
        new OA\Property(property: 'status', type: 'string', example: 'active'),
        new OA\Property(property: 'createdAt', type: 'string', format: 'date-time'),
    ],
)]
#[OA\Schema(
    schema: 'Variant',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'sku', type: 'string'),
        new OA\Property(property: 'size', type: 'string', nullable: true),
        new OA\Property(property: 'color', type: 'string', nullable: true),
        new OA\Property(property: 'price', type: 'integer', example: 2500),
        new OA\Property(property: 'stock', type: 'integer', example: 12),
        new OA\Property(property: 'isDefault', type: 'boolean'),
        new OA\Property(property: 'status', type: 'string', example: 'active'),
    ],
)]
#[OA\Schema(
    schema: 'Product',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'slug', type: 'string'),
        new OA\Property(property: 'description', type: 'string', nullable: true),
        new OA\Property(property: 'category', ref: '#/components/schemas/Category'),
        new OA\Property(property: 'status', type: 'string', example: 'active'),
        new OA\Property(property: 'variants', type: 'array', items: new OA\Items(ref: '#/components/schemas/Variant')),
    ],
)]
#[OA\Schema(
    schema: 'Order',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'orderNumber', type: 'string', example: 'ORD-20260930-0001'),
        new OA\Property(property: 'status', type: 'string', example: 'pending_payment'),
        new OA\Property(property: 'fulfillmentMethod', type: 'string', example: 'pickup'),
        new OA\Property(property: 'total', type: 'integer', example: 2500),
        new OA\Property(property: 'items', type: 'array', items: new OA\Items(type: 'object')),
        new OA\Property(property: 'createdAt', type: 'string', format: 'date-time'),
    ],
)]
#[OA\Schema(
    schema: 'RegisterRequest',
    type: 'object',
    required: ['firstname', 'lastname', 'phone', 'email', 'password', 'password_confirmation'],
    properties: [
        new OA\Property(property: 'firstname', type: 'string', minLength: 2, maxLength: 100),
        new OA\Property(property: 'lastname', type: 'string', minLength: 2, maxLength: 100),
        new OA\Property(property: 'phone', type: 'string', pattern: '^\\+?228[0-9]{8}$', example: '+22890123456'),
        new OA\Property(property: 'email', type: 'string', format: 'email', maxLength: 255),
        new OA\Property(property: 'password', type: 'string', format: 'password', minLength: 8, maxLength: 72),
        new OA\Property(property: 'password_confirmation', type: 'string', format: 'password'),
    ],
)]
#[OA\Schema(
    schema: 'LoginRequest',
    type: 'object',
    required: ['email', 'password'],
    properties: [
        new OA\Property(property: 'email', type: 'string', format: 'email', maxLength: 255),
        new OA\Property(property: 'password', type: 'string', format: 'password', maxLength: 72),
        new OA\Property(property: 'remember', type: 'boolean', default: false),
    ],
)]
#[OA\Schema(
    schema: 'CategoryRequest',
    type: 'object',
    properties: [
        new OA\Property(property: 'name', type: 'string', minLength: 2, maxLength: 150),
        new OA\Property(property: 'description', type: 'string', nullable: true, maxLength: 5000),
        new OA\Property(property: 'slug', type: 'string', nullable: true, maxLength: 150, pattern: '^[A-Za-z0-9_-]+$'),
        new OA\Property(property: 'status', type: 'integer', enum: [0, 1], description: '0 = inactive, 1 = active.'),
    ],
)]
#[OA\Schema(
    schema: 'VariantInput',
    type: 'object',
    required: ['sku', 'name', 'price', 'stock'],
    properties: [
        new OA\Property(property: 'uuid', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'sku', type: 'string', maxLength: 100),
        new OA\Property(property: 'name', type: 'string', minLength: 1, maxLength: 150),
        new OA\Property(property: 'size', type: 'string', nullable: true, maxLength: 50),
        new OA\Property(property: 'color', type: 'string', nullable: true, maxLength: 50),
        new OA\Property(property: 'price', type: 'integer', minimum: 0, example: 2500),
        new OA\Property(property: 'stock', type: 'integer', minimum: 0, example: 20),
        new OA\Property(property: 'status', type: 'integer', enum: [0, 1]),
    ],
)]
#[OA\Schema(
    schema: 'ProductRequest',
    type: 'object',
    description: 'A la creation, envoyer variants ou default_variant, mais pas les deux. A la mise a jour, tous les champs sont facultatifs.',
    properties: [
        new OA\Property(property: 'name', type: 'string', minLength: 2, maxLength: 200),
        new OA\Property(property: 'description', type: 'string', nullable: true, maxLength: 10000),
        new OA\Property(property: 'image_url', type: 'string', format: 'uri', nullable: true, maxLength: 2048),
        new OA\Property(property: 'category_id', type: 'integer', minimum: 1),
        new OA\Property(property: 'slug', type: 'string', nullable: true, maxLength: 200),
        new OA\Property(property: 'status', type: 'integer', enum: [0, 1]),
        new OA\Property(property: 'variants', type: 'array', items: new OA\Items(ref: '#/components/schemas/VariantInput')),
        new OA\Property(property: 'default_variant', ref: '#/components/schemas/DefaultVariantInput', nullable: true),
    ],
)]
#[OA\Schema(
    schema: 'DefaultVariantInput',
    type: 'object',
    required: ['price', 'stock'],
    properties: [
        new OA\Property(property: 'sku', type: 'string', nullable: true, maxLength: 100),
        new OA\Property(property: 'name', type: 'string', nullable: true, minLength: 1, maxLength: 150),
        new OA\Property(property: 'size', type: 'string', nullable: true, maxLength: 50),
        new OA\Property(property: 'color', type: 'string', nullable: true, maxLength: 50),
        new OA\Property(property: 'price', type: 'integer', minimum: 0, example: 2500),
        new OA\Property(property: 'stock', type: 'integer', minimum: 0, example: 20),
        new OA\Property(property: 'status', type: 'integer', enum: [0, 1]),
    ],
)]
#[OA\Schema(
    schema: 'OrderItemInput',
    type: 'object',
    required: ['uuid', 'quantity'],
    properties: [
        new OA\Property(property: 'uuid', type: 'string', format: 'uuid'),
        new OA\Property(property: 'quantity', type: 'integer', minimum: 1, maximum: 20),
    ],
)]
#[OA\Schema(
    schema: 'StoreOrderRequest',
    type: 'object',
    required: ['items', 'customer_name', 'customer_phone_number', 'fulfillment_method', 'payment_method'],
    properties: [
        new OA\Property(property: 'items', type: 'array', minItems: 1, maxItems: 20, items: new OA\Items(ref: '#/components/schemas/OrderItemInput')),
        new OA\Property(property: 'customer_name', type: 'string', minLength: 2, maxLength: 120),
        new OA\Property(property: 'customer_phone_number', type: 'string', example: '+22890123456'),
        new OA\Property(property: 'customer_phone_country', type: 'string', nullable: true, minLength: 2, maxLength: 2, example: 'tg'),
        new OA\Property(property: 'fulfillment_method', type: 'string', enum: ['pickup', 'delivery']),
        new OA\Property(property: 'shipping_address', type: 'string', nullable: true, minLength: 5, maxLength: 500, description: 'Obligatoire si fulfillment_method vaut delivery.'),
        new OA\Property(property: 'payment_method', type: 'string', enum: ['mobile_money', 'card']),
        new OA\Property(property: 'participant_id', type: 'string', nullable: true, maxLength: 100),
    ],
)]
#[OA\Schema(
    schema: 'PickupScanRequest',
    type: 'object',
    required: ['payload'],
    properties: [
        new OA\Property(property: 'payload', type: 'string', maxLength: 2000, description: 'Contenu JSON du QR de retrait.'),
    ],
)]
#[OA\Schema(
    schema: 'AnalyticsEventsRequest',
    type: 'object',
    required: ['events'],
    properties: [
        new OA\Property(property: 'events', type: 'array', minItems: 1, maxItems: 100, items: new OA\Items(type: 'object', required: ['event_name', 'event_time', 'session_id'], properties: [
            new OA\Property(property: 'event_name', type: 'string', maxLength: 100),
            new OA\Property(property: 'event_time', type: 'string', format: 'date-time'),
            new OA\Property(property: 'session_id', type: 'string', maxLength: 64),
            new OA\Property(property: 'participant_id', type: 'string', nullable: true, maxLength: 100),
            new OA\Property(property: 'page', type: 'string', nullable: true, maxLength: 500),
            new OA\Property(property: 'product_id', type: 'string', nullable: true, maxLength: 64),
            new OA\Property(property: 'device_type', type: 'string', nullable: true, maxLength: 50),
            new OA\Property(property: 'browser', type: 'string', nullable: true, maxLength: 100),
            new OA\Property(property: 'os', type: 'string', nullable: true, maxLength: 100),
            new OA\Property(property: 'source', type: 'string', nullable: true, maxLength: 100),
            new OA\Property(property: 'campaign', type: 'string', nullable: true, maxLength: 100),
            new OA\Property(property: 'properties', type: 'object', nullable: true, additionalProperties: true),
        ])),
    ],
)]
#[OA\Schema(
    schema: 'PaymentResource',
    type: 'object',
    properties: [
        new OA\Property(property: 'uuid', type: 'string', format: 'uuid'),
        new OA\Property(property: 'orderId', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'participantId', type: 'string', nullable: true),
        new OA\Property(property: 'currency', type: 'string', nullable: true, example: 'XOF'),
        new OA\Property(property: 'amount', type: 'string', example: '2500'),
        new OA\Property(property: 'method', type: 'string', enum: ['mobile_money', 'card']),
        new OA\Property(property: 'provider', type: 'string', nullable: true, example: 'fedapay'),
        new OA\Property(property: 'status', type: 'integer', enum: [1, 2, 3, 4], description: '1 pending, 2 success, 3 failed, 4 refunded.'),
        new OA\Property(property: 'transactionId', type: 'string', nullable: true),
        new OA\Property(property: 'checkoutUrl', type: 'string', format: 'uri', nullable: true),
        new OA\Property(property: 'failureReason', type: 'string', nullable: true),
        new OA\Property(property: 'createdAt', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'paidAt', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'failedAt', type: 'string', format: 'date-time', nullable: true),
    ],
)]
#[OA\Schema(
    schema: 'FedapayWebhookEvent',
    type: 'object',
    description: 'Notification signee par FedaPay. Le header X-FEDAPAY-SIGNATURE est obligatoire et le corps doit rester intact.',
    properties: [
        new OA\Property(property: 'id', type: 'integer', nullable: true),
        new OA\Property(property: 'type', type: 'string', nullable: true, example: 'transaction.approved'),
        new OA\Property(property: 'name', type: 'string', nullable: true, example: 'transaction.approved'),
        new OA\Property(property: 'data', type: 'object', nullable: true, properties: [
            new OA\Property(property: 'id', type: 'integer', example: 9001),
            new OA\Property(property: 'reference', type: 'string', example: 'FEDAPAY-9001'),
            new OA\Property(property: 'status', type: 'string', enum: ['pending', 'approved', 'transferred', 'declined', 'canceled', 'refunded', 'approved_partially_refunded', 'transferred_partially_refunded']),
            new OA\Property(property: 'amount', type: 'integer', example: 2500),
            new OA\Property(property: 'metadata', type: 'object', nullable: true, additionalProperties: true),
            new OA\Property(property: 'custom_metadata', type: 'object', nullable: true, additionalProperties: true),
        ]),
        new OA\Property(property: 'object', type: 'object', nullable: true, description: 'Alternative a data selon la representation FedaPay.', additionalProperties: true),
    ],
)]
#[OA\Schema(
    schema: 'PaymentNotificationRequest',
    type: 'object',
    description: 'Corps plat des notifications des agregateurs generiques. Cette structure ne s applique pas au webhook FedaPay.',
    required: ['reference', 'status', 'amount'],
    properties: [
        new OA\Property(property: 'reference', type: 'string', format: 'uuid'),
        new OA\Property(property: 'transaction_id', type: 'string', nullable: true, maxLength: 190),
        new OA\Property(property: 'status', type: 'string', enum: ['pending', 'success', 'successful', 'failed', 'refused']),
        new OA\Property(property: 'amount', type: 'string', example: '2500'),
        new OA\Property(property: 'failure_reason', type: 'string', nullable: true, maxLength: 500),
    ],
)]
#[OA\Get(path: '/health', tags: ['Sante'], summary: 'Verifier la disponibilite', responses: [new OA\Response(response: 200, description: 'API disponible'), new OA\Response(response: 503, description: 'Dependance indisponible')])]
#[OA\Get(path: '/auth/csrf-token', tags: ['Authentification'], summary: 'Obtenir le jeton CSRF', responses: [new OA\Response(response: 200, description: 'Jeton genere')])]
#[OA\Post(path: '/auth/register', tags: ['Authentification'], summary: 'Creer un compte', requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/RegisterRequest')), responses: [new OA\Response(response: 201, description: 'Compte cree', content: new OA\JsonContent(ref: '#/components/schemas/User')), new OA\Response(response: 422, description: 'Donnees invalides')])]
#[OA\Post(path: '/auth/login', tags: ['Authentification'], summary: 'Ouvrir une session', requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/LoginRequest')), responses: [new OA\Response(response: 200, description: 'Session ouverte', content: new OA\JsonContent(ref: '#/components/schemas/User')), new OA\Response(response: 422, description: 'Identifiants invalides')])]
#[OA\Post(path: '/auth/logout', tags: ['Authentification'], summary: 'Fermer la session', security: [['sessionCookie' => []]], responses: [new OA\Response(response: 200, description: 'Session fermee')])]
#[OA\Get(path: '/auth/me', tags: ['Authentification'], summary: 'Lire le profil courant', security: [['sessionCookie' => []]], responses: [new OA\Response(response: 200, description: 'Profil', content: new OA\JsonContent(ref: '#/components/schemas/User'))])]
#[OA\Get(path: '/categories', tags: ['Catalogue'], summary: 'Lister les categories', parameters: [new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer'))], responses: [new OA\Response(response: 200, description: 'Collection paginee')])]
#[OA\Get(path: '/categories/{uuid}', tags: ['Catalogue'], summary: 'Lire une categorie', parameters: [new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Categorie', content: new OA\JsonContent(ref: '#/components/schemas/Category')), new OA\Response(response: 404, description: 'Categorie introuvable')])]
#[OA\Post(path: '/categories', tags: ['Catalogue'], summary: 'Creer une categorie', security: [['sessionCookie' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/CategoryRequest')), responses: [new OA\Response(response: 201, description: 'Categorie creee')])]
#[OA\Put(path: '/categories/{uuid}', tags: ['Catalogue'], summary: 'Modifier une categorie', security: [['sessionCookie' => []]], parameters: [new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/CategoryRequest')), responses: [new OA\Response(response: 200, description: 'Categorie modifiee')])]
#[OA\Delete(path: '/categories/{uuid}', tags: ['Catalogue'], summary: 'Supprimer une categorie', security: [['sessionCookie' => []]], parameters: [new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Categorie supprimee')])]
#[OA\Get(path: '/products', tags: ['Catalogue'], summary: 'Lister les produits', parameters: [new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer')), new OA\Parameter(name: 'category_id', in: 'query', schema: new OA\Schema(type: 'integer')), new OA\Parameter(name: 'search', in: 'query', schema: new OA\Schema(type: 'string'))], responses: [new OA\Response(response: 200, description: 'Collection paginee')])]
#[OA\Get(path: '/products/{uuid}', tags: ['Catalogue'], summary: 'Lire un produit', parameters: [new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Produit', content: new OA\JsonContent(ref: '#/components/schemas/Product'))])]
#[OA\Get(path: '/products/{uuid}/variants', tags: ['Catalogue'], summary: 'Lister les variantes', parameters: [new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Variantes')])]
#[OA\Post(path: '/products', tags: ['Catalogue'], summary: 'Creer un produit', security: [['sessionCookie' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ProductRequest')), responses: [new OA\Response(response: 201, description: 'Produit cree')])]
#[OA\Put(path: '/products/{uuid}', tags: ['Catalogue'], summary: 'Modifier un produit', security: [['sessionCookie' => []]], parameters: [new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ProductRequest')), responses: [new OA\Response(response: 200, description: 'Produit modifie')])]
#[OA\Delete(path: '/products/{uuid}', tags: ['Catalogue'], summary: 'Supprimer un produit', security: [['sessionCookie' => []]], parameters: [new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Produit supprime')])]
#[OA\Post(path: '/orders', tags: ['Commandes'], summary: 'Creer une commande', requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/StoreOrderRequest')), responses: [new OA\Response(response: 201, description: 'Commande creee', content: new OA\JsonContent(ref: '#/components/schemas/Order'))])]
#[OA\Get(path: '/orders', tags: ['Commandes'], summary: 'Lister mes commandes', security: [['sessionCookie' => []]], responses: [new OA\Response(response: 200, description: 'Collection paginee')])]
#[OA\Get(path: '/orders/{uuid}', tags: ['Commandes'], summary: 'Lire une commande', parameters: [new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'X-Order-Token', in: 'header', required: false, schema: new OA\Schema(type: 'string'))], responses: [new OA\Response(response: 200, description: 'Commande', content: new OA\JsonContent(ref: '#/components/schemas/Order'))])]
#[OA\Post(path: '/orders/{uuid}/payment', tags: ['Paiements'], summary: 'Ouvrir le paiement d\'une commande', description: 'Aucun body. Le montant, la devise et le moyen de paiement viennent de la commande. Le client est redirige vers checkoutUrl, une page hebergee par FedaPay. L\'appel est idempotent et n\'encaisse rien tant que le webhook n\'a pas confirme le paiement.', parameters: [new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'X-Order-Token', in: 'header', required: false, description: 'Obligatoire pour une commande invitee. Non requis avec une session autorisee.', schema: new OA\Schema(type: 'string'))], responses: [new OA\Response(response: 200, description: 'Paiement ouvert', content: new OA\JsonContent(ref: '#/components/schemas/PaymentResource')), new OA\Response(response: 403, description: 'Commande d\'un autre compte ou acces absent'), new OA\Response(response: 409, description: 'Commande deja reglee ou annulee'), new OA\Response(response: 429, description: 'Trop de demandes'), new OA\Response(response: 502, description: 'Operateur injoignable'), new OA\Response(response: 503, description: 'Prestataire non configure')])]
#[OA\Post(path: '/orders/{uuid}/cancel', tags: ['Commandes'], summary: 'Annuler une commande', security: [['sessionCookie' => []]], parameters: [new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Commande annulee')])]
#[OA\Post(path: '/orders/{uuid}/refund', tags: ['Paiements'], summary: 'Demander le remboursement d\'une commande', security: [['sessionCookie' => []]], description: 'Aucun body. La route cree une demande de remboursement ; le statut final depend de la notification de depot FedaPay.', parameters: [new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Demande de remboursement enregistree'), new OA\Response(response: 409, description: 'Commande non remboursable')])]
#[OA\Get(path: '/pickup/orders', tags: ['Retrait'], summary: 'Lister la file de retrait', security: [['sessionCookie' => []]], responses: [new OA\Response(response: 200, description: 'File de retrait')])]
#[OA\Post(path: '/pickup/scan', tags: ['Retrait'], summary: 'Scanner une commande', security: [['sessionCookie' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/PickupScanRequest')), responses: [new OA\Response(response: 200, description: 'Commande retiree')])]
#[OA\Post(path: '/orders/{uuid}/ready', tags: ['Retrait'], summary: 'Marquer une commande prete', security: [['sessionCookie' => []]], parameters: [new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Commande prete')])]
#[OA\Post(path: '/orders/{uuid}/picked-up', tags: ['Retrait'], summary: 'Marquer une commande retiree', security: [['sessionCookie' => []]], parameters: [new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Commande retiree')])]
#[OA\Get(path: '/orders/{uuid}/qr', tags: ['Retrait'], summary: 'Telecharger le QR de retrait', parameters: [new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Image PNG du QR')])]
#[OA\Post(path: '/events', tags: ['Analytics'], summary: 'Enregistrer des evenements analytics', requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/AnalyticsEventsRequest')), responses: [new OA\Response(response: 201, description: 'Evenements enregistres'), new OA\Response(response: 422, description: 'Payload invalide')])]
#[OA\Post(path: '/payments/webhooks/{provider}', tags: ['Paiements'], summary: 'Recevoir une notification de paiement generique', description: 'Utilise le corps plat et la signature HMAC des agregateurs generiques. FedaPay utilise la route dediee ci-dessous.', parameters: [new OA\Parameter(name: 'provider', in: 'path', required: true, schema: new OA\Schema(type: 'string', enum: ['kkiapay', 'paygate', 'flooz', 'tmoney']))], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/PaymentNotificationRequest')), responses: [new OA\Response(response: 200, description: 'Notification traitee'), new OA\Response(response: 401, description: 'Signature invalide')])]
#[OA\Post(path: '/payments/webhooks/fedapay', tags: ['Paiements'], summary: 'Recevoir une notification FedaPay', description: 'Le header X-FEDAPAY-SIGNATURE est obligatoire et signe `<horodatage>.<corps>`. Le corps contient une enveloppe data ou object. Les evenements pending, refund et remboursement partiel sont acquittes sans confirmer un paiement.', parameters: [new OA\Parameter(name: 'X-FEDAPAY-SIGNATURE', in: 'header', required: true, description: 'Format t=timestamp,s=signature. La signature porte sur timestamp.corporel.', schema: new OA\Schema(type: 'string', example: 't=1730000000,s=abcdef123456'))], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/FedapayWebhookEvent')), responses: [new OA\Response(response: 200, description: 'Notification comprise et acquittee', content: new OA\JsonContent(type: 'object')), new OA\Response(response: 401, description: 'Signature invalide ou horodatage hors fenetre'), new OA\Response(response: 422, description: 'Evenement illisible ou statut inconnu'), new OA\Response(response: 503, description: 'Secret de webhook non configure')])]

final class OpenApiSpec {}
