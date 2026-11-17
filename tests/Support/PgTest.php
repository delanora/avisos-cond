<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Repositories\Pg;
use PDO;
use Tests\DatabaseTestCase;

final class PgTest extends DatabaseTestCase
{
    public function testArrayDeInteirosSoAceitaInteiros(): void
    {
        $this->assertSame('{1,2,3}', Pg::arrayDeInteiros([1, 2, 3]));
        $this->assertSame('{}', Pg::arrayDeInteiros([]));
        $this->assertSame('{7,0}', Pg::arrayDeInteiros(['7', 'abc']), 'Qualquer coisa vira inteiro: nada de texto entra no array.');
    }

    public function testArrayDeTextosEscapaAspasEBarras(): void
    {
        $this->assertSame('{"abc.png"}', Pg::arrayDeTextos(['abc.png']));
        $this->assertSame('{"a\\"b","c\\\\d"}', Pg::arrayDeTextos(['a"b', 'c\\d']));
        $this->assertSame('{}', Pg::arrayDeTextos([]));
    }

    /** @return array<string, array{list<string>}> */
    public static function textosDificeis(): array
    {
        return [
            'comuns' => [['abc.png', 'def.mp4']],
            'aspas e barras' => [['a"b', 'c\\d', '\\"', '"""']],
            'símbolos do array' => [['x,y}', '{braces}', 'a{b,c}d', ',']],
            'palavras especiais' => [['NULL', 'null', 'ARRAY', '']],
            'espaços e acentos' => [['  com espaços ', 'ação', 'ü']],
            'tentativa de injeção' => [["'; DROP TABLE avisos; --", '") OR 1=1 --']],
        ];
    }

    /** @param list<string> $textos */
    #[\PHPUnit\Framework\Attributes\DataProvider('textosDificeis')]
    public function testIdaEVoltaNoPostgresPreservaCadaTexto(array $textos): void
    {
        $stmt = $this->pdo->prepare('SELECT unnest(:lista::text[])');
        $stmt->execute(['lista' => Pg::arrayDeTextos($textos)]);

        $this->assertSame($textos, $stmt->fetchAll(PDO::FETCH_COLUMN));
    }
}
