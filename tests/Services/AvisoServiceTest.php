<?php

declare(strict_types=1);

namespace Tests\Services;

use App\Repositories\AvisoRepository;
use App\Services\AvisoService;
use App\Services\UploadService;
use App\Services\ValidacaoException;
use App\Support\NaoEncontradoException;
use Tests\BancoMigradoTestCase;
use Tests\Support\Amostras;

final class AvisoServiceTest extends BancoMigradoTestCase
{
    private string $dir;
    private int $a;
    private int $b;
    private int $usuario;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/avisos_srv_' . bin2hex(random_bytes(6));
        $this->a = $this->condominio('A');
        $this->b = $this->condominio('B');
        $this->usuario = $this->criarUsuario('g@exemplo.com.br');
        $this->padroesDoGerente($this->usuario);
        $this->vincular($this->usuario, $this->a);
        $this->vincular($this->usuario, $this->b);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $arquivo) {
            @unlink($arquivo);
        }
        @rmdir($this->dir);
        Amostras::limpar();
    }

    private function condominio(string $nome): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO condominios (nome) VALUES (:n) RETURNING id');
        $stmt->execute(['n' => $nome]);

        return (int) $stmt->fetchColumn();
    }

    private function servico(): AvisoService
    {
        return new AvisoService(
            new AvisoRepository($this->pdo),
            new UploadService($this->dir, 1048576, static fn (string $de, string $para): bool => copy($de, $para)),
            new \App\Services\TipoAvisoService(new \App\Repositories\TipoAvisoRepository($this->pdo))
        );
    }

    /** @return array<string, mixed> */
    private function dados(array $extra = []): array
    {
        return $extra + [
            'titulo' => 'Falta de água', 'corpo' => "Amanhã das 8h às 12h.\nDesculpe o transtorno.", 'tipo' => 'manutencao',
            'inicio_em' => '2026-11-20T08:00', 'fim_em' => '2026-11-20T12:00', 'ativo' => '1',
        ];
    }

    /** @return list<string> */
    private function arquivosGuardados(): array
    {
        return array_map('basename', glob($this->dir . '/*') ?: []);
    }

    public function testCriaAvisoSemMidia(): void
    {
        $id = $this->servico()->criar([$this->a], $this->a, $this->dados(), null, $this->usuario);

        $aviso = $this->servico()->buscar([$this->a], $this->a, $id);
        $this->assertSame('Falta de água', $aviso['titulo']);
        $this->assertSame("Amanhã das 8h às 12h.\nDesculpe o transtorno.", $aviso['corpo']);
        $this->assertSame('manutencao', $aviso['tipo']);
        $this->assertNull($aviso['midia']);
        $this->assertTrue($aviso['ativo']);
        $this->assertSame($this->a, $aviso['condominio_id']);
        $this->assertSame($this->usuario, $aviso['criado_por']);
    }

    public function testHorariosDoFormularioSaoHoraDeBrasiliaGuardadaEmUtc(): void
    {
        $id = $this->servico()->criar([$this->a], $this->a, $this->dados(), null, $this->usuario);

        $aviso = $this->servico()->buscar([$this->a], $this->a, $id);

        $this->assertSame(gmmktime(11, 0, 0, 11, 20, 2026), $aviso['inicio_epoch'], '08:00 de Brasília = 11:00 UTC');
        $this->assertSame(gmmktime(15, 0, 0, 11, 20, 2026), $aviso['fim_epoch']);
    }

    public function testCriaAvisoComImagemEComVideo(): void
    {
        $comImagem = $this->servico()->criar([$this->a], $this->a, $this->dados(), Amostras::arquivo('foto.jpg', Amostras::jpg()), $this->usuario);
        $comVideo = $this->servico()->criar([$this->a], $this->a, $this->dados(), Amostras::arquivo('v.mp4', Amostras::mp4()), $this->usuario);

        $imagem = $this->servico()->buscar([$this->a], $this->a, $comImagem);
        $video = $this->servico()->buscar([$this->a], $this->a, $comVideo);
        $this->assertSame('imagem', $imagem['midia_tipo']);
        $this->assertSame('video', $video['midia_tipo']);
        $this->assertFileExists($this->dir . '/' . $imagem['midia']);
        $this->assertFileExists($this->dir . '/' . $video['midia']);
    }

    public function testAvisoSemMidiaIgnoraOCampoDeArquivoVazio(): void
    {
        $id = $this->servico()->criar([$this->a], $this->a, $this->dados(), Amostras::nenhum(), $this->usuario);

        $this->assertNull($this->servico()->buscar([$this->a], $this->a, $id)['midia']);
        $this->assertSame([], $this->arquivosGuardados());
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function invalidos(): array
    {
        return [
            'título vazio' => [['titulo' => ''], 'titulo'],
            'título longo demais' => [['titulo' => str_repeat('x', 151)], 'titulo'],
            'texto longo demais' => [['corpo' => str_repeat('x', 2001)], 'corpo'],
            'tipo inválido' => [['tipo' => 'propaganda'], 'tipo'],
            'início inválido' => [['inicio_em' => 'amanhã'], 'inicio_em'],
            'fim inválido' => [['fim_em' => ''], 'fim_em'],
            'fim antes do início' => [['fim_em' => '2026-11-20T07:59'], 'fim_em'],
            'fim igual ao início' => [['fim_em' => '2026-11-20T08:00'], 'fim_em'],
        ];
    }

    /** @param array<string, mixed> $alteracao */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidos')]
    public function testValidacoesNaoGravamNadaNemGuardamArquivo(array $alteracao, string $campo): void
    {
        try {
            $this->servico()->criar([$this->a], $this->a, $alteracao + $this->dados(), Amostras::arquivo('foto.jpg', Amostras::jpg()), $this->usuario);
            $this->fail('Era esperada uma ValidacaoException.');
        } catch (ValidacaoException $e) {
            $this->assertArrayHasKey($campo, $e->erros);
        }

        $this->assertSame(0, (int) $this->pdo->query('SELECT count(*) FROM avisos')->fetchColumn());
        $this->assertSame([], $this->arquivosGuardados(), 'Com dados inválidos, o arquivo nem chega a ser guardado.');
    }

    public function testMidiaInvalidaVoltaComoErroDoCampoENaoCriaOAviso(): void
    {
        try {
            $this->servico()->criar([$this->a], $this->a, $this->dados(), Amostras::arquivo('doc.pdf', Amostras::pdf()), $this->usuario);
            $this->fail('Era esperada uma ValidacaoException.');
        } catch (ValidacaoException $e) {
            $this->assertArrayHasKey('midia', $e->erros);
            $this->assertStringContainsString('Envie uma imagem', $e->erros['midia']);
        }

        $this->assertSame(0, (int) $this->pdo->query('SELECT count(*) FROM avisos')->fetchColumn());
    }

    public function testCriarEmCondominioNaoPermitidoRetorna404SemGuardarArquivo(): void
    {
        try {
            $this->servico()->criar([$this->a], $this->b, $this->dados(), Amostras::arquivo('foto.jpg', Amostras::jpg()), $this->usuario);
            $this->fail('Era esperada uma NaoEncontradoException.');
        } catch (NaoEncontradoException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(0, (int) $this->pdo->query('SELECT count(*) FROM avisos')->fetchColumn());
        $this->assertSame([], $this->arquivosGuardados());
    }

    public function testAtualizarAlteraOsCampos(): void
    {
        $id = $this->servico()->criar([$this->a], $this->a, $this->dados(), null, $this->usuario);

        $this->servico()->atualizar([$this->a], $this->a, $id, ['titulo' => 'Novo título', 'tipo' => 'urgente', 'ativo' => ''] + $this->dados(), null, $this->usuario);

        $aviso = $this->servico()->buscar([$this->a], $this->a, $id);
        $this->assertSame('Novo título', $aviso['titulo']);
        $this->assertSame('urgente', $aviso['tipo']);
        $this->assertFalse($aviso['ativo']);
    }

    public function testTrocarMidiaApagaOArquivoAntigo(): void
    {
        $id = $this->servico()->criar([$this->a], $this->a, $this->dados(), Amostras::arquivo('a.jpg', Amostras::jpg()), $this->usuario);
        $antiga = $this->servico()->buscar([$this->a], $this->a, $id)['midia'];

        $this->servico()->atualizar([$this->a], $this->a, $id, $this->dados(), Amostras::arquivo('v.mp4', Amostras::mp4()), $this->usuario);

        $aviso = $this->servico()->buscar([$this->a], $this->a, $id);
        $this->assertSame('video', $aviso['midia_tipo']);
        $this->assertNotSame($antiga, $aviso['midia']);
        $this->assertFileDoesNotExist($this->dir . '/' . $antiga);
        $this->assertSame([$aviso['midia']], $this->arquivosGuardados());
    }

    public function testAtualizarSemNovaMidiaMantemAAtual(): void
    {
        $id = $this->servico()->criar([$this->a], $this->a, $this->dados(), Amostras::arquivo('a.jpg', Amostras::jpg()), $this->usuario);
        $antes = $this->servico()->buscar([$this->a], $this->a, $id)['midia'];

        $this->servico()->atualizar([$this->a], $this->a, $id, ['titulo' => 'Outro'] + $this->dados(), Amostras::nenhum(), $this->usuario);

        $this->assertSame($antes, $this->servico()->buscar([$this->a], $this->a, $id)['midia']);
        $this->assertFileExists($this->dir . '/' . $antes);
    }

    public function testRemoverMidiaApagaOArquivoELimpaOsCampos(): void
    {
        $id = $this->servico()->criar([$this->a], $this->a, $this->dados(), Amostras::arquivo('a.jpg', Amostras::jpg()), $this->usuario);

        $this->servico()->atualizar([$this->a], $this->a, $id, ['remover_midia' => '1'] + $this->dados(), null, $this->usuario);

        $aviso = $this->servico()->buscar([$this->a], $this->a, $id);
        $this->assertNull($aviso['midia']);
        $this->assertNull($aviso['midia_tipo']);
        $this->assertSame([], $this->arquivosGuardados());
    }

    public function testNovaMidiaInvalidaNaoTiraAAtual(): void
    {
        $id = $this->servico()->criar([$this->a], $this->a, $this->dados(), Amostras::arquivo('a.jpg', Amostras::jpg()), $this->usuario);
        $antes = $this->servico()->buscar([$this->a], $this->a, $id)['midia'];

        try {
            $this->servico()->atualizar([$this->a], $this->a, $id, $this->dados(), Amostras::arquivo('x.svg', Amostras::svg()), $this->usuario);
            $this->fail('Era esperada uma ValidacaoException.');
        } catch (ValidacaoException $e) {
            $this->assertArrayHasKey('midia', $e->erros);
        }

        $this->assertSame($antes, $this->servico()->buscar([$this->a], $this->a, $id)['midia']);
        $this->assertSame([$antes], $this->arquivosGuardados());
    }

    public function testExcluirApagaOAvisoEAMidia(): void
    {
        $id = $this->servico()->criar([$this->a], $this->a, $this->dados(), Amostras::arquivo('a.jpg', Amostras::jpg()), $this->usuario);

        $this->servico()->excluir([$this->a], $this->a, $id);

        $this->assertSame(0, (int) $this->pdo->query('SELECT count(*) FROM avisos')->fetchColumn());
        $this->assertSame([], $this->arquivosGuardados());
    }

    public function testNaoExisteAvisoDeOutroCondominio(): void
    {
        $idB = $this->servico()->criar([$this->b], $this->b, $this->dados(['titulo' => 'Do B']), Amostras::arquivo('a.jpg', Amostras::jpg()), $this->usuario);

        foreach ([
            fn () => $this->servico()->buscar([$this->a], $this->a, $idB),
            fn () => $this->servico()->buscar([$this->a], $this->b, $idB),
            fn () => $this->servico()->atualizar([$this->a], $this->a, $idB, $this->dados(), null, $this->usuario),
            fn () => $this->servico()->atualizar([$this->a], $this->b, $idB, $this->dados(), null, $this->usuario),
            fn () => $this->servico()->excluir([$this->a], $this->a, $idB),
            fn () => $this->servico()->excluir([$this->a], $this->b, $idB),
            fn () => $this->servico()->listar([$this->a], $this->b),
        ] as $acao) {
            try {
                $acao();
                $this->fail('Era esperada uma NaoEncontradoException.');
            } catch (NaoEncontradoException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame('Do B', $this->servico()->buscar([$this->b], $this->b, $idB)['titulo']);
        $this->assertCount(1, $this->arquivosGuardados(), 'A mídia do aviso do B continua.');
    }

    public function testListarMostraSituacaoDeCadaAviso(): void
    {
        $agora = gmmktime(12, 0, 0, 11, 20, 2026);
        $criar = fn (string $inicio, string $fim, string $ativo = '1') => $this->servico()->criar(
            [$this->a], $this->a, ['inicio_em' => $inicio, 'fim_em' => $fim, 'ativo' => $ativo] + $this->dados(), null, $this->usuario
        );
        $criar('2026-11-20T08:00', '2026-11-20T18:00'); // 11:00-21:00 UTC: no ar às 12:00 UTC
        $criar('2026-11-21T08:00', '2026-11-22T08:00'); // futuro
        $criar('2026-11-18T08:00', '2026-11-19T08:00'); // passado
        $criar('2026-11-20T08:00', '2026-11-20T18:00', ''); // desativado

        $situacoes = array_column(array_column($this->servico()->listar([$this->a], $this->a, $agora), 'situacao'), 'codigo');

        $this->assertEqualsCanonicalizing(['no-ar', 'agendado', 'encerrado', 'desativado'], $situacoes);
    }

    public function testSituacaoNosLimitesDoPeriodo(): void
    {
        $aviso = ['ativo' => true, 'inicio_epoch' => 1000, 'fim_epoch' => 2000];

        $this->assertSame('agendado', AvisoService::situacao($aviso, 999)['codigo']);
        $this->assertSame('no-ar', AvisoService::situacao($aviso, 1000)['codigo'], 'O início já conta.');
        $this->assertSame('no-ar', AvisoService::situacao($aviso, 1999)['codigo']);
        $this->assertSame('encerrado', AvisoService::situacao($aviso, 2000)['codigo'], 'O fim já não conta.');
        $this->assertSame('desativado', AvisoService::situacao(['ativo' => false] + $aviso, 1500)['codigo']);
    }

    // ---------- Repetição (Fase 2, 2.2c) ----------

    public function testCriaAvisoComDiasEJanelaDeHorario(): void
    {
        $id = $this->servico()->criar([$this->a], $this->a, $this->dados(['dias' => ['2', '5'], 'hora_inicio' => '07:00', 'hora_fim' => '09:30']), null, $this->usuario);

        $aviso = $this->servico()->buscar([$this->a], $this->a, $id);

        $this->assertSame([0b0100100, '07:00', '09:30'], [$aviso['dias_semana'], $aviso['hora_inicio'], $aviso['hora_fim']]);
    }

    public function testSemRepeticaoFicaTudoNuloENosSeteDiasTambem(): void
    {
        $a = $this->servico()->criar([$this->a], $this->a, $this->dados(), null, $this->usuario);
        $b = $this->servico()->criar([$this->a], $this->a, $this->dados(['dias' => ['0', '1', '2', '3', '4', '5', '6']]), null, $this->usuario);

        foreach ([$a, $b] as $id) {
            $aviso = $this->servico()->buscar([$this->a], $this->a, $id);
            $this->assertSame([null, null, null], [$aviso['dias_semana'], $aviso['hora_inicio'], $aviso['hora_fim']]);
        }
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function repeticoesInvalidas(): array
    {
        return [
            'só a hora inicial' => [['hora_inicio' => '08:00'], 'hora_fim'],
            'só a hora final' => [['hora_fim' => '08:00'], 'hora_fim'],
            'fim antes do início' => [['hora_inicio' => '09:00', 'hora_fim' => '08:00'], 'hora_fim'],
            'fim igual ao início' => [['hora_inicio' => '09:00', 'hora_fim' => '09:00'], 'hora_fim'],
            'atravessando a meia-noite' => [['hora_inicio' => '22:00', 'hora_fim' => '02:00'], 'hora_fim'],
            'hora inválida' => [['hora_inicio' => '25:00', 'hora_fim' => '26:00'], 'hora_fim'],
            'texto no lugar da hora' => [['hora_inicio' => 'abc', 'hora_fim' => 'def'], 'hora_fim'],
            'dia fora de 0 a 6' => [['dias' => ['7']], 'dias'],
            'dia não numérico' => [['dias' => ['segunda']], 'dias'],
            'dia como lista aninhada' => [['dias' => [['1']]], 'dias'],
        ];
    }

    /** @param array<string, mixed> $alteracao */
    #[\PHPUnit\Framework\Attributes\DataProvider('repeticoesInvalidas')]
    public function testRepeticaoInvalidaNaoGravaNada(array $alteracao, string $campo): void
    {
        try {
            $this->servico()->criar([$this->a], $this->a, $this->dados($alteracao), null, $this->usuario);
            $this->fail('Deveria falhar.');
        } catch (ValidacaoException $e) {
            $this->assertArrayHasKey($campo, $e->erros);
        }
        $this->assertSame(0, (int) $this->pdo->query('SELECT count(*) FROM avisos')->fetchColumn());
    }

    public function testAtualizarMudaERemoveARepeticao(): void
    {
        $id = $this->servico()->criar([$this->a], $this->a, $this->dados(['dias' => ['1'], 'hora_inicio' => '08:00', 'hora_fim' => '09:00']), null, $this->usuario);

        $this->servico()->atualizar([$this->a], $this->a, $id, $this->dados(['dias' => ['3', '4']]), null, $this->usuario);
        $depois = $this->servico()->buscar([$this->a], $this->a, $id);
        $this->assertSame([0b0011000, null, null], [$depois['dias_semana'], $depois['hora_inicio'], $depois['hora_fim']]);

        $this->servico()->atualizar([$this->a], $this->a, $id, $this->dados(), null, $this->usuario);
        $limpo = $this->servico()->buscar([$this->a], $this->a, $id);
        $this->assertSame([null, null, null], [$limpo['dias_semana'], $limpo['hora_inicio'], $limpo['hora_fim']]);
    }

    public function testDiaInteiroComTodosOsDiasEquivaleASemRepeticaoMasUmaJanelaMenorNao(): void
    {
        $inteiro = $this->servico()->criar([$this->a], $this->a, $this->dados(['dias' => ['0', '1', '2', '3', '4', '5', '6'], 'hora_inicio' => '00:00', 'hora_fim' => '23:59']), null, $this->usuario);
        $menor = $this->servico()->criar([$this->a], $this->a, $this->dados(['hora_inicio' => '00:00', 'hora_fim' => '23:00']), null, $this->usuario);

        $a = $this->servico()->buscar([$this->a], $this->a, $inteiro);
        $b = $this->servico()->buscar([$this->a], $this->a, $menor);
        $this->assertSame([null, null, null], [$a['dias_semana'], $a['hora_inicio'], $a['hora_fim']]);
        $this->assertSame([null, '00:00', '23:00'], [$b['dias_semana'], $b['hora_inicio'], $b['hora_fim']]);
    }

    public function testSituacaoForaDoHorarioDentroDoPeriodo(): void
    {
        // 2026-11-17 é terça; 12:00 UTC = 09:00 em Brasília.
        $agora = gmmktime(12, 0, 0, 11, 17, 2026);
        $base = ['ativo' => true, 'inicio_epoch' => $agora - 86400, 'fim_epoch' => $agora + 86400];

        $this->assertSame('no-ar', AvisoService::situacao($base + ['dias_semana' => 0b0000100, 'hora_inicio' => '08:00', 'hora_fim' => '10:00'], $agora)['codigo']);
        $this->assertSame('fora-do-horario', AvisoService::situacao($base + ['dias_semana' => 0b0000100, 'hora_inicio' => '10:00', 'hora_fim' => '11:00'], $agora)['codigo']);
        $this->assertSame('fora-do-horario', AvisoService::situacao($base + ['dias_semana' => 0b0001000, 'hora_inicio' => null, 'hora_fim' => null], $agora)['codigo']);
        $this->assertSame('encerrado', AvisoService::situacao(['fim_epoch' => $agora - 1] + $base + ['dias_semana' => 0b0001000], $agora)['codigo'], 'Período encerrado vem antes.');
        $this->assertSame('agendado', AvisoService::situacao(['inicio_epoch' => $agora + 1] + $base + ['dias_semana' => 0b0001000], $agora)['codigo']);
    }

    public function testListarTrazOResumoDaRepeticao(): void
    {
        $this->servico()->criar([$this->a], $this->a, $this->dados(['dias' => ['2', '5'], 'hora_inicio' => '07:00', 'hora_fim' => '09:00']), null, $this->usuario);
        $this->servico()->criar([$this->a], $this->a, $this->dados(['titulo' => 'Sem repetir']), null, $this->usuario);

        $lista = $this->servico()->listar([$this->a], $this->a);

        $porTitulo = array_column($lista, 'repeticao', 'titulo');
        $this->assertSame('Ter e Sex · 07:00 às 09:00', $porTitulo['Falta de água']);
        $this->assertNull($porTitulo['Sem repetir']);
    }
}
