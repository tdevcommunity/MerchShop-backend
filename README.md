# MerchShop Backend — TDEV Festival 2026

API, paiements et gestion des retraits Merch du **TDEV Festival 2026** :
catalogue, commandes, intégration paiement (Mobile Money & Carte bancaire),
moteur de stock et génération du QR Code de retrait.

> ⚠️ **Phase actuelle : organisation & définition des règles.**
> La stack (Node.js/NestJS ou PHP/Laravel + PostgreSQL/MySQL) est **en cours de validation** —
> aucun code n'est initialisé tant que le choix n'est pas acté.

---

## 👤 Équipe & docs

- ⭐ [`CONTRIBUTING.md`](./CONTRIBUTING.md) — **règles de branches Git, flux de travail, conventions de commits** — à lire avant toute action
- [`CODE_OF_CONDUCT.md`](./CODE_OF_CONDUCT.md) — code de conduite de l'équipe
- [`docs/NAMING_CONVENTIONS.md`](./docs/NAMING_CONVENTIONS.md) — conventions de nommage (branches, commits, fichiers, code, API, BDD, env)
- [`docs/CHANTIER-2-SHOP-MERCH.md`](./docs/CHANTIER-2-SHOP-MERCH.md) — spécifications opérationnelles du Chantier 2
- [`docs/CODE_STANDARDS.md`](./docs/CODE_STANDARDS.md) — standards de qualité du code
- [`.github/PULL_REQUEST_TEMPLATE.md`](./.github/PULL_REQUEST_TEMPLATE.md) — modèle de Pull Request

---

## 🚀 Démarrage rapide

```bash
# 1. Je pars de develop
git checkout develop && git pull origin develop

# 2. Je crée ma branche de travail
git checkout -b feat/<ma-tache>

# 3. Je travaille et je commit
# 4. Je pousse ma branche
git push -u origin feat/<ma-tache>

# 5. J'ouvre une Pull Request vers develop
```

Le détail complet est dans [`CONTRIBUTING.md`](./CONTRIBUTING.md).