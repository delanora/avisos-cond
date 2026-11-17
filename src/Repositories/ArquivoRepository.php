<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

/** Quais arquivos de upload ainda são usados (por avisos ou pela logo de algum condomínio). */
final class ArquivoRepository
{
    private const LOTE = 500;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Dos nomes informados, devolve os que estão em uso (mídia de aviso ou logo de condomínio).
     *
     * @param list<string> $nomes
     * @return list<string>
     */
    public function emUso(array $nomes): array
    {
        $usados = [];
        foreach (array_chunk($nomes, self::LOTE) as $lote) {
            $stmt = $this->pdo->prepare(
                'SELECT midia AS nome FROM avisos WHERE midia = ANY(:nomes::text[])
                 UNION
                 SELECT logo AS nome FROM condominios WHERE logo = ANY(:nomes::text[])'
            );
            $stmt->execute(['nomes' => Pg::arrayDeTextos($lote)]);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $nome) {
                $usados[] = (string) $nome;
            }
        }

        return $usados;
    }
}
