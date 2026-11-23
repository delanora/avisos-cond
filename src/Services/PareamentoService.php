<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\DispositivoRepository;
use App\Repositories\PareamentoRepository;
use App\Support\NaoEncontradoException;
use App\Support\Transaction;
use RuntimeException;

/**
 * Pareamento da TV por código. A TV (sem login) pede um código em /tv e fica consultando com um segredo
 * de 128 bits (só o hash é guardado). O superadmin digita o código no painel do dispositivo; a TV então recebe,
 * uma única vez, o token do dispositivo e passa a abrir /tv/{token} sozinha. Parear troca o token do dispositivo:
 * o endereço antigo (e a TV antiga, se houver) deixa de funcionar.
 */
final class PareamentoService
{
    public const CODIGO_TAMANHO = 6;
    public const VALIDADE = 600;
    /** Depois de pareado, a TV tem este tempo para buscar o token. */
    public const JANELA_DE_ENTREGA = 120;
    /** Intervalo sugerido de consulta da TV, em segundos. */
    public const INTERVALO = 4;

    // Códigos novos: 20 por IP em 10 min (uma TV só pede outro quando o anterior vence), bloqueio de 10 min.
    private const MAX_CODIGOS = 20;
    private const JANELA_CODIGOS = 600;
    private const BLOQUEIO_CODIGOS = 600;
    // Segredo desconhecido: 30 falhas em 10 min bloqueiam o IP por 15 min (mesmo desenho da TvAcesso).
    private const MAX_FALHAS = 30;
    private const JANELA_FALHAS = 600;
    private const BLOQUEIO_FALHAS = 900;
    private const TENTATIVAS_DE_CODIGO = 10;
    // Códigos errados digitados por UM usuário: 10 em 15 min (o 10º já bloqueia) bloqueiam por 15 min (o gerente não pode adivinhar o código de uma TV de outro condomínio).
    private const MAX_ERROS_DO_USUARIO = 9; // o 10º erro liga o bloqueio
    private const JANELA_ERROS = 900;
    private const BLOQUEIO_ERROS = 900;

    public function __construct(
        private readonly PareamentoRepository $pareamentos,
        private readonly DispositivoRepository $dispositivos,
        private readonly DispositivoService $servicoDispositivos,
        private readonly RateLimiter $limitador,
        private readonly Transaction $transacao,
    ) {
    }

    /**
     * Abre um pareamento para a TV que pediu.
     *
     * @return array{codigo: string, segredo: string, expira_em: int}
     * @throws RateLimitExcedidoException
     */
    public function iniciar(string $ip, int $agora): array
    {
        $chave = 'pareamento:codigo:ip:' . $ip;
        $espera = $this->limitador->segundosBloqueado($chave);
        if ($espera > 0) {
            throw new RateLimitExcedidoException($espera);
        }
        if ($this->limitador->registrar($chave, self::MAX_CODIGOS, self::JANELA_CODIGOS, self::BLOQUEIO_CODIGOS)) {
            throw new RateLimitExcedidoException(self::BLOQUEIO_CODIGOS);
        }

        $this->pareamentos->removerPendentesVencidos();
        $segredo = bin2hex(random_bytes(16));
        for ($i = 0; $i < self::TENTATIVAS_DE_CODIGO; $i++) {
            $codigo = self::sortearCodigo();
            if ($this->pareamentos->criar($codigo, hash('sha256', $segredo), $ip, self::VALIDADE)) {
                return ['codigo' => $codigo, 'segredo' => $segredo, 'expira_em' => $agora + self::VALIDADE];
            }
        }

        throw new RuntimeException('Não foi possível gerar um código de pareamento único.');
    }

