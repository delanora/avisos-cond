<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Aparência (logo e cores) de um condomínio na TV e nos painéis.
 * Sem personalização, vale o padrão da Embracore.
 */
final class AparenciaService
{
    // Cores do DESIGN.md: verde de destaque e grafite.
    public const COR_PRIMARIA_PADRAO = '#00A67E';
    public const COR_SECUNDARIA_PADRAO = '#202124';
    public const LOGO_PADRAO = '/assets/logo-embracore.svg';

    /**
     * @param array<string, mixed> $condominio precisa de logo, cor_primaria e cor_secundaria (nulos = padrão)
     * @param ?string              $urlLogo    endereço de onde a logo personalizada é entregue (rota protegida)
     * @return array{logo: ?string, logo_padrao: bool, cor_primaria: string, cor_secundaria: string, personalizada: bool}
     */
    public function resolver(array $condominio, ?string $urlLogo = null): array
    {
        $logo = $condominio['logo'] ?? null;
        $primaria = $condominio['cor_primaria'] ?? null;
        $secundaria = $condominio['cor_secundaria'] ?? null;

        return [
            // Logo personalizada é um arquivo em storage/uploads (servido por rota própria, ROADMAP 1.5).
            'logo' => is_string($logo) && $logo !== '' ? ($urlLogo ?? $logo) : self::LOGO_PADRAO,
            'logo_padrao' => !is_string($logo) || $logo === '',
            'cor_primaria' => is_string($primaria) && self::corValida($primaria) ? $primaria : self::COR_PRIMARIA_PADRAO,
            'cor_secundaria' => is_string($secundaria) && self::corValida($secundaria) ? $secundaria : self::COR_SECUNDARIA_PADRAO,
            'personalizada' => $logo !== null || $primaria !== null || $secundaria !== null,
        ];
    }

    /** Cor de texto legível (branco ou grafite) sobre o fundo informado, pelo contraste. */
    public static function corDoTexto(string $fundo): string
    {
        if (!self::corValida($fundo)) {
            return '#FFFFFF';
        }

        $canal = static function (string $hex): float {
            $c = hexdec($hex) / 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        };
        $luminancia = 0.2126 * $canal(substr($fundo, 1, 2)) + 0.7152 * $canal(substr($fundo, 3, 2)) + 0.0722 * $canal(substr($fundo, 5, 2));

        // Escolhe o texto com maior contraste (branco: L=1; grafite #1D2125: L≈0.015).
        $contrasteBranco = 1.05 / ($luminancia + 0.05);
        $contrasteGrafite = ($luminancia + 0.05) / 0.065;

        return $contrasteBranco >= $contrasteGrafite ? '#FFFFFF' : '#1D2125';
    }

    public static function corValida(string $cor): bool
    {
        return preg_match('/^#[0-9A-Fa-f]{6}$/', $cor) === 1;
    }
}
