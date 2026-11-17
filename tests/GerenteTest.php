<?php

declare(strict_types=1);

namespace Tests;

use App\Repositories\VinculoRepository;
use Tests\Support\Amostras;

/** Fluxos do painel do gerente: condomínios, avisos (com upload), prévia, mídia e aparência. */
final class GerenteTest extends AppHttpTestCase
{
    private int $gerente;
    private int $condominio;

    /** Gerente logado e vinculado a um condomínio. */
    private function entrar(string $nomeCondominio = 'Residencial Sol'): void
    {
        $this->gerente = $this->criarUsuario('gerente@exemplo.com.br');
        $this->condominio = $this->criarCondominio($nomeCondominio);
        (new VinculoRepository($this->pdo))->sincronizarDoUsuario($this->gerente, [$this->condominio]);
        $this->logar();
    }

    private function base(): string
    {
        return "/gerente/condominios/{$this->condominio}";
    }

    /** @return array<string, string> */
    private function aviso(array $extra = []): array
    {
        return $extra + [
            'titulo' => 'Falta de água', 'corpo' => "Amanhã das 8h às 12h.\nDesculpe o transtorno.", 'tipo' => 'manutencao',
            'inicio_em' => '2026-11-20T08:00', 'fim_em' => '2026-11-20T12:00', 'ativo' => '1',
        ];
    }

    private function criarAviso(array $extra = [], array $arquivos = []): int
    {
        $this->post($this->base() . '/avisos', $this->aviso($extra), arquivos: $arquivos);

        return (int) $this->pdo->query('SELECT max(id) FROM avisos')->fetchColumn();
    }

    /** @return list<string> */
    private function arquivosGuardados(): array
    {
        return array_map('basename', glob($this->uploads . '/*') ?: []);
    }

    // ---------- Condomínios do gerente ----------

    public function testComUmCondominioVaiDiretoParaOsAvisos(): void
    {
        $this->entrar();

        $r = $this->get('/gerente');

        $this->assertSame(302, $r->status);
        $this->assertSame($this->base() . '/avisos', $r->headers['Location']);
    }

    public function testRaizDoSistemaLevaOGerenteAoPainelDele(): void
    {
        $this->entrar();

        $this->assertSame('/gerente', $this->get('/')->headers['Location']);
    }

    public function testComVariosCondominiosMostraAListaSoComOsDele(): void
    {
        $this->entrar('Residencial Sol');
        $segundo = $this->criarCondominio('Edifício Lua');
        $this->criarCondominio('Condomínio Alheio');
        (new VinculoRepository($this->pdo))->sincronizarDoUsuario($this->gerente, [$this->condominio, $segundo]);

        $r = $this->get('/gerente');

        $this->assertSame(200, $r->status);
        $this->assertStringContainsString('Residencial Sol', $r->body);
        $this->assertStringContainsString('Edifício Lua', $r->body);
        $this->assertStringNotContainsString('Condomínio Alheio', $r->body);
        $this->assertStringContainsString("/gerente/condominios/{$segundo}/avisos", $r->body);
    }

    public function testSemNenhumCondominioMostraMensagemAmigavel(): void
    {
        $this->criarUsuario('gerente@exemplo.com.br');
        $this->logar();

        $r = $this->get('/gerente');

        $this->assertSame(200, $r->status);
        $this->assertStringContainsString('Nenhum condomínio por aqui ainda', $r->body);
    }

    public function testSeletorDeCondominioAparecePraTrocarSoQuandoHaMaisDeUm(): void
    {
        $this->entrar('Residencial Sol');
        $this->assertStringNotContainsString('seletor-lista', $this->get($this->base() . '/avisos')->body);
        $this->assertStringContainsString('<span>Residencial Sol</span>', $this->get($this->base() . '/avisos')->body);

        $segundo = $this->criarCondominio('Edifício Lua');
        (new VinculoRepository($this->pdo))->sincronizarDoUsuario($this->gerente, [$this->condominio, $segundo]);

        $corpo = $this->get($this->base() . '/avisos')->body;
        $this->assertStringContainsString('seletor-lista', $corpo);
        $this->assertStringContainsString("/gerente/condominios/{$segundo}/avisos", $corpo);
        $this->assertStringContainsString('aria-current="true"', $corpo);
    }

    // ---------- CRUD de avisos ----------

    public function testListaVaziaMostraEstadoVazio(): void
    {
        $this->entrar();

        $this->assertStringContainsString('Nenhum aviso por aqui', $this->get($this->base() . '/avisos')->body);
    }

    public function testCriarAvisoComImagemMostraNaListaComMensagemUmaVez(): void
    {
        $this->entrar();

        $r = $this->post($this->base() . '/avisos', $this->aviso(), arquivos: ['midia' => Amostras::arquivo('foto.png', Amostras::png())]);

        $this->assertSame(303, $r->status);
        $this->assertSame($this->base() . '/avisos', $r->headers['Location']);

        $lista = $this->get($this->base() . '/avisos');
        $this->assertStringContainsString('Aviso criado com sucesso.', $lista->body);
        $this->assertStringContainsString('Falta de água', $lista->body);
        $this->assertStringContainsString('Manutenção', $lista->body);
        $this->assertStringContainsString('Imagem', $lista->body);
        $this->assertStringContainsString('20/11/2026 08:00 até 20/11/2026 12:00', $lista->body);
        $this->assertStringNotContainsString('Aviso criado com sucesso.', $this->get($this->base() . '/avisos')->body);
        $this->assertCount(1, $this->arquivosGuardados());
    }

    public function testCriarAvisoComVideo(): void
    {
        $this->entrar();

        $this->post($this->base() . '/avisos', $this->aviso(), arquivos: ['midia' => Amostras::arquivo('v.mp4', Amostras::mp4())]);

        $this->assertSame('video', $this->pdo->query('SELECT midia_tipo FROM avisos')->fetchColumn());
        $this->assertStringContainsString('Vídeo', $this->get($this->base() . '/avisos')->body);
    }

    public function testCondominioDoAvisoVemDaUrlEIgnoraOCorpoDoFormulario(): void
    {
        $this->entrar();
        $outro = $this->criarCondominio('Outro');

        $this->post($this->base() . '/avisos', $this->aviso(['condominio_id' => (string) $outro]));

        $this->assertSame($this->condominio, (int) $this->pdo->query('SELECT condominio_id FROM avisos')->fetchColumn());
        $this->assertSame($this->gerente, (int) $this->pdo->query('SELECT criado_por FROM avisos')->fetchColumn());
    }

