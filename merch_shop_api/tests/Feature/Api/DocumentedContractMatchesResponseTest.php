<?php

namespace Tests\Feature\Api;

use App\Models\Category;
use Dedoc\Scramble\Generator;
use Dedoc\Scramble\Scramble;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * La spécification doit décrire la réponse que l'API rend réellement.
 *
 * Le contrat de réponse et les messages de validation sont traduits deux fois :
 * une fois en PHP, une fois dans la documentation. Ces tests comparent les deux,
 * car une documentation divergente ne se voit pas — elle se découvre quand un
 * client généré lit `error` et ne trouve rien.
 */
class DocumentedContractMatchesResponseTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_documented_error_envelope_matches_a_validation_failure(): void
    {
        $response = $this->postJson('/api/v1/auth/register', ['email' => 'pas-un-email'])->assertStatus(422);
        $documented = $this->responseSchema('ValidationException');

        $this->assertArrayHasKey('error', $documented['properties'], 'L\'erreur doit être imbriquée sous `error`, pas étalée à la racine.');
        $this->assertSame(array_keys($response->json('error')), array_keys($documented['properties']['error']['properties']));
        $this->assertSame(array_keys($response->json()), array_keys($documented['properties']));
    }

    public function test_the_documented_field_errors_keep_the_keys_the_client_sent(): void
    {
        $response = $this->postJson('/api/v1/auth/register', ['email' => 'pas-un-email'])->assertStatus(422);
        $documented = $this->responseSchema('ValidationException');

        $details = $documented['properties']['error']['properties']['details']['properties'] ?? [];

        $this->assertArrayHasKey('fields', $details, 'Une erreur de validation doit documenter le détail `fields`.');
        $this->assertSame('object', $details['fields']['type']);
        $this->assertArrayHasKey('email', $response->json('error.details.fields'));
    }

    public function test_validation_messages_are_french(): void
    {
        $response = $this->postJson('/api/v1/auth/register', ['email' => 'pas-un-email'])->assertStatus(422);
        $messages = implode(' ', array_merge(...array_values($response->json('error.details.fields'))));

        $this->assertStringNotContainsString('The ', $messages);
        $this->assertStringContainsString('est obligatoire', $messages);
    }

    public function test_the_documented_response_keys_are_camel_case(): void
    {
        Category::factory()->create();

        $response = $this->getJson('/api/v1/categories')->assertOk();
        $documented = $this->schema('CategoryResource');

        $this->assertSame(
            array_keys($response->json('data.0')),
            array_keys($documented['properties']),
            'Le schéma de la ressource doit décrire les clés réellement émises.',
        );
    }

    public function test_nullable_fields_are_not_declared_required(): void
    {
        $schema = $this->schema('CategoryResource');
        $nullable = array_keys(array_filter(
            $schema['properties'],
            fn (array $property): bool => in_array('null', (array) ($property['type'] ?? ''), true),
        ));

        $this->assertNotEmpty($nullable, 'La ressource doit exposer au moins un champ nullable.');
        $this->assertEmpty(
            array_intersect($nullable, $schema['required'] ?? []),
            'Un champ nullable ne peut pas être obligatoire : le client ne distingue plus absent de vide.',
        );
    }

    public function test_request_schemas_stay_snake_case(): void
    {
        $schema = $this->schema('StoreProductRequest');

        $this->assertArrayHasKey('category_id', $schema['properties']);
        $this->assertArrayNotHasKey('categoryId', $schema['properties']);
    }

    public function test_the_documented_error_descriptions_are_in_french(): void
    {
        // Les rédactions par défaut du package sont anglaises, et réapparaissent
        // dès qu'une extension d'exception cesse d'être prioritaire. Les
        // fragments entourés d'accents graves sont retirés avant analyse : ce
        // sont des noms de routes, pas de la prose.
        //
        // Garde-fou, pas garantie : une liste de mots ne peut pas couvrir tout
        // l'anglais. Elle attrape la régression réelle — un retour au libellé du
        // package, ou une réécriture bâclée — mais laisserait passer une phrase
        // anglaise qui n'emploierait aucun de ces mots.
        $anglais = [
            'error overview', 'unauthenticated', 'authorization error', 'validation error',
            'not found', 'too many requests', 'server error', 'invalid', 'required',
            'unauthorized', 'forbidden', 'overview', 'payload', 'request', 'access',
            'denied', 'permission', 'permissions', 'insufficient', 'quota', 'rate limit',
            'retry', 'exceeded', 'resource', 'not authenticated',
        ];

        $responses = $this->openApi()['components']['responses'] ?? [];

        foreach (['ValidationException', 'AuthenticationException', 'AuthorizationException'] as $name) {
            $this->assertArrayHasKey($name, $responses, "Réponse {$name} absente de la spécification.");

            $description = $responses[$name]['description'] ?? '';

            $this->assertNotSame('', $description, "La réponse {$name} doit être décrite.");
            $this->assertMatchesRegularExpression('/\S+\.\s*$/u', $description, 'Une description se termine par un point.');

            $prose = strtolower((string) preg_replace('/`[^`]*`/u', ' ', $description));

            foreach ($anglais as $mot) {
                $this->assertDoesNotMatchRegularExpression(
                    '/\b'.preg_quote($mot, '/').'\b/u',
                    $prose,
                    "La description de {$name} contient le mot anglais « {$mot} ».",
                );
            }
        }
    }

    public function test_the_documented_health_response_matches_the_real_one(): void
    {
        $response = $this->getJson('/api/v1/health');
        $documented = $this->openApi()['paths']['/v1/health']['get']['responses'][(string) $response->status()]['content']['application/json']['schema'] ?? [];

        $this->assertNotSame([], $documented, 'La réponse de la sonde doit être décrite dans la spécification.');
        $this->assertSame(
            array_keys($response->json()),
            array_keys($documented['properties'] ?? []),
            'Le schéma de la sonde doit décrire les clés réellement émises.',
        );

        // La sonde n'est pas résolue depuis un modèle : ses clés sont écrites à
        // la main, donc rien ne les corrige automatiquement si la conversion en
        // camelCase évolue.
        $this->assertSame(
            array_keys($response->json('data')),
            array_keys($documented['properties']['data']['properties'] ?? []),
            'Le schéma imbriqué de la sonde doit décrire les clés réellement émises.',
        );
    }

    /**
     * Schéma d'un composant de la spécification.
     *
     * Le document est produit dans le test plutôt que lu dans `api.json` : la
     * comparaison doit porter sur ce que la génération produit aujourd'hui, pas
     * sur un export qu'il faudrait penser à régénérer.
     *
     * @return array<string, mixed>
     */
    private function schema(string $name): array
    {
        $schemas = $this->openApi()['components']['schemas'] ?? [];

        $this->assertArrayHasKey($name, $schemas, "Schéma {$name} absent de la spécification.");

        return $schemas[$name];
    }

    /**
     * Corps JSON documenté pour une réponse d'erreur nommée.
     *
     * @return array<string, mixed>
     */
    private function responseSchema(string $name): array
    {
        $responses = $this->openApi()['components']['responses'] ?? [];

        $this->assertArrayHasKey($name, $responses, "Réponse {$name} absente de la spécification.");

        return $responses[$name]['content']['application/json']['schema'];
    }

    /**
     * @return array<string, mixed>
     */
    private function openApi(): array
    {
        return app(Generator::class)
            ->generate(Scramble::getGeneratorConfig('default'))
            ->spec();
    }
}
