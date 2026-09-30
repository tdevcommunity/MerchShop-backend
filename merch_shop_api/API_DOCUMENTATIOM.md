# Documentation de l'API MerchShop

Cette documentation décrit l'API Laravel du TDEV Festival 2026 : catalogue,
authentification, commandes, paiement et retrait.

Le fichier décrit l'implémentation actuelle. La spécification OpenAPI générée
par L5-Swagger est disponible sur `/api/documentation` et `/docs`.

## 1. Accès et conventions

### Préfixe

Tous les endpoints métier sont préfixés par :

```text
/api/v1
```

Les identifiants de ressources sont des UUID (`{uuid}`), même lorsque la clé
primaire interne de la base est un entier.

### Authentification

L'API utilise une authentification Laravel par **cookie de session** : aucun
JWT ou bearer token n'est retourné.

Pour une opération d'écriture effectuée depuis un navigateur :

1. appeler `GET /api/v1/auth/csrf-token` ;
2. conserver le cookie `XSRF-TOKEN` ;
3. envoyer sa valeur dans `X-XSRF-TOKEN` ;
4. conserver le cookie de session fourni par Laravel.

Les réponses JSON utilisent des clés `camelCase`, alors que les payloads
entrants restent en `snake_case` (`category_id`, `per_page`, etc.).

### Accès par rôle

- **Visiteur** : santé, token CSRF, inscription, connexion, consultation du
  catalogue et création d'une commande.
- **Client connecté** : profil, déconnexion, historique de ses commandes,
  annulation selon le statut.
- **Staff** : file de retrait, scan et opérations de retrait.
- **Administrateur** : gestion des catégories et produits, ainsi que les
  opérations staff.

Les contrôleurs délèguent l'autorisation aux policies. Un rôle `staff` ne peut
pas gérer le catalogue et un compte désactivé ne doit pas être considéré comme
actif.

### Réponses et erreurs

- Une collection paginée contient `data`, `links` et `meta`.
- Les erreurs métier utilisent une enveloppe JSON avec `errorCode`, `message`
  et éventuellement `details`.
- Les erreurs de validation retournent `422` en JSON ; aucune redirection HTML
  n'est faite.
- `404` indique une ressource introuvable.
- `401` indique une authentification absente ou invalide.
- `403` indique une authentification valide mais une autorisation insuffisante.
- `409` indique un conflit d'état métier, par exemple une commande non
  servable ou une transition impossible.
- `429` indique un dépassement de limitation de débit.

`per_page` est borné entre 1 et `API_MAX_PER_PAGE`. La valeur par défaut et
les limites de débit sont configurées dans `config/api.php` et documentées dans
`.env.example`.

## 2. Données de test

Le seeder principal crée un environnement local de démonstration avec les
comptes suivants, tous avec le mot de passe `password` :

| Rôle | E-mail |
|---|---|
| Administrateur | `admin@merchshop.test` |
| Staff | `staff@merchshop.test` |
| Client | `client@merchshop.test` |

Il crée également deux catégories, deux produits et quatre variantes en stock.
Pour le relancer : `php artisan db:seed`.

## 3. Endpoints

### Payloads et réponses JSON attendues

Les tableaux ci-dessous décrivent le contrat client/serveur. Les payloads entrants sont en `snake_case` et les réponses JSON sont sérialisées en `camelCase` par les resources API.

La colonne `Requis` indique si un champ est obligatoire (`Oui`) ou facultatif (`Non`).

#### Authentification

| Endpoint | Champ du payload | Type | Requis | Description |
|---|---|---|---|---|
| `POST /api/v1/auth/register` | `firstname` | `string` | Oui | prénom |
|  | `lastname` | `string` | Oui | nom |
|  | `phone` | `string` | Oui | numéro de téléphone |
|  | `email` | `string` | Oui | adresse e-mail unique |
|  | `password` | `string` | Oui | mot de passe en clair côté client, hashé côté serveur |
| `POST /api/v1/auth/login` | `email` | `string` | Oui | adresse e-mail |
|  | `password` | `string` | Oui | mot de passe |
|  | `remember` | `boolean` | Non | active une session persistante |

Réponse attendue : `201` pour l'inscription ou `200` pour la connexion, avec un objet `UserResource`.

#### Catalogue : catégories

