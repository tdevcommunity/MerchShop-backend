# Documentation de l'API MerchShop

Cette documentation décrit l'API Laravel du TDEV Festival 2026 : catalogue,
authentification, commandes, paiement et retrait.

Le fichier décrit l'implémentation actuelle. La spécification OpenAPI générée
par Scramble reste disponible sur `/docs/api` et `/docs/api.json`.

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

## 2. Endpoints

### Santé

| Méthode | Endpoint | Accès | Contrôleur | Fonction |
|---|---|---|---|---|
| GET | `/api/v1/health` | Public | `HealthController` | `__invoke` |

Retourne l'état de disponibilité de l'application et de la base de données.
Un état non prêt est rendu avec le statut HTTP correspondant, sans contourner
la sérialisation des ressources.

### Authentification

| Méthode | Endpoint | Accès | Contrôleur | Fonction |
|---|---|---|---|---|
| GET | `/api/v1/auth/csrf-token` | Public | `AuthController` | `csrfToken` |
| POST | `/api/v1/auth/register` | Public, `throttle:register` | `AuthController` | `register` |
| POST | `/api/v1/auth/login` | Public, `throttle:login` | `AuthController` | `login` |
| POST | `/api/v1/auth/logout` | Session requise | `AuthController` | `logout` |
| GET | `/api/v1/auth/me` | Session requise | `AuthController` | `me` |

#### `GET /auth/csrf-token`

Initialise la session et renvoie le token CSRF. Le token sert ensuite dans
`X-XSRF-TOKEN`. Cette route est volontairement publique et sans limitation de
débit renforcée.

#### `POST /auth/register`

Crée un compte client actif, hache son mot de passe et ouvre immédiatement une
session. Le client ne peut pas choisir le rôle ou le statut du compte.

Payload attendu : `firstname`, `lastname`, `phone`, `email`, `password`.
Réponse : `201` avec `UserResource`.

#### `POST /auth/login`

Vérifie `email` et `password`, puis ouvre la session. Le champ optionnel
`remember` demande une session persistante. Les erreurs d'email inconnu et de
mot de passe incorrect utilisent le même message afin d'éviter l'énumération
des comptes.

Réponse : `200` avec `UserResource`.

#### `POST /auth/logout`

Invalide la session et purge le token CSRF. Réponse `200` avec un message dans
`data`.

#### `GET /auth/me`

Retourne l'utilisateur authentifié courant avec `UserResource`.

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

`GET /categories/{uuid}` retourne une catégorie active ou une erreur `404`.

`POST /categories` accepte le payload validé par `StoreCategoryRequest`, crée
la catégorie et renvoie `201` avec `CategoryResource`.

`PUT /categories/{uuid}` accepte `UpdateCategoryRequest`, vérifie la policy,
met à jour la catégorie et renvoie `CategoryResource`.

`DELETE /categories/{uuid}` vérifie la policy puis supprime la catégorie. La
suppression échoue si les règles métier empêchent de supprimer une catégorie
encore utilisée.

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

Les produits inactifs ne sont pas visibles publiquement. Les jokers SQL sont
échappés dans la recherche.

`GET /products/{uuid}` retourne le produit avec sa catégorie et ses variantes
publiques. `GET /products/{uuid}/variants` retourne uniquement les variantes
publiques du produit.

`POST /products` et `PUT /products/{uuid}` utilisent respectivement
`StoreProductRequest` et `UpdateProductRequest`. Le service gère le slug, la
catégorie, les variantes, les prix en XOF, les SKU et la variante par défaut.

`DELETE /products/{uuid}` vérifie la policy puis supprime le produit selon les
règles de conservation des données et de ses variantes.

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

Réponse : `201` avec `OrderResource`. Le token invité n'est retourné qu'une
seule fois dans cette réponse et doit être envoyé ensuite dans l'en-tête
`X-Guest-Order-Token`.

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

`GET /orders/{uuid}` accepte soit la session du propriétaire, soit le token
d'une commande invitée. Une commande d'un autre compte ne peut pas être lue
par cette voie.

