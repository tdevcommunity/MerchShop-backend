<?php

namespace Tests\Unit\Lang;

use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * Les messages de validation sont produits par le framework, donc hors de la
 * portée du code du projet : seule la configuration de locale décide de leur
 * langue. Une régression sur `APP_LOCALE` ne casse aucun test métier, elle
 * rend simplement l'API anglophone — d'où ce test, qui l'attrape.
 */
class ValidationMessagesAreFrenchTest extends TestCase
{
    public function test_the_application_locale_is_french(): void
    {
        $this->assertSame('fr', app()->getLocale());
    }

    /**
     * Une règle absente du fichier français ne doit pas produire la clé brute
     * (`validation.required`) : le repli anglais garantit un message lisible.
     */
    public function test_an_untranslated_rule_falls_back_instead_of_leaking_its_key(): void
    {
        $validator = Validator::make(['name' => null], ['name' => ['required']]);

        $message = $validator->errors()->first('name');

        $this->assertNotSame('validation.required', $message);
        $this->assertStringContainsString('obligatoire', $message);
    }

    public function test_common_rules_are_translated(): void
    {
        $validator = Validator::make(
            ['email' => 'pas-un-email', 'name' => 'beaucoup-trop-long', 'status' => 'inconnu'],
            [
                'email' => ['email'],
                'name' => ['required', 'string', 'max:5'],
                'status' => ['in:actif,inactif'],
            ],
        );

        $errors = $validator->errors();

        $this->assertStringContainsString('n\'est pas une adresse valide', $errors->first('email'));
        $this->assertStringContainsString('plus de 5 caractères', $errors->first('name'));
        $this->assertStringContainsString('invalide', $errors->first('status'));
    }

    public function test_the_required_rule_is_translated(): void
    {
        $validator = Validator::make([], ['name' => ['required', 'string']]);

        $this->assertStringContainsString('est obligatoire', $validator->errors()->first('name'));
    }

    /**
     * Le nom affiché vient de `attributes()` du FormRequest, pas du nom de la
     * colonne : le client doit lire « prénom », jamais « firstname ».
     */
    public function test_the_attribute_label_is_used_in_the_message(): void
    {
        $validator = Validator::make(
            ['firstname' => ''],
            ['firstname' => ['required']],
            [],
            ['firstname' => 'prénom'],
        );

        $this->assertSame('Le champ prénom est obligatoire.', $validator->errors()->first('firstname'));
    }
}
