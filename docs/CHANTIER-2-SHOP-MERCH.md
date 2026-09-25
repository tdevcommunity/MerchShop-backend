# Chantier 2 : Le Shop Merch TDEV — Spécifications Opérationnelles

*Récapitulatif structuré du document officiel « Spécifications Opérationnelles — Chantier 2 : Le Shop Merch TDEV ».
Source de vérité à jour pour l'équipe jusqu'à validation des choix techniques.*

---

## 🎯 Objectif du chantier

Déployer une **boutique e-commerce fluide, responsive et sécurisée** permettant aux participants
et sympathisants d'acheter les goodies officiels du **TDEV Festival 2026** en ligne
(**Mobile Money & Carte bancaire**), avec **génération automatique d'un Pass / QR Code de retrait**.

---

## 📦 Catalogue produits & variantes à gérer

| Catégorie | Produits | Variantes |
|---|---|---|
| **Textile** | Pulls, T-shirts, Polos, Casquettes | Gestion stricte des **tailles** (S, M, L, XL, XXL, XXXL) et des **couleurs** |
| **Accessoires & Bureau** | Stickers, Pins, Agendas, Bouteilles isothermes | — |
| **Bagagerie & Goodies** | Totebags, Éventails, Porte-clés | — |

---

## 🛠 Stack technique recommandée

> ⚠️ **Recommandations du document officiel uniquement — choix final non acté par l'équipe.**

- **Front-end** : Next.js (React) + TailwindCSS
- **Back-end & API** : Node.js / NestJS **ou** PHP (Laravel) + PostgreSQL / MySQL
- **Agrégateur de paiement** : FedaPay / KKiaPay / PayGate / Flooz & T-Money (webhooks pour validation automatique)
- **Stockage & QR Code** : Cloudinary / S3 (images produits) + bibliothèque QR Code (ex. `qrcode` Node.js)

---

## 👥 Attributions & missions par membre

### 1. Bogue Komla Armel — Lead Tech & Architecte Général

- **Architecture globale** : valider les choix techniques, le schéma de la base de données et la structure de l'API.
- **Orchestration & Code Review** : définir les standards de code sur GitHub et valider les Pull Requests.
- **Sécurisation** : étanchéité des transactions, gestion des sessions utilisateurs, stabilité lors des pics de trafic.
- **Synchronisation** : pont technique avec l'équipe de l'App Mobile de Scan (Chantier 3B) pour garantir la lisibilité du QR Code au guichet le Jour J.

### 2. AHOUNDA Amakoo Ancla — Lead Back-end & Paiements Mobile Money

- **Intégration paiement** : connecter l'API de paiement local (FedaPay, KKiaPay ou PayGate) pour la collecte via T-Money et Flooz.
- **Webhooks** : implémenter la logique de callback/webhook sécurisée (validation du paiement en BDD et mise à jour instantanée du statut de commande).
- **Moteur de stock & BDD** : logique de décrémentation automatique des stocks par variante (taille/couleur) pour éviter surstock/survente.
- **Génération QR Code** : générer le QR Code unique de retrait (encodé avec Order_ID + hash de sécurité) et déclencher l'envoi du mail/SMS de confirmation avec le badge de retrait.

### 3. Cédric AMOUZOU-ABLO — Lead Front-end & Intégration UI

- **Architecture front-end** : structurer le projet Next.js / React (composants réutilisables, état global du panier).
- **Tunnel d'achat (Checkout)** : workflow de commande sans friction — Sélection article ➔ Panier ➔ Choix du mode (Retrait Jour J ou Livraison) ➔ Paiement.
- **Page de confirmation & reçu** : vue client post-paiement affichant le récapitulatif de commande et le QR Code à présenter au stand Merch.
- **Responsive design** : expérience fluide sur smartphone (**mobile-first**).

### 4. AMEKPO Romuald — Dev Fullstack & UI/UX Design

- **Prototypage & assets** : maquettes Figma du shop et traitement des visuels des goodies (détourage, optimisation des images via Photoshop/CapCut).
- **Intégration catalogue** : grille de présentation des produits avec filtres par catégorie (Textile, Accessoires, Bagagerie).
- **Fiche produit interactive** : sélecteurs dynamiques de variantes (tailles, couleurs) avec stock disponible en temps réel.

### 5. BAWA Abdoul-Rachid — Dev Support, QA & Tests d'Infiltration

- **Tests de charge & scénarios** : comportement de la boutique lors d'achats simultanés (concurrence sur les stocks).
- **Recettage paiement** : batteries de tests sur le bac à sable (Sandbox) et en environnement réel (Mobile Money) pour vérifier la bonne remontée des paiements.
- **Audit de sécurité** : vérifier l'impossibilité d'usurper un QR Code de retrait ou de modifier le montant total de la commande côté client.
- **Panneau d'administration (Support Back-office)** : mini dashboard pour le staff sur place (liste des commandes à livrer, filtres par taille/statut).

---

## 🔄 Plan de travail & jalons (Sprint Shop)

| Étape | Contenu | Responsables |
|---|---|---|
| **1. Modélisation** | BDD, maquettes Figma & API Specs | Armel, Romuald, Ancla |
| **2. Dev Core** | Catalogue / Panier + Backend / Paiement | Cédric & Romuald (front) / Ancla (back) |
| **3. Intégration** | Webhooks Mobile Money + Génération QR Code | Armel & Ancla |
| **4. Tests & QA** | Tests d'infiltration & Sandbox paiement | Rachid |
| **5. Mise en Prod** | Déploiement Vercel/VPS & sync avec l'App Scan | Armel & Cédric |

---

## 🔗 Documents liés

- [`../CONTRIBUTING.md`](../CONTRIBUTING.md) — règles de branches et flux de travail
- [`../CODE_OF_CONDUCT.md`](../CODE_OF_CONDUCT.md) — code de conduite de l'équipe
- [`CODE_STANDARDS.md`](./CODE_STANDARDS.md) — standards de qualité du code