#### Annulation

`POST /orders/{uuid}/cancel` annule uniquement une commande encore annulable.
La transition et la restitution du stock sont vérifiées dans la même
transaction pour éviter une course avec un paiement.

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

`POST /pickup/scan` reçoit `payload` via `PickupScanRequest`. Le QR est résolu
et vérifié avant toute écriture. Une commande inexistante retourne `404` ; une
commande impayée, déjà retirée ou livrée retourne `409`.

`POST /orders/{uuid}/ready` fait passer une commande payée à l'état prête au
retrait.

`POST /orders/{uuid}/picked-up` marque une commande servie comme retirée. Cette
route permet un rattrapage manuel lorsque le scan est indisponible.

`GET /orders/{uuid}/qr` renvoie une image PNG. Une commande invitée doit fournir
`X-Guest-Order-Token`. La réponse utilise `Content-Type: image/png` et un cache
privé court.

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

La réponse `200` contient seulement `orderUuid`, `orderNumber` et `status`.
Une signature invalide retourne `401`, un payload mal formé `422`, et un
rapprochement impossible provoque une réponse non-2xx afin que l'opérateur
réessaie.

### Documentation OpenAPI

| Méthode | Endpoint | Accès |
|---|---|---|
| GET | `/docs/api` | Local ou administrateur actif |
| GET | `/docs/api.json` | Local ou administrateur actif |

Scramble génère la documentation à partir des routes, Form Requests et
Resources. L'accès est refusé à un compte staff, car le document décrit aussi
les opérations d'administration.

## 3. Modèles Eloquent

Les modèles portent la structure, les relations, les casts et les scopes ; les
règles de cas d'usage restent dans les services.

### `User`

Compte authentifiable, identifié publiquement par UUID. Champs sensibles
`password` et `remember_token` masqués. Relations : `orders()`.

Méthodes et scopes :

- `orders()` : commandes du compte ;
- `active()` : filtre les comptes actifs ;
- `staff()` : filtre staff et administrateurs ;
- `casts()` : convertit rôle et statut en enums, le mot de passe en hash et la
  date de vérification en date.

### `Category`

Catégorie du catalogue, avec relation `products()`. Le scope `active()` limite
le catalogue aux catégories actives. `casts()` convertit le statut en
`CatalogStatus`.

### `Product`

Produit du catalogue, avec `category()` et `variants()`. Le scope `active()`
retient les produits publiés. `casts()` convertit le statut en
`CatalogStatus`.

### `Variant`

Déclinaison vendable d'un produit, par exemple une taille ou une couleur.
Relations : `product()` et `orderItems()`.

- `active()` : variantes actives ;
- `inStock()` : variantes dont le stock est disponible ;
- `isAvailable()` : attribut calculé de disponibilité ;
- `casts()` : types de prix, stock et statut.

### `Order`

Commande d'un client ou commande invitée. Relations : `user()`, `items()`,
`products()`, `invoice()` et `payments()`.

- `isOwnedBy(?User $user)` : vérifie la propriété par session ;
- `awaitingPayment()` : scope des commandes en attente de paiement ;
- `forPickup()` : scope des commandes destinées au retrait ;
- `isPickedUp()` : attribut calculé ;
- `casts()` : statuts, méthode de livraison et dates.

### `OrderItem`

Ligne d'une commande. Relations : `order()`, `product()` et `variant()`.
`casts()` convertit les quantités, prix et totaux vers leurs types applicatifs.

### `Payment`

Tentative de paiement rattachée à une commande par `order()`.

- `successful()` : scope des paiements confirmés ;
- `isPaid()` : indique si le paiement est finalisé avec succès ;
- `casts()` : méthode, fournisseur et statut en enums.

### `Invoice`

Facture associée à une commande par `order()`. `casts()` convertit les montants
et dates utiles à la facturation.

## 4. Enums métier