| Endpoint | Champ du payload | Type | Requis | Description |
|---|---|---|---|---|
| `POST /api/v1/categories` | `name` | `string` | Oui | nom de la catégorie |
|  | `description` | `string|null` | Non | description de la catégorie |
|  | `slug` | `string` | Oui | identifiant lisible pour l'URL |
|  | `status` | `string` | Oui | `active` ou `inactive` |
| `PUT /api/v1/categories/{uuid}` | `name` | `string` | Oui | nom de la catégorie |
|  | `description` | `string|null` | Non | description de la catégorie |
|  | `slug` | `string` | Oui | identifiant lisible pour l'URL |
|  | `status` | `string` | Oui | `active` ou `inactive` |

Réponse attendue : `201` pour la création et `200` pour la mise à jour, avec un objet `CategoryResource`.

#### Catalogue : produits et variantes

| Endpoint | Champ du payload | Type | Requis | Description |
|---|---|---|---|---|
| `POST /api/v1/products` | `name` | `string` | Oui | nom du produit |
|  | `description` | `string|null` | Non | description du produit |
|  | `slug` | `string` | Oui | slug public du produit |
|  | `category_uuid` | `string` | Oui | UUID de la catégorie parent |
|  | `status` | `string` | Oui | `active` ou `inactive` |
|  | `variants` | `array<object>` | Oui | liste des variantes |
| `PUT /api/v1/products/{uuid}` | `name` | `string` | Oui | nom du produit |
|  | `description` | `string|null` | Non | description du produit |
|  | `slug` | `string` | Oui | slug public du produit |
|  | `category_uuid` | `string` | Oui | UUID de la catégorie parent |
|  | `status` | `string` | Oui | `active` ou `inactive` |
|  | `variants` | `array<object>` | Oui | liste des variantes |

Payload d'une variante :

| Champ | Type | Requis | Description |
|---|---|---|---|
| `uuid` | `string|null` | Non | UUID existant pour une mise à jour |
| `sku` | `string` | Oui | SKU unique de la variante |
| `size` | `string|null` | Non | taille (S, M, L, XL, etc.) |
| `color` | `string|null` | Non | couleur |
| `price` | `integer` | Oui | prix en XOF |
| `stock` | `integer` | Oui | quantité disponible |
| `is_default` | `boolean` | Non | indique que la variante est la variante par défaut |
| `status` | `string` | Oui | `active` ou `inactive` |

Réponse attendue : `201` ou `200` avec un objet `ProductResource` contenant les données de la catégorie et des variantes publiées.

#### Commandes

| Endpoint | Champ du payload | Type | Requis | Description |
|---|---|---|---|---|
| `POST /api/v1/orders` | `items` | `array<object>` | Oui | lignes de commande |
|  | `fulfillment_method` | `string` | Oui | `pickup` ou `delivery` |
|  | `shipping_address` | `string|null` | Non | adresse de livraison si applicable |
|  | `participant_id` | `string|null` | Non | identifiant participant pour un achat externe |

Payload d'une ligne de commande :

| Champ | Type | Requis | Description |
|---|---|---|---|
| `variant_uuid` | `string` | Oui | UUID de la variante concernée |
| `quantity` | `integer` | Oui | quantité commandée |
| `unit_price` | `integer` | Non | prix unitaire calculé côté serveur |
| `notes` | `string|null` | Non | commentaire libre |

Réponse attendue : `201` avec un objet `OrderResource`. Pour une commande invitée, le token de consultation est retourné une seule fois dans la réponse et doit être réenvoyé via l'en-tête `X-Guest-Order-Token`.

#### Retrait au guichet

| Endpoint | Champ du payload | Type | Requis | Description |
|---|---|---|---|---|
| `POST /api/v1/pickup/scan` | `payload` | `string` | Oui | contenu du QR lu au guichet |

Réponse attendue : `200` avec l'objet de commande ou le statut de retrait. Un QR invalide ou une commande introuvable renvoie `404` ou `409` selon le cas.

#### Webhooks de paiement

