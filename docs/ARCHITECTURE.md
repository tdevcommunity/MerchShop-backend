# Architecture de l'API — MerchShop Backend

Document de référence pour l'API Laravel située dans [`merch_shop_api/`](../merch_shop_api).
Il décrit les couches, la règle de dépendance et la marche à suivre pour ajouter une ressource.

> Documents liés : [`NAMING_CONVENTIONS.md`](./NAMING_CONVENTIONS.md) (nommage),
> [`CODE_STANDARDS.md`](./CODE_STANDARDS.md) (qualité, sécurité, tests),
> [`CHANTIER-2-SHOP-MERCH.md`](./CHANTIER-2-SHOP-MERCH.md) (spécifications métier).

---

## 1. Stack retenue pour l'API

| Élément | Choix |
|---|---|
| Framework | Laravel 13 (PHP 8.3+) |
| Base de données | PostgreSQL en local ; **PostgreSQL** en production (`.env` de `merch_shop_api`) |
| Authentification | **Sessions Laravel** + CSRF (voir §7) |
| Format | JSON, `camelCase` en réponse ; entrée et paramètres d'URL en `snake_case` |
| Versionnement d'URL | `/api/v1/...` |

---

## 2. Vue d'ensemble des couches

```
   Requête HTTP
        │
        ▼
┌───────────────────────────────────────────────┐
│ PRÉSENTATION                                  │
│  Http/Requests      validation de l'entrée    │
│  Http/Controllers   traduction HTTP ↔ métier  │
│  Http/Resources     formatage camelCase      │
└───────────────────┬───────────────────────────┘
                    │ appelle
┌───────────────────▼───────────────────────────┐
│ CAS D'USAGE                                    │
│  Services        règles métier, transactions  │
└───────────────────┬───────────────────────────┘
                    │ dépend de
┌───────────────────▼───────────────────────────┐
│ ACCÈS AUX DONNÉES                              │
│  Repositories/Contracts   interfaces (abstractions) │
│  Repositories/Eloquent    implémentations Laravel    │
└───────────────────┬───────────────────────────┘
                    │ persiste
┌───────────────────▼───────────────────────────┐
│ PERSISTANCE                                    │
│  Models          Eloquent (colonnes, relations)    │
└───────────────────────────────────────────────┘
```

**Règle de dépendance : les dépendances pointent vers l'intérieur.** Un contrôleur ne
connaît que les services ; un service ne connaît que les interfaces de repository ;
seul `Repositories/Eloquent` connaît Eloquent. Une couche ne remonte jamais vers
l'extérieur (un service n'appelle jamais un contrôleur, un repository ne lit jamais
une requête HTTP).

**Conséquence pratique :** un contrôleur ne contient ni requête Eloquent ni règle
métier. S'il faut y ajouter l'une des deux, c'est que la logique est mal placée.

---

## 3. Arborescence

```
merch_shop_api/
├── app/
│   ├── Enums/                           enums backed (statuts, roles, moyens de paiement)
│   ├── Exceptions/
│   │   └── ApiException.php            erreur métier typée (statut + code stable)
│   ├── Http/
│   │   ├── Controllers/
│   │   │   └── Api/
│   │   │       ├── ApiController.php    base des contrôleurs (perPage, jsonResource)
│   │   │       └── V1/                  un dossier par version d'URL
│   │   ├── Requests/
│   │   │   └── ApiRequest.php           base des FormRequest
│   │   └── Resources/
│   │       ├── ApiResource.php          base : normalisation camelCase
│   │       └── *Resource.php            une ressource par entité exposée
│   ├── Models/                          Eloquent — persistance uniquement
│   │   └── Concerns/                    traits partagés (HasPublicIdentifier)
│   ├── OpenApi/
│   │   └── CamelCasesResourceProperties.php  aligne la spec sur le JSON camelCase
│   ├── Providers/
│   │   └── AppServiceProvider.php       liaisons contrat → implémentation, rate limiting, docs
│   ├── Repositories/
│   │   ├── Contracts/                   interfaces (dépendances des services)
│   │   └── Eloquent/                    implémentations
│   ├── Services/                        cas d'usage
│   └── Support/Api/                     transverse : enveloppe d'erreur, camelCase
├── config/api.php                       version, pagination, quota de débit
├── config/cors.php                      origines autorisées (variables d'env)
├── config/scramble.php                  génération OpenAPI, sécurité, accès UI
├── api.json                             export OpenAPI versionné
└── routes/api.php                       point d'entrée, groupes de version
```

