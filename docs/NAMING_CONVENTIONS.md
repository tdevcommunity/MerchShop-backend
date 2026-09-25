# Conventions de Nommage — Team Shop Merch TDEV

Ce document centralise **toutes les conventions de nommage** de l'équipe.
Objectif : un nommage cohérent partout (branches, commits, fichiers, code, API, BDD, environnement),
pour que n'importe quel membre retrouve immédiatement ce qu'il cherche.

> ⚠️ Stack non finalisée : les règles 5 et 6 (API, BDD) entreront en vigueur
> dès la validation de la stack technique.

---

## 1. Branches Git

Format : `<type>/<nom-de-la-tache>`

| Type | Usage |
|---|---|
| `feat/` | Nouvelle fonctionnalité |
| `fix/` | Correction de bug sur `develop` |
| `chore/` | Tâche technique (outils, linters, scripts) |
| `hotfix/` | Correction d'urgence en production |

Règles :
- **Tout en minuscules**, sans accents, mots séparés par des tirets (`-`).
- Nom de tâche court et explicite, en anglais ou en français (au choix de l'auteur mais **cohérent**).

Exemples :
```
feat/paygate-api
feat/cart-ui
feat/catalog-grid
fix/stock-deduction
chore/test-scenarios
hotfix/payment-webhook
```

---

## 2. Commits (Conventional Commits)

Format : `type(scope): description`

- **type** : `feat`, `fix`, `docs`, `style`, `refactor`, `test`, `chore`, `perf`, `build`, `ci`, `revert`.
- **scope** : domaine impacté, en **kebab-case** (liste de référence ci-dessous).
- **description** : courte, impérative, en minuscules, sans point final.
- Ajouter `(#<id-issue>)` si une issue existe.

### Scopes de référence (domaines du shop)

| Scope | Domaine |
|---|---|
| `api` | endpoints, routes, controllers |
| `catalog` | catalogue, produits, variantes |
| `cart` | panier |
| `checkout` | tunnel d'achat |
| `payment` | paiements, webhooks, agrégateurs |
| `stock` | gestion et décrémentation des stocks |
| `qrcode` | génération / affichage du QR Code de retrait |
| `auth` | sessions utilisateurs, connexion |
| `admin` | panneau d'administration / back-office |
| `docker` | conteneurs, environnement de dev |
| `ci` | intégration continue, scripts de test |
| `docs` | documentation |

Exemples :
```
feat(api): ajouter l'endpoint de création de commande
fix(stock): corriger la décrémentation par variante
feat(cart): ajouter le sélecteur de quantité (#14)
chore(ci): configurer les tests sur les PR
docs(readme): documenter les conventions de nommage
```

---

## 3. Fichiers & dossiers

Règles :
- **`kebab-case`** (minuscules + tirets) pour tous les fichiers et dossiers : `product-card.tsx`, `cart-service.ts`, `api-specs.md`.
- **Pas d'accents, pas de majuscules, pas d'espaces** dans les noms de fichiers.
- Projet : `merch-shop-backend` / `merch-shop-frontend`.
- Dossiers de code : nom au singulier (`component/`, `service/`, `controller/`, `models/`) — à affiner avec la stack.

Exemples :
```
docs/chantier-2-shop-merch.md
src/api/order.controller.ts
components/product-card.tsx
```

---

## 4. Code (variables, fonctions, composants)

> Règles **transverses** ; la casse exacte des types sera confirmée avec le langage retenu
> (JS/TS, PHP…). Le principe reste le même partout.

| Élément | Convention | Exemple |
|---|---|---|
| Variables & fonctions | `camelCase` | `cartTotal`, `getProductById()` |
| Classes / Composants | `PascalCase` | `ProductCard`, `OrderService` |
| Constantes (config, limites) | `UPPER_SNAKE_CASE` | `MAX_QUANTITY`, `PAYMENT_STATUS` |
| Booléens | préfixe `is/has/can/should` | `isAvailable`, `hasVariant`, `canCheckout` |
| Private / local (si pertinent) | préfixe `_` ou par langage | `_internalHelper()` |

Règles : nommer par **ce que ça fait ou contient**, pas par le type (`products` ✔, `arr1` ✘).

---

## 5. API (REST) — à finaliser avec la stack

- Base : `/api/v1/<ressource>` — ressource au **pluriel**, **kebab-case**.
- Actions classiques : `GET /products`, `GET /products/{id}`, `POST /orders`, `POST /payments/webhook`.
- Pas de verbes dans les URLs (`getProducts` ✘ → `GET /products` ✔).
- Réponses JSON en `camelCase` (ex. `orderId`, `stockQuantity`).

---

## 6. Base de données — à finaliser avec la stack

- Tables : **`snake_case` au pluriel** : `products`, `orders`, `order_items`, `product_variants`.
- Colonnes : `snake_case` : `stock_quantity`, `unit_price`, `payment_status`.
- Clés : `id` (PK), `<table>_id` (FK) : `order_id`, `product_id`.

---

## 7. Variables d'environnement

- **`UPPER_SNAKE_CASE`**, préfixées par domaine pour éviter les collisions :
  - `DB_HOST`, `DB_PORT`, `DB_NAME`
  - `FEDAPAY_SECRET_KEY`, `FEDAPAY_SANDBOX`
  - `JWT_SECRET`, `APP_URL`
- Front : `NEXT_PUBLIC_*` ou `VITE_*` selon la stack (à confirmer).
- Jamais commitées (`.env` est dans le `.gitignore`).

---

## 8. Mises à jour

Toute évolution de ces conventions passe par une **PR sur ce document**, discutée en équipe.
Voir [`../CONTRIBUTING.md`](../CONTRIBUTING.md) pour le flux de travail.