| Endpoint | Champ du payload | Type | Requis | Description |
|---|---|---|---|---|
| `POST /api/v1/payments/webhooks/{provider}` | `event` | `string` | Oui | type d'événement fourni par le provider |
|  | `transaction_id` | `string` | Oui | identifiant de transaction fournisseur |
|  | `status` | `string` | Oui | statut final ou intermédiaire retourné par le provider |
|  | `amount` | `integer` | Oui | montant payé en sous-unité |
|  | `currency` | `string` | Oui | devise de paiement |
|  | `signature` | `string` | Oui | signature vérifiée par le serveur |

Réponse attendue : `200` avec un objet minimal de confirmation : `orderUuid`, `orderNumber` et `status`.

### Santé

| Méthode | Endpoint | Accès | Contrôleur | Fonction |
|---|---|---|---|---|
| GET | `/api/v1/health` | Public | `HealthController` | `__invoke` |

Payload : aucun.

Réponse attendue : `200` avec un objet de santé :

```json
{
  "status": "ok",
  "database": "connected",
  "timestamp": "2026-09-30T12:00:00Z"
}
```

En cas de base indisponible, la réponse est un statut HTTP correspondant au problème sans contourner la sérialisation standard.

### Authentification

| Méthode | Endpoint | Accès | Contrôleur | Fonction |
|---|---|---|---|---|
| GET | `/api/v1/auth/csrf-token` | Public | `AuthController` | `csrfToken` |
| POST | `/api/v1/auth/register` | Public, `throttle:register` | `AuthController` | `register` |
| POST | `/api/v1/auth/login` | Public, `throttle:login` | `AuthController` | `login` |
| POST | `/api/v1/auth/logout` | Session requise | `AuthController` | `logout` |
| GET | `/api/v1/auth/me` | Session requise | `AuthController` | `me` |

#### `GET /auth/csrf-token`

Payload : aucun.

Réponse attendue : `200` avec un objet JSON contenant le token CSRF et l’état de la session :

```json
{
  "csrfToken": "abc123...",
  "message": "CSRF token generated"
}
```

#### `POST /auth/register`

Crée un compte client actif, hache son mot de passe et ouvre immédiatement une
session. Le client ne peut pas choisir le rôle ou le statut du compte.

Payload attendu : `firstname`, `lastname`, `phone`, `email`, `password`.
Réponse : `201` avec `UserResource`.

Exemple de réponse attendue :

```json
{
  "id": "a1b2c3d4-e5f6-7890-abcd-ef1234567890",
  "firstname": "Jean",
  "lastname": "Dupont",
  "phone": "+22890000000",
  "email": "jean.dupont@example.com",
  "role": "customer",
  "status": "active",
  "createdAt": "2026-09-30T12:00:00Z"
}
```

#### `POST /auth/login`

Vérifie `email` et `password`, puis ouvre la session. Le champ optionnel
`remember` demande une session persistante. Les erreurs d'email inconnu et de
mot de passe incorrect utilisent le même message afin d'éviter l'énumération
des comptes.

Payload attendu : `email`, `password`, `remember` (optionnel).
Réponse : `200` avec `UserResource`.

Exemple de réponse attendue :

```json
{
  "id": "a1b2c3d4-e5f6-7890-abcd-ef1234567890",
  "firstname": "Jean",
  "lastname": "Dupont",
  "phone": "+22890000000",
  "email": "jean.dupont@example.com",
  "role": "customer",
  "status": "active",
  "createdAt": "2026-09-30T12:00:00Z"
}
```

#### `POST /auth/logout`

Invalide la session et purge le token CSRF. Réponse `200` avec un message dans
`data`.

Payload : aucun.

Exemple de réponse attendue :

```json
{
  "data": {
    "message": "Logged out successfully"
  }
}
```

#### `GET /auth/me`

Retourne l'utilisateur authentifié courant avec `UserResource`.

Payload : aucun.

Exemple de réponse attendue :

```json
{
  "id": "a1b2c3d4-e5f6-7890-abcd-ef1234567890",
  "firstname": "Jean",
  "lastname": "Dupont",
  "phone": "+22890000000",
  "email": "jean.dupont@example.com",
  "role": "customer",
  "status": "active",
  "createdAt": "2026-09-30T12:00:00Z"
}
```

### Catalogue : catégories

