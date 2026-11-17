<?php

declare(strict_types=1);

namespace Tests\Services;

use App\Repositories\UsuarioRepository;
use App\Services\AparenciaService;
use App\Services\ValidacaoException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\BancoMigradoTestCase;

/** Cor de destaque do gerente e as variações calculadas a partir dela. */
final class AparenciaServiceTest extends BancoMigradoTestCase
{
    private function servico(): AparenciaService
    {
        return new AparenciaService(new UsuarioRepository($this->pdo));
    }

    private function usuario(): int
    {
        return (int) $this->pdo->query("INSERT INTO usuarios (nome, email, senha_hash, papel) VALUES ('G', 'g@x.com', 'h', 'gerente') RETURNING id")->fetchColumn();
    }

    private function cor(int $id): ?string
    {
        $v = $this->pdo->query("SELECT cor_primaria FROM usuarios WHERE id = {$id}")->fetchColumn();

        return $v === null ? null : (string) $v;
    }

    private static function contrasteComBranco(string $hex): float
    {
        $canal = static function (string $c): float {
            $v = hexdec($c) / 255;

            return $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
        };

        return 1.05 / (0.2126 * $canal(substr($hex, 1, 2)) + 0.7152 * $canal(substr($hex, 3, 2)) + 0.0722 * $canal(substr($hex, 5, 2)) + 0.05);
    }

    public function testSalvaACorEmMaiusculoEUsarPadraoLimpa(): void
    {
        $id = $this->usuario();

        $this->assertSame('#2F6FED', $this->servico()->salvar($id, ' #2f6fed ', false));
        $this->assertSame('#2F6FED', $this->cor($id));

        $this->assertNull($this->servico()->salvar($id, '#2F6FED', true));
        $this->assertNull($this->cor($id));
    }

    #[DataProvider('coresInvalidas')]
    public function testCorInvalidaEhRejeitadaESemAlterar(mixed $invalida): void
    {
        $id = $this->usuario();
        $this->servico()->salvar($id, '#112233', false);

        try {
            $this->servico()->salvar($id, $invalida, false);
            $this->fail('Cor inválida deveria ser rejeitada.');
        } catch (ValidacaoException $e) {
            $this->assertArrayHasKey('cor_primaria', $e->erros);
        }
        $this->assertSame('#112233', $this->cor($id));
    }

    /** @return array<string, array{mixed}> */
    public static function coresInvalidas(): array
    {
        return ['nome' => ['azul'], 'curta' => ['#FFF'], 'sem cerquilha' => ['112233'], 'lixo' => ['#GGHHII'], 'nula' => [null], 'lista' => [['#112233']], 'injeção' => ["#112233; } body { display:none"]];
    }

    public function testOutroUsuarioNaoEhAfetado(): void
    {
        $a = $this->usuario();
        $b = (int) $this->pdo->query("INSERT INTO usuarios (nome, email, senha_hash, papel) VALUES ('H', 'h@x.com', 'h', 'gerente') RETURNING id")->fetchColumn();

        $this->servico()->salvar($a, '#AA0000', false);

        $this->assertNull($this->cor($b));
    }

    #[DataProvider('coresEscolhidas')]
    public function testPaletaDerivadaTemContrasteNosBotoesEClarosMaisClaros(string $cor): void
    {
        $p = AparenciaService::paleta($cor);

        $this->assertSame(strtoupper($cor), $p['--marca']);
        $this->assertGreaterThanOrEqual(4.5, self::contrasteComBranco($p['--marca-700']), 'Texto branco legível nos botões e links.');
        $this->assertGreaterThan(self::contrasteComBranco($p['--marca-700']), self::contrasteComBranco($p['--marca-800']) + 0.0001, 'O tom de hover é mais escuro.');
        $this->assertLessThan(1.5, self::contrasteComBranco($p['--marca-50']), 'O fundo suave é quase branco.');
        $this->assertLessThanOrEqual(self::contrasteComBranco($p['--marca-100']), self::contrasteComBranco($p['--marca-50']), 'O 50 é mais claro que o 100.');
        $this->assertMatchesRegularExpression('/^#[0-9A-F]{6}$/', $p['--marca-50']);
    }

    /** @return array<string, array{string}> */
    public static function coresEscolhidas(): array
    {
        return ['verde padrão' => ['#00A67E'], 'azul' => ['#2F6FED'], 'amarelo claro' => ['#FACC15'], 'vermelho' => ['#D92D20'], 'roxo escuro' => ['#4C1D95'], 'preto' => ['#000000'], 'branco' => ['#FFFFFF'], 'cinza' => ['#808080']];
    }

    public function testPaletaDoVerdePadraoFicaPertoDaDesenhadaAMao(): void
    {
        $p = AparenciaService::paleta('#00A67E');

        // Desenhadas à mão no app.css: #007A5C, #EAF7F2 e #D3F0E5. As calculadas devem ser parecidas.
        $this->assertGreaterThanOrEqual(5.0, self::contrasteComBranco($p['--marca-700']));
        $this->assertSame('#EBF8F5', $p['--marca-50']);
        $this->assertSame('#D1EFE8', $p['--marca-100']);
    }

    public function testCssGeradoSoTemVariaveisValidas(): void
    {
        $css = AparenciaService::css('#2F6FED');

        $this->assertStringStartsWith(":root {\n", $css);
        $this->assertSame(8, substr_count($css, ';'));
        $this->assertDoesNotMatchRegularExpression('/[<>@{}]\s*[<>@]/', $css);
        $this->assertStringContainsString('--marca-foco: rgba(47, 111, 237, 0.18);', $css);
    }

    public function testCorValida(): void
    {
        $this->assertTrue(AparenciaService::corValida('#aBc123'));
        $this->assertFalse(AparenciaService::corValida('#abc'));
        $this->assertFalse(AparenciaService::corValida("#abc123\n"));
    }
}