- `CatalogStatus` : `INACTIVE = 0`, `ACTIVE = 1`.
- `FulfillmentMethod` : `pickup`, `delivery` ; `requiresPickupQrCode()` indique
  si un QR de retrait est nécessaire.
- `OrderStatus` : `PENDING_PAYMENT = 1`, `PAID = 2`, `READY_FOR_PICKUP = 3`,
  `PICKED_UP = 4`, `CANCELLED = 5`, `REFUNDED = 6` ; `isSettled()` indique un
  état financier réglé.
- `PaymentMethod` : `mobile_money`, `card`.
- `PaymentProvider` : `fedapay`, `kkiapay`, `paygate`, `flooz`, `tmoney`.
- `PaymentStatus` : `PENDING = 1`, `SUCCESS = 2`, `FAILED = 3`, `REFUNDED = 4` ;
  `isFinal()` indique qu'aucune nouvelle tentative ne doit modifier le statut.
- `PickupStatus` : `pending`, `picked_up`, `cancelled`.
- `UserRole` : `customer`, `staff`, `admin` ; `canOperateMerch()` indique si le
  compte peut opérer le guichet.
- `UserStatus` : `INACTIVE = 0`, `ACTIVE = 1` ; `isActive()` indique si le
  compte peut se connecter et agir.

## 5. Contrôleurs

Les contrôleurs traduisent HTTP vers les services : ils valident l'entrée via
un Form Request, autorisent l'action avec une policy et sérialisent la sortie
via une Resource.

### `ApiController`

Base commune des contrôleurs API.

- `perPage(Request)` : lit `per_page` et applique les bornes de configuration ;
- `jsonResource(JsonResource, status)` : force le statut HTTP tout en gardant
  la normalisation des ressources.

### `HealthController`

`__invoke(SystemHealthService)` expose la readiness de l'application.

### `AuthController`

Injecte `AuthService` et expose `csrfToken`, `register`, `login`, `logout` et
`me`. Il ne renvoie jamais de token bearer.

### `CategoryController`

Injecte `CategoryService`. `index` et `show` lisent le catalogue ; `store`,
`update` et `destroy` sont réservées à l'administration.

### `ProductController`

Injecte `ProductService`. `index` filtre et pagine le catalogue ; `show` et
`variants` exposent le détail ; `store`, `update` et `destroy` administrent les
produits. `filterCategoryId` et `filterSearch` normalisent les filtres HTTP.

### `OrderController`

Injecte `OrderService` et `OrderAccess`. `store`, `index`, `show` et `cancel`
gèrent le parcours client. `authorizeRead` choisit entre session et token
invité ; `filterStatus` transforme et valide le filtre de statut.

### `OrderPickupController`

Injecte `OrderService`, `PickupQrCodeService` et `OrderAccess`. Il expose la
file de retrait, le passage à `ready`, le scan, le retrait manuel et le rendu
PNG du QR.

### `PaymentWebhookController`

Injecte `PaymentService` et `WebhookSignatureVerifier`. `handle` vérifie la
signature, transforme le statut opérateur en enum et délègue le rapprochement.

## 6. Services métier

Les services contiennent les cas d'usage et les transactions. Ils ne
construisent pas de réponse HTTP et dépendent des interfaces de repositories.

### `SystemHealthService`

- `readiness()` : agrège l'état de l'application et de la base ;
- `databaseIsReachable()` : vérifie la connexion via le repository santé.

### `AuthService`

- `register(Request, array)` : crée un client actif, hache le mot de passe et
  ouvre la session ;
- `attempt(Request, email, password, remember)` : vérifie les identifiants,
  protège contre l'énumération et ouvre la session ;
- `logout(Request)` : invalide la session et journalise l'événement.

Les méthodes privées ouvrent la session, égalisent le coût de vérification avec
un hash factice et journalisent les tentatives d'authentification.

### `CategoryService`