| Méthode | Endpoint | Accès | Contrôleur | Fonction |
|---|---|---|---|---|
| GET | `/api/v1/categories` | Public | `CategoryController` | `index` |
| GET | `/api/v1/categories/{uuid}` | Public | `CategoryController` | `show` |
| POST | `/api/v1/categories` | Admin | `CategoryController` | `store` |
| PUT | `/api/v1/categories/{uuid}` | Admin | `CategoryController` | `update` |
| DELETE | `/api/v1/categories/{uuid}` | Admin | `CategoryController` | `destroy` |

`GET /categories` retourne uniquement les catégories visibles du catalogue,
avec pagination et clés de réponse en camelCase.

Query params : `per_page` (optionnel), `page` (optionnel).

Exemple de réponse attendue :

```json
{
  "data": [
    {
      "id": "11111111-2222-3333-4444-555555666666",
      "name": "Accessoires",
      "slug": "accessoires",
      "description": "Produits complémentaires",
      "status": "active",
      "createdAt": "2026-09-30T12:00:00Z"
    }
  ],
  "links": {
    "first": "...",
    "last": "...",
    "prev": null,
    "next": null
  },
  "meta": {
    "currentPage": 1,
    "lastPage": 1,
    "perPage": 15,
    "total": 1
  }
}
```

`GET /categories/{uuid}` retourne une catégorie active ou une erreur `404`.

Exemple de réponse attendue :

```json
{
  "id": "11111111-2222-3333-4444-555555666666",
  "name": "Accessoires",
  "slug": "accessoires",
  "description": "Produits complémentaires",
  "status": "active",
  "createdAt": "2026-09-30T12:00:00Z"
}
```

`POST /categories` accepte le payload validé par `StoreCategoryRequest`, crée
la catégorie et renvoie `201` avec `CategoryResource`.

Payload attendu :

| Champ | Type | Requis | Description |
|---|---|---|---|
| `name` | `string` | Oui | nom de la catégorie |
| `description` | `string|null` | Non | description |
| `slug` | `string` | Oui | identifiant public de la catégorie |
| `status` | `string` | Oui | `active` ou `inactive` |

Exemple de réponse attendue :

```json
{
  "id": "11111111-2222-3333-4444-555555666666",
  "name": "Accessoires",
  "slug": "accessoires",
  "description": "Produits complémentaires",
  "status": "active",
  "createdAt": "2026-09-30T12:00:00Z"
}
```

`PUT /categories/{uuid}` accepte `UpdateCategoryRequest`, vérifie la policy,
met à jour la catégorie et renvoie `CategoryResource`.

Payload attendu : identique à `POST /categories` (champs modifiables).

Exemple de réponse attendue :

```json
{
  "id": "11111111-2222-3333-4444-555555666666",
  "name": "Nouveaux accessoires",
  "slug": "nouveaux-accessoires",
  "description": "Nouvelle description",
  "status": "active",
  "updatedAt": "2026-09-30T12:05:00Z"
}
```

`DELETE /categories/{uuid}` vérifie la policy puis supprime la catégorie. La
suppression échoue si les règles métier empêchent de supprimer une catégorie
encore utilisée.

Payload : aucun.

Réponse attendue : `204` ou `200` selon la stratégie de suppression, avec éventuellement un message :

```json
{
  "message": "Category deleted successfully"
}
```

### Catalogue : produits et variantes

| Méthode | Endpoint | Accès | Contrôleur | Fonction |
|---|---|---|---|---|
| GET | `/api/v1/products` | Public | `ProductController` | `index` |
| GET | `/api/v1/products/{uuid}` | Public | `ProductController` | `show` |
| GET | `/api/v1/products/{uuid}/variants` | Public | `ProductController` | `variants` |
| POST | `/api/v1/products` | Admin | `ProductController` | `store` |
| PUT | `/api/v1/products/{uuid}` | Admin | `ProductController` | `update` |
| DELETE | `/api/v1/products/{uuid}` | Admin | `ProductController` | `destroy` |

`GET /products` accepte :

- `per_page` : nombre d'éléments, borné par la configuration ;
- `category_id` : entier positif ;
- `search` : recherche textuelle sur le nom du produit.

Exemple de réponse attendue :

