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
        $this->assertMatchesRegularExpression('/name="tipo" value="comum" checked/', $corpo);
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
        $this->assertMatchesRegularExpression('/name="tipo" value="assembleia" checked/', $r->body);
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
                  $this->base() . "/avisos/{$id}/excluir", $this->base() . "/avisos/{$id}/previa", $this->base() . '/aparencia'] as $caminho) {
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

        foreach ([$this->base() . '/avisos', $this->base() . "/avisos/{$id}", $this->base() . "/avisos/{$id}/excluir", $this->base() . '/aparencia'] as $caminho) {
            $this->assertSame(403, $this->post($caminho, $this->aviso(['titulo' => 'HACK']), comCsrf: false)->status, $caminho);
        }

        $this->assertSame('Falta de água', $this->pdo->query('SELECT titulo FROM avisos')->fetchColumn());
        $this->assertSame(1, $this->contar('avisos'));
    }

    // ---------- Prévia na TV ----------

    public function testPreviaMostraOAvisoComOTemaDoCondominio(): void
    {
        $this->entrar();
        $id = $this->criarAviso();

        $r = $this->get($this->base() . "/avisos/{$id}/previa");

        $this->assertSame(200, $r->status);
        $this->assertStringContainsString('class="tv tv-manutencao"', $r->body);
        $this->assertStringContainsString('<h2 class="tv-titulo">Falta de água</h2>', $r->body);
        $this->assertStringContainsString('Manutenção', $r->body);
        $this->assertStringContainsString('Desculpe o transtorno.', $r->body);
        $this->assertStringContainsString("href=\"{$this->base()}/tema.css\"", $r->body);
        $this->assertStringContainsString('/assets/logo-embracore.svg', $r->body, 'Sem logo própria, usa a padrão.');
        $this->assertMatchesRegularExpression('#<span class="tv-relogio">\d{2}:\d{2}</span>#', $r->body);
        $this->assertStringNotContainsString('desativado', $r->body);
    }

    public function testPreviaDeAvisoUrgenteTemDestaque(): void
    {
        $this->entrar();
        $id = $this->criarAviso(['tipo' => 'urgente']);

        $this->assertStringContainsString('class="tv tv-urgente"', $this->get($this->base() . "/avisos/{$id}/previa")->body);
    }

    public function testPreviaComImagemEComVideo(): void
    {
        $this->entrar();
        $comImagem = $this->criarAviso([], ['midia' => Amostras::arquivo('f.png', Amostras::png())]);
        $comVideo = $this->criarAviso([], ['midia' => Amostras::arquivo('v.mp4', Amostras::mp4())]);

        $imagem = $this->get($this->base() . "/avisos/{$comImagem}/previa")->body;
        $video = $this->get($this->base() . "/avisos/{$comVideo}/previa")->body;

        $this->assertStringContainsString('class="tv-corpo tv-com-midia"', $imagem);
        $this->assertStringContainsString("<img src=\"{$this->base()}/avisos/{$comImagem}/midia\"", $imagem);
        $this->assertStringContainsString("<video src=\"{$this->base()}/avisos/{$comVideo}/midia\"", $video);
    }

    public function testPreviaAvisaQuandoOAvisoEstaDesativado(): void
    {
        $this->entrar();
        $id = $this->criarAviso(['ativo' => '']);

        $this->assertStringContainsString('Este aviso está desativado e não aparece na TV.', $this->get($this->base() . "/avisos/{$id}/previa")->body);
    }

    public function testPreviaUsaALogoPersonalizadaDoCondominio(): void
    {
        $this->entrar();
        $id = $this->criarAviso();
        $this->post($this->base() . '/aparencia', ['usar_padrao' => '1'], arquivos: ['logo' => Amostras::arquivo('logo.png', Amostras::png())]);

        $corpo = $this->get($this->base() . "/avisos/{$id}/previa")->body;

        $this->assertStringContainsString("<img src=\"{$this->base()}/logo\"", $corpo);
        $this->assertStringNotContainsString('logo-embracore.svg', $corpo);
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

    // ---------- Aparência ----------

    public function testFormularioDeAparenciaMostraOPadraoDaEmbracore(): void
    {
        $this->entrar();

        $corpo = $this->get($this->base() . '/aparencia')->body;

        $this->assertStringContainsString('Logo padrão da Embracore', $corpo);
        $this->assertMatchesRegularExpression('/name="cor_primaria" value="#00A67E"/', $corpo);
        $this->assertMatchesRegularExpression('/name="cor_secundaria" value="#202124"/', $corpo);
        $this->assertMatchesRegularExpression('/name="usar_padrao" value="1" checked/', $corpo);
        $this->assertStringContainsString('enctype="multipart/form-data"', $corpo);
    }

    public function testSalvarCoresELogoEMostrarDepois(): void
    {
        $this->entrar();

        $r = $this->post($this->base() . '/aparencia', ['cor_primaria' => '#2f6fed', 'cor_secundaria' => '#111111'], arquivos: ['logo' => Amostras::arquivo('logo.png', Amostras::png())]);

        $this->assertSame(303, $r->status);
        $c = $this->pdo->query("SELECT logo, cor_primaria, cor_secundaria FROM condominios WHERE id = {$this->condominio}")->fetch();
        $this->assertSame('#2F6FED', $c['cor_primaria']);
        $this->assertSame('#111111', $c['cor_secundaria']);
        $this->assertNotNull($c['logo']);

        $corpo = $this->get($this->base() . '/aparencia')->body;
        $this->assertStringContainsString('Aparência atualizada com sucesso.', $corpo);
        $this->assertStringContainsString('Logo do condomínio', $corpo);
        $this->assertMatchesRegularExpression('/name="cor_primaria" value="#2F6FED"/', $corpo);
        $this->assertDoesNotMatchRegularExpression('/name="usar_padrao" value="1" checked/', $corpo);
        $this->assertStringContainsString('name="remover_logo"', $corpo);
    }

    public function testAparenciaInvalidaMostraErrosESemAlterar(): void
    {
        $this->entrar();

        $r = $this->post($this->base() . '/aparencia', ['cor_primaria' => 'vermelho', 'cor_secundaria' => '#111111'], arquivos: ['logo' => Amostras::arquivo('logo.pdf', Amostras::pdf())]);

        $this->assertSame(422, $r->status);
        $this->assertStringContainsString('Escolha uma cor válida.', $r->body);
        $this->assertNull($this->pdo->query("SELECT cor_primaria FROM condominios WHERE id = {$this->condominio}")->fetchColumn() ?: null);
        $this->assertSame([], $this->arquivosGuardados());
    }

    public function testLogoPersonalizadaEEntreguePelaRotaProtegida(): void
    {
        $this->entrar();
        $this->assertSame(404, $this->get($this->base() . '/logo')->status, 'Sem logo própria não há o que entregar.');

        $this->post($this->base() . '/aparencia', ['usar_padrao' => '1'], arquivos: ['logo' => Amostras::arquivo('logo.jpg', Amostras::jpg())]);
        $r = $this->get($this->base() . '/logo');

        $this->assertSame(200, $r->status);
        $this->assertSame('image/jpeg', $r->headers['Content-Type']);
        $this->assertSame(Amostras::jpg(), (string) file_get_contents((string) $r->arquivo));
    }

    public function testTemaCssTemAsCoresDoCondominioOuAsPadrao(): void
    {
        $this->entrar();

        $padrao = $this->get($this->base() . '/tema.css');
        $this->assertSame(200, $padrao->status);
        $this->assertStringStartsWith('text/css', $padrao->headers['Content-Type']);
        $this->assertSame('nosniff', $padrao->headers['X-Content-Type-Options']);
        $this->assertStringContainsString('--tv-primaria: #00A67E;', $padrao->body);
        $this->assertStringContainsString('--tv-secundaria: #202124;', $padrao->body);
        $this->assertStringContainsString('--tv-primaria-texto: #1D2125;', $padrao->body, 'Verde claro pede texto escuro.');
        $this->assertStringContainsString('--tv-secundaria-texto: #FFFFFF;', $padrao->body);

        $this->post($this->base() . '/aparencia', ['cor_primaria' => '#1E3A8A', 'cor_secundaria' => '#FACC15']);
        $custom = $this->get($this->base() . '/tema.css')->body;
        $this->assertStringContainsString('--tv-primaria: #1E3A8A;', $custom);
        $this->assertStringContainsString('--tv-primaria-texto: #FFFFFF;', $custom);
        $this->assertStringContainsString('--tv-secundaria: #FACC15;', $custom);
        $this->assertStringContainsString('--tv-secundaria-texto: #1D2125;', $custom);
    }
}
