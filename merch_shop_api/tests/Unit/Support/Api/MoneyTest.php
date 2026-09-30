<?php

namespace Tests\Unit\Support\Api;

use App\Support\Api\Money;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Montants en francs CFA.
 *
 * Le franc CFA n'a pas de subdivision, donc ces tests verrouillent deux choses :
 * qu'un montant entier passe par toutes ses formes d'ecriture, et qu'un montant
 * non entier soit refuse plutot qu'arrondi. Le refus est le point important : un
 * arrondi silencieux transformerait un prix que personne n'a choisi en un autre
 * prix, et l'ecart ne se decouvrirait qu'au moment du paiement.
 */
class MoneyTest extends TestCase
{
    private Money $money;

    protected function setUp(): void
    {
        parent::setUp();

        $this->money = new Money;
    }

    public function test_it_reads_a_whole_number_of_francs(): void
    {
        $this->assertSame(2500, $this->money->toAmount('2500'));
        $this->assertSame(2500, $this->money->toAmount(2500));
        $this->assertSame(0, $this->money->toAmount(0));
    }

    #[DataProvider('equivalentWritings')]
    public function test_it_accepts_every_writing_of_the_same_sum(string|int $amount): void
    {
        /*
         * Un operateur de paiement, une saisie au clavier et l'API n'ecrivent pas
         * forcement la meme forme. Tant que la somme est la meme, c'est le meme
         * montant : refuser « 2500.00 » ferait echouer une notification
         * parfaitement legitime.
         */
        $this->assertSame(2500, $this->money->toAmount($amount));
    }

    /**
     * @return array<string, array{string|int}>
     */
    public static function equivalentWritings(): array
    {
        return [
            'entier' => ['2500'],
            'avec decimales nulles' => ['2500.00'],
            'avec virgule' => ['2500,00'],
            'avec separateur de milliers' => ['2 500'],
            'avec espace insecable' => ["2\u{00a0}500"],
            'nombre entier' => [2500],
        ];
    }

    #[DataProvider('amountsThatDoNotExist')]
    public function test_it_refuses_an_amount_with_a_subdivision(string $amount): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->money->toAmount($amount);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function amountsThatDoNotExist(): array
    {
        return [
            'un centime' => ['2500.50'],
            'une unite et un centime' => ['0.01'],
            'arrondi au centime superieur' => ['2500.999'],
        ];
    }

    public function test_it_explains_that_the_currency_has_no_subdivision(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('le franc CFA n\'a pas de subdivision');

        $this->money->toAmount('2500.50');
    }

    public function test_it_refuses_a_negative_amount(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->money->toAmount('-1');
    }

    public function test_it_refuses_a_non_numeric_amount(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->money->toAmount('gratuit');
    }

    public function test_it_refuses_a_non_finite_amount(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->money->toAmount(INF);
    }

    public function test_it_reads_a_float_that_is_actually_a_whole_number(): void
    {
        /*
         * 2499,9999999999995 n'est pas representable exactement, mais c'est une
         * division, pas une saisie : elle vaut 2500. Le refuser ferait echouer un
         * calcul legitime, alors que la valeur voulue est bien entiere.
         */
        $this->assertSame(2500, $this->money->toAmount(2499.9999999999995));
        $this->assertSame(2500, $this->money->toAmount(2500.0));
    }

    public function test_it_sums_amounts_without_loss(): void
    {
        $this->assertSame(7_500, $this->money->sum([2_500, 5_000]));
        $this->assertSame(0, $this->money->sum([]));
    }

    public function test_it_keeps_a_large_sum_exact(): void
    {
        // La raison d'etre des entiers : au-dela de 2^53, un flottant perd une unite.
        $this->assertSame(29_999_999_997, $this->money->sum([9_999_999_999, 9_999_999_999, 9_999_999_999]));
    }

    public function test_it_compares_amounts_by_value_not_by_writing(): void
    {
        $this->assertTrue($this->money->equals('2500.00', 2500));
        $this->assertTrue($this->money->equals('2 500', '2500'));
        $this->assertFalse($this->money->equals('2500', '2501'));
    }

    public function test_it_rounds_a_computed_amount_to_the_nearest_franc(): void
    {
        // Une remise en pourcentage produit un resultat non entier : c'est le
        // seul moment ou un arrondi a lieu, et il doit etre explicite. La
        // demi-unite monte, comme un arrondi commercial.
        $this->assertSame(2_500, $this->money->roundToPayable(2_499.5));
        $this->assertSame(2_499, $this->money->roundToPayable(2_499.49));
        $this->assertSame(2_499, $this->money->roundToPayable(2_499.1));
        $this->assertSame(1, $this->money->roundToPayable(0.5));
        $this->assertSame(0, $this->money->roundToPayable(0.4));
    }

    public function test_it_refuses_to_round_a_nonsensical_amount(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->money->roundToPayable(-1.0);
    }

    public function test_it_names_its_currency(): void
    {
        $this->assertSame('XOF', Money::CURRENCY);
    }
}
