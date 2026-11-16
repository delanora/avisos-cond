<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Validador simples. Regras separadas por "|":
 * required, email, int, min:N, max:N, in:a,b,c, same:outro_campo
 *
 * min/max medem o tamanho do texto (ou o valor, quando a regra "int" está presente).
 * Mensagens em português do Brasil.
 */
final class Validator
{
    /** @var array<string, string> */
    private array $erros = [];

    /** @var array<string, string> */
    private array $valores = [];

    /** @var array<string, mixed> */
    private readonly array $dados;

    /**
     * @param array<string, mixed>  $dados
     * @param array<string, string> $regras
     */
    public function __construct(array $dados, array $regras)
    {
        $this->dados = $dados;

        foreach ($regras as $campo => $regra) {
            $valor = $dados[$campo] ?? '';
            $valor = is_string($valor) ? trim($valor) : '';
            $this->valores[$campo] = $valor;

            $erro = $this->validarCampo($valor, explode('|', $regra));
            if ($erro !== null) {
                $this->erros[$campo] = $erro;
            }
        }
    }

    public function passes(): bool
    {
        return $this->erros === [];
    }

    /** @return array<string, string> primeiro erro de cada campo */
    public function errors(): array
    {
        return $this->erros;
    }

    /** @return array<string, string> só os campos que têm regra, já sem espaços nas pontas */
    public function valores(): array
    {
        return $this->valores;
    }

    /** @param list<string> $regras */
    private function validarCampo(string $valor, array $regras): ?string
    {
        $obrigatorio = in_array('required', $regras, true);
        if ($valor === '') {
            return $obrigatorio ? 'Este campo é obrigatório.' : null;
        }

        $numerico = in_array('int', $regras, true);

        foreach ($regras as $regra) {
            [$nome, $parametro] = array_pad(explode(':', $regra, 2), 2, '');

            $erro = match ($nome) {
                'email' => filter_var($valor, FILTER_VALIDATE_EMAIL) === false
                    ? 'Informe um e-mail válido.' : null,
                'int' => filter_var($valor, FILTER_VALIDATE_INT) === false
                    ? 'Informe um número inteiro.' : null,
                'min' => $this->medida($valor, $numerico) < (int) $parametro
                    ? ($numerico ? "O valor mínimo é {$parametro}." : "Use no mínimo {$parametro} caracteres.") : null,
                'max' => $this->medida($valor, $numerico) > (int) $parametro
                    ? ($numerico ? "O valor máximo é {$parametro}." : "Use no máximo {$parametro} caracteres.") : null,
                'in' => !in_array($valor, explode(',', $parametro), true)
                    ? 'Valor inválido.' : null,
                'same' => !hash_equals((string) ($this->dados[$parametro] ?? ''), $valor)
                    ? 'Os valores não coincidem.' : null,
                default => null,
            };

            if ($erro !== null) {
                return $erro;
            }
        }

        return null;
    }

    private function medida(string $valor, bool $numerico): int
    {
        return $numerico ? (int) $valor : mb_strlen($valor);
    }
}