- `listCatalog(perPage)` : pagination des catégories publiques ;
- `findOrFail(uuid)` : recherche publique ;
- `findForManagementOrFail(uuid)` : recherche incluant les données de gestion ;
- `create(data)` : crée une catégorie avec slug résolu ;
- `update(category, data)` : met à jour les données et le slug ;
- `delete(category)` : supprime la catégorie selon les contraintes métier.

### `ProductService`

- `listCatalog(perPage, filters)` : liste les produits visibles avec filtres ;
- `findOrFail(uuid)` : charge un produit public avec ses relations ;
- `findForManagementOrFail(uuid)` : charge un produit pour l'administration ;
- `listVariants(product)` : liste les variantes publiques ;
- `create(data)` : crée un produit, son slug et ses variantes ;
- `update(product, data)` : met à jour le produit et synchronise ses variantes ;
- `delete(product)` : supprime le produit selon les règles métier.

Les helpers internes gèrent les prix en montant entier, les SKU, les variantes
par défaut, la catégorie et l'unicité des slugs.

### `OrderService`

- `create(data)` : crée une commande transactionnelle et réserve le stock ;
- `listForUser(user, perPage, status)` : liste uniquement les commandes du
  compte ;
- `listForPickup(perPage)` : construit la file de retrait ;
- `findOrFail(id)` : charge une commande avec ses relations ;
- `cancel(order)` : annule et restitue le stock ;
- `markPaid(order)` : passe la commande au statut payé ;
- `markReadyForPickup(order)` : la rend prête au guichet ;
- `markPickedUp(order)` : enregistre le retrait ;
- `refund(order)` : gère le remboursement et ses transitions ;
- `canTransition(order, target)` : teste une transition ;
- `assertCanTransition(order, target)` : refuse une transition invalide ;
- `allowedTransitions(order)` : retourne les transitions permises.

Les helpers privés verrouillent les variantes, calculent les lignes, contrôlent
le stock, créent les lignes, libèrent le stock et garantissent l'unicité du
numéro de commande.

### `PaymentService`

- `handleNotification(notification)` : rapproche une notification opérateur,
  crée ou retrouve la tentative de paiement et fait évoluer la commande ;
- `confirm(payment)` : confirme le paiement et ses effets ;
- `reject(payment, reason)` : enregistre un paiement refusé ;
- `attachTransaction(payment, notification)` : rattache la référence opérateur ;
- `outstandingAmount(order)` : calcule le montant restant à payer.

### `InvoiceService`

- `issueFor(order)` : émet une facture unique pour une commande ;
- `generateInvoiceNumber(order)` : produit une référence de facture unique.

### `PickupQrCodeService`

- `grant(order)` : accorde un droit de retrait et son secret ;
- `hasPickupRight(order)` : indique si la commande dispose de ce droit ;
- `renderPng(order)` : fabrique le QR en PNG ;
- `encodePayload(order)` : encode le contenu du QR ;
- `resolveOrder(token)` : retrouve une commande depuis un token ;
- `resolveOrderFromPayload(payload)` : retrouve une commande depuis un scan ;
- `assertServable(order)` : vérifie qu'une commande peut être servie.

## 7. Repositories

Les repositories isolent Eloquent des services. Les contrats se trouvent dans
`app/Repositories/Contracts` et leurs implémentations dans
`app/Repositories/Eloquent`. Les liaisons interface -> implémentation sont
déclarées dans `AppServiceProvider`.

### Contrat générique

`RepositoryInterface` définit `query`, `find`, `findOrFail`, `all`, `paginate`,
`create`, `update` et `delete`.

`EloquentRepository` implémente ces opérations, utilise la clé de route du
modèle et ne porte aucune règle métier.

### Repositories spécialisés

- `HealthRepositoryInterface` / `EloquentHealthRepository` :
  `pingDatabase()` vérifie la disponibilité de la base.
- `UserRepositoryInterface` / `EloquentUserRepository` :
  `findByEmail(email)` retrouve un compte pour la connexion.
- `CategoryRepositoryInterface` / `EloquentCategoryRepository` :
  `findBySlug`, `findById`, `findForCatalog`, `findForManagement`,
  `paginateForCatalog` et `slugExists`.