> **Identifiant public.** Chaque entité métier porte un `id` (PK interne) et un `uuid`
> (colonne unique) généré par le trait `App\Models\Concerns\HasPublicIdentifier`. L'API
> adresse les ressources par `uuid` (`#[RouteKey('uuid')]`), ce qui évite de fuiter
> l'identifiant interne et permet aux systèmes partenaires (app de scan) de conserver
> une référence stable.
>
> **Statuts.** Les colonnes `status` / `role` / `method` / `provider` posées en entier
> ou chaîne par la migration sont castées en enums PHP (`app/Enums/`) : les valeurs
> métier sont nommées dans le code plutôt qu'écrites en littéraux.

> **Nommage.** `NAMING_CONVENTIONS.md` §3 demande des dossiers au singulier, avec la
> mention « à affiner avec la stack ». La stack étant désormais Laravel, on retient le
> **pluriel** (convention du framework) : `Services/`, `Repositories/`, `Resources/`.
> Ce point reste à entériner par une PR sur le document de conventions.
>
> Les **fichiers de classes** sont en `PascalCase` : PSR-4 impose que le nom de fichier
> corresponde exactement au nom de la classe. Le kebab-case reste la règle pour les
> fichiers qui ne sont pas des classes (routes, docs, configuration, migrations).

---

## 4. Contrat d'API

### 4.1 URLs

- Base : `/api/v1`
- Ressources au **pluriel**, en `kebab-case` : `/api/v1/products`, `/api/v1/orders`
- Aucun verbe dans l'URL : les verbes HTTP suffisent
- Pagination : `?page=1&per_page=15` (`per_page` borné par `config/api.php`)

### 4.2 Réponse succès

Ressource unique :

```json
{
  "data": {
    "id": 12,
    "productName": "T-shirt TDEV",
    "stockQuantity": 42
  }
}
```

Liste paginée :

```json
{
  "data": [ /* ... */ ],
  "links": { "first": "...", "last": "...", "prev": null, "next": "..." },
  "meta": { "currentPage": 1, "lastPage": 3, "perPage": 15, "total": 42, "from": 1, "to": 15 }
}
```

### 4.3 Réponse d'erreur

Toutes les erreurs — métier, validation, framework — partagent la même forme :

```json
{
  "error": {
    "code": "VALIDATION_ERROR",
    "message": "Les données envoyées sont invalides.",
    "details": { "fields": { "quantity": ["Le champ quantity doit être au moins 1."] } }
  }
}
```

