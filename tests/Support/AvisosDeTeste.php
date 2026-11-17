<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Repositories\AvisoRepository;
use App\Repositories\DispositivoRepository;
use PDO;

/** Atalhos para montar avisos e dispositivos com horários relativos ao "agora" nos testes da TV. */
trait AvisosDeTeste
{
    /**
     * @param array<string, mixed> $extra campos a sobrescrever (titulo, corpo, tipo, midia, midia_tipo, ativo)
     * @param int $inicio segundos a partir de agora (negativo = já começou)
     * @param int $fim segundos a partir de agora
     */
    protected function avisoTv(PDO $pdo, int $condominioId, string $titulo, int $inicio, int $fim, array $extra = []): int
    {
        $agora = time();
        $dados = $extra + [
            'titulo' => $titulo, 'corpo' => "Texto de {$titulo}", 'tipo' => 'comum', 'midia' => null, 'midia_tipo' => null,
            'inicio_epoch' => $agora + $inicio, 'fim_epoch' => $agora + $fim, 'ativo' => true,
        ];
        $usuario = (int) $pdo->query('SELECT id FROM usuarios ORDER BY id LIMIT 1')->fetchColumn() ?: 1;

        return (int) (new AvisoRepository($pdo))->criar([$condominioId], $condominioId, $dados, $usuario);
    }

    protected function dispositivoTv(PDO $pdo, int $condominioId, string $token = 'ABCDEFGH2345', string $nome = 'Elevador'): int
    {
        return (int) (new DispositivoRepository($pdo))->criar($condominioId, $nome, $token);
    }
}
