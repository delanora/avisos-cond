<?php

declare(strict_types=1);

namespace Tests;

use App\Repositories\VinculoRepository;
use Tests\Support\Amostras;

/**
 * ISOLAMENTO ENTRE CONDOMÍNIOS (regra de segurança nº 1, AGENTS.md 6.1 e 9).
 *
 * Cenário padrão: o gerente A, vinculado só ao condomínio A, tenta ler, editar, excluir e criar
 * coisas no condomínio B (por ID direto, na URL ou no corpo do formulário). Em TODOS os casos o
 * resultado é 404, sem nenhuma alteração no banco nem nos arquivos, e sem vazar dado do B.
 */
final class GerenteIsolamentoTest extends AppHttpTestCase
{
    private int $gerenteA;
    private int $gerenteB;
    private int $condA;
    private int $condB;
    private int $avisoA;
    private int $avisoB;
    private string $midiaB;
    private int $tipoB;
    private int $modeloB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gerenteA = $this->criarUsuario('gerente.a@exemplo.com.br');
        $this->gerenteB = $this->criarUsuario('gerente.b@exemplo.com.br');
        $this->condA = $this->criarCondominio('Condomínio Alfa');
        $this->condB = $this->criarCondominio('Condomínio Beta');
        $vinculos = new VinculoRepository($this->pdo);
        $vinculos->sincronizarDoUsuario($this->gerenteA, [$this->condA]);
        $vinculos->sincronizarDoUsuario($this->gerenteB, [$this->condB]);

        // Dados do condomínio B, criados pelo gerente B (que vê só o dele).
        $this->logar('gerente.b@exemplo.com.br');
        $this->avisoB = $this->criarAvisoEm($this->condB, 'SEGREDO-DO-BETA', Amostras::arquivo('beta.png', Amostras::png()));
        $this->post('/gerente/aparencia', ['cor_primaria' => '#AA0000']);
        $this->post('/gerente/tipos', ['nome' => 'Tipo Secreto Beta', 'cor' => '#00BB77', 'fixo' => '1']);
        $this->tipoB = (int) $this->pdo->query("SELECT id FROM tipos_aviso WHERE chave = 'tipo-secreto-beta' AND usuario_id = {$this->gerenteB}")->fetchColumn();
        $this->post('/gerente/modelos', ['nome' => 'Modelo Secreto Beta', 'titulo' => 'Titulo Secreto Beta', 'corpo' => 'Corpo Secreto Beta', 'tipo' => 'comum']);
        $this->modeloB = (int) $this->pdo->query("SELECT id FROM modelos_aviso WHERE nome = 'Modelo Secreto Beta' AND usuario_id = {$this->gerenteB}")->fetchColumn();
        $this->midiaB = (string) $this->pdo->query("SELECT midia FROM avisos WHERE id = {$this->avisoB}")->fetchColumn();