    public function testFormularioNovoTemSugestaoDePeriodoEDefaults(): void
    {
        $this->entrar();

        $corpo = $this->get($this->base() . '/avisos/novo')->body;

        $this->assertStringContainsString('enctype="multipart/form-data"', $corpo);
        $this->assertMatchesRegularExpression('/name="inicio_em"\s+value="\d{4}-\d{2}-\d{2}T\d{2}:\d{2}"/', $corpo);
        $this->assertMatchesRegularExpression('/<option value="comum" selected>/', $corpo);
        $this->assertMatchesRegularExpression('/name="ativo" value="1" checked/', $corpo);
        $this->assertStringContainsString('Não aceitamos PDF', $corpo);
        $this->assertMatchesRegularExpression('/name="_csrf" value="[a-f0-9]{64}"/', $corpo);
    }

    public function testCriarComErrosMostraMensagensEMantemOsDados(): void
    {
        $this->entrar();

        $r = $this->post($this->base() . '/avisos', $this->aviso(['titulo' => '', 'fim_em' => '2026-11-20T07:00', 'tipo' => 'comum']));

        $this->assertSame(422, $r->status);
        $this->assertStringContainsString('Este campo é obrigatório.', $r->body);
        $this->assertStringContainsString('O fim precisa ser depois do início.', $r->body);
        $this->assertStringContainsString('value="2026-11-20T08:00"', $r->body);
        $this->assertStringContainsString('Desculpe o transtorno.', $r->body);
        $this->assertSame(0, $this->contar('avisos'));
    }

    public function testMidiaInvalidaMostraErroNoCampoSemCriarOAviso(): void
    {
        $this->entrar();

        $r = $this->post($this->base() . '/avisos', $this->aviso(), arquivos: ['midia' => Amostras::arquivo('contrato.pdf', Amostras::pdf())]);

        $this->assertSame(422, $r->status);
        $this->assertStringContainsString('Envie uma imagem (JPG, PNG ou WebP) ou um vídeo MP4.', $r->body);
        $this->assertSame(0, $this->contar('avisos'));
        $this->assertSame([], $this->arquivosGuardados());
    }

    public function testArquivoMaiorQueOLimiteMostraMensagemClara(): void
    {
        $this->entrar();
        $grande = Amostras::arquivo('grande.png', Amostras::png() . str_repeat('x', 1_500_000));

        $r = $this->post($this->base() . '/avisos', $this->aviso(), arquivos: ['midia' => $grande]);

        $this->assertSame(422, $r->status);
        $this->assertStringContainsString('maior que o limite de 1 MB', $r->body);
        $this->assertSame(0, $this->contar('avisos'));
    }

    public function testEnvioMaiorQueOLimiteDoServidorRecebe413(): void
    {
        $this->entrar();

        $r = $this->post($this->base() . '/avisos', [], servidor: ['CONTENT_LENGTH' => (string) (5 * 1048576)]);

        $this->assertSame(413, $r->status);
        $this->assertStringContainsString('Arquivo grande demais', $r->body);
        $this->assertSame(0, $this->contar('avisos'));
    }

    public function testEditarMostraOsDadosEOHorarioDeBrasilia(): void
    {
        $this->entrar();
        $id = $this->criarAviso(['tipo' => 'assembleia']);

        $r = $this->get($this->base() . "/avisos/{$id}/editar");

        $this->assertSame(200, $r->status);
        $this->assertStringContainsString('value="Falta de água"', $r->body);
        $this->assertStringContainsString('value="2026-11-20T08:00"', $r->body);
        $this->assertStringContainsString('value="2026-11-20T12:00"', $r->body);
        $this->assertMatchesRegularExpression('/<option value="assembleia" selected>/', $r->body);
        $this->assertStringContainsString('Ver como fica na TV', $r->body);
    }

    public function testEditarComMidiaMostraAMiniaturaEAOpcaoDeRemover(): void
    {
        $this->entrar();
        $id = $this->criarAviso([], ['midia' => Amostras::arquivo('foto.png', Amostras::png())]);

        $corpo = $this->get($this->base() . "/avisos/{$id}/editar")->body;

        $this->assertStringContainsString("src=\"{$this->base()}/avisos/{$id}/midia\"", $corpo);
        $this->assertStringContainsString('name="remover_midia"', $corpo);
        $this->assertStringContainsString('Trocar por outro arquivo', $corpo);
    }

    public function testAtualizarAvisoESubstituirAMidia(): void
    {
        $this->entrar();
        $id = $this->criarAviso([], ['midia' => Amostras::arquivo('foto.png', Amostras::png())]);
        $antiga = $this->pdo->query('SELECT midia FROM avisos')->fetchColumn();

        $r = $this->post($this->base() . "/avisos/{$id}", $this->aviso(['titulo' => 'Título novo', 'ativo' => '']), arquivos: ['midia' => Amostras::arquivo('v.mp4', Amostras::mp4())]);

        $this->assertSame(303, $r->status);
        $linha = $this->pdo->query('SELECT titulo, ativo, midia, midia_tipo FROM avisos')->fetch();
        $this->assertSame('Título novo', $linha['titulo']);
        $this->assertFalse((bool) $linha['ativo']);
        $this->assertSame('video', $linha['midia_tipo']);
        $this->assertNotSame($antiga, $linha['midia']);
        $this->assertSame([$linha['midia']], $this->arquivosGuardados());
        $this->assertStringContainsString('Aviso atualizado com sucesso.', $this->get($this->base() . '/avisos')->body);
    }

    public function testAtualizarComErroMantemOAvisoEMostraOFormulario(): void
    {
        $this->entrar();
        $id = $this->criarAviso();

        $r = $this->post($this->base() . "/avisos/{$id}", $this->aviso(['titulo' => '']));

        $this->assertSame(422, $r->status);
        $this->assertStringContainsString('Este campo é obrigatório.', $r->body);
        $this->assertSame('Falta de água', $this->pdo->query('SELECT titulo FROM avisos')->fetchColumn());
    }

    public function testExcluirPedeConfirmacaoEApagaOAvisoEAMidia(): void
    {
        $this->entrar();
        $id = $this->criarAviso([], ['midia' => Amostras::arquivo('foto.png', Amostras::png())]);

        $confirmacao = $this->get($this->base() . "/avisos/{$id}/excluir");
        $this->assertStringContainsString('Tem certeza', $confirmacao->body);
        $this->assertSame(1, $this->contar('avisos'), 'Abrir a confirmação não exclui nada.');

        $r = $this->post($this->base() . "/avisos/{$id}/excluir");

        $this->assertSame(303, $r->status);
        $this->assertSame(0, $this->contar('avisos'));
        $this->assertSame([], $this->arquivosGuardados());
        $this->assertStringContainsString('Aviso excluído.', $this->get($this->base() . '/avisos')->body);
    }

