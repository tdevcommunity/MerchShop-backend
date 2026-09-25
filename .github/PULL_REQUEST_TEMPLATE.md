# Pull Request — Modèle à suivre

> Toute Pull Request **vers `develop` ou `main`** doit suivre ce modèle.
> Une PR = une tâche = une branche.

---

## Description

**Type** : `feat` / `fix` / `chore` / `hotfix` / `docs` …

**Quoi** — En une phrase, que fait cette PR ?

**Pourquoi** — Quel problème résout-elle, quel besoin couvre-t-elle ? (cf. spécifications / issue si applicable : lien ou `#<id>`)

**Comment** — Points d'implémentation notables, choix techniques, fichiers clés modifiés.

## Tests effectués

- [ ] Recette manuelle (parcours décrit si nécessaire)
- [ ] Tests automatisés exécutés
- [ ] Environnement utilisé (local / staging / sandbox)

## Checklist

- [ ] Branche créée depuis `develop` et **à jour** dessus (`git merge develop` fait).
- [ ] Commits au format conventionnel (`feat(scope): …`, `fix(scope): …`).
- [ ] Aucun secret / `.env` / donnée sensible n'a été commité.
- [ ] Code relu et nettoyé (pas de console.log oubliés, de TODOs involontaires).
- [ ] Impact QA/sécurité évalué (stock, paiement, QR, montants) — conformité [`docs/CODE_STANDARDS.md`](../docs/CODE_STANDARDS.md).
- [ ] PR prête pour la review (description complète, diff ciblé).

---

## 📎 Rappels

- **Cible** : cette PR doit aller vers `develop` (sauf hotfix → `main`, avec backport vers `develop`).
- **Review** : au moins 1 approbation (Lead Tech pour les choix structurants).
- Après fusion : **supprimer** la branche temporaire.