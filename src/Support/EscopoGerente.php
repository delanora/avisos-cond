<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Acesso, nos controllers, aos condomínios que o gerente pode ver. A lista vem do banco a cada
 * requisição (EscopoGerenteMiddleware); o condomínio da URL só vale se estiver nela.
 */
final class EscopoGerente
{
    /** @return list<array<string, mixed>> */
    public static function condominios(Request $request): array
    {
        $lista = $request->attribute('condominios', []);

        return is_array($lista) ? array_values($lista) : [];
    }

    /** @return list<int> */
    public static function permitidos(Request $request): array
    {
        return array_map(static fn (array $c): int => (int) $c['id'], self::condominios($request));
    }

    /**
     * Condomínio da URL, desde que o gerente tenha acesso.
     *
     * @return array<string, mixed>
     * @throws NaoEncontradoException condomínio inexistente, inativo ou de outro gerente (sempre 404)
     */
    public static function exigir(Request $request, int $condominioId): array
    {
        foreach (self::condominios($request) as $condominio) {
            if ((int) $condominio['id'] === $condominioId) {
                return $condominio;
            }
        }

        throw new NaoEncontradoException();
    }

    /**
     * Dados comuns a todas as telas do gerente (barra superior, seletor de condomínio).
     *
     * @param ?array<string, mixed> $condominio condomínio atual, se houver
     * @return array<string, mixed>
     */
    public static function contexto(Request $request, ?array $condominio = null): array
    {
        return [
            'usuario' => $request->attribute('usuario'),
            'condominios' => self::condominios($request),
            'condominio' => $condominio,
        ];
    }
}