    public function testListaMostraSituacaoDoAviso(): void
    {
        $this->entrar();
        $this->criarAviso(['titulo' => 'Passado', 'inicio_em' => '2020-01-01T08:00', 'fim_em' => '2020-01-02T08:00']);
        $this->criarAviso(['titulo' => 'Futuro', 'inicio_em' => '2099-01-01T08:00', 'fim_em' => '2099-01-02T08:00']);
        $this->criarAviso(['titulo' => 'Agora', 'inicio_em' => '2020-01-01T08:00', 'fim_em' => '2099-01-02T08:00']);
        $this->criarAviso(['titulo' => 'Guardado', 'inicio_em' => '2020-01-01T08:00', 'fim_em' => '2099-01-02T08:00', 'ativo' => '']);

        $corpo = $this->get($this->base() . '/avisos')->body;

        foreach (['etiqueta-situacao-encerrado' => 'Encerrado', 'etiqueta-situacao-agendado' => 'Agendado', 'etiqueta-situacao-no-ar' => 'No ar', 'etiqueta-situacao-desativado' => 'Desativado'] as $classe => $rotulo) {
            $this->assertStringContainsString("{$classe}\">{$rotulo}<", $corpo);
        }
    }

    // ---------- Segurança de saída (XSS) e CSP ----------

    public function testTextosDoAvisoSaemEscapadosEmTodasAsTelas(): void
    {
        $this->entrar();
        $xss = '<script>alert(1)</script>';
        $id = $this->criarAviso(['titulo' => $xss, 'corpo' => '<img src=x onerror=alert(2)>']);

        foreach (['/avisos', "/avisos/{$id}/editar", "/avisos/{$id}/excluir", "/avisos/{$id}/previa"] as $caminho) {
            $corpo = $this->get($this->base() . $caminho)->body;
            $this->assertStringNotContainsString($xss, $corpo, $caminho);
            $this->assertStringNotContainsString('<img src=x', $corpo, $caminho);
            $this->assertStringContainsString('&lt;script&gt;', $corpo, $caminho);
        }
    }

    public function testPaginasNaoUsamStyleInlineNemScript(): void
    {
        $this->entrar();
        $id = $this->criarAviso([], ['midia' => Amostras::arquivo('foto.png', Amostras::png())]);

        foreach (['/gerente', $this->base() . '/avisos', $this->base() . '/avisos/novo', $this->base() . "/avisos/{$id}/editar",
                  $this->base() . "/avisos/{$id}/excluir", $this->base() . "/avisos/{$id}/previa", '/gerente/aparencia'] as $caminho) {
            $r = $this->get($caminho);
            $this->assertStringNotContainsString(' style=', $r->body, $caminho);
            $this->assertStringNotContainsString('<script', $r->body, $caminho);
            $this->assertSame('no-store', $r->headers['Cache-Control'] ?? '', $caminho);
        }
    }

    public function testEscritasSemCsrfSaoBloqueadas(): void
    {
        $this->entrar();
        $id = $this->criarAviso();

        foreach ([$this->base() . '/avisos', $this->base() . "/avisos/{$id}", $this->base() . "/avisos/{$id}/excluir", '/gerente/aparencia'] as $caminho) {
            $this->assertSame(403, $this->post($caminho, $this->aviso(['titulo' => 'HACK']), comCsrf: false)->status, $caminho);
        }

        $this->assertSame('Falta de água', $this->pdo->query('SELECT titulo FROM avisos')->fetchColumn());
        $this->assertSame(1, $this->contar('avisos'));
    }

    // ---------- Prévia na TV ----------

    public function testPreviaMostraOAvisoDoCarrossel(): void
    {
        $this->entrar();
        $id = $this->criarAviso();

        $r = $this->get($this->base() . "/avisos/{$id}/previa");

        $this->assertSame(200, $r->status);
        $this->assertStringContainsString('<div class="tv">', $r->body, 'Aviso de carrossel: tela sem a faixa do topo.');
        $this->assertStringNotContainsString('tv-fixos', $r->body);
        $this->assertStringContainsString('<h2 class="tv-titulo">Falta de água</h2>', $r->body);
        $this->assertStringContainsString('Manutenção', $r->body);
        $this->assertStringContainsString('Desculpe o transtorno.', $r->body);
        $this->assertStringContainsString('href="/assets/tv.css"', $r->body);
        $this->assertStringNotContainsString('tv-topo', $r->body, 'A TV não tem mais barra no topo.');
        $this->assertStringNotContainsString('desativado', $r->body);
    }

    public function testPreviaDeAvisoUrgenteMostraNaFaixaDoTopoSoComTexto(): void
    {
        $this->entrar();
        $id = $this->criarAviso(['tipo' => 'urgente', 'titulo' => 'Vazamento']);

        $corpo = $this->get($this->base() . "/avisos/{$id}/previa")->body;

        $this->assertStringContainsString('<div class="tv tv-com-fixos tv-sem-carrossel">', $corpo);
        $this->assertStringContainsString('class="tv-fixos tv-fixos-cols-1 tv-fixos-rows-1"', $corpo);
        $this->assertStringContainsString('<article class="tv-fixo tv-fixo-urgente tv-t', $corpo);
        $this->assertStringContainsString('<h2 class="tv-fixo-titulo">Vazamento</h2>', $corpo);
        $this->assertStringNotContainsString('tv-topo', $corpo);
        $this->assertStringContainsString('Aviso do tipo Urgente (fixo): fica sempre à vista na faixa do topo da TV', $corpo);
        $this->assertStringNotContainsString('class="tv-corpo', $corpo, 'Urgente não entra no carrossel.');
    }

    public function testPreviaDeAvisoFixoTambemFicaNaFaixaEAvisaQueAMidiaNaoApareceNaTv(): void
    {
        $this->entrar();
        $id = $this->criarAviso(['tipo' => 'fixo', 'titulo' => 'Regras da piscina'], ['midia' => Amostras::arquivo('f.png', Amostras::png())]);

        $corpo = $this->get($this->base() . "/avisos/{$id}/previa")->body;

        $this->assertStringContainsString('<article class="tv-fixo tv-fixo-fixo tv-t', $corpo);
        $this->assertStringContainsString('Aviso do tipo Fixo (fixo): fica sempre à vista', $corpo);
        $this->assertStringContainsString('A foto ou o vídeo deste aviso não são exibidos na TV.', $corpo);
        $this->assertStringNotContainsString('class="tv-midia"', $corpo);
    }