```json
{
  "data": [
    {
      "id": "22222222-3333-4444-5555-666666777777",
      "name": "T-shirt Merch",
      "slug": "t-shirt-merch",
      "description": "T-shirt officiel",
      "category": {
        "id": "11111111-2222-3333-4444-555555666666",
        "name": "Vêtements"
      },
      "status": "active",
      "variants": [
        {
          "id": "33333333-4444-5555-6666-777777888888",
          "sku": "TSHIRT-RED-M",
          "size": "M",
          "color": "red",
          "price": 2500,
          "stock": 12,
          "isDefault": true,
          "status": "active"
        }
      ]
    }
  ],
  "links": {
    "first": "...",
    "last": "...",
    "prev": null,
    "next": null
  },
  "meta": {
    "currentPage": 1,
    "lastPage": 1,
    "perPage": 15,
    "total": 1
  }
}
```

`GET /products/{uuid}` retourne le produit avec sa catégorie et ses variantes
publiques. `GET /products/{uuid}/variants` retourne uniquement les variantes
publiques du produit.

Exemple de réponse attendue pour `GET /products/{uuid}` :

```json
{
  "id": "22222222-3333-4444-5555-666666777777",
  "name": "T-shirt Merch",
  "slug": "t-shirt-merch",
  "description": "T-shirt officiel",
  "category": {
    "id": "11111111-2222-3333-4444-555555666666",
    "name": "Vêtements"
  },
  "status": "active",
  "variants": [
    {
      "id": "33333333-4444-5555-6666-777777888888",
      "sku": "TSHIRT-RED-M",
      "size": "M",
      "color": "red",
      "price": 2500,
      "stock": 12,
      "isDefault": true,
      "status": "active"
    }
  ]
}
```

`POST /products` et `PUT /products/{uuid}` utilisent respectivement
`StoreProductRequest` et `UpdateProductRequest`. Le service gère le slug, la
catégorie, les variantes, les prix en XOF, les SKU et la variante par défaut.

Payload attendu :

| Champ | Type | Requis | Description |
|---|---|---|---|
| `name` | `string` | Oui | nom du produit |
| `description` | `string|null` | Non | description |
| `slug` | `string` | Oui | identifiant public du produit |
| `category_uuid` | `string` | Oui | UUID de la catégorie |
| `status` | `string` | Oui | `active` ou `inactive` |
| `variants` | `array<object>` | Oui | liste des variantes |

Exemple de réponse attendue :

```json
{
  "id": "22222222-3333-4444-5555-666666777777",
  "name": "T-shirt Merch",
  "slug": "t-shirt-merch",
  "description": "T-shirt officiel",
  "category": {
    "id": "11111111-2222-3333-4444-555555666666",
    "name": "Vêtements"
  },
  "status": "active",
  "variants": [
    {
      "id": "33333333-4444-5555-6666-777777888888",
      "sku": "TSHIRT-RED-M",
      "size": "M",
      "color": "red",
      "price": 2500,
      "stock": 12,
      "isDefault": true,
      "status": "active"
    }
  ]
}
```

`DELETE /products/{uuid}` vérifie la policy puis supprime le produit selon les
règles de conservation des données et de ses variantes.

Payload : aucun.

Réponse attendue : `204` ou `200` selon la stratégie de suppression :

```json
{
  "message": "Product deleted successfully"
}
```

### Commandes

| Méthode | Endpoint | Accès | Contrôleur | Fonction |
|---|---|---|---|---|
| POST | `/api/v1/orders` | Public, `throttle:checkout` | `OrderController` | `store` |
| GET | `/api/v1/orders` | Session requise | `OrderController` | `index` |
| GET | `/api/v1/orders/{uuid}` | Session ou token invité | `OrderController` | `show` |
| POST | `/api/v1/orders/{uuid}/cancel` | Session requise | `OrderController` | `cancel` |

#### Création

`POST /orders` valide les lignes de commande avec `StoreOrderRequest`. La
commande peut être liée à l'utilisateur connecté ou rester une commande
invitée. Le service :

1. verrouille les variantes demandées ;
2. vérifie qu'elles sont vendables et que le stock suffit ;
3. calcule les prix côté serveur ;
4. réserve le stock dans une transaction ;
5. crée les lignes et génère un numéro de commande unique ;
6. émet un token d'accès uniquement pour une commande invitée.

Payload attendu :

| Champ | Type | Requis | Description |
|---|---|---|---|
| `items` | `array<object>` | Oui | lignes de commande |
| `fulfillment_method` | `string` | Oui | `pickup` ou `delivery` |
| `shipping_address` | `string|null` | Non | adresse de livraison si applicable |
| `participant_id` | `string|null` | Non | identifiant participant pour un achat externe |

