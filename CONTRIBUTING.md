# CONTRIBUTING — Règles de travail sur le dépôt

Ce document définit **les règles Git, le flux de travail et les conventions de commits**
de l'équipe Shop Merch TDEV Festival 2026. **Chaque membre doit le lire avant de toucher au dépôt.**

---

## 1. Les deux branches permanentes

Ces deux branches restent **toujours** sur le dépôt et **personne ne pousse directement dessus**.

### `main` — Production

- Contient uniquement le code **stable, validé et prêt à être déployé**.
- Alimentée **uniquement** par fusion (merge/PR) depuis `develop`, ou via un **hotfix d'urgence**.

### `develop` — Intégration / Staging

- C'est la **branche centrale de travail au quotidien**.
- Toutes les fonctionnalités testées par l'équipe y sont regroupées pour vérifier que tout fonctionne ensemble.

---

## 2. Les branches temporaires (créées par l'équipe)

Chaque développeur part de `develop`, fait son travail sur sa propre branche, puis ouvre une
**Pull Request vers `develop`**.

| Préfixe | Usage | Exemple |
|---|---|---|
| `feat/<nom-tache>` | Nouvelle fonctionnalité | `feat/paygate-api`, `feat/cart-ui`, `feat/catalog-grid` |
| `fix/<nom-bug>` | Correction d'un bug constaté sur `develop` | `fix/stock-deduction` |
| `chore/<tache-technique>` | Configuration d'outils, linters, scripts de test | `chore/test-scenarios` |
| `hotfix/<nom-bug>` ⚠️ | Correction **urgente** en production (PR vers `main` **et** `develop`) | `hotfix/payment-webhook` |

> ⚠️ `hotfix/*` est une **proposition** pour couvrir le "hotfix d'urgence" vers `main`.
> À valider ensemble au premier cas réel.

**Règles :**
- Toujours partir de `develop` (sauf hotfix, qui part de `main`).
- Une branche temporaire = une tâche = une Pull Request.
- Les branches temporaires sont **supprimées après fusion** de leur PR.

---

## 3. Résumé du flux pour les membres

```bash
# 1. Je pars de develop
git checkout develop && git pull origin develop

# 2. Je crée ma branche
git checkout -b feat/<ma-tache>

# 3. Je bosse et je commit
git add <mes-fichiers>
git commit -m "feat(scope): description courte et claire"

# 4. Je pousse ma branche
git push -u origin feat/<ma-tache>

# 5. J'ouvre une Pull Request sur GitHub : de ma branche vers develop
```

---

## 4. Règles d'or

1. **Jamais de push direct** sur `main` ni `develop`. Tout passe par une Pull Request.
2. **Toute PR doit être reviewée** avant fusion (au minimum par le Lead Tech — Armel).
3. `develop` reste **toujours déployable** : on ne merge pas une branche cassée.
4. `main` n'est alimentée **que** par `develop` (ou un hotfix validé).
5. **Mettre à jour sa branche** avant d'ouvrir la PR :
   `git checkout develop && git pull origin develop && git checkout <ma-branche> && git merge develop`
6. **Une PR = une tâche.** Pas de mélange de fonctionnalités dans une même PR.
7. **Pensez aux tests** avant d'ouvrir une PR (le QA/recettage de Rachid ne doit pas subir de regressions évitables).
8. **Pas de secrets en clair** (clés API, mots de passe…) : tout passe par des variables d'environnement.

---

## 5. Conventions de commits — Conventional Commits

Format : `type(scope): description`

| Type | Quand l'utiliser |
|---|---|
| `feat` | Nouvelle fonctionnalité |
| `fix` | Correction de bug |
| `docs` | Documentation (.md, commentaires…) |
| `style` | Formatage, espacements (sans impact logique) |
| `refactor` | Refactorisation sans changement de comportement |
| `test` | Ajout / correction de tests |
| `chore` | Tâches techniques (dépendances, config, CI…) |
| `perf` | Amélioration de performance |
| `build` / `ci` | Système de build / intégration continue |
| `revert` | Annulation d'un commit |

Exemples :

```bash
feat(api): ajouter l'endpoint de création de commande
fix(stock): corriger la décrémentation du stock par variante
chore(ci): ajouter les scripts de test sur les PR
docs(readme): mettre à jour le workflow git
```

**Règles :**
- Description courte, en français (langue de l'équipe), impérative ("ajouter", "corriger"…).
- Ajouter la référence de l'issue si elle existe : `feat(api): ... (#12)`.
- Scopes de référence, exemples et détails : voir [`docs/NAMING_CONVENTIONS.md`](./docs/NAMING_CONVENTIONS.md).

---

## 6. Modèle de Pull Request

Utiliser systématiquement le template : [`.github/PULL_REQUEST_TEMPLATE.md`](./.github/PULL_REQUEST_TEMPLATE.md).
En résumé, toute PR doit renseigner :

- **Description** : quoi / pourquoi / comment.
- **Tests effectués** : comment la validation a été faite.
- **Checklist** : branche à jour, commits conventionnels, pas de secrets, code relu, tests OK.

---

## 7. Configuration GitHub recommandée (à activer côté repo)

> À appliquer par le Lead Tech sur le dépôt GitHub.

- **Protection de `main`** :
  - Exiger une Pull Request (1 reviewer minimum avant fusion).
  - Interdire la fusion sans review (`Require pull request reviews before merging`).
  - Éventuellement : exiger le status check des tests CI.
- **Protection de `develop`** :
  - Exiger une Pull Request.
  - Interdire le push direct (`Prevent direct pushes`).
- Les branches temporaires (`feat/*`, `fix/*`, `chore/*`) sont libres de push direct : chacun pousse sa branche de travail.

---

## 8. Remarques finales

- Tout changement à ces règles se fait par **Pull Request** sur ce document, discutée en équipe.
- En cas de doute : poser la question à l'équipe plutôt que de contourner le flux.