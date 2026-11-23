<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\AuditoriaRepository;
use App\Support\Logger;
use App\Support\Request;
use Throwable;

/**
 * Registra na auditoria quem fez o quê. Nunca derruba a ação do usuário: se o registro falhar, o erro vai para o log.
 * Em modo suporte, "quem agiu" é o administrador e "como" é o gerente cujo painel ele está usando.
 */
final class AuditoriaService
{
    private const DETALHE_MAX = 300;

    public function __construct(
        private readonly AuditoriaRepository $repositorio,
        private readonly Logger $logger,
    ) {
    }

    /**
     * Registra uma ação feita por quem está na requisição (já autenticado).
     *
     * @param ?array{id: int, nome: string}  $condominio condomínio a que a ação se refere
     * @param array<string, mixed>           $detalhes   resumo do que aconteceu (sem senha, token ou segredo)
     */
    public function registrarDaRequisicao(Request $request, string $acao, ?string $entidade = null, ?int $entidadeId = null, ?array $condominio = null, array $detalhes = []): void
    {
        $usuario = $request->attribute('usuario');
        $this->registrar($acao, is_array($usuario) ? $usuario : null, $request->ip(), $entidade, $entidadeId, $condominio, $detalhes);
    }

    /**
     * @param ?array<string, mixed>          $usuario    usuário atual (em modo suporte traz "suporte_admin" com o administrador)
     * @param ?array{id: int, nome: string}  $condominio
     * @param array<string, mixed>           $detalhes
     */
    public function registrar(string $acao, ?array $usuario, ?string $ip, ?string $entidade = null, ?int $entidadeId = null, ?array $condominio = null, array $detalhes = []): void
    {
        try {
            $real = $usuario;
            $como = null;
            if (is_array($usuario) && is_array($usuario['suporte_admin'] ?? null)) {
                $real = $usuario['suporte_admin'];
                $como = $usuario;
            }

            $this->repositorio->inserir([
                'acao' => $acao,
                'usuario_id' => $real['id'] ?? null,
                'usuario_nome' => isset($real['nome']) ? mb_substr((string) $real['nome'], 0, 150) : null,
                'como_id' => $como['id'] ?? null,
                'como_nome' => isset($como['nome']) ? mb_substr((string) $como['nome'], 0, 150) : null,
                'entidade' => $entidade,
                'entidade_id' => $entidadeId,
                'condominio_id' => $condominio['id'] ?? null,
                'condominio_nome' => isset($condominio['nome']) ? mb_substr((string) $condominio['nome'], 0, 150) : null,
                'detalhes' => self::limpar($detalhes),
                'ip' => $ip,
            ]);
        } catch (Throwable $erro) {
            $this->logger->error("Falha ao registrar a auditoria ({$acao})", $erro);
        }
    }

    /**
     * Deixa só valores simples e curtos, e tira qualquer campo que pareça segredo (senha, token, chave).
     *
     * @param array<string, mixed> $detalhes
     * @return array<string, scalar|null>
     */
    public static function limpar(array $detalhes): array
    {
        $saida = [];
        foreach ($detalhes as $chave => $valor) {
            if (preg_match('/senha|token|secret|segredo|chave|hash/i', (string) $chave) === 1) {
                continue;
            }
            if (is_array($valor)) {
                $valor = implode(', ', array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : '', $valor));
            }
            if (is_bool($valor) || is_int($valor) || is_float($valor) || $valor === null) {
                $saida[(string) $chave] = $valor;
            } elseif (is_string($valor)) {
                $saida[(string) $chave] = mb_substr($valor, 0, self::DETALHE_MAX);
            }
        }

        return $saida;
    }
}
