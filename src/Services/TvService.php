<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\AvisoRepository;
use App\Repositories\DispositivoRepository;
use App\Support\NaoEncontradoException;
use App\Support\Recorrencia;

/** O que a TV de um condomínio exibe: o estado (JSON) e as mídias. Nada de gerentes, financeiro ou outros condomínios. */
final class TvService
{
    /** Quanto antes do início um aviso já vai para a TV (para exibir na hora certa mesmo offline). */
    public const ANTECEDENCIA_SEGUNDOS = 86400;

    public function __construct(
        private readonly AvisoRepository $avisos,
        private readonly DispositivoRepository $dispositivos,
        private readonly UploadService $upload,
        private readonly int $intervaloSegundos = 45,
        private readonly string $versaoApp = '',
    ) {
    }

    /**
     * Estado para a TV. Quem registra o heartbeat é o controller do /state (esta função só monta os dados).
     *
     * @param array{dispositivo: array<string, mixed>, condominio: array<string, mixed>} $acesso
     * @return array<string, mixed>
     */
    public function estado(array $acesso, int $agora): array
    {
        $condominio = $acesso['condominio'];
        $token = (string) $acesso['dispositivo']['token'];

        // Condomínio desativado (ou bloqueado, a partir da Fase 3): a TV mostra só o relógio.
        $bloqueado = !$condominio['ativo'] || ($condominio['bloqueado'] ?? false) === true;

        $avisos = [];
        if (!$bloqueado) {
            $linhas = $this->avisos->visiveisNaTv([(int) $condominio['id']], (int) $condominio['id'], $agora, self::ANTECEDENCIA_SEGUNDOS);
            foreach ($linhas as $linha) {
                $avisos[] = [
                    'id' => $linha['id'],
                    'titulo' => $linha['titulo'],
                    'corpo' => $linha['corpo'],
                    'tipo' => $linha['tipo'],
                    'tipo_nome' => $linha['tipo_nome'],
                    // Fixo = vai para a faixa do topo (só texto); a cor do tipo vira etiqueta e cartão (tom claro) na TV.
                    'fixo' => $linha['fixo'],
                    'visual' => AparenciaService::coresDoTipo($linha['tipo_cor']),
                    // Dias da semana (0 = domingo) e janela de horário em Brasília; a TV aplica pelo relógio corrigido.
                    'repete' => Recorrencia::temRepeticao($linha['dias_semana'], $linha['hora_inicio']) ? [
                        'dias' => $linha['dias_semana'] === null ? null : Recorrencia::listaDeDias($linha['dias_semana']),
                        'inicio' => $linha['hora_inicio'],
                        'fim' => $linha['hora_fim'],
                    ] : null,
                    'inicio_em' => gmdate('Y-m-d\TH:i:s\Z', $linha['inicio_epoch']),
                    'fim_em' => gmdate('Y-m-d\TH:i:s\Z', $linha['fim_epoch']),
                    'midia' => $linha['midia'] === null ? null : [
                        'tipo' => $linha['midia_tipo'],
                        'url' => "/tv/{$token}/media/{$linha['midia']}",
                    ],
                ];
            }
        }

        // Tudo que muda a exibição entra na versão; a TV só redesenha quando ela muda.
        $conteudo = [
            'bloqueado' => $bloqueado,
            'avisos' => $avisos,
        ];

        return $conteudo + [
            'version' => substr(sha1(json_encode($conteudo, JSON_THROW_ON_ERROR)), 0, 16),
            'server_time' => gmdate('Y-m-d\TH:i:s\Z', $agora),
            'server_epoch' => $agora,
            'intervalo' => $this->intervaloSegundos,
            // Versão do JS/CSS da TV: quando muda (deploy), a TV se recarrega sozinha.
            'app' => $this->versaoApp,
        ];
    }

    /** Heartbeat: a TV acabou de consultar o estado. */
    public function registrarContato(array $acesso): void
    {
        $this->dispositivos->registrarPing((int) $acesso['dispositivo']['id']);
    }

    /**
     * Caminho do arquivo de mídia de um aviso visível na TV deste condomínio.
     *
     * @param array{dispositivo: array<string, mixed>, condominio: array<string, mixed>} $acesso
     * @return array{caminho: string, nome: string}
     * @throws NaoEncontradoException
     */
    public function midia(array $acesso, string $nomeDoArquivo, int $agora): array
    {
        $condominio = $acesso['condominio'];
        if (!$condominio['ativo'] || ($condominio['bloqueado'] ?? false) === true) {
            throw new NaoEncontradoException();
        }

        $aviso = $this->avisos->porMidiaVisivelNaTv([(int) $condominio['id']], (int) $condominio['id'], $nomeDoArquivo, $agora, self::ANTECEDENCIA_SEGUNDOS);
        $caminho = $aviso === null ? null : $this->upload->caminho($nomeDoArquivo);
        if ($caminho === null) {
            throw new NaoEncontradoException();
        }

        return ['caminho' => $caminho, 'nome' => $nomeDoArquivo];
    }
}
