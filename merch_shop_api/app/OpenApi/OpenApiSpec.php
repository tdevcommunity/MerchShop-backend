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
#[OA\Get(path: '/health', tags: ['Sante'], summary: 'Verifier la disponibilite', responses: [new OA\Response(response: 200, description: 'API disponible'), new OA\Response(response: 503, description: 'Dependance indisponible')])]
#[OA\Get(path: '/auth/csrf-token', tags: ['Authentification'], summary: 'Obtenir le jeton CSRF', responses: [new OA\Response(response: 200, description: 'Jeton genere')])]
#[OA\Post(path: '/auth/register', tags: ['Authentification'], summary: 'Creer un compte', requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['firstname', 'lastname', 'phone', 'email', 'password'], properties: [new OA\Property(property: 'firstname', type: 'string'), new OA\Property(property: 'lastname', type: 'string'), new OA\Property(property: 'phone', type: 'string'), new OA\Property(property: 'email', type: 'string', format: 'email'), new OA\Property(property: 'password', type: 'string', format: 'password')])), responses: [new OA\Response(response: 201, description: 'Compte cree', content: new OA\JsonContent(ref: '#/components/schemas/User')), new OA\Response(response: 422, description: 'Donnees invalides')])]
#[OA\Post(path: '/auth/login', tags: ['Authentification'], summary: 'Ouvrir une session', requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['email', 'password'], properties: [new OA\Property(property: 'email', type: 'string', format: 'email'), new OA\Property(property: 'password', type: 'string', format: 'password'), new OA\Property(property: 'remember', type: 'boolean')])), responses: [new OA\Response(response: 200, description: 'Session ouverte', content: new OA\JsonContent(ref: '#/components/schemas/User')), new OA\Response(response: 422, description: 'Identifiants invalides')])]
#[OA\Post(path: '/auth/logout', tags: ['Authentification'], summary: 'Fermer la session', security: [['sessionCookie' => []]], responses: [new OA\Response(response: 200, description: 'Session fermee')])]
#[OA\Get(path: '/auth/me', tags: ['Authentification'], summary: 'Lire le profil courant', security: [['sessionCookie' => []]], responses: [new OA\Response(response: 200, description: 'Profil', content: new OA\JsonContent(ref: '#/components/schemas/User'))])]
#[OA\Get(path: '/categories', tags: ['Catalogue'], summary: 'Lister les categories', parameters: [new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer'))], responses: [new OA\Response(response: 200, description: 'Collection paginee')])]
#[OA\Get(path: '/categories/{uuid}', tags: ['Catalogue'], summary: 'Lire une categorie', parameters: [new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Categorie', content: new OA\JsonContent(ref: '#/components/schemas/Category')), new OA\Response(response: 404, description: 'Categorie introuvable')])]
#[OA\Post(path: '/categories', tags: ['Catalogue'], summary: 'Creer une categorie', security: [['sessionCookie' => []]], responses: [new OA\Response(response: 201, description: 'Categorie creee')])]
#[OA\Put(path: '/categories/{uuid}', tags: ['Catalogue'], summary: 'Modifier une categorie', security: [['sessionCookie' => []]], parameters: [new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Categorie modifiee')])]
#[OA\Delete(path: '/categories/{uuid}', tags: ['Catalogue'], summary: 'Supprimer une categorie', security: [['sessionCookie' => []]], parameters: [new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Categorie supprimee')])]
#[OA\Get(path: '/products', tags: ['Catalogue'], summary: 'Lister les produits', parameters: [new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer')), new OA\Parameter(name: 'category_id', in: 'query', schema: new OA\Schema(type: 'integer')), new OA\Parameter(name: 'search', in: 'query', schema: new OA\Schema(type: 'string'))], responses: [new OA\Response(response: 200, description: 'Collection paginee')])]
#[OA\Get(path: '/products/{uuid}', tags: ['Catalogue'], summary: 'Lire un produit', parameters: [new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Produit', content: new OA\JsonContent(ref: '#/components/schemas/Product'))])]
#[OA\Get(path: '/products/{uuid}/variants', tags: ['Catalogue'], summary: 'Lister les variantes', parameters: [new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Variantes')])]
#[OA\Post(path: '/products', tags: ['Catalogue'], summary: 'Creer un produit', security: [['sessionCookie' => []]], responses: [new OA\Response(response: 201, description: 'Produit cree')])]
#[OA\Put(path: '/products/{uuid}', tags: ['Catalogue'], summary: 'Modifier un produit', security: [['sessionCookie' => []]], parameters: [new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Produit modifie')])]
#[OA\Delete(path: '/products/{uuid}', tags: ['Catalogue'], summary: 'Supprimer un produit', security: [['sessionCookie' => []]], parameters: [new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Produit supprime')])]
#[OA\Post(path: '/orders', tags: ['Commandes'], summary: 'Creer une commande', responses: [new OA\Response(response: 201, description: 'Commande creee', content: new OA\JsonContent(ref: '#/components/schemas/Order'))])]
#[OA\Get(path: '/orders', tags: ['Commandes'], summary: 'Lister mes commandes', security: [['sessionCookie' => []]], responses: [new OA\Response(response: 200, description: 'Collection paginee')])]
#[OA\Get(path: '/orders/{uuid}', tags: ['Commandes'], summary: 'Lire une commande', parameters: [new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'X-Guest-Order-Token', in: 'header', required: false, schema: new OA\Schema(type: 'string'))], responses: [new OA\Response(response: 200, description: 'Commande', content: new OA\JsonContent(ref: '#/components/schemas/Order'))])]
#[OA\Post(path: '/orders/{uuid}/cancel', tags: ['Commandes'], summary: 'Annuler une commande', security: [['sessionCookie' => []]], parameters: [new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Commande annulee')])]
#[OA\Get(path: '/pickup/orders', tags: ['Retrait'], summary: 'Lister la file de retrait', security: [['sessionCookie' => []]], responses: [new OA\Response(response: 200, description: 'File de retrait')])]
#[OA\Post(path: '/pickup/scan', tags: ['Retrait'], summary: 'Scanner une commande', security: [['sessionCookie' => []]], responses: [new OA\Response(response: 200, description: 'Commande retiree')])]
#[OA\Post(path: '/orders/{uuid}/ready', tags: ['Retrait'], summary: 'Marquer une commande prete', security: [['sessionCookie' => []]], parameters: [new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Commande prete')])]
#[OA\Post(path: '/orders/{uuid}/picked-up', tags: ['Retrait'], summary: 'Marquer une commande retiree', security: [['sessionCookie' => []]], parameters: [new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Commande retiree')])]
#[OA\Get(path: '/orders/{uuid}/qr', tags: ['Retrait'], summary: 'Telecharger le QR de retrait', parameters: [new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Image PNG du QR')])]
#[OA\Post(path: '/payments/webhooks/{provider}', tags: ['Paiements'], summary: 'Recevoir une notification de paiement', parameters: [new OA\Parameter(name: 'provider', in: 'path', required: true, schema: new OA\Schema(type: 'string'))], responses: [new OA\Response(response: 200, description: 'Notification traitee'), new OA\Response(response: 401, description: 'Signature invalide')])]
final class OpenApiSpec {}
