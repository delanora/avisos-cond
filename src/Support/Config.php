<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Configuração lida do arquivo .env.
 *
 * Variáveis de ambiente reais têm prioridade sobre o arquivo; assim os testes
 * (e o servidor) podem sobrescrever valores sem editar o .env.
 */
final class Config
{
    /** @param array<string, string> $valores */
    public function __construct(private readonly array $valores = [])
    {
    }

    public static function fromFile(string $caminho): self
    {
        // Sem arquivo é válido: a configuração pode vir só do ambiente.
        if (!is_file($caminho)) {
            return new self();
        }

        $linhas = file($caminho, FILE_IGNORE_NEW_LINES);
        if ($linhas === false) {
            throw new ConfigException('Não foi possível ler o arquivo de configuração.');
        }

        $valores = [];
        foreach ($linhas as $linha) {
            $linha = trim($linha);
            if ($linha === '' || $linha[0] === '#' || !str_contains($linha, '=')) {
                continue;
            }

            [$chave, $valor] = explode('=', $linha, 2);
            $valores[trim($chave)] = self::limparValor(trim($valor));
        }

        return new self($valores);
    }

    public function get(string $chave, ?string $padrao = null): ?string
    {
        $ambiente = getenv($chave);
        if ($ambiente !== false) {
            return $ambiente;
        }

        return $this->valores[$chave] ?? $padrao;
    }

    public function string(string $chave, string $padrao = ''): string
    {
        return $this->get($chave) ?? $padrao;
    }

    /** Falha cedo quando uma configuração indispensável está ausente ou vazia. */
    public function obrigatorio(string $chave): string
    {
        $valor = $this->get($chave);
        if ($valor === null || $valor === '') {
            throw new ConfigException("Configuração obrigatória ausente: {$chave}");
        }

        return $valor;
    }

    public function int(string $chave, int $padrao = 0): int
    {
        $valor = $this->get($chave);

        return ($valor === null || $valor === '' || !is_numeric($valor)) ? $padrao : (int) $valor;
    }

    public function bool(string $chave, bool $padrao = false): bool
    {
        $valor = $this->get($chave);
        if ($valor === null || $valor === '') {
            return $padrao;
        }

        return in_array(strtolower($valor), ['1', 'true', 'on', 'yes', 'sim'], true);
    }

    private static function limparValor(string $valor): string
    {
        if ($valor !== '' && ($valor[0] === '"' || $valor[0] === "'")) {
            $aspas = $valor[0];
            $fim = strpos($valor, $aspas, 1);
            if ($fim !== false) {
                return substr($valor, 1, $fim - 1);
            }
        }

        // Comentário no fim da linha ("VALOR   # comentário") ou valor vazio seguido de comentário.
        return rtrim((string) preg_replace('/(^|\s+)#.*$/', '', $valor));
    }
}