Payload d'une ligne de commande :

| Champ | Type | Requis | Description |
|---|---|---|---|
| `variant_uuid` | `string` | Oui | UUID de la variante |
| `quantity` | `integer` | Oui | quantité commandée |
| `unit_price` | `integer` | Non | prix unitaire calculé côté serveur |
| `notes` | `string|null` | Non | commentaire libre |

Réponse : `201` avec `OrderResource`. Le token invité n'est retourné qu'une
seule fois dans cette réponse et doit être envoyé ensuite dans l'en-tête
`X-Guest-Order-Token`.

Exemple de réponse attendue :

```json
{
  "id": "44444444-5555-6666-7777-888888999999",
  "orderNumber": "ORD-20260930-0001",
  "status": "pending_payment",
  "fulfillmentMethod": "pickup",
  "items": [
    {
      "variantId": "33333333-4444-5555-6666-777777888888",
      "productName": "T-shirt Merch",
      "quantity": 1,
      "unitPrice": 2500,
      "total": 2500
    }
  ],
  "total": 2500,
  "guestOrderToken": "secret-token-only-once"
}
```

#### Lecture et filtrage

`GET /orders` retourne uniquement les commandes du compte connecté. Le filtre
optionnel `status` accepte les valeurs suivantes :

| Valeur | Statut |
|---:|---|
| 1 | En attente de paiement |
| 2 | Payée |
| 3 | Prête au retrait |
| 4 | Retirée |
| 5 | Annulée |
| 6 | Remboursée |

Exemple de réponse attendue :

```json
{
  "data": [
    {
      "id": "44444444-5555-6666-7777-888888999999",
      "orderNumber": "ORD-20260930-0001",
      "status": "pending_payment",
      "fulfillmentMethod": "pickup",
      "total": 2500,
      "createdAt": "2026-09-30T12:00:00Z"
    }
  ],
  "links": {
    "first": "...",
    "last": "...",
    "prev": null,
    "next": null
  },
  "meta": {
    "currentPage": 1,
    "lastPage": 1,
    "perPage": 15,
    "total": 1
  }
}
```

`GET /orders/{uuid}` accepte soit la session du propriétaire, soit le token
d'une commande invitée. Une commande d'un autre compte ne peut pas être lue
par cette voie.

Exemple de réponse attendue :

```json
{
  "id": "44444444-5555-6666-7777-888888999999",
  "orderNumber": "ORD-20260930-0001",
  "status": "pending_payment",
  "fulfillmentMethod": "pickup",
  "total": 2500,
  "items": [
    {
      "variantId": "33333333-4444-5555-6666-777777888888",
      "productName": "T-shirt Merch",
      "quantity": 1,
      "unitPrice": 2500,
      "total": 2500
    }
  ],
  "createdAt": "2026-09-30T12:00:00Z"
}
```

#### Annulation

`POST /orders/{uuid}/cancel` annule uniquement une commande encore annulable.
La transition et la restitution du stock sont vérifiées dans la même
transaction pour éviter une course avec un paiement.

Payload : aucun.

Exemple de réponse attendue :

```json
{
  "id": "44444444-5555-6666-7777-888888999999",
  "status": "cancelled",
  "updatedAt": "2026-09-30T12:03:00Z"
}
```

### Retrait au guichet

| Méthode | Endpoint | Accès | Contrôleur | Fonction |
|---|---|---|---|---|
| GET | `/api/v1/pickup/orders` | Staff ou admin | `OrderPickupController` | `index` |
| POST | `/api/v1/pickup/scan` | Staff ou admin | `OrderPickupController` | `scan` |
| POST | `/api/v1/orders/{uuid}/ready` | Staff ou admin | `OrderPickupController` | `markReady` |
| POST | `/api/v1/orders/{uuid}/picked-up` | Staff ou admin | `OrderPickupController` | `markPickedUp` |
| GET | `/api/v1/orders/{uuid}/qr` | Propriétaire, token invité ou staff autorisé | `OrderPickupController` | `qrCode` |

`GET /pickup/orders` retourne la file des commandes de retrait, paginée et
triée de la plus récente à la plus ancienne.

Exemple de réponse attendue :