- `ProductRepositoryInterface` / `EloquentProductRepository` :
  `findWithRelations`, `findForCatalog`, `paginateForCatalog`, `findBySlug`,
  `slugExists` et `countActiveByCategory`.
- `VariantRepositoryInterface` / `EloquentVariantRepository` :
  `forProduct`, `forPublicCatalog`, `findForProduct`, `findBySku`,
  `findTrashedForProductBySku`, `deleteAllForProduct`, `lockForSale` et
  `restock`.
- `OrderRepositoryInterface` / `EloquentOrderRepository` :
  `findWithRelations`, `paginateForUser`, `paginateForPickup`,
  `findByPickupTokenHash` et `orderNumberExists`.
- `PaymentRepositoryInterface` / `EloquentPaymentRepository` :
  `findWithOrder`, `findByTransactionId`, `forOrder`, `attachTransactionId` et
  `settledAmount`.
- `InvoiceRepositoryInterface` / `EloquentInvoiceRepository` :
  `findForOrder` et `invoiceNumberExists`.

## 8. Validation, resources et sécurité transverses

### Form Requests

`ApiRequest` transforme toute erreur de validation en réponse JSON `422` et
expose `body()` pour fournir au contrôleur le payload validé.

Les classes `LoginRequest`, `RegisterRequest`, `StoreCategoryRequest`,
`UpdateCategoryRequest`, `StoreProductRequest`, `UpdateProductRequest`,
`StoreOrderRequest`, `PickupScanRequest` et
`PaymentNotificationRequest` définissent les autorisations et règles d'entrée.
`ProductPayloadRules` partage les règles des produits et vérifie la cohérence
des variantes.

### Resources

`ApiResource` et `ApiResourceCollection` constituent la base de sérialisation.
`NormalizesResponseKeys` convertit les clés de sortie en camelCase.

Les resources disponibles sont `HealthResource`, `UserResource`,
`CategoryResource`, `ProductResource`, `VariantResource`, `OrderResource`,
`OrderItemResource`, `PaymentResource` et `InvoiceResource`.
`OrderResource` conditionne les données selon l'accès du lecteur et peut
ajouter temporairement le token invité lors de la création.

### Policies

- `CategoryPolicy` et `ProductPolicy` délèguent la gestion du catalogue au
  comportement commun `ManagesCatalog` et la réservent à l'administrateur.
- `OrderPolicy` contrôle la lecture par propriétaire, l'annulation et les
  opérations du guichet.

### Support

- `ApiErrorResponder` : enveloppe uniforme des erreurs API ;
- `CamelCase` : conversion unique des clés JSON ;
- `Money` : normalisation des montants ;
- `OrderAccess` : émission et vérification du token de commande invitée ;
- `WebhookSignatureVerifier` : validation des signatures de paiement ;
- `PriceInXof` : règle de validation des prix en francs CFA.

### Rate limiting

`AppServiceProvider` configure les limiteurs `api`, `login`, `register`,
`checkout` et `webhook`. L'API générale distingue l'adresse IP du compte
connecté ; les flux sensibles ont des limites spécifiques.

## 9. Flux principaux

### Client connecté

```text
GET csrf-token
  -> POST register ou login
  -> GET products / categories
  -> POST orders
  -> GET orders/{uuid}
  -> paiement via webhook opérateur
  -> retrait du QR au guichet
```

### Client invité

```text
GET csrf-token
  -> POST orders
  -> conserver X-Guest-Order-Token
  -> GET orders/{uuid} avec l'en-tête
  -> GET orders/{uuid}/qr avec l'en-tête
```

### Guichet

```text
GET pickup/orders
  -> POST orders/{uuid}/ready
  -> POST pickup/scan
  -> POST orders/{uuid}/picked-up
```

### Opérateur de paiement

```text
POST payments/webhooks/{provider}
  -> vérification de signature
  -> validation du payload
  -> rapprochement idempotent du paiement
  -> évolution de la commande
```
