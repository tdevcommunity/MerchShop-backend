# Paiement Velto (FedaPay heberge)

## Flux

1. Le frontend cree la commande puis appelle `POST /api/v1/orders/{uuid}/payment`.
2. L'API ouvre une session Velto et renvoie `checkoutUrl`.
3. L'acheteur regle sur la page hebergee (Mobile Money ou carte).
4. Velto notifie l'API via webhook et redirige le navigateur vers `PAYMENT_RETURN_URL`.
5. Le frontend confirme uniquement lorsque `paymentStatus` vaut success (webhook ou sync-return en local).

Le numero Mobile Money est saisi sur le portail Velto/FedaPay, pas dans le formulaire boutique (telephone de contact seulement).

## Variables d'environnement (API)

| Variable | Description |
|----------|-------------|
| `PAYMENT_PROVIDER` | `velto` |
| `PAYMENT_RETURN_URL` | URL publique **HTTPS** du frontend. En **live**, `http://localhost` est refuse par Velto. |
| `VELTO_PUBLIC_KEY` | Cle publique `pk_sandbox_*` ou `pk_live_*` (portail Velto) |
| `VELTO_SECRET_KEY` | Cle gate : `sk_*` de preference, ou `pk_*` avec `VELTO_WEB_ORIGIN` |
| `VELTO_BASE_URL` | Gate API, ex. `https://velto-gate.kiiako.io/v1` |
| `VELTO_WEB_ORIGIN` | Origine du **shop** (`http://localhost:3000` ou `https://shop.ourtdev.com`) pour les en-tetes Origin/Referer |
| `PAYMENT_WEBHOOK_SECRET_VELTO` | Verification `X-Velto-Signature` |
| `PICKUP_TOKEN_SECRET` | Secret QR retrait au stand (obligatoire en prod). Sans lui, la confirmation apres paiement pickup echoue. En local, `APP_KEY` sert de repli si la variable est vide. |

## Portail Velto

- **Applications > Securite & Domaines** : whitelist du domaine frontend (pas seulement l'API).
- **Webhooks** : `https://shop-api.ourtdev.com/api/v1/payments/webhooks/velto`

## Local vs production

- En local, le webhook Velto n'atteint pas `localhost` : le frontend appelle `POST .../payment/sync-return` avec `transaction_id` au retour navigateur.
- En production, le webhook est la source de verite ; sync-return sert de rattrapage ponctuel.

## Sandbox FedaPay

- Sandbox : operateur **Momo Test**, pays **Benin** (+229), numero **64000001** ; `VELTO_CHECKOUT_PHONE_COUNTRY=bj`.
- **Production** (`sk_live_*`, `APP_ENV=production`) : email **exact** du formulaire, pas de suffixe `+`, pas de pays `bj` force.
- Sans email au checkout : adresse technique `{orderNumber}@checkout.guest.ourtdev.com`.
- Si FedaPay memorise un mauvais numero en sandbox seulement : `VELTO_SANDBOX_UNIQUE_CUSTOMER_EMAIL=true`.

## Retour navigateur

L'API ajoute `orderId={uuid}` a `callback_url` pour que la page de retour puisse suivre la commande meme si `sessionStorage` est perdu.