    /**
     * Consulta da TV. "pareado" traz o token e só acontece uma vez por pareamento.
     *
     * @return array{estado: 'aguardando'|'expirado'}|array{estado: 'pareado', token: string}
     * @throws RateLimitExcedidoException IP bloqueado por excesso de segredos desconhecidos
     * @throws NaoEncontradoException segredo malformado ou desconhecido
     */
    public function consultar(string $segredo, string $ip): array
    {
        $chave = 'pareamento:falha:ip:' . $ip;
        $espera = $this->limitador->segundosBloqueado($chave);
        if ($espera > 0) {
            throw new RateLimitExcedidoException($espera);
        }
        if (preg_match('/^[a-f0-9]{32}$/', $segredo) !== 1) {
            throw new NaoEncontradoException();
        }

        $p = $this->pareamentos->porSegredo(hash('sha256', $segredo), self::JANELA_DE_ENTREGA);
        if ($p === null) {
            $this->limitador->registrar($chave, self::MAX_FALHAS, self::JANELA_FALHAS, self::BLOQUEIO_FALHAS);

            throw new NaoEncontradoException();
        }
        if ($p['expirado'] || $p['entregue']) {
            return ['estado' => 'expirado'];
        }
        if (!$p['pareado']) {
            return ['estado' => 'aguardando'];
        }

        // Pareado e ainda não entregue: só quem ganhar a marcação recebe o token.
        $dispositivo = $this->dispositivos->porId((int) $p['dispositivo_id']);
        if ($dispositivo === null || !$dispositivo['ativo'] || !$this->pareamentos->marcarEntregue($p['id'])) {
            return ['estado' => 'expirado'];
        }

        return ['estado' => 'pareado', 'token' => (string) $dispositivo['token']];
    }

    /**
     * Superadmin liga o código mostrado na TV ao dispositivo e troca o token dele.
     *
     * @return string IP da TV que pediu o código (para conferência e auditoria)
     * @throws NaoEncontradoException dispositivo inexistente
     * @throws ValidacaoException código inválido, vencido ou dispositivo revogado
     */
    public function parear(int $dispositivoId, string $codigoDigitado, int $usuarioId): string
    {
        $chave = 'pareamento:erro:usuario:' . $usuarioId;
        if ($this->limitador->segundosBloqueado($chave) > 0) {
            throw new ValidacaoException(['geral' => 'Muitas tentativas com código incorreto. Aguarde alguns minutos e tente de novo.']);
        }

        $dispositivo = $this->dispositivos->porId($dispositivoId) ?? throw new NaoEncontradoException();
        if (!$dispositivo['ativo']) {
            throw new ValidacaoException(['geral' => 'Este dispositivo está revogado. Reative-o antes de parear uma TV.']);
        }

        $codigo = self::normalizarCodigo($codigoDigitado);
        if ($codigo === null) {
            $this->limitador->registrar($chave, self::MAX_ERROS_DO_USUARIO, self::JANELA_ERROS, self::BLOQUEIO_ERROS);

            throw new ValidacaoException(['codigo' => 'Digite o código de ' . self::CODIGO_TAMANHO . ' caracteres que aparece na TV.']);
        }

        try {
            return $this->transacao->executar(function () use ($dispositivoId, $codigo, $usuarioId): string {
                $ip = $this->pareamentos->parear($codigo, $dispositivoId, $usuarioId)
                    ?? throw new ValidacaoException(['codigo' => 'Código não encontrado ou expirado. Confira na TV ou espere surgir um código novo.']);
                $this->servicoDispositivos->trocarToken($dispositivoId);

                return $ip;
            });
        } catch (ValidacaoException $e) {
            $this->limitador->registrar($chave, self::MAX_ERROS_DO_USUARIO, self::JANELA_ERROS, self::BLOQUEIO_ERROS);

            throw $e;
        }
    }

    /** Aceita minúsculas, espaços e hífens ("abc 234", "ABC-234"); devolve a forma guardada ou null. */
    public static function normalizarCodigo(string $codigo): ?string
    {
        $codigo = strtoupper((string) preg_replace('/[\s\-]+/', '', $codigo));

        return preg_match('/^[' . TokenDispositivo::ALFABETO . ']{' . self::CODIGO_TAMANHO . '}$/', $codigo) === 1 ? $codigo : null;
    }

    private static function sortearCodigo(): string
    {
        $codigo = '';
        $ultimo = strlen(TokenDispositivo::ALFABETO) - 1;
        for ($i = 0; $i < self::CODIGO_TAMANHO; $i++) {
            $codigo .= TokenDispositivo::ALFABETO[random_int(0, $ultimo)];
        }

        return $codigo;
    }
}
