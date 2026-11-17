<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Token da URL da TV (/tv/{token}): 12 caracteres sorteados com random_int (CSPRNG) de um alfabeto de
 * 31 símbolos sem caracteres ambíguos (sem 0, O, 1, I e L), para ser fácil de digitar com o controle
 * remoto. São cerca de 59 bits; a segurança depende também do rate limit de tokens inválidos.
 */
final class TokenDispositivo
{
    public const ALFABETO = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';
    public const TAMANHO = 12;

    public static function gerar(): string
    {
        $token = '';
        $ultimo = strlen(self::ALFABETO) - 1;
        for ($i = 0; $i < self::TAMANHO; $i++) {
            $token .= self::ALFABETO[random_int(0, $ultimo)];
        }

        return $token;
    }

    /** Aceita o token em qualquer caixa e devolve a forma guardada (maiúsculas); null se o formato for inválido. */
    public static function normalizar(string $token): ?string
    {
        $token = strtoupper($token);

        return preg_match('/^[' . self::ALFABETO . ']{' . self::TAMANHO . '}$/', $token) === 1 ? $token : null;
    }
}