        // Dados do condomínio A, criados pelo gerente A; depois o "navegador" fica logado como A.
        $_SESSION = [];
        $this->logar('gerente.a@exemplo.com.br');
        $this->avisoA = $this->criarAvisoEm($this->condA, 'AVISO-DO-ALFA');
    }

    private function criarAvisoEm(int $condominio, string $titulo, ?array $midia = null): int
    {
        $this->post("/gerente/condominios/{$condominio}/avisos", [
            'titulo' => $titulo, 'corpo' => "Texto de {$titulo}", 'tipo' => 'comum',
            'inicio_em' => '2026-11-20T08:00', 'fim_em' => '2026-11-20T12:00', 'ativo' => '1',
        ], arquivos: $midia === null ? [] : ['midia' => $midia]);

        return (int) $this->pdo->query('SELECT max(id) FROM avisos')->fetchColumn();
    }

    /** @return array<string, mixed> tudo que as rotas poderiam alterar, para comparar antes e depois */
    private function fotografia(): array
    {
        $arquivos = array_map('basename', glob($this->uploads . '/*') ?: []);
        sort($arquivos);

        return [
            'modelos' => $this->pdo->query("SELECT string_agg(id || '|' || coalesce(usuario_id, 0) || '|' || nome || '|' || titulo || '|' || corpo || '|' || tipo, ';' ORDER BY id) FROM modelos_aviso")->fetchColumn(),
            'tipos' => $this->pdo->query("SELECT string_agg(id || '|' || coalesce(usuario_id, 0) || '|' || chave || '|' || nome || '|' || coalesce(cor, '') || '|' || fixo::text, ';' ORDER BY id) FROM tipos_aviso")->fetchColumn(),
            'avisos' => $this->pdo->query("SELECT string_agg(id || '|' || condominio_id || '|' || titulo || '|' || corpo || '|' || tipo_id || '|' || ativo::text || '|' || coalesce(midia, ''), ';' ORDER BY id) FROM avisos")->fetchColumn(),
            'condominios' => $this->pdo->query("SELECT string_agg(id || '|' || nome || '|' || ativo::text, ';' ORDER BY id) FROM condominios")->fetchColumn(),
            'cores' => $this->pdo->query("SELECT string_agg(id || '|' || coalesce(cor_primaria, ''), ';' ORDER BY id) FROM usuarios")->fetchColumn(),
            'vinculos' => $this->pdo->query("SELECT string_agg(usuario_id || '-' || condominio_id, ';' ORDER BY usuario_id, condominio_id) FROM usuario_condominio")->fetchColumn(),
            'arquivos' => $arquivos,
        ];
    }

    /** @return array<string, string> corpo de um formulário de aviso "malicioso" */
    private function corpoMalicioso(int $condominioAlvo): array
    {
        return [
            'titulo' => 'INVADIDO', 'corpo' => 'x', 'tipo' => 'urgente', 'inicio_em' => '2026-11-20T08:00', 'fim_em' => '2026-11-20T12:00',
            'ativo' => '1', 'condominio_id' => (string) $condominioAlvo, 'remover_midia' => '1',
        ];
    }

    /** @return list<array{string, string}> [método, caminho] de todas as rotas, apontando para o condomínio B */
    private function rotasDoCondominioB(): array
    {
        $b = "/gerente/condominios/{$this->condB}";

        return [
            ['GET', "{$b}/avisos"], ['GET', "{$b}/avisos/novo"],
            ['GET', "{$b}/avisos/{$this->avisoB}/editar"], ['GET', "{$b}/avisos/{$this->avisoB}/excluir"],
            ['GET', "{$b}/avisos/{$this->avisoB}/previa"], ['GET', "{$b}/avisos/{$this->avisoB}/midia"],
            ['POST', "{$b}/avisos"], ['POST', "{$b}/avisos/{$this->avisoB}"], ['POST', "{$b}/avisos/{$this->avisoB}/excluir"],
            ['GET', "{$b}/tipos.css"],
        ];
    }

    private function chamar(string $metodo, string $caminho): \App\Support\Response
    {
        if ($metodo === 'GET') {
            return $this->get($caminho);
        }

        return $this->post($caminho, $this->corpoMalicioso($this->condB) + ['usar_padrao' => '1'], arquivos: [
            'midia' => Amostras::arquivo('invasor.png', Amostras::png()),
        ]);
    }

    private function assertSemVazamento(string $corpo, string $contexto): void
    {
        foreach (['SEGREDO-DO-BETA', 'Condomínio Beta', 'Tipo Secreto Beta', 'tipo-secreto-beta', 'Modelo Secreto Beta', 'Titulo Secreto Beta', 'Corpo Secreto Beta', '#00BB77', $this->midiaB, '#AA0000'] as $segredo) {
            $this->assertStringNotContainsString($segredo, $corpo, "Vazou '{$segredo}' em {$contexto}");
        }
    }

    // ---------- Gerente A × condomínio B: tudo é 404 e nada muda ----------

    public function testTodasAsRotasDoCondominioBRetornam404ParaOGerenteA(): void
    {
        $antes = $this->fotografia();

        foreach ($this->rotasDoCondominioB() as [$metodo, $caminho]) {
            $r = $this->chamar($metodo, $caminho);

            $this->assertSame(404, $r->status, "{$metodo} {$caminho}");
            $this->assertSemVazamento($r->body, "{$metodo} {$caminho}");
            $this->assertNull($r->arquivo, "{$metodo} {$caminho} não pode entregar arquivo");
            $this->assertArrayNotHasKey('X-Accel-Redirect', $r->headers, "{$metodo} {$caminho}");
        }

        $this->assertSame($antes, $this->fotografia(), 'O banco e os arquivos do condomínio B ficaram intactos.');
    }

    public function testAvisoDoBPedidoPelaUrlDoAEh404EmTodasAsRotasComId(): void
    {
        $a = "/gerente/condominios/{$this->condA}/avisos/{$this->avisoB}";
        $antes = $this->fotografia();

        foreach ([['GET', "{$a}/editar"], ['GET', "{$a}/excluir"], ['GET', "{$a}/previa"], ['GET', "{$a}/midia"], ['POST', $a], ['POST', "{$a}/excluir"]] as [$metodo, $caminho]) {
            $r = $this->chamar($metodo, $caminho);

            $this->assertSame(404, $r->status, "{$metodo} {$caminho}");
            $this->assertSemVazamento($r->body, "{$metodo} {$caminho}");
            $this->assertNull($r->arquivo, "{$metodo} {$caminho}");
        }

        $this->assertSame($antes, $this->fotografia());
    }

    public function testAvisoDoAPedidoPelaUrlDoBTambemEh404(): void
    {
        $b = "/gerente/condominios/{$this->condB}/avisos/{$this->avisoA}";
        $antes = $this->fotografia();

        foreach ([['GET', "{$b}/editar"], ['GET', "{$b}/midia"], ['POST', $b], ['POST', "{$b}/excluir"]] as [$metodo, $caminho]) {
            $this->assertSame(404, $this->chamar($metodo, $caminho)->status, "{$metodo} {$caminho}");
        }

        $this->assertSame($antes, $this->fotografia());
    }

    public function testCriarAvisoNoBNaoCriaNadaENaoGuardaArquivo(): void
    {
        $antes = $this->fotografia();

        $r = $this->post("/gerente/condominios/{$this->condB}/avisos", $this->corpoMalicioso($this->condB), arquivos: ['midia' => Amostras::arquivo('x.png', Amostras::png())]);

        $this->assertSame(404, $r->status);
        $this->assertSame($antes, $this->fotografia());
    }

    public function testCondominioIdNoCorpoEhIgnoradoNaCriacaoENaEdicao(): void
    {
        // Criar na URL do A mandando condominio_id do B: o aviso nasce no A.
        $this->post("/gerente/condominios/{$this->condA}/avisos", $this->corpoMalicioso($this->condB));
        $novo = (int) $this->pdo->query('SELECT max(id) FROM avisos')->fetchColumn();
        $this->assertSame($this->condA, (int) $this->pdo->query("SELECT condominio_id FROM avisos WHERE id = {$novo}")->fetchColumn());

        // Editar o aviso do A mandando condominio_id do B: continua no A.
        $this->post("/gerente/condominios/{$this->condA}/avisos/{$this->avisoA}", $this->corpoMalicioso($this->condB));
        $this->assertSame($this->condA, (int) $this->pdo->query("SELECT condominio_id FROM avisos WHERE id = {$this->avisoA}")->fetchColumn());

        $this->assertSame(0, (int) $this->pdo->query("SELECT count(*) FROM avisos WHERE condominio_id = {$this->condB} AND titulo = 'INVADIDO'")->fetchColumn());
    }

    public function testCorDeUmGerenteNaoApareceNemMudaParaOOutro(): void
    {
        // A cor é do próprio gerente: A não vê a de B e, ao salvar a dele, a de B continua igual.
        $this->assertStringNotContainsString('#AA0000', $this->get('/gerente/tema.css')->body);
        $this->post('/gerente/aparencia', ['cor_primaria' => '#0044CC']);

        $this->assertSame('#AA0000', $this->pdo->query("SELECT cor_primaria FROM usuarios WHERE id = {$this->gerenteB}")->fetchColumn());
        $this->assertSame('#0044CC', $this->pdo->query("SELECT cor_primaria FROM usuarios WHERE id = {$this->gerenteA}")->fetchColumn());
    }

    public function testTipoDoBNaoEhListadoNemUsadoNemEditadoPeloA(): void
    {
        $antes = $this->fotografia();

        // Não aparece em nenhuma tela do A (formulário de aviso, lista de tipos, folha de cores).
        foreach (["/gerente/condominios/{$this->condA}/avisos/novo", '/gerente/tipos', "/gerente/condominios/{$this->condA}/tipos.css"] as $caminho) {
            $r = $this->get($caminho);
            $this->assertSame(200, $r->status, $caminho);
            $this->assertSemVazamento($r->body, $caminho);
        }

        // Não pode ser escolhido num aviso do A, nem pela chave nem pelo id.
        $r = $this->post("/gerente/condominios/{$this->condA}/avisos", ['tipo' => 'tipo-secreto-beta', 'titulo' => 'Tentativa', 'corpo' => 'x', 'inicio_em' => '2026-11-20T08:00', 'fim_em' => '2026-11-20T12:00', 'ativo' => '1']);
        $this->assertSame(422, $r->status);
        $this->assertStringContainsString('Escolha um tipo válido.', $r->body);
        $this->assertSame(0, (int) $this->pdo->query("SELECT count(*) FROM avisos WHERE titulo = 'Tentativa'")->fetchColumn());

        // Nem editado/apagado pelo A, mesmo usando o id dele na URL do condomínio do A.
        foreach ([['GET', "/gerente/tipos/{$this->tipoB}/editar"], ['GET', "/gerente/tipos/{$this->tipoB}/excluir"], ['POST', "/gerente/tipos/{$this->tipoB}"], ['POST', "/gerente/tipos/{$this->tipoB}/excluir"]] as [$metodo, $caminho]) {
            $this->assertSame(404, $this->chamar($metodo, $caminho)->status, "{$metodo} {$caminho}");
        }
        $this->assertSame($antes, $this->fotografia());
    }

    public function testModeloDoBNaoEhListadoNemUsadoNemEditadoPeloA(): void
    {
        $antes = $this->fotografia();

        foreach (["/gerente/condominios/{$this->condA}/avisos/novo", '/gerente/modelos'] as $caminho) {
            $r = $this->get($caminho);
            $this->assertSame(200, $r->status, $caminho);
            $this->assertSemVazamento($r->body, $caminho);
        }

        // Usar o modelo do B na tela de aviso do A, ou mexer nele pelo id, é 404 e não muda nada.
        foreach ([['GET', "/gerente/condominios/{$this->condA}/avisos/novo?modelo={$this->modeloB}"], ['GET', "/gerente/modelos/{$this->modeloB}/editar"], ['GET', "/gerente/modelos/{$this->modeloB}/excluir"],
                  ['POST', "/gerente/modelos/{$this->modeloB}"], ['POST', "/gerente/modelos/{$this->modeloB}/excluir"]] as [$metodo, $caminho]) {
            $r = $this->chamar($metodo, $caminho);
            $this->assertSame(404, $r->status, "{$metodo} {$caminho}");
            $this->assertSemVazamento($r->body, "{$metodo} {$caminho}");
        }
        $this->assertSame($antes, $this->fotografia());
    }

    // ---------- Dois gerentes do MESMO condomínio: tipos e modelos continuam sendo de cada um ----------

    /** @return array{int, int, int} [id do gerente C, id do tipo dele, id do modelo dele]; deixa o navegador logado como C */
    private function gerenteCNoMesmoCondominioDoA(): array
    {
        $c = $this->criarUsuario('gerente.c@exemplo.com.br');
        (new VinculoRepository($this->pdo))->sincronizarDoUsuario($c, [$this->condA]);
        $_SESSION = [];
        $this->logar('gerente.c@exemplo.com.br');
        $this->post('/gerente/tipos', ['nome' => 'Tipo Secreto do C', 'cor' => '#CC0077', 'fixo' => '1']);
        $this->post('/gerente/modelos', ['nome' => 'Modelo Secreto do C', 'titulo' => 'Titulo Secreto do C', 'corpo' => 'Corpo Secreto do C', 'tipo' => 'comum']);
        $tipo = (int) $this->pdo->query("SELECT id FROM tipos_aviso WHERE usuario_id = {$c} AND chave = 'tipo-secreto-do-c'")->fetchColumn();
        $modelo = (int) $this->pdo->query("SELECT id FROM modelos_aviso WHERE usuario_id = {$c} AND nome = 'Modelo Secreto do C'")->fetchColumn();

        return [$c, $tipo, $modelo];
    }

    public function testTiposEModelosDoCNaoAparecemParaOAMesmoNoMesmoCondominio(): void
    {
        [, $tipoC, $modeloC] = $this->gerenteCNoMesmoCondominioDoA();
        $_SESSION = [];
        $this->logar('gerente.a@exemplo.com.br');
        $antes = $this->fotografia();

        foreach (["/gerente/condominios/{$this->condA}/avisos/novo", '/gerente/tipos', '/gerente/modelos'] as $caminho) {
            $corpo = $this->get($caminho)->body;
            foreach (['Tipo Secreto do C', 'tipo-secreto-do-c', 'Modelo Secreto do C', 'Titulo Secreto do C', 'Corpo Secreto do C', '#CC0077'] as $segredo) {
                $this->assertStringNotContainsString($segredo, $corpo, "Vazou '{$segredo}' em {$caminho}");
            }
        }
        foreach ([['GET', "/gerente/tipos/{$tipoC}/editar"], ['POST', "/gerente/tipos/{$tipoC}"], ['POST', "/gerente/tipos/{$tipoC}/excluir"],
                  ['GET', "/gerente/modelos/{$modeloC}/editar"], ['POST', "/gerente/modelos/{$modeloC}"], ['POST', "/gerente/modelos/{$modeloC}/excluir"],
                  ['GET', "/gerente/condominios/{$this->condA}/avisos/novo?modelo={$modeloC}"]] as [$metodo, $caminho]) {
            $this->assertSame(404, $this->chamar($metodo, $caminho)->status, "{$metodo} {$caminho}");
        }
        $this->assertSame($antes, $this->fotografia());
    }

    public function testAnaoUsaOTipoDoCNemPelaChaveNemCriandoAvisoNoMesmoCondominio(): void
    {
        [, $tipoC] = $this->gerenteCNoMesmoCondominioDoA();
        $_SESSION = [];
        $this->logar('gerente.a@exemplo.com.br');

        $r = $this->post("/gerente/condominios/{$this->condA}/avisos", ['tipo' => 'tipo-secreto-do-c', 'titulo' => 'Tentativa', 'corpo' => 'x', 'inicio_em' => '2026-11-20T08:00', 'fim_em' => '2026-11-20T12:00', 'ativo' => '1']);
        $this->assertSame(422, $r->status);
        $this->assertStringContainsString('Escolha um tipo válido.', $r->body);

        // "__atual" só vale ao EDITAR um aviso que já tem o tipo; ao criar não dá para pegar o do C.
        $r = $this->post("/gerente/condominios/{$this->condA}/avisos", ['tipo' => '__atual', 'titulo' => 'Tentativa', 'corpo' => 'x', 'inicio_em' => '2026-11-20T08:00', 'fim_em' => '2026-11-20T12:00', 'ativo' => '1']);
        $this->assertSame(422, $r->status);
        $this->assertSame(0, (int) $this->pdo->query("SELECT count(*) FROM avisos WHERE titulo = 'Tentativa'")->fetchColumn());
        $this->assertSame(0, (int) $this->pdo->query("SELECT count(*) FROM avisos WHERE tipo_id = {$tipoC}")->fetchColumn());
    }

    public function testAvisoCriadoPeloCComOTipoDeleApareceParaOAComEtiquetaEPodeSerEditadoSemPerderOTipo(): void
    {
        [$c, $tipoC] = $this->gerenteCNoMesmoCondominioDoA();
        $this->post("/gerente/condominios/{$this->condA}/avisos", ['tipo' => 'tipo-secreto-do-c', 'titulo' => 'AVISO-DO-C', 'corpo' => 'x', 'inicio_em' => '2026-11-20T08:00', 'fim_em' => '2026-11-20T12:00', 'ativo' => '1']);
        $avisoC = (int) $this->pdo->query("SELECT id FROM avisos WHERE titulo = 'AVISO-DO-C'")->fetchColumn();
        $_SESSION = [];
        $this->logar('gerente.a@exemplo.com.br');

        // O aviso é do condomínio (compartilhado): o A o vê com a etiqueta e a cor do tipo do C.
        $lista = $this->get("/gerente/condominios/{$this->condA}/avisos")->body;
        $this->assertStringContainsString("etiqueta-tipo-t{$tipoC}\">Tipo Secreto do C<", $lista);
        $this->assertStringContainsString('#CC0077', $this->get("/gerente/condominios/{$this->condA}/tipos.css")->body);
        // Na edição aparece como "manter o tipo atual"; os tipos do A continuam sendo só os do A.
        $edicao = $this->get("/gerente/condominios/{$this->condA}/avisos/{$avisoC}/editar")->body;
        $this->assertStringContainsString('<option value="__atual" selected>Tipo Secreto do C', $edicao);
        $this->assertStringNotContainsString('value="tipo-secreto-do-c"', $edicao);

        $this->post("/gerente/condominios/{$this->condA}/avisos/{$avisoC}", ['tipo' => '__atual', 'titulo' => 'AVISO-DO-C editado', 'corpo' => 'x', 'inicio_em' => '2026-11-20T08:00', 'fim_em' => '2026-11-20T12:00', 'ativo' => '1']);
        $this->assertSame($tipoC, (int) $this->pdo->query("SELECT tipo_id FROM avisos WHERE id = {$avisoC}")->fetchColumn(), 'Editar não troca o tipo do C.');
        $this->assertSame('AVISO-DO-C editado', $this->pdo->query("SELECT titulo FROM avisos WHERE id = {$avisoC}")->fetchColumn());
        $this->assertSame($c, (int) $this->pdo->query("SELECT usuario_id FROM tipos_aviso WHERE id = {$tipoC}")->fetchColumn());
    }

    public function testCNaoApagaOTipoQueTemAvisoENemOAApagaPeloIdDoC(): void
    {
        [, $tipoC] = $this->gerenteCNoMesmoCondominioDoA();
        $this->post("/gerente/condominios/{$this->condA}/avisos", ['tipo' => 'tipo-secreto-do-c', 'titulo' => 'AVISO-DO-C', 'corpo' => 'x', 'inicio_em' => '2026-11-20T08:00', 'fim_em' => '2026-11-20T12:00', 'ativo' => '1']);

        $this->post("/gerente/tipos/{$tipoC}/excluir");

        $this->assertSame(1, (int) $this->pdo->query("SELECT count(*) FROM tipos_aviso WHERE id = {$tipoC}")->fetchColumn(), 'Em uso: continua.');
    }

    public function testCondominioInexistenteSeComportaIgualAoDeOutroGerente(): void
    {
        foreach (['GET /gerente/condominios/999999/avisos'] as $rota) {
            [$metodo, $caminho] = explode(' ', $rota);
            $this->assertSame(404, $this->get($caminho)->status, $rota);
        }
    }

    public function testListaEPaginasDoAMostramSoDadosDoA(): void
    {
        foreach (["/gerente/condominios/{$this->condA}/avisos", "/gerente/aparencia", "/gerente/tema.css", "/gerente/condominios/{$this->condA}/avisos/{$this->avisoA}/previa"] as $caminho) {
            $r = $this->get($caminho);

            $this->assertSame(200, $r->status, $caminho);
            $this->assertSemVazamento($r->body, $caminho);
        }
        $this->assertStringContainsString('AVISO-DO-ALFA', $this->get("/gerente/condominios/{$this->condA}/avisos")->body);
    }

    public function testMidiaDoBNaoSaiPeloCondominioDoA(): void
    {
        // Mesmo conhecendo o ID do aviso do B e o nome do arquivo, nenhuma rota o entrega ao A.
        foreach (["/gerente/condominios/{$this->condA}/avisos/{$this->avisoB}/midia", "/gerente/condominios/{$this->condB}/avisos/{$this->avisoB}/midia"] as $caminho) {
            $r = $this->get($caminho);

            $this->assertSame(404, $r->status, $caminho);
            $this->assertNull($r->arquivo, $caminho);
        }
        $this->assertFileExists($this->uploads . '/' . $this->midiaB);
    }

    public function testPainelEMenuDoAListamSoOCondominioDele(): void
    {
        $inicio = $this->get('/gerente');
        $this->assertSame(302, $inicio->status);
        $this->assertSame("/gerente/condominios/{$this->condA}/avisos", $inicio->headers['Location']);

        $this->assertSemVazamento($this->get("/gerente/condominios/{$this->condA}/avisos")->body, 'menu');
    }

    // ---------- Vínculos: concedidos e revogados valem na hora ----------

    public function testVinculoRemovidoPeloAdminValeNaProximaRequisicao(): void
    {
        $url = "/gerente/condominios/{$this->condA}/avisos";
        $this->assertSame(200, $this->get($url)->status);

        (new VinculoRepository($this->pdo))->sincronizarDoUsuario($this->gerenteA, []);

        $this->assertSame(404, $this->get($url)->status);
        $this->assertSame(404, $this->get("{$url}/{$this->avisoA}/editar")->status);
        $this->assertSame(404, $this->post("{$url}/{$this->avisoA}/excluir")->status);
        $this->assertSame(1, (int) $this->pdo->query("SELECT count(*) FROM avisos WHERE id = {$this->avisoA}")->fetchColumn());
    }

    public function testVinculoNovoLiberaOAcessoNaHora(): void
    {
        $url = "/gerente/condominios/{$this->condB}/avisos";
        $this->assertSame(404, $this->get($url)->status);

        (new VinculoRepository($this->pdo))->sincronizarDoUsuario($this->gerenteA, [$this->condA, $this->condB]);

        $this->assertSame(200, $this->get($url)->status);
        $this->assertStringContainsString('SEGREDO-DO-BETA', $this->get($url)->body, 'Agora o A é gerente do B também.');
    }

    public function testCondominioDesativadoPeloAdminSomeParaOGerente(): void
    {
        $this->pdo->exec("UPDATE condominios SET ativo = false WHERE id = {$this->condA}");

        $this->assertSame(404, $this->get("/gerente/condominios/{$this->condA}/avisos")->status);
        $this->assertSame(404, $this->post("/gerente/condominios/{$this->condA}/avisos", $this->corpoMalicioso($this->condA))->status);
        $this->assertStringContainsString('Nenhum condomínio por aqui ainda', $this->get('/gerente')->body);
    }

    public function testGerenteSemNenhumVinculoNaoAcessaNadaPorId(): void
    {
        $semVinculo = $this->criarUsuario('livre@exemplo.com.br');
        $_SESSION = [];
        $this->logar('livre@exemplo.com.br');
        $this->get('/gerente'); // o primeiro acesso entrega a ele a cópia dos tipos e modelos de fábrica
        $antes = $this->fotografia();

        foreach ([[$this->condA, $this->avisoA], [$this->condB, $this->avisoB]] as [$cond, $aviso]) {
            foreach ([['GET', "/gerente/condominios/{$cond}/avisos"], ['GET', "/gerente/condominios/{$cond}/avisos/{$aviso}/editar"], ['GET', "/gerente/condominios/{$cond}/avisos/{$aviso}/midia"],
                      ['POST', "/gerente/condominios/{$cond}/avisos/{$aviso}/excluir"]] as [$metodo, $caminho]) {
                $this->assertSame(404, $this->chamar($metodo, $caminho)->status, "{$metodo} {$caminho}");
            }
        }

        $this->assertSame($antes, $this->fotografia());
        $this->assertGreaterThan(0, $semVinculo);
    }

    public function testGerenteComDoisCondominiosAcessaOsDoisMasNaoUmTerceiro(): void
    {
        $terceiro = $this->criarCondominio('Condomínio Gama');
        (new VinculoRepository($this->pdo))->sincronizarDoUsuario($this->gerenteA, [$this->condA, $this->condB]);

        $this->assertSame(200, $this->get("/gerente/condominios/{$this->condA}/avisos")->status);
        $this->assertSame(200, $this->get("/gerente/condominios/{$this->condB}/avisos")->status);
        $this->assertSame(404, $this->get("/gerente/condominios/{$terceiro}/avisos")->status);

        // Dentro de um dos seus condomínios, o aviso do outro continua só acessível pela URL certa.
        $this->assertSame(404, $this->get("/gerente/condominios/{$this->condA}/avisos/{$this->avisoB}/editar")->status);
        $this->assertSame(200, $this->get("/gerente/condominios/{$this->condB}/avisos/{$this->avisoB}/editar")->status);
    }

    // ---------- Papéis e sessão ----------

    public function testSuperadminNaoUsaAsRotasDoGerente(): void
    {
        $this->criarUsuario('admin@exemplo.com.br', 'superadmin');
        $_SESSION = [];
        $this->logar('admin@exemplo.com.br');
        $antes = $this->fotografia();

        foreach ($this->rotasDoCondominioB() as [$metodo, $caminho]) {
            $this->assertSame(403, $this->chamar($metodo, $caminho)->status, "{$metodo} {$caminho}");
        }
        $this->assertSame(403, $this->get('/gerente')->status);

        $this->assertSame($antes, $this->fotografia());
    }

    public function testVisitanteAnonimoVaiParaOLoginEmTodasAsRotas(): void
    {
        $_SESSION = [];
        $antes = $this->fotografia();

        foreach ([...$this->rotasDoCondominioB(), ['GET', '/gerente'], ['GET', "/gerente/condominios/{$this->condA}/avisos"]] as [$metodo, $caminho]) {
            $r = $metodo === 'GET' ? $this->get($caminho) : $this->post($caminho, $this->corpoMalicioso($this->condB), arquivos: ['midia' => Amostras::arquivo('x.png', Amostras::png())]);

            $this->assertSame(302, $r->status, "{$metodo} {$caminho}");
            $this->assertSame('/login', $r->headers['Location'], "{$metodo} {$caminho}");
            $this->assertNull($r->arquivo);
        }

        $this->assertSame($antes, $this->fotografia());
    }

    public function testRotasDoAdminContinuamNegadasAoGerente(): void
    {
        $this->assertSame(403, $this->get('/admin')->status);
        $this->assertSame(403, $this->get('/admin/condominios')->status);
        $this->assertSame(403, $this->get("/admin/condominios/{$this->condB}/editar")->status);
    }
}