    public function testFormularioOferecaOTipoFixoEExplicaAFaixaDoTopo(): void
    {
        $this->entrar();

        $corpo = $this->get($this->base() . '/avisos/novo')->body;

        $this->assertMatchesRegularExpression('/<select id="campo-tipo" name="tipo"/', $corpo, 'Tipo é um dropdown.');
        $this->assertStringContainsString('<option value="fixo">Fixo (fixo)</option>', $corpo);
        $this->assertStringContainsString('<option value="comum" selected>Comum</option>', $corpo);
        $this->assertStringContainsString('faixa no topo da TV', $corpo);
        $this->assertStringContainsString('Criar um tipo novo', $corpo);
    }

    public function testCriarEListarAvisoFixoMostraEtiquetaPropria(): void
    {
        $this->entrar();

        $this->criarAviso(['tipo' => 'fixo', 'titulo' => 'Horário da portaria']);

        $this->assertSame('fixo', $this->pdo->query('SELECT tipo FROM avisos')->fetchColumn());
        $this->assertMatchesRegularExpression('/etiqueta-tipo-t\d+">Fixo</', $this->get($this->base() . '/avisos')->body);
    }

    public function testPreviaComImagemEComVideo(): void
    {
        $this->entrar();
        $comImagem = $this->criarAviso([], ['midia' => Amostras::arquivo('f.png', Amostras::png())]);
        $comVideo = $this->criarAviso([], ['midia' => Amostras::arquivo('v.mp4', Amostras::mp4())]);

        $imagem = $this->get($this->base() . "/avisos/{$comImagem}/previa")->body;
        $video = $this->get($this->base() . "/avisos/{$comVideo}/previa")->body;

        $this->assertMatchesRegularExpression('/class="tv-corpo tv-t\d+ tv-com-midia"/', $imagem);
        $this->assertStringContainsString("<img src=\"{$this->base()}/avisos/{$comImagem}/midia\"", $imagem);
        $this->assertStringContainsString("<video src=\"{$this->base()}/avisos/{$comVideo}/midia\"", $video);
    }

    public function testPreviaAvisaQuandoOAvisoEstaDesativado(): void
    {
        $this->entrar();
        $id = $this->criarAviso(['ativo' => '']);

        $this->assertStringContainsString('Este aviso está desativado e não aparece na TV.', $this->get($this->base() . "/avisos/{$id}/previa")->body);
    }

    // ---------- Entrega de mídia ----------

    public function testMidiaDoAvisoEEntregueComTipoCorretoESemSniffing(): void
    {
        $this->entrar();
        $id = $this->criarAviso([], ['midia' => Amostras::arquivo('foto.png', Amostras::png())]);

        $r = $this->get($this->base() . "/avisos/{$id}/midia");

        $this->assertSame(200, $r->status);
        $this->assertSame('image/png', $r->headers['Content-Type']);
        $this->assertSame('nosniff', $r->headers['X-Content-Type-Options']);
        $this->assertStringStartsWith('private', $r->headers['Cache-Control']);
        $this->assertSame(Amostras::png(), (string) file_get_contents((string) $r->arquivo));
    }

    public function testVideoEEntregueComoMp4(): void
    {
        $this->entrar();
        $id = $this->criarAviso([], ['midia' => Amostras::arquivo('v.mp4', Amostras::mp4())]);

        $this->assertSame('video/mp4', $this->get($this->base() . "/avisos/{$id}/midia")->headers['Content-Type']);
    }

    public function testComNginxAEntregaVaiPorXAccelRedirectSemCorpo(): void
    {
        $this->entrar();
        $id = $this->criarAviso([], ['midia' => Amostras::arquivo('foto.png', Amostras::png())]);
        $nome = (string) $this->pdo->query('SELECT midia FROM avisos')->fetchColumn();
        $this->app = $this->novaApp(['MEDIA_ACCEL' => '/media/']);

        $r = $this->get($this->base() . "/avisos/{$id}/midia");

        $this->assertSame("/media/{$nome}", $r->headers['X-Accel-Redirect']);
        $this->assertSame('', $r->body);
        $this->assertNull($r->arquivo);
        $this->assertSame('image/png', $r->headers['Content-Type']);
    }

    public function testAvisoSemMidiaOuArquivoSumidoRetorna404(): void
    {
        $this->entrar();
        $semMidia = $this->criarAviso();
        $this->assertSame(404, $this->get($this->base() . "/avisos/{$semMidia}/midia")->status);

        $comMidia = $this->criarAviso([], ['midia' => Amostras::arquivo('foto.png', Amostras::png())]);
        foreach ($this->arquivosGuardados() as $arquivo) {
            unlink($this->uploads . '/' . $arquivo);
        }
        $this->assertSame(404, $this->get($this->base() . "/avisos/{$comMidia}/midia")->status);
    }

    // ---------- Tipos de aviso (nome, cor, fixo) ----------

    private function tipoPorChave(string $chave): array
    {
        return $this->pdo->query("SELECT * FROM tipos_aviso WHERE chave = '{$chave}'")->fetch();
    }

    public function testListaDeTiposMostraOsDoSistemaSemAcoesEOsProprios(): void
    {
        $this->entrar();
        $this->post($this->base() . '/tipos', ['nome' => 'Zeladoria', 'cor' => '#336699']);

        $corpo = $this->get($this->base() . '/tipos')->body;

        foreach (['Comum', 'Fixo', 'Urgente', 'Manutenção', 'Assembleia', 'Zeladoria'] as $nome) {
            $this->assertStringContainsString(">{$nome}<", $corpo);
        }
        $this->assertSame(5, substr_count($corpo, 'padrão do sistema'));
        $zel = $this->tipoPorChave('zeladoria');
        $this->assertStringContainsString("/tipos/{$zel['id']}/editar", $corpo);
        $urgente = $this->tipoPorChave('urgente');
        $this->assertStringNotContainsString("/tipos/{$urgente['id']}/editar", $corpo, 'Tipo do sistema não tem ação.');
    }

