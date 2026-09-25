# Standards de Qualité du Code

> ⚠️ **Document transversal et évolutif.** Les règles précises (linters, framework, tests)
> seront finalisées **une fois la stack technique validée par l'équipe**.
> Les principes ci-dessous s'appliquent dès le premier commit, quelle que soit la techno.

---

## 1. Principes généraux

- **Lisibilité avant astuce** : du code simple et explicite vaut mieux qu'un code "malin" illisible.
- **Nommage explicite** : variables, fonctions, fichiers et branches portent des noms qui décrivent leur rôle.
- **Une fonction = une responsabilité** : pas de fonctions "fourre-tout" de plusieurs centaines de lignes.
- **Pas de duplication** : factoriser ce qui est commun (composants, helpers, utilitaires), sans sur-architecturer.
- **Commentaires utiles** : expliquer le *pourquoi* (contexte métier, contrainte de paiement, sécurité), pas le *quoi*.

## 2. Git & commits

- Suivre les règles de [`../CONTRIBUTING.md`](../CONTRIBUTING.md) : branches `feat/fix/chore`, PR vers `develop`, commits conventionnels.
- Un commit = un changement cohérent. Commiter souvent, avec des messages clairs.
- **Ne jamais committer** : fichiers de config locaux, secrets, clés, `.env`, logs, artefacts de build.

## 3. Sécurité (transverse)

- **Aucun secret en clair** : clés API, identifiants de paiement, chaînes de connexion BDD → uniquement par variables d'environnement, jamais dans le code.
- **Validation systématique** des entrées utilisateur côté API (montants, variantes, quantités) : le client n'est jamais une source de vérité.
- **Toute manipulation d'argent** (montants, statuts de paiement, remboursements) doit être validée/confirmée côté serveur via les webhooks sécurisés.
- **QR Code de retrait** : encodé avec Order_ID + hash de sécurité — jamais modifiable côté client.
- Penser aux tests d'infiltration de Rachid : santiser, authentifier, limiter.

## 4. Tests

- Toute fonctionnalité livrée est accompagnée de **tests** (au minimum les scénarios critiques : panier, stock, paiement, QR).
- Les cas critiques à couvrir en priorité :
  - Décrémentation du stock par variante (et sa concurrence — achats simultanés).
  - Cycle de vie d'une commande (création → paiement → confirmation → retrait).
  - Webhooks de paiement (validation et mise à jour du statut).
  - Impossibilité de modifier montant / commande côté client.
- Le QA de Rachid doit pouvoir **recetter** : prévoir des environnements Sandbox (paiement) et un environnement de staging.

## 5. Revue de code (Pull Request)

- Auto-review avant d'ouvrir la PR : relire son diff, nettoyer les oublis, mettre à jour sa branche sur `develop`.
- En PR, commenter de façon **constructive et factuelle** (voir Code de Conduite).
- La fusion en `develop` implique : checklist du template complétée, tests verts, pas de conflits.
- Le **Lead Tech** valide in fine les choix structurants.

## 6. Définition of Done (DoD) d'une tâche

Une tâche est **achevée** lorsque :

- [ ] Le code respecte les conventions de commits et le format des branches.
- [ ] La PR est décrite (contexte, choix, tests effectués) via le modèle de PR.
- [ ] La branche est à jour sur `develop` et sans conflit.
- [ ] Les tests nécessaires sont écrits et passent (fonctionnels ou manuels documentés).
- [ ] Aucun secret ni donnée sensible n'est exposé.
- [ ] La PR a été reviewée et approuvée (au minimum par un reviewer) avant fusion.

---

## 7. Mise à jour du document

Ces standards seront **complétés** (outils : ESLint/Prettier, gestionnaires de paquets, commandes de test,
conventions de nommage des routes/pages, structure de dossiers…) dès que la stack sera validée.