<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\UsuarioRepository;

/**
 * Aparência do painel do gerente: uma única cor de destaque. Todas as variações (tons claros para fundos,
 * tons escuros para botões e links) saem dessa cor, calculadas aqui. Sem cor escolhida, vale o verde padrão.
 * A TV não é personalizável.
 */
final class AparenciaService
{
    // Verde do DESIGN.md. Os tons derivados dele ficam no app.css (valores desenhados à mão).
    public const COR_PADRAO = '#00A67E';

    /** Contraste mínimo do branco sobre o tom escuro (botões e links); o padrão atual tem 5,3:1. */
    private const CONTRASTE_ESCURO = 5.0;

    public function __construct(private readonly UsuarioRepository $usuarios)
    {
    }

    /**
     * @return string cor gravada (#RRGGBB maiúsculo) ou null para voltar ao padrão
     * @throws ValidacaoException
     */
    public function salvar(int $usuarioId, mixed $cor, bool $usarPadrao): ?string
    {
        if ($usarPadrao) {
            $this->usuarios->atualizarCor($usuarioId, null);

            return null;
        }
        if (!is_string($cor) || !self::corValida(trim($cor))) {
            throw new ValidacaoException(['cor_primaria' => 'Escolha uma cor válida.']);
        }

        $cor = strtoupper(trim($cor));
        $this->usuarios->atualizarCor($usuarioId, $cor);

        return $cor;
    }

    public static function corValida(string $cor): bool
    {
        return preg_match('/^#[0-9A-Fa-f]{6}\z/', $cor) === 1;
    }

    /**
     * Cores derivadas da principal, como variáveis do app.css.
     *
     * @return array<string, string>
     */
    public static function paleta(string $cor): array
    {
        [$r, $g, $b] = self::rgb($cor);
        [$h, $s, $l] = self::paraHsl($r, $g, $b);

        // Tom escuro: escurece a cor até o branco ter contraste suficiente (texto de botão e links).
        $l700 = $l;
        while ($l700 > 0 && self::contrasteComBranco(...self::deHsl($h, $s, $l700)) < self::CONTRASTE_ESCURO) {
            $l700 = max(0.0, $l700 - 0.01);
        }
        $escuro = self::hex(...self::deHsl($h, $s, $l700));
        $maisEscuro = self::hex(...self::deHsl($h, $s, max(0.0, $l700 - 0.07)));

        return [
            '--marca' => self::hex($r, $g, $b),
            '--marca-700' => $escuro,
            '--marca-800' => $maisEscuro,
            // Tons claros: a cor principal diluída em branco (fundos de destaque e menu ativo).
            '--marca-50' => self::misturarComBranco($r, $g, $b, 0.08),
            '--marca-100' => self::misturarComBranco($r, $g, $b, 0.18),
            '--marca-300' => self::misturarComBranco($r, $g, $b, 0.45),
            '--marca-foco' => sprintf('rgba(%d, %d, %d, 0.18)', $r, $g, $b),
            '--marca-sombra' => sprintf('rgba(%d, %d, %d, 0.25)', ...self::rgb($maisEscuro)),
        ];
    }

    /** Folha de estilo com as variáveis (o CSP não permite estilo inline). */
    public static function css(string $cor): string
    {
        $linhas = '';
        foreach (self::paleta($cor) as $nome => $valor) {
            $linhas .= "    {$nome}: {$valor};\n";
        }

        return ":root {\n{$linhas}}\n";
    }

    /** @return array{int, int, int} */
    private static function rgb(string $cor): array
    {
        return [(int) hexdec(substr($cor, 1, 2)), (int) hexdec(substr($cor, 3, 2)), (int) hexdec(substr($cor, 5, 2))];
    }

    private static function hex(int $r, int $g, int $b): string
    {
        return sprintf('#%02X%02X%02X', $r, $g, $b);
    }

    private static function misturarComBranco(int $r, int $g, int $b, float $parteDaCor): string
    {
        $mistura = static fn (int $c): int => (int) round($c * $parteDaCor + 255 * (1 - $parteDaCor));

        return self::hex($mistura($r), $mistura($g), $mistura($b));
    }

    private static function contrasteComBranco(int $r, int $g, int $b): float
    {
        $canal = static function (int $v): float {
            $c = $v / 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        };

        return 1.05 / (0.2126 * $canal($r) + 0.7152 * $canal($g) + 0.0722 * $canal($b) + 0.05);
    }

    /** @return array{float, float, float} matiz (0-360), saturação e luminosidade (0-1) */
    private static function paraHsl(int $r, int $g, int $b): array
    {
        $r /= 255;
        $g /= 255;
        $b /= 255;
        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $l = ($max + $min) / 2;
        if ($max === $min) {
            return [0.0, 0.0, $l];
        }

        $d = $max - $min;
        $s = $l > 0.5 ? $d / (2 - $max - $min) : $d / ($max + $min);
        $h = match ($max) {
            $r => ($g - $b) / $d + ($g < $b ? 6 : 0),
            $g => ($b - $r) / $d + 2,
            default => ($r - $g) / $d + 4,
        };

        return [$h * 60, $s, $l];
    }

    /** @return array{int, int, int} */
    private static function deHsl(float $h, float $s, float $l): array
    {
        $q = $l < 0.5 ? $l * (1 + $s) : $l + $s - $l * $s;
        $p = 2 * $l - $q;
        $canal = static function (float $t) use ($p, $q): int {
            $t = fmod($t + 1, 1);
            $v = match (true) {
                $t < 1 / 6 => $p + ($q - $p) * 6 * $t,
                $t < 1 / 2 => $q,
                $t < 2 / 3 => $p + ($q - $p) * (2 / 3 - $t) * 6,
                default => $p,
            };

            return (int) round($v * 255);
        };

        return [$canal($h / 360 + 1 / 3), $canal($h / 360), $canal($h / 360 - 1 / 3)];
    }
}