    public function testCriarTipoPelaTelaDeTipos(): void
    {
        $this->entrar();

        $r = $this->post($this->base() . '/tipos', ['nome' => 'Reunião do Conselho', 'cor' => '#2f6fed', 'fixo' => '1']);

        $this->assertSame(303, $r->status);
        $t = $this->tipoPorChave('reuniao-do-conselho');
        $this->assertSame(['Reunião do Conselho', '#2F6FED', true, $this->condominio], [$t['nome'], $t['cor'], (bool) $t['fixo'], (int) $t['condominio_id']]);
        $this->assertStringContainsString('Tipo criado com sucesso.', $this->get($this->base() . '/tipos')->body);
    }

    public function testTipoInvalidoMostraErrosESemCriar(): void
    {
        $this->entrar();

        $r = $this->post($this->base() . '/tipos', ['nome' => 'A', 'cor' => 'azul']);

        $this->assertSame(422, $r->status);
        $this->assertStringContainsString('Informe um nome de 2 a 30 caracteres.', $r->body);
        $this->assertStringContainsString('Escolha uma cor válida.', $r->body);
        $this->assertSame(0, (int) $this->pdo->query('SELECT count(*) FROM tipos_aviso WHERE NOT sistema')->fetchColumn());
    }

    public function testEditarTipoMudaNomeCorEFixoMasNaoAChave(): void
    {
        $this->entrar();
        $this->post($this->base() . '/tipos', ['nome' => 'Zeladoria', 'cor' => '#336699']);
        $id = (int) $this->tipoPorChave('zeladoria')['id'];

        $form = $this->get($this->base() . "/tipos/{$id}/editar")->body;
        $this->assertStringContainsString('value="Zeladoria"', $form);
        $this->assertStringContainsString('value="#336699"', $form);

        $this->assertSame(303, $this->post($this->base() . "/tipos/{$id}", ['nome' => 'Zeladoria e limpeza', 'cor' => '#AA5500', 'fixo' => '1'])->status);

        $t = $this->tipoPorChave('zeladoria');
        $this->assertSame(['Zeladoria e limpeza', '#AA5500', true], [$t['nome'], $t['cor'], (bool) $t['fixo']]);
    }

    public function testEditarOuApagarTipoDoSistemaEh404(): void
    {
        $this->entrar();
        $id = (int) $this->tipoPorChave('urgente')['id'];

        $this->assertSame(404, $this->get($this->base() . "/tipos/{$id}/editar")->status);
        $this->assertSame(404, $this->post($this->base() . "/tipos/{$id}", ['nome' => 'Mudou', 'cor' => '#000000'])->status);
        $this->assertSame(404, $this->post($this->base() . "/tipos/{$id}/excluir")->status);
        $this->assertSame('Urgente', $this->tipoPorChave('urgente')['nome']);
    }

    public function testApagarTipoLivreFuncionaEEmUsoEhRecusado(): void
    {
        $this->entrar();
        $this->post($this->base() . '/tipos', ['nome' => 'Zeladoria', 'cor' => '#336699']);
        $id = (int) $this->tipoPorChave('zeladoria')['id'];
        $this->criarAviso(['tipo' => 'zeladoria']);

        $this->assertStringContainsString('é usado por 1 aviso', $this->get($this->base() . "/tipos/{$id}/excluir")->body);
        $this->post($this->base() . "/tipos/{$id}/excluir");
        $this->assertStringContainsString('usado por 1 aviso', $this->get($this->base() . '/tipos')->body);
        $this->assertSame(1, (int) $this->pdo->query("SELECT count(*) FROM tipos_aviso WHERE chave = 'zeladoria'")->fetchColumn(), 'Em uso: continua existindo.');

        $this->pdo->exec('DELETE FROM avisos');
        $this->assertSame(303, $this->post($this->base() . "/tipos/{$id}/excluir")->status);
        $this->assertSame(0, (int) $this->pdo->query("SELECT count(*) FROM tipos_aviso WHERE chave = 'zeladoria'")->fetchColumn());
    }

    public function testEscritasDeTiposSemCsrfSaoBloqueadas(): void
    {
        $this->entrar();
        $this->post($this->base() . '/tipos', ['nome' => 'Zeladoria', 'cor' => '#336699']);
        $id = (int) $this->tipoPorChave('zeladoria')['id'];

        foreach ([$this->base() . '/tipos', $this->base() . "/tipos/{$id}", $this->base() . "/tipos/{$id}/excluir"] as $caminho) {
            $this->assertSame(403, $this->post($caminho, ['nome' => 'HACK', 'cor' => '#000000'], comCsrf: false)->status, $caminho);
        }
        $this->assertSame('Zeladoria', $this->tipoPorChave('zeladoria')['nome']);
    }

    public function testFormularioDoAvisoTemDropdownComOsTiposDoCondominio(): void
    {
        $this->entrar();
        $this->post($this->base() . '/tipos', ['nome' => 'Zeladoria', 'cor' => '#336699', 'fixo' => '1']);

        $corpo = $this->get($this->base() . '/avisos/novo')->body;

        $this->assertStringContainsString('<option value="zeladoria">Zeladoria (fixo)</option>', $corpo);
        $this->assertStringContainsString('<option value="manutencao">Manutenção</option>', $corpo);
        $this->assertStringContainsString('name="novo_tipo_nome"', $corpo);
        $this->assertStringContainsString('name="novo_tipo_cor"', $corpo);
        $this->assertStringContainsString('name="novo_tipo_fixo"', $corpo);
    }

    public function testCriarAvisoJaCriandoUmTipoNovoNoMesmoFormulario(): void
    {
        $this->entrar();

        $r = $this->post($this->base() . '/avisos', $this->aviso(['tipo' => 'comum', 'novo_tipo_nome' => 'Obra na fachada', 'novo_tipo_cor' => '#c2410c', 'novo_tipo_fixo' => '1']));

        $this->assertSame(303, $r->status);
        $t = $this->tipoPorChave('obra-na-fachada');
        $this->assertSame(['Obra na fachada', '#C2410C', true, $this->condominio], [$t['nome'], $t['cor'], (bool) $t['fixo'], (int) $t['condominio_id']]);
        $this->assertSame('obra-na-fachada', $this->pdo->query('SELECT tipo FROM avisos')->fetchColumn(), 'O tipo novo vale no lugar do seletor.');
        $this->assertStringContainsString('<option value="obra-na-fachada">Obra na fachada (fixo)</option>', $this->get($this->base() . '/avisos/novo')->body);
    }