```json
{
  "data": [
    {
      "id": "44444444-5555-6666-7777-888888999999",
      "orderNumber": "ORD-20260930-0001",
      "status": "ready_for_pickup",
      "customerName": "Jean Dupont",
      "pickupToken": "abc123"
    }
  ],
  "meta": {
    "currentPage": 1,
    "lastPage": 1,
    "perPage": 15,
    "total": 1
  }
}
```

`POST /pickup/scan` reçoit `payload` via `PickupScanRequest`. Le QR est résolu
et vérifié avant toute écriture. Une commande inexistante retourne `404` ; une
commande impayée, déjà retirée ou livrée retourne `409`.

Payload attendu :

| Champ | Type | Requis | Description |
|---|---|---|---|
| `payload` | `string` | Oui | contenu du QR lu au guichet |

Exemple de réponse attendue :

```json
{
  "id": "44444444-5555-6666-7777-888888999999",
  "orderNumber": "ORD-20260930-0001",
  "status": "ready_for_pickup",
  "pickedUp": false
}
```

`POST /orders/{uuid}/ready` fait passer une commande payée à l'état prête au
retrait.

Payload : aucun.

Exemple de réponse attendue :

```json
{
  "id": "44444444-5555-6666-7777-888888999999",
  "status": "ready_for_pickup",
  "updatedAt": "2026-09-30T12:05:00Z"
}
```

`POST /orders/{uuid}/picked-up` marque une commande servie comme retirée. Cette
route permet un rattrapage manuel lorsque le scan est indisponible.

Payload : aucun.

Exemple de réponse attendue :

```json
{
  "id": "44444444-5555-6666-7777-888888999999",
  "status": "picked_up",
  "updatedAt": "2026-09-30T12:06:00Z"
}
```

`GET /orders/{uuid}/qr` renvoie une image PNG. Une commande invitée doit fournir
`X-Guest-Order-Token`. La réponse utilise `Content-Type: image/png` et un cache
privé court.

Payload : aucun.

Réponse attendue : `200` avec un binaire PNG ; aucun JSON n'est renvoyé en tant que tel.

### Webhooks de paiement

| Méthode | Endpoint | Accès | Contrôleur | Fonction |
|---|---|---|---|---|
| POST | `/api/v1/payments/webhooks/fedapay` | Signature opérateur | `PaymentWebhookController` | `handle` |
| POST | `/api/v1/payments/webhooks/kkiapay` | Signature opérateur | `PaymentWebhookController` | `handle` |
| POST | `/api/v1/payments/webhooks/paygate` | Signature opérateur | `PaymentWebhookController` | `handle` |
| POST | `/api/v1/payments/webhooks/flooz` | Signature opérateur | `PaymentWebhookController` | `handle` |
| POST | `/api/v1/payments/webhooks/tmoney` | Signature opérateur | `PaymentWebhookController` | `handle` |

Les webhooks ne nécessitent pas de session. La signature du corps est vérifiée
avant la validation et avant toute écriture. Une notification signée et valide
est rapprochée par `PaymentService`, qu'elle indique un succès ou un échec.

Payload attendu :

| Champ | Type | Requis | Description |
|---|---|---|---|
| `event` | `string` | Oui | type d'événement fourni par le provider |
| `transaction_id` | `string` | Oui | identifiant de transaction fournisseur |
| `status` | `string` | Oui | statut final ou intermédiaire retourné par le provider |
| `amount` | `integer` | Oui | montant payé en sous-unité |
| `currency` | `string` | Oui | devise de paiement |
| `signature` | `string` | Oui | signature vérifiée par le serveur |

Réponse attendue : `200` avec un objet minimal de confirmation :

```json
{
  "orderUuid": "44444444-5555-6666-7777-888888999999",
  "orderNumber": "ORD-20260930-0001",
  "status": "paid"
}
```

### Documentation OpenAPI

| Méthode | Endpoint | Accès |
|---|---|---|
| GET | `/api/documentation` | Public |
| GET | `/docs` | Public |

Payload : aucun.

Réponse attendue : document OpenAPI JSON ou vue HTML de la documentation de l’API.

L5-Swagger génère la documentation à partir des attributs OpenAPI de
`app/OpenApi/OpenApiSpec.php`. La documentation est publique et ne nécessite
pas de session.