| `code` | Statut | Origine |
|---|---|---|
| `VALIDATION_ERROR` | 422 | FormRequest |
| `UNAUTHENTICATED` | 401 | Session absente |
| `ACCOUNT_DISABLED` | 403 | Compte désactivé (connexion) |
| `FORBIDDEN` | 403 | Rôle insuffisant (policy) |
| `NOT_FOUND` | 404 | Ressource inexistante |
| `CATEGORY_NOT_FOUND` | 404 | Catégorie absente, masquée ou supprimée |
| `PRODUCT_NOT_FOUND` | 404 | Produit absent, masqué ou supprimé |
| `INVALID_FILTER` | 422 | Paramètre de liste mal typé |
| `DUPLICATED_VARIANT_SKU` | 422 | Même SKU répété dans la charge utile |
| `VARIANT_NOT_IN_PRODUCT` | 422 | Variante inconnue ou appartenant à un autre produit |
| `SLUG_ALREADY_EXISTS` | 409 | Slug déjà pris par une autre ressource |
| `SKU_ALREADY_EXISTS` | 409 | SKU déjà porté par un autre produit |
| `CATEGORY_NOT_EMPTY` | 409 | Suppression refusée, produits actifs encore rattachés |
| `METHOD_NOT_ALLOWED` | 405 | Verbe HTTP non supporté |
| `INSUFFICIENT_STOCK` | 409 | `ApiException` métier |
| `RATE_LIMIT_EXCEEDED` | 429 | Quota de débit |
| `HTTP_ERROR` | variable | Exception HTTP du framework non listée ci-dessus |
| `INTERNAL_ERROR` | 500 | Exception inattendue |

`code` est la **seule** valeur sur laquelle un client doit brancher sa logique ;
`message` est destiné à l'affichage humain et peut être reformulé.

Le 403 mérite une note : Laravel réemballe `AuthorizationException` dans une
`AccessDeniedHttpException` avant le rendu. `ApiErrorResponder` teste donc les
deux classes, sans quoi tout refus d'accès partirait en `HTTP_ERROR` et le
front ne distinguerait plus « pas connecté » (401) de « rôle insuffisant » (403).

### 4.4 Conventions de casse

| Contexte | Format | Exemple |
|---|---|---|
| JSON entrant | `snake_case` | `category_id`, `price_from` |
| JSON sortant | `camelCase` | `categoryId`, `priceFrom` |
| Paramètres d'URL | `snake_case` | `?per_page=24&category_id=3` |
| Tables et colonnes | `snake_case` pluriel | `orders`, `order_items.unit_price` |
| Fichiers de classes | `PascalCase` (PSR-4) | `OrderRepository.php` |