    public function testTipoNovoInvalidoNoAvisoNaoCriaNemTipoNemAviso(): void
    {
        $this->entrar();

        $r = $this->post($this->base() . '/avisos', $this->aviso(['novo_tipo_nome' => 'X', 'novo_tipo_cor' => 'azul']));

        $this->assertSame(422, $r->status);
        $this->assertStringContainsString('Informe um nome de 2 a 30 caracteres.', $r->body);
        $this->assertStringContainsString('Escolha uma cor válida.', $r->body);
        $this->assertStringContainsString('<details class="novo-tipo" open>', $r->body, 'A seção do tipo novo reabre mostrando os erros.');
        $this->assertSame(0, $this->contar('avisos'));
        $this->assertSame(0, (int) $this->pdo->query('SELECT count(*) FROM tipos_aviso WHERE NOT sistema')->fetchColumn());
    }

    public function testAvisoInvalidoComTipoNovoNaoDeixaOTipoCriado(): void
    {
        $this->entrar();

        $r = $this->post($this->base() . '/avisos', $this->aviso(['titulo' => '', 'novo_tipo_nome' => 'Obra na fachada', 'novo_tipo_cor' => '#c2410c']));

        $this->assertSame(422, $r->status);
        $this->assertSame(0, (int) $this->pdo->query('SELECT count(*) FROM tipos_aviso WHERE NOT sistema')->fetchColumn(), 'Só cria o tipo quando o aviso é válido.');
    }

    public function testTiposCssTemAsCoresDoCondominioEVemSoDeleEstaNaPaginaDoGerente(): void
    {
        $this->entrar();
        $this->post($this->base() . '/tipos', ['nome' => 'Zeladoria', 'cor' => '#336699']);
        $id = (int) $this->tipoPorChave('zeladoria')['id'];

        $r = $this->get($this->base() . '/tipos.css');

        $this->assertSame(200, $r->status);
        $this->assertStringStartsWith('text/css', $r->headers['Content-Type']);
        $this->assertSame('nosniff', $r->headers['X-Content-Type-Options']);
        $this->assertStringContainsString(".etiqueta-tipo-t{$id} { color: #FFFFFF; background: #336699; }", $r->body);
        $this->assertStringContainsString("href=\"{$this->base()}/tipos.css\"", $this->get($this->base() . '/avisos')->body);
    }

    public function testEtiquetaDoTipoPropioNaListaDeAvisosUsaONomeEACor(): void
    {
        $this->entrar();
        $this->post($this->base() . '/tipos', ['nome' => 'Zeladoria', 'cor' => '#336699']);
        $this->criarAviso(['tipo' => 'zeladoria']);
        $id = (int) $this->tipoPorChave('zeladoria')['id'];

        $this->assertStringContainsString("etiqueta-tipo-t{$id}\">Zeladoria<", $this->get($this->base() . '/avisos')->body);
    }

    public function testPreviaDeTipoFixoPropioFicaNaFaixaComACorDoTipo(): void
    {
        $this->entrar();
        $this->post($this->base() . '/tipos', ['nome' => 'Zeladoria', 'cor' => '#336699', 'fixo' => '1']);
        $id = (int) $this->tipoPorChave('zeladoria')['id'];
        $aviso = $this->criarAviso(['tipo' => 'zeladoria', 'titulo' => 'Escada interditada']);

        $corpo = $this->get($this->base() . "/avisos/{$aviso}/previa")->body;

        $this->assertStringContainsString("tv-fixo tv-fixo-zeladoria tv-t{$id}", $corpo);
        $this->assertStringContainsString('<span>Zeladoria</span>', $corpo);
        $this->assertStringContainsString('Aviso do tipo Zeladoria (fixo)', $corpo);
    }

    // ---------- Repetição do aviso ----------

    public function testFormularioTemSecaoDeRepeticaoComDiasEHorarios(): void
    {
        $this->entrar();

        $corpo = $this->get($this->base() . '/avisos/novo')->body;

        $this->assertStringContainsString('Repetição (opcional)', $corpo);
        foreach (['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'] as $i => $dia) {
            $this->assertStringContainsString("name=\"dias[]\" value=\"{$i}\"><span>{$dia}</span>", $corpo);
        }
        $this->assertStringContainsString('type="time" name="hora_inicio"', $corpo);
        $this->assertStringContainsString('type="time" name="hora_fim"', $corpo);
    }

    public function testCriarAvisoComRepeticaoGuardaMostraNaListaEReabreNaEdicao(): void
    {
        $this->entrar();

        $r = $this->post($this->base() . '/avisos', $this->aviso(['titulo' => 'Coleta de lixo', 'dias' => ['2', '5'], 'hora_inicio' => '07:00', 'hora_fim' => '09:00']));

        $this->assertSame(303, $r->status);
        $linha = $this->pdo->query("SELECT dias_semana, to_char(hora_inicio, 'HH24:MI') AS hi, to_char(hora_fim, 'HH24:MI') AS hf FROM avisos")->fetch();
        $this->assertSame([36, '07:00', '09:00'], [(int) $linha['dias_semana'], $linha['hi'], $linha['hf']]);
        $this->assertStringContainsString('Ter e Sex · 07:00 às 09:00', $this->get($this->base() . '/avisos')->body);

        $id = (int) $this->pdo->query('SELECT id FROM avisos')->fetchColumn();
        $edicao = $this->get($this->base() . "/avisos/{$id}/editar")->body;
        $this->assertMatchesRegularExpression('/value="2" checked/', $edicao);
        $this->assertMatchesRegularExpression('/value="5" checked/', $edicao);
        $this->assertDoesNotMatchRegularExpression('/value="3" checked/', $edicao);
        $this->assertStringContainsString('name="hora_inicio" value="07:00"', $edicao);
        $this->assertStringContainsString('name="hora_fim" value="09:00"', $edicao);
    }

    public function testRepeticaoInvalidaMostraErroMantendoOQueFoiDigitado(): void
    {
        $this->entrar();

        $r = $this->post($this->base() . '/avisos', $this->aviso(['dias' => ['3'], 'hora_inicio' => '10:00', 'hora_fim' => '09:00']));

        $this->assertSame(422, $r->status);
        $this->assertStringContainsString('O horário final precisa ser depois do inicial', $r->body);
        $this->assertMatchesRegularExpression('/value="3" checked/', $r->body);
        $this->assertStringContainsString('name="hora_inicio" value="10:00"', $r->body);
        $this->assertSame(0, $this->contar('avisos'));
    }

