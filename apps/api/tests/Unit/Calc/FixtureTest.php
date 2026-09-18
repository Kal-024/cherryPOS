<?php

namespace Tests\Unit\Calc;

use App\Services\Calc\SaleCalculator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Los mismos casos que corre Vitest en `packages/calc`.
 *
 * Si estos dos conjuntos de pruebas dejan de dar idéntico, el CI falla — que es
 * el único mecanismo real contra la divergencia de los dos motores. Los
 * fixtures viven fuera de esta aplicación a propósito: son de ambas, no de una.
 */
class FixtureTest extends TestCase
{
    private const FIXTURES = __DIR__.'/../../../../../packages/calc/fixtures';

    public static function fixtureProvider(): array
    {
        $files = glob(self::FIXTURES.'/*.json');
        sort($files);

        $cases = [];
        foreach ($files as $file) {
            $suite = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
            foreach ($suite['cases'] as $case) {
                $cases["{$suite['suite']} · {$case['name']}"] = [$case];
            }
        }

        return $cases;
    }

    public function test_los_fixtures_existen(): void
    {
        $this->assertNotEmpty(
            self::fixtureProvider(),
            'No se encontraron fixtures en packages/calc/fixtures. El motor de cálculo '
            .'no puede darse por verde sin ellos.'
        );
    }

    #[DataProvider('fixtureProvider')]
    public function test_el_motor_php_coincide_con_el_fixture(array $case): void
    {
        $result = (new SaleCalculator)->calculate($case['input']);

        $this->assertSame(
            json_encode($case['expected'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            $case['description']
        );
    }
}