La conversion `snake_case` → `camelCase` est faite par `ApiResource` (sorties) et par
`ApiErrorResponder` (enveloppe d'erreur), au niveau de la couche présentation. Elle ne
touche ni les modèles, ni les files d'attente, ni le cache.

**Exception : les clés de `error.details.fields`.** Elles ne sont pas de la donnée à
présenter mais une référence aux champs que le client a envoyés, pour surligner le
mauvais input. Les normaliser casserait la correspondance — un client qui envoie
`category_id` recevrait `categoryId`, introuvable dans sa propre requête. Elles sont
donc renvoyées telles quelles.

---

## 5. Ajouter une ressource

Checklist complète, dans cet ordre :

1. **Migration** — table `snake_case` au pluriel, clés étrangères `<table>_id`, index sur
   les colonnes filtrées (`config/database.php` ou une migration dédiée).
2. **Modèle** — `app/Models/` : relations, casts, `#[Fillable]`. **Aucune règle métier.**
3. **Contrat** — `app/Repositories/Contracts/XRepositoryInterface.php` :
   ```php
   interface ProductRepositoryInterface extends RepositoryInterface
   {
       public function findBySlug(string $slug): ?Product;
   }
   ```
   Séparer la lecture publique de la lecture de gestion dès ce stade :
   `findForCatalog()` filtre sur le statut, `findForManagement()` l'ignore. Les
   confondre casse soit la publication, soit la réactivation (voir §8).
4. **Implémentation** — `app/Repositories/Eloquent/EloquentProductRepository.php` :
   ```php
   final class EloquentProductRepository extends EloquentRepository implements ProductRepositoryInterface
   {
       protected function modelClass(): string
       {
           return Product::class;
       }
   }
   ```
5. **Liaison** — dans `AppServiceProvider::register()` :
   `$this->app->bind(ProductRepositoryInterface::class, EloquentProductRepository::class);`
6. **Service** — `app/Services/ProductService.php`, un cas d'usage par méthode.
7. **Requête de validation** — `app/Http/Requests/Api/V1/StoreProductRequest.php`
   (hérite de `ApiRequest`, déclare `authorize()` et `rules()`).
8. **Ressource** — `app/Http/Resources/ProductResource.php` (hérite de `ApiResource`).
9. **Contrôleur** — `app/Http/Controllers/Api/V1/ProductController.php` (hérite de
   `ApiController`) : résout le service, retourne une ressource. Une route
   d'écriture résout par `findForManagementOrFail()`.
10. **Route** — dans le groupe `v1` de `routes/api.php`, écritures sous
    `auth` + policy.
11. **Tests** — `tests/Feature/Api/V1/...` (parcours nominal, cas d'erreur, cas limite) et
    `tests/Unit/Services/...` (règles métier avec un repository simulé). Toute ressource
    publiable est testée sur ses deux lectures : visible quand active, `404` quand
    masquée, et malgré tout réactivable.

---

## 6. Sécurité

- **Toute entrée est validée par un `ApiRequest`.** Un contrôleur qui lit `$request->all()`
  ou `$request->input()` sans passer par un FormRequest viole les standards de l'équipe.
- **Le client n'est jamais une source de vérité** sur les montants, les quantités, les
  statuts de paiement ou le contenu d'un QR Code : ces valeurs sont recalculées côté
  serveur à partir de la base.
- **Erreurs génériques côté client.** Le détail technique (trace, SQL, chemin interne) part
  dans les logs serveur. `APP_DEBUG=false` est obligatoire en staging et en production.
- **Quota de débit** actif sur toutes les routes `/api/*` (`throttle:api`).
- **CORS** restreint par `CORS_ALLOWED_ORIGINS` — jamais `*` en production, et
  `CORS_SUPPORTS_CREDENTIALS=true` tant que l'authentification est par session.
- **Une ressource masquée n'est pas servie publiquement**, pas même par son URL.
- **Cloisonnement des tests** : `phpunit.xml` force SQLite en mémoire, donc la suite ne
  touche jamais la base de développement. Laravel contourne la vérification CSRF en
  mode test : la couverture réelle du CSRF est donc à faire hors de la suite
  automatisée ou via un test de configuration des middlewares.
- **La documentation OpenAPI n'est pas publique.** Elle décrit les routes d'écriture du
  back-office, leurs payloads et leurs contraintes : la lire doit exiger le même
  pouvoir que les appeler. Accès limité à l'environnement `local` et aux
  administrateurs actifs (gate `viewApiDocs`), `staff` exclu.

---

## 7. Authentification par session

Le choix est arrêté : **sessions Laravel**, sans Sanctum ni JWT. Le shop est un
site marchand classique, avec un back-office derrière la même origine : la
session est le modèle qui correspond, et un jeton à stocker côté navigateur
n'apporte rien ici.

`statefulApi()` de Sanctum n'est pas utilisé (le paquet n'est pas installé) ; le
groupe `api` reçoit les mêmes middlewares à la main dans `bootstrap/app.php` :

`EncryptCookies` → `AddQueuedCookiesToResponse` → `StartSession` →
`PreventRequestForgery` → `throttle:api`.

CSRF et CORS forment un couple : une requête cross-origin qui porte des cookies
n'est acceptée que si elle prouve qu'elle connaît le jeton.

| Élément | Comportement |
|---|---|
| Amorçage | `GET /api/v1/auth/csrf-token` pose le cookie `XSRF-TOKEN` et renvoie le jeton |
| Envoi client | `credentials: 'include'`, en-tête `X-XSRF-TOKEN` recopié depuis le cookie |
| Enregistrement | `POST /api/v1/auth/register` — rôle forcé à `customer`, statut forcé à `active` |
| Connexion | `POST /api/v1/auth/login` — message identique pour email inconnu et mot de passe faux |
| Fixation de session | L'identifiant de session est renouvelé à la connexion |
| Déconnexion | `POST /api/v1/auth/logout` invalide la session et régénère le jeton |
| Quotas | `throttle:login` et `throttle:register`, distincts du quota `api` |

Configuration : `SESSION_DRIVER`, `SESSION_SECURE_COOKIE`, `SESSION_SAME_SITE`,
`CORS_ALLOWED_ORIGINS`, `CORS_SUPPORTS_CREDENTIALS` (voir `.env.example`).
`CORS_ALLOWED_ORIGINS` doit lister les origines explicitement — `*` est refusé
car il est incompatible avec l'envoi de cookies.

### 7.1 Commande invitée : un jeton d'accès, pas une session

Le passage de commande est public : un festival vend à des visiteurs qui
n'ont pas forcément de compte, et exiger une inscription avant d'ajouter au
panier ferait perdre une part importante des ventes. Mais les routes de lecture
passent par une session, donc cette commande invitee serait orpheline : son
auteur ne pourrait ni la relire, ni afficher le QR que son paiement vient
d'ouvrir.

`App\Support\Orders\OrderAccess` comble ce manque par un jeton d'accès :

| Caractéristique | Choix | Pourquoi |
|---|---|---|
| Émis | une fois, à la création, et seulement si `user_id` est nul | un client connecté a déjà sa session ; lui en donner une seconde créerait une identité de plus à divulguer |
| Entropie | 32 octets aléatoires (`Str::random`) | un jeton calculé depuis l'uuid de la commande serait recalculable par quiconque connaît cet uuid |
| Stockage | empreinte SHA-256 seule | une fuite de la base ne doit pas suffire à lire une commande |
| Présentation | renvoyé une seule fois, via la ressource | il n'est pas dans le modèle, donc ne ressort pas sur les lectures suivantes |
| Usage | en-tête `X-Order-Token` sur `GET /orders/{uuid}` et `GET /orders/{uuid}/qr` | il ouvre la lecture, rien d'autre |
| Portée | la commande visée par l'uuid de l'URL | l'empreinte comparée est celle de cette commande, pas celle de l'ordre de la requête |
| Fin de validité | le jour où la commande est rattachée à un compte | un jeton ne doit pas survivre au changement de propriétaire |

Le jeton n'est pas un rôle : c'est une clé porteuse. `OrderPolicy` reste le
seul juge des droits attaches à un compte et au personnel, et le contrôleur
n'appelle la policy que si le jeton n'a rien ouvert. Un jeton ne donne donc
jamais accès à une transition (`cancel`, `ready`, `picked-up`), qui reste
derrière `auth`.

Conséquence sur le découpage : `GET /orders/{uuid}` et `GET /orders/{uuid}/qr`
sont hors du groupe `auth`, sinon le jeton d'un invite serait rejeté avant d'être
regardé. Une requête sans session ni jeton reçoit un 403, pas un 401.

### 7.2 Devise : le franc CFA, et pas un centime

Le festival ne vend qu'en francs CFA (XOF). Cette devise n'a pas de
subdivision : un montant payable est toujours un nombre entier de francs. Le
choix du franc CFA comme seule et unique devise est donc structurel dans le code,
et non une convention implicite.

| Conséquence | Choix | Raison |
|---|---|---|
| Type des colonnes de prix et de montants | `unsignedBigInteger` | `decimal(12,2)` autoriserait des centimes qui n'existent pas |
| Coût de validation des prix | `PriceInXof` | un montant non entier est refusé, avec un message qui dit *pourquoi* |
| Écriture en base | `ProductService::priceAsAmount()` | la validation tolère « 2500,00 », la colonne, elle, est entière |
| Arrondi | `Money::roundToPayable()` | une remise en pourcentage produit un résultat non entier : il faut choisir où cela s'arrête |
| Devise | pas de colonne `currency` | une seule devise : la colonne serait une réponse à une question que ce projet ne pose pas |

Aucune valeur n'est stockée en flottant. Le franc CFA rend la question sans
objet là où elle bites le plus souvent — les centimes perdues dans une somme —
puisqu'il n'y a rien à perdre.

Un montant non entier est **refusé** et non arrondi. Un arrondi à cet endroit
produirait un prix que personne n'a choisi, et l'écart ne se découvrirait qu'au
moment du paiement, sur l'écran du guichetier. Le seul endroit où un arrondi est
légitime est le calcul d'une remise, et il est nommé.

### 7.3 Livraison : gratuite, donc sans ligne de frais

Il n'y a pas de frais de port. Une commande livrée vaut exactement son
sous-total, et `shippingCost` n'existe pas dans le contrat : une ligne à zéro
affichée à un client sur un ticket est une ligne qu'il va interpréter comme un
montant à régler. La colonne a disparu de `orders` et `invoices` avec elle.

Le mode de retrait reste, car il décrit *comment* la commande est servie, pas ce
qu'elle coûte : une commande livrée n'a ni QR de retrait, ni passage au guichet.

---

## 8. Catalogue : lecture publique, écriture admin

La lecture du catalogue est publique ; toute écriture passe par le rôle `admin`
(voir `app/Policies/Concerns/ManagesCatalog.php`).

| Méthode | Route | Accès |
|---|---|---|
| `GET` | `/api/v1/categories`, `/api/v1/products` | public |
| `GET` | `/api/v1/categories/{uuid}`, `/api/v1/products/{uuid}` | public |
| `GET` | `/api/v1/products/{uuid}/variants` | public |
| `POST` | `/api/v1/categories`, `/api/v1/products` | admin |
| `PUT` / `DELETE` | idem, par `uuid` | admin |

Trois règles de visibilité, à appliquer à chaque ressource de catalogue :

1. **Lecture publique filtrée sur le statut.** Une ressource masquée
   (`status = inactive`) disparaît de la liste *et* de son URL : la servir par
   lien direct viderait le sens d'un retrait. Les écritures, elles, passent par
   une relecture de gestion qui ignore le statut — sinon une ressource masquée
   deviendrait impossible à réactiver.
2. **Le filtre de statut ne vaut pas pour les opérations d'écriture.** La
   synchronisation des variantes voit toutes les lignes, y compris masquées :
   sinon une variante masquée passerait pour inconnue et ne pourrait plus être
   ni mise à jour ni supprimée.
3. **Le prix « à partir de » ignore les variantes épuisées et masquées.** Un
   montant que le visiteur ne peut pas commander ne doit pas être annoncé.

### Synchronisation des variantes

`variants` absent → les variantes ne sont pas touchées. `variants` présent, y
compris `[]` → la liste reçue fait foi, et ce qui n'y figure plus est supprimé
logiquement. Une variante entrante est reconnue par `uuid`, à défaut par `sku`.

Ce contrat autorise les deux usages réels sans qu'ils s'excluent : renommer un
produit sans toucher à ses déclinaisons, et redéfinir toutes les déclinaisons
en un appel. Le SKU est conservé lors d'une suppression logique, l'index unique
portant sur la seule colonne `sku` : une variante réintroduite est restaurée
plutôt que dupliquée.

---

## 9. Documentation OpenAPI

La spécification est **générée depuis le code** par
[Scramble](https://scramble.dedoc.co) : aucun document n'est écrit à la main, donc
la documentation ne peut pas diverger des contrôleurs.

| Ressource | Rôle |
|---|---|
| `/docs/api` | interface Swagger (différenciateur `SCRAMBLE_DEV_TOOLS`) |
| `/docs/api.json` | document OpenAPI 3.1 servi par l'application |
| `api.json` | export versionné, pour les clients générés hors PHP |

`php artisan scramble:export` régénère l'export ; `scramble:clear` invalide le cache.

### 9.1 Sécurité déclarée

L'authentification est par cookie de session, pas par jeton. La stratégie de sécurité
détecte le middleware `auth` et déclare un schéma `apiKey` en `cookie` — un schéma
`bearer`, par défaut dans Scramble, induirait le client en erreur en lui faisant
envoyer un en-tête `Authorization` que le serveur ignore. Les routes sans `auth`
sont explicitement marquées publiques (`security: []`).

### 9.2 Asymétrie de casse, et son effet sur la génération

Le contrat est asymétrique (§ 4.4) : les requêtes sont en `snake_case`, les
réponses en `camelCase`. Or la conversion camelCase intervient à la **sérialisation**
(`NormalizesResponseKeys`), donc après l'analyse statique des ressources : la
génération verrait des schémas en `snake_case`, décrivant un payload que l'API
n'émet jamais.

`App\OpenApi\CamelCasesResourceProperties` réapplique donc la conversion en fin de
génération. Il ne touche que les composants `*Resource` : les `*Request` restent en
`snake_case`, conformément au contrat d'entrée. Il retire aussi des `required` les
propriétés nullables, qu'inférence liste par erreur comme obligatoires.

Les deux chemins partagent `App\Support\Api\CamelCase::key()` : une règle appliquée
au JSON et une autre à sa description rendraient le contrat décrit faux.

### 9.3 Attributs de documentation : où ils se placent

- `#[QueryParameter]` et `#[Response]` doivent être posés sur **l'action de route**.
  Scramble n'inspecte que la méthode de route (`reflectionAction()`) : un attribut
  posé sur une méthode privée appelée par l'action n'est jamais collecté.
- Les enveloppes de réponse (`links`, `meta` de pagination) ne sont pas inférées :
  elles sont déclarées explicitement, sinon la spécification ne décrirait que
  `data`.
- Les filtres lus via `$request->query()` sont invisibles de l'inférence et doivent
  être déclarés, même s'ils sont invalidés à la main dans le contrôleur.

---

## 10. Points ouverts

| Sujet | Statut | Impact |
|---|---|---|
| Transaction et décrémentation de stock | Traité | `OrderService::create()` réserve le stock dans la même transaction que la commande, sous `lockForUpdate`, les variantes étant verrouillées par identifiant interne trié pour éviter les interblocages. Non testé en concurrence réelle : SQLite ignore `lockForUpdate`, seul PostgreSQL l'applique. |
| Webhooks de paiement | Traité | Une route par agrégateur, hors session et hors CSRF, dont la signature HMAC-SHA256 du corps brut est vérifiée avant toute validation. Le rapprochement est idempotent : un rejeu ne refacture pas et ne réémet pas de facture. |
| Chiffrement du QR Code de retrait | Traité | Le contenu porte l'uuid de commande et une empreinte HMAC de cet uuid, dérivée de `PICKUP_TOKEN_SECRET`. Seul l'empreinte est stockée ; le contenu est ré-générable. Un secret absent ferme la route de génération. |
| Génération du QR Code | Traité | Rendu à la volée en PNG par `endroid/qr-code`, sans stockage : le contenu est déterministe, donc l'image est reproductible et n'a pas à être purgée. Requiert l'extension `ext-gd`, déclarée dans `composer.json`. |
| Livraison : statut d'expédition | À concevoir | `OrderStatus` ne distingue pas « expédiée » de « payée » : une commande livrée reste en `PAID` jusqu'au retrait éventuel, faute d'événement de transport dans le plan de tracking. |
| Accès aux commandes invitées | Traité | Une commande sans compte reçoit un jeton d'accès à la création, qui donne accès à cette seule commande et à son QR. Il n'ouvre aucune transition, et cesse d'être valable si la commande est rattachée à un compte. |
| Facture downloadable | À concevoir | La facture est émise et lisible par l'API, mais aucun endpoint ne la rend en PDF : le format de sortie et sa source de police restent à décider. |