    public function testSemMarcarNadaOAvisoNaoRepete(): void
    {
        $this->entrar();

        $this->criarAviso();

        $this->assertSame([null, null, null], array_values($this->pdo->query('SELECT dias_semana, hora_inicio, hora_fim FROM avisos')->fetch(\PDO::FETCH_NUM)));
        $this->assertStringNotContainsString('class="repete"', $this->get($this->base() . '/avisos')->body);
    }

    public function testPreviaAvisaQueOAvisoSeRepete(): void
    {
        $this->entrar();
        $id = $this->criarAviso(['dias' => ['1'], 'hora_inicio' => '08:00', 'hora_fim' => '09:00']);

        $this->assertStringContainsString('Este aviso se repete', $this->get($this->base() . "/avisos/{$id}/previa")->body);
        $semRepetir = $this->criarAviso(['titulo' => 'Outro']);
        $this->assertStringNotContainsString('Este aviso se repete', $this->get($this->base() . "/avisos/{$semRepetir}/previa")->body);
    }

    // ---------- Modelos de aviso ----------

    private function modeloPorNome(string $nome): array
    {
        return $this->pdo->query("SELECT * FROM modelos_aviso WHERE nome = '{$nome}'")->fetch();
    }

    public function testFormularioNovoOferecaOsModelosProntos(): void
    {
        $this->entrar();

        $corpo = $this->get($this->base() . '/avisos/novo')->body;

        $this->assertStringContainsString('Começar de um modelo', $corpo);
        foreach (['Falta de água', 'Manutenção do elevador', 'Dedetização', 'Assembleia'] as $nome) {
            $this->assertStringContainsString(">{$nome}</option>", $corpo);
        }
        $this->assertStringContainsString('<form method="get" action="' . $this->base() . '/avisos/novo"', $corpo, 'Funciona sem JavaScript.');
    }

    public function testUsarModeloPreencheTituloTextoETipo(): void
    {
        $this->entrar();
        $modelo = (int) $this->modeloPorNome('Falta de água')['id'];

        $corpo = $this->get($this->base() . "/avisos/novo?modelo={$modelo}")->body;

        $this->assertStringContainsString('value="Falta de água"', $corpo);
        $this->assertStringContainsString('o abastecimento de água será interrompido em [data]', $corpo);
        $this->assertStringContainsString('<option value="urgente" selected>', $corpo);
    }

    public function testModeloInexistenteOuDeOutroCondominioNaTelaNovaEh404(): void
    {
        $this->entrar();
        $outro = $this->criarCondominio('Outro');
        $this->pdo->exec("INSERT INTO modelos_aviso (condominio_id, nome, titulo) VALUES ({$outro}, 'Segredo do outro', 'T')");
        $id = (int) $this->modeloPorNome('Segredo do outro')['id'];

        $this->assertSame(404, $this->get($this->base() . "/avisos/novo?modelo={$id}")->status);
        $this->assertSame(404, $this->get($this->base() . '/avisos/novo?modelo=999999')->status);
        $this->assertSame(200, $this->get($this->base() . '/avisos/novo?modelo=abc')->status, 'Valor que não é número é ignorado.');
        $this->assertStringNotContainsString('Segredo do outro', $this->get($this->base() . '/avisos/novo')->body);
    }

    public function testListaDeModelosMostraOsDoSistemaSemAcoesDeEdicao(): void
    {
        $this->entrar();
        $this->post($this->base() . '/modelos', ['nome' => 'Reunião do bloco', 'titulo' => 'Reunião', 'corpo' => 'x', 'tipo' => 'comum']);

        $corpo = $this->get($this->base() . '/modelos')->body;

        $this->assertStringContainsString('Modelo do sistema', $corpo);
        $this->assertStringContainsString('Reunião do bloco', $corpo);
        $sistema = $this->modeloPorNome('Assembleia');
        $proprio = $this->modeloPorNome('Reunião do bloco');
        $this->assertStringNotContainsString("/modelos/{$sistema['id']}/editar", $corpo);
        $this->assertStringContainsString("/modelos/{$proprio['id']}/editar", $corpo);
        $this->assertStringContainsString("/avisos/novo?modelo={$sistema['id']}", $corpo, 'Atalho para criar aviso com o modelo.');
    }

    public function testCriarEditarEApagarModelo(): void
    {
        $this->entrar();

        $this->assertSame(303, $this->post($this->base() . '/modelos', ['nome' => 'Reunião do bloco', 'titulo' => 'Reunião', 'corpo' => 'Dia [data]', 'tipo' => 'assembleia'])->status);
        $m = $this->modeloPorNome('Reunião do bloco');
        $this->assertSame([$this->condominio, 'assembleia'], [(int) $m['condominio_id'], $m['tipo']]);

        $this->assertStringContainsString('value="Reunião do bloco"', $this->get($this->base() . "/modelos/{$m['id']}/editar")->body);
        $this->assertSame(303, $this->post($this->base() . "/modelos/{$m['id']}", ['nome' => 'Reunião geral', 'titulo' => 'Reunião geral', 'corpo' => 'x', 'tipo' => 'comum'])->status);
        $this->assertSame('Reunião geral', $this->pdo->query("SELECT nome FROM modelos_aviso WHERE id = {$m['id']}")->fetchColumn());

        $this->assertStringContainsString('Reunião geral', $this->get($this->base() . "/modelos/{$m['id']}/excluir")->body);
        $this->assertSame(303, $this->post($this->base() . "/modelos/{$m['id']}/excluir")->status);
        $this->assertSame(0, (int) $this->pdo->query("SELECT count(*) FROM modelos_aviso WHERE id = {$m['id']}")->fetchColumn());
    }

    public function testModeloInvalidoMostraErrosESemCriar(): void
    {
        $this->entrar();

        $r = $this->post($this->base() . '/modelos', ['nome' => '', 'titulo' => '', 'corpo' => '', 'tipo' => 'propaganda']);

        $this->assertSame(422, $r->status);
        $this->assertStringContainsString('Escolha um tipo válido.', $r->body);
        $this->assertSame(0, (int) $this->pdo->query('SELECT count(*) FROM modelos_aviso WHERE NOT sistema')->fetchColumn());
    }

    public function testEditarOuApagarModeloDoSistemaEh404(): void
    {
        $this->entrar();
        $id = (int) $this->modeloPorNome('Assembleia')['id'];

        $this->assertSame(404, $this->get($this->base() . "/modelos/{$id}/editar")->status);
        $this->assertSame(404, $this->post($this->base() . "/modelos/{$id}", ['nome' => 'X', 'titulo' => 'X', 'corpo' => '', 'tipo' => 'comum'])->status);
        $this->assertSame(404, $this->post($this->base() . "/modelos/{$id}/excluir")->status);
        $this->assertSame('Assembleia geral', $this->modeloPorNome('Assembleia')['titulo']);
    }

