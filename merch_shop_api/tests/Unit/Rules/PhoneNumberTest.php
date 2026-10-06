<?php

namespace Tests\Unit\Rules;

use App\Rules\PhoneNumber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Le numero de telephone de l'acheteur.
 *
 * Ce numero est la seule donnee d'identite demandee a quelqu'un dont on ne
 * connait pas le compte, et il finit chez l'operateur : un numero mal forme
 * devient une commande « payee » vers une destination qui n'existe pas, donc un
 * remboursement qui echoue toujours. C'est ce qui justifie des cas de test sur
 * une normalisation plutot que sur une validation.
 *
 * Les memes frontieres sont declarees ailleurs dans l'API — `RegisterRequest`
 * valide le compte sur `^\+?228[0-9]{8}$`, le schema OpenAPI annonce la meme
 * forme. Elles etaient tombees sur un autre pays dans les trois lectures, et
 * l'API acceptait un compte que la commande refusait ensuite. Ces tests
 * verrouillent la forme togolaise, donc l'accord entre les trois.
 */
final class PhoneNumberTest extends TestCase
{
    /**
     * Les trois ecritures d'un meme numero togolais, plus les separateurs que
     * la saisie autorise.
     *
     * @return array<string, array{0: string}>
     */
    public static function equivalentSpellings(): array
    {
        return [
            'nu' => ['90123456'],
            'avec indicatif' => ['+22890123456'],
            'sans plus ni indicatif' => ['22890123456'],
            'avec le prefixe d appel international' => ['0022890123456'],
            'espaces' => ['+228 90 12 34 56'],
            'points et tirets' => ['+228-90.12.34.56'],
            'indicatif entre parentheses' => ['(+228) 90 12 34 56'],
        ];
    }

    #[DataProvider('equivalentSpellings')]
    public function test_it_accepts_every_spelling_of_a_togolese_number(string $input): void
    {
        $this->assertSame('90123456', PhoneNumber::normalise($input));
    }

    /**
     * Un national togolais peut commencer par l'indicatif.
     *
     * `22890123` est un numero valide de huit chiffres. Le retirer comme on
     * retire un indicatif produirait `90123`, un numero qui n'appartient a
     * personne : un retrait fait sur une saisie valide, vers un faux numero.
     * C'est la raison d'etre de la borne sur la longueur.
     */
    public function test_it_does_not_strip_the_country_code_from_a_national_number_that_starts_with_it(): void
    {
        $this->assertSame('22890123', PhoneNumber::normalise('22890123'));
    }

    #[DataProvider('rejectedNumbers')]
    public function test_it_refuses_a_number_that_is_not_togolese(mixed $input): void
    {
        $this->assertNull(PhoneNumber::normalise($input));
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function rejectedNumbers(): array
    {
        return [
            /*
             * Dix chiffres est la forme d'un numero du pays voisin. La retenir
             * laisserait commander vers un numero sans abonne au Togo, donc
             * encaisser pour un remboursement que personne ne pourra recevoir.
             */
            'dix chiffres, forme du pays voisin' => ['0707070707'],

            /* Un national prefixe d'un autre pays, ecrase par le nôtre. */
            'indicatif beninois' => ['+22901978456'],
            'indicatif ivoirien' => ['+2250707070707'],

            'trop court' => ['9012345'],
            'trop long' => ['901234567'],

            /*
             * Un numero dont tous les chiffres se repètent ne designe aucun
             * abonne. Le laisser passer produirait une commande remboursable
             * vers une destination inexistante.
             */
            'tous les chiffres identiques' => ['99999999'],

            'vide' => [''],
            'aucun chiffre' => ['+'],

            /* La regle normalise une chaine : un entier n'en est pas une. */
            'entier' => [90123456],
            'tableau' => [[]],
            'nul' => [null],
        ];
    }

    /**
     * Le code pays est celui qu'attend l'operateur.
     *
     * Il n'est pas demande au client : `StoreOrderRequest` le deduit de la
     * regle. Une valeur divergente ici se retrouverait dans la colonne voisine
     * de la commande et dans l'appel de remboursement.
     */
    public function test_it_exposes_the_country_the_operator_expects(): void
    {
        $this->assertSame('tg', PhoneNumber::countryCode());
    }
}