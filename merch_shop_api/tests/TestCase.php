<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Mail;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        /*
         * Aucun test n'envoie reellement de courriel.
         *
         * La file tourne en `sync` sous PHPUnit : le job de confirmation
         * execute donc son envoi dans la requete qui le declenche, c'est-a-
         * dire dans le webhook de paiement. Faker la boite l'arrete la — le
         * mailable est enregistre sans etre rendu — ce qui evite d'une part
         * de faire rendre un gabarit (et de generer le PNG du QR, qui suppose
         * l'extension GD) au passage, et d'autre part qu'un test qui verifie
         * un paiement envoie par erreur un courriel de reel.
         *
         * Les tests qui pretendent le contraire — « ce paiement doit envoyer
         * une confirmation » — s'appuient sur `Mail::assertSent()`, rendu
         * possible exactement par ce faker.
         */
        Mail::fake();
    }
}