    public function testEscritasDeModelosSemCsrfSaoBloqueadas(): void
    {
        $this->entrar();
        $this->post($this->base() . '/modelos', ['nome' => 'Reunião do bloco', 'titulo' => 'Reunião', 'corpo' => '', 'tipo' => 'comum']);
        $id = (int) $this->modeloPorNome('Reunião do bloco')['id'];

        foreach ([$this->base() . '/modelos', $this->base() . "/modelos/{$id}", $this->base() . "/modelos/{$id}/excluir"] as $caminho) {
            $this->assertSame(403, $this->post($caminho, ['nome' => 'HACK', 'titulo' => 'HACK', 'corpo' => '', 'tipo' => 'comum'], comCsrf: false)->status, $caminho);
        }
        $this->assertSame(1, (int) $this->pdo->query("SELECT count(*) FROM modelos_aviso WHERE nome = 'Reunião do bloco'")->fetchColumn());
    }

    public function testSalvarAvisoComoModeloGuardaTituloTextoETipo(): void
    {
        $this->entrar();

        $this->post($this->base() . '/avisos', $this->aviso(['titulo' => 'Corte de energia', 'corpo' => 'Sábado de manhã', 'tipo' => 'urgente', 'salvar_modelo' => '1']));

        $m = $this->modeloPorNome('Corte de energia');
        $this->assertSame(['Corte de energia', 'Sábado de manhã', 'urgente', $this->condominio], [$m['titulo'], $m['corpo'], $m['tipo'], (int) $m['condominio_id']]);
        $this->assertSame(1, $this->contar('avisos'));
    }

    public function testAvisoSemMarcarSalvarComoModeloNaoCriaModelo(): void
    {
        $this->entrar();

        $this->criarAviso();

        $this->assertSame(0, (int) $this->pdo->query('SELECT count(*) FROM modelos_aviso WHERE NOT sistema')->fetchColumn());
    }

    // ---------- Aparência (cor do gerente) ----------

    public function testFormularioDeAparenciaMostraOPadraoDaEmbracore(): void
    {
        $this->entrar();

        $r = $this->get('/gerente/aparencia');
        $corpo = $r->body;

        $this->assertSame(200, $r->status);
        $this->assertMatchesRegularExpression('/name="cor_primaria" value="#00A67E"/', $corpo);
        $this->assertMatchesRegularExpression('/name="usar_padrao" value="1" checked/', $corpo);
        $this->assertStringNotContainsString('cor_secundaria', $corpo, 'Só uma cor: o tom claro é calculado.');
        $this->assertStringNotContainsString('type="file"', $corpo, 'Não há mais upload de logo.');
        $this->assertStringNotContainsString('tema.css', $corpo, 'Sem cor escolhida, nenhuma folha extra.');
        $this->assertStringContainsString('href="/gerente/aparencia" class="ativo"', $corpo);
    }

    public function testSalvarCorEMostrarDepois(): void
    {
        $this->entrar();

        $r = $this->post('/gerente/aparencia', ['cor_primaria' => '#2f6fed']);

        $this->assertSame(303, $r->status);
        $this->assertSame('#2F6FED', $this->pdo->query("SELECT cor_primaria FROM usuarios WHERE email = 'gerente@exemplo.com.br'")->fetchColumn());

        $corpo = $this->get('/gerente/aparencia')->body;
        $this->assertStringContainsString('Aparência atualizada com sucesso.', $corpo);
        $this->assertMatchesRegularExpression('/name="cor_primaria" value="#2F6FED"/', $corpo);
        $this->assertDoesNotMatchRegularExpression('/name="usar_padrao" value="1" checked/', $corpo);
        $this->assertStringContainsString('<link rel="stylesheet" href="/gerente/tema.css?v=2F6FED">', $corpo);
        $this->assertStringContainsString('tema.css?v=2F6FED', $this->get($this->base() . '/avisos')->body, 'A cor vale em todas as telas do painel.');
        $this->assertStringContainsString('tema.css?v=2F6FED', $this->get($this->base() . '/avisos/novo')->body);
    }

    public function testAparenciaInvalidaMostraErroESemAlterar(): void
    {
        $this->entrar();

        $r = $this->post('/gerente/aparencia', ['cor_primaria' => 'vermelho']);

        $this->assertSame(422, $r->status);
        $this->assertStringContainsString('Escolha uma cor válida.', $r->body);
        $this->assertNull($this->pdo->query("SELECT cor_primaria FROM usuarios WHERE email = 'gerente@exemplo.com.br'")->fetchColumn() ?: null);
    }

    public function testVoltarAoPadraoLimpaACor(): void
    {
        $this->entrar();
        $this->post('/gerente/aparencia', ['cor_primaria' => '#2F6FED']);

        $this->post('/gerente/aparencia', ['cor_primaria' => '#2F6FED', 'usar_padrao' => '1']);

        $this->assertNull($this->pdo->query("SELECT cor_primaria FROM usuarios WHERE email = 'gerente@exemplo.com.br'")->fetchColumn() ?: null);
        $this->assertStringNotContainsString('tema.css', $this->get($this->base() . '/avisos')->body);
    }

    public function testTemaCssTemAsVariaveisDaCorDoGerente(): void
    {
        $this->entrar();

        $padrao = $this->get('/gerente/tema.css');
        $this->assertSame(200, $padrao->status);
        $this->assertStringStartsWith('text/css', $padrao->headers['Content-Type']);
        $this->assertSame('nosniff', $padrao->headers['X-Content-Type-Options']);
        $this->assertSame('', $padrao->body, 'Sem cor escolhida vale o padrão do app.css.');

        $this->post('/gerente/aparencia', ['cor_primaria' => '#1E3A8A']);
        $custom = $this->get('/gerente/tema.css')->body;

        foreach (['--marca: #1E3A8A;', '--marca-700:', '--marca-800:', '--marca-50:', '--marca-100:', '--marca-300:', '--marca-foco: rgba(30, 58, 138, 0.18);'] as $trecho) {
            $this->assertStringContainsString($trecho, $custom);
        }
    }

    public function testSuperadminNaoUsaAAparenciaDoGerente(): void
    {
        $this->logarComoAdmin();

        $this->assertSame(403, $this->get('/gerente/aparencia')->status);
        $this->assertSame(403, $this->get('/gerente/tema.css')->status);
    }
}
