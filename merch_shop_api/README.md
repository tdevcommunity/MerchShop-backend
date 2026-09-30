<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Logo Laravel"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Statut de la compilation"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total des téléchargements"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Dernière version stable"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="Licence"></a>
</p>

## MerchShop API

API Laravel du **TDEV Festival 2026** (catalogue, panier, commandes, paiement, QR Code de retrait).

### Architecture

L'API suit le **repository pattern** en quatre couches, avec une règle de dépendance
à sens unique (présentation → services → repositories → modèles) :

| Couche | Dossier | Rôle |
|---|---|---|
| Présentation | `app/Http/{Requests,Controllers,Resources}` | validation de l'entrée, traduction HTTP, formatage `camelCase` |
| Cas d'usage | `app/Services` | règles métier, transactions |
| Accès aux données | `app/Repositories/{Contracts,Eloquent}` | interfaces + implémentations Eloquent |
| Persistance | `app/Models` | Eloquent — structure uniquement, aucune règle métier |

➡️ **Documentation complète** : [`../docs/ARCHITECTURE.md`](../docs/ARCHITECTURE.md)
(contrat d'API, checklist « ajouter une ressource », sécurité, points ouverts).

### Commandes

```bash
composer install && php artisan key:generate
php artisan migrate

# Routes de l'API
php artisan route:list --path=api

# Suite de tests
php artisan test

# Données locales de démonstration
php artisan db:seed

# Style de code
./vendor/bin/pint

# Documentation OpenAPI : régénère l'export L5-Swagger
php artisan l5-swagger:generate
```

### Documentation de l'API

La spécification OpenAPI est générée par
[L5-Swagger](https://github.com/DarkaOnLine/L5-Swagger) depuis les attributs
OpenAPI déclarés dans `app/OpenApi/OpenApiSpec.php`.

| Ressource | Accès |
|---|---|
| `/api/documentation` | interface Swagger, en lecture seule sur l'API |
| `/docs` | document OpenAPI JSON, à consommer par les clients générés |
| `storage/api-docs/api-docs.json` | export généré localement |

L'accès à la documentation est public et ne nécessite pas de session. Cette
règle est couverte par
`tests/Feature/Api/ApiDocumentationAccessTest.php`.

L'authentification est par cookie de session : la spécification déclare un
schéma `apiKey` en `cookie` plutôt que `bearer`, et les écritures exigent en
plus l'en-tête `X-XSRF-TOKEN`.

Le contrat de nommage est asymétrique et la génération en tient compte :

- les **requêtes** restent en `snake_case` (`category_id`) ;
- les **réponses** sont converties en `camelCase` à la sérialisation
  (`productsCount`), par le trait `NormalizesResponseKeys`.

Les schémas publics sont centralisés dans `App\OpenApi\OpenApiSpec` et les
tests vérifient la présence des principaux chemins générés.

### Configuration

Les variables de l'API sont documentées dans `.env.example` :
`API_VERSION`, `API_DEFAULT_PER_PAGE`, `API_MAX_PER_PAGE`, `API_THROTTLE_PER_MINUTE`,
`CORS_ALLOWED_ORIGINS`.

## À propos de Laravel

Laravel est un framework d'application web à la syntaxe expressive et élégante. Nous pensons que le développement doit être une expérience agréable et créative pour être véritablement épanouissante. Laravel simplifie le développement en facilitant les tâches courantes de nombreux projets web, telles que :

- Un [moteur de routage simple et rapide](https://laravel.com/docs/routing).
- Un [puissant conteneur d'injection de dépendances](https://laravel.com/docs/container).
- Plusieurs back-ends pour le stockage des [sessions](https://laravel.com/docs/session) et du [cache](https://laravel.com/docs/cache).
- Un [ORM de base de données](https://laravel.com/docs/eloquent) expressif et intuitif.
- Des [migrations de schéma](https://laravel.com/docs/migrations) indépendantes du SGBD.
- Un [traitement robuste des tâches en arrière-plan](https://laravel.com/docs/queues).
- La [diffusion d'événements en temps réel](https://laravel.com/docs/broadcasting).

Laravel est accessible, puissant, et fournit les outils nécessaires aux applications volumineuses et robustes.

## Apprendre Laravel

Laravel dispose de la [documentation](https://laravel.com/docs) et de la bibliothèque de tutoriels vidéo les plus complètes de tous les frameworks web modernes, ce qui rend la prise en main très simple.

De plus, [Laracasts](https://laracasts.com) propose des milliers de tutoriels vidéo sur de nombreux sujets, dont Laravel, le PHP moderne, les tests unitaires et JavaScript. Améliorez vos compétences en explorant cette bibliothèque vidéo complète.

Vous pouvez aussi suivre de courtes leçons basées sur des projets concrets sur [Laravel Learn](https://laravel.com/learn), où vous serez guidé pas à pas dans la création d'une application Laravel de zéro, tout en apprenant les bases de PHP.

## Développement assisté par agents

La structure prévisible et les conventions de Laravel le rendent idéal pour les agents de codage IA comme Claude Code, Cursor et GitHub Copilot. Installez [Laravel Boost](https://laravel.com/docs/ai) pour améliorer votre workflow avec l'IA :

```bash
composer require laravel/boost --dev

php artisan boost:install
```

Boost fournit à votre agent plus de 15 outils et skills qui l'aident à développer des applications Laravel en suivant les bonnes pratiques.

## Contribuer

Merci d'envisager de contribuer au framework Laravel ! Le guide de contribution se trouve dans la [documentation de Laravel](https://laravel.com/docs/contributions).

## Code de conduite

Afin de garantir que la communauté Laravel reste accueillante pour tous, veuillez consulter et respecter le [Code de conduite](https://laravel.com/docs/contributions#code-of-conduct).

## Failles de sécurité

Si vous découvrez une faille de sécurité dans Laravel, veuillez envoyer un e-mail à Taylor Otwell à l'adresse [taylor@laravel.com](mailto:taylor@laravel.com). Toutes les failles de sécurité seront traitées rapidement.

## Licence

Le framework Laravel est un logiciel open source distribué sous la [licence MIT](https://opensource.org/licenses/MIT).
