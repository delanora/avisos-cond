<?php

declare(strict_types=1);

namespace Tests;

use App\Services\FinanceiroService;
use Tests\Support\AvisosDeTeste;

/** Financeiro manual: restrito ao superadmin, situação por condomínio, totais, bloqueio e TV bloqueada. */
final class FinanceiroTest extends AppHttpTestCase
{
    use AvisosDeTeste;

    private const TOKEN = 'ABCDEFGH2345';

    private int $a;
    private int $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->a = $this->criarCondominio('Residencial Alfa');
        $this->b = $this->criarCondominio('Residencial Beta');
        $this->dispositivoTv($this->pdo, $this->a, self::TOKEN, 'Elevador');
    }

    /** @return array{get: list<string>, post: list<string>} */
    private function rotas(): array
    {
        $id = $this->a;

        return [
            'get' => ['/admin/financeiro', "/admin/financeiro/{$id}/editar"],
            'post' => ["/admin/financeiro/{$id}", "/admin/financeiro/{$id}/bloquear", "/admin/financeiro/{$id}/desbloquear"],
        ];
    }

    private function fotografia(): string
    {
        return (string) $this->pdo->query("SELECT (SELECT count(*) FROM condominio_financeiro) || '|' || (SELECT string_agg(bloqueado::text, ',' ORDER BY id) FROM condominios)")->fetchColumn();
    }

    /** @return array<string, string> */
    private function dados(array $extra = []): array
    {
        return $extra + ['situacao' => 'pendente', 'valor_mensal' => '1.250,50', 'dia_vencimento' => '10', 'observacoes' => 'Combinou pagar na sexta'];
    }

    // ---------- Autorização ----------

    public function testVisitanteEGerenteNaoAcessamNemAlteramNada(): void
    {
        $antes = $this->fotografia();
        foreach ($this->rotas()['get'] as $rota) {
            $this->assertSame(302, $this->get($rota)->status, $rota);
        }
        foreach ($this->rotas()['post'] as $rota) {
            $this->assertSame(302, $this->post($rota, $this->dados(['motivo' => 'x']))->status, $rota);
        }

        $this->criarUsuario('gerente@exemplo.com.br');
        $this->logar();
        foreach ($this->rotas()['get'] as $rota) {
            $this->assertSame(403, $this->get($rota)->status, $rota);
        }
        foreach ($this->rotas()['post'] as $rota) {
            $this->assertSame(403, $this->post($rota, $this->dados(['motivo' => 'x']))->status, $rota);
        }

        $this->assertSame($antes, $this->fotografia());
        $this->assertStringNotContainsString('/admin/financeiro', $this->get('/gerente')->body, 'O menu do gerente não tem financeiro.');
    }

    public function testEscritasSemCsrfSaoBloqueadas(): void
    {
        $this->logarComoAdmin();
        $antes = $this->fotografia();

        foreach ($this->rotas()['post'] as $rota) {
            $this->assertSame(403, $this->post($rota, $this->dados(['motivo' => 'x']), comCsrf: false)->status, $rota);
        }

        $this->assertSame($antes, $this->fotografia());
    }

    public function testCondominioInexistenteDa404(): void
    {
        $this->logarComoAdmin();

        $this->assertSame(404, $this->get('/admin/financeiro/999999/editar')->status);
        $this->assertSame(404, $this->post('/admin/financeiro/999999', $this->dados())->status);
        $this->assertSame(404, $this->post('/admin/financeiro/999999/bloquear', ['motivo' => 'x'])->status);
        $this->assertSame(404, $this->post('/admin/financeiro/999999/desbloquear')->status);
    }

    // ---------- Situação e totais ----------

    public function testSalvaSituacaoEMostraNaListaComTotais(): void
    {
        $this->logarComoAdmin();

        $r = $this->post("/admin/financeiro/{$this->a}", $this->dados());
        $this->assertSame(303, $r->status);
        $this->post("/admin/financeiro/{$this->b}", $this->dados(['situacao' => 'em_dia', 'valor_mensal' => '800', 'dia_vencimento' => '5']));

        $linha = $this->pdo->query("SELECT situacao, valor_mensal, dia_vencimento, observacoes FROM condominio_financeiro WHERE condominio_id = {$this->a}")->fetch();
        $this->assertSame(['situacao' => 'pendente', 'valor_mensal' => 125050, 'dia_vencimento' => 10, 'observacoes' => 'Combinou pagar na sexta'], $linha);

        $lista = $this->get('/admin/financeiro');
        $this->assertSame(200, $lista->status);
        $this->assertStringContainsString('Residencial Alfa', $lista->body);
        $this->assertStringContainsString('R$ 1.250,50', $lista->body, 'Valor em aberto.');
        $this->assertStringContainsString('R$ 800,00', $lista->body, 'Valor pago pelo condomínio em dia.');
    }

    public function testSalvarDeNovoAtualizaSemDuplicar(): void
    {
        $this->logarComoAdmin();
        $this->post("/admin/financeiro/{$this->a}", $this->dados());
        $this->post("/admin/financeiro/{$this->a}", $this->dados(['situacao' => 'em_dia']));

        $this->assertSame(1, $this->contar('condominio_financeiro'));
        $this->assertSame('em_dia', $this->pdo->query('SELECT situacao FROM condominio_financeiro')->fetchColumn());
    }

    public function testFiltrosDaLista(): void
    {
        $this->logarComoAdmin();
        $this->post("/admin/financeiro/{$this->a}", $this->dados());
        $this->post("/admin/financeiro/{$this->a}/bloquear", ['motivo' => 'Atraso']);

        $pendentes = $this->get('/admin/financeiro?situacao=pendente')->body;
        $this->assertStringContainsString('Residencial Alfa', $pendentes);
        $this->assertStringNotContainsString('Residencial Beta', $pendentes);

        $semCadastro = $this->get('/admin/financeiro?situacao=sem_cadastro')->body;
        $this->assertStringContainsString('Residencial Beta', $semCadastro);
        $this->assertStringNotContainsString('Residencial Alfa', $semCadastro);

        $bloqueados = $this->get('/admin/financeiro?situacao=bloqueado')->body;
        $this->assertStringContainsString('Residencial Alfa', $bloqueados);
        $this->assertStringNotContainsString('Residencial Beta', $bloqueados);

        $this->assertSame(200, $this->get("/admin/financeiro?situacao=%27%3B--")->status, 'Filtro inválido vira "todos".');
    }

    /** @return array<string, array{array<string, string>, string}> */
    public static function entradasInvalidas(): array
    {
        return [
            'situação desconhecida' => [['situacao' => 'cancelado'], 'situacao'],
            'valor com letras' => [['valor_mensal' => 'abc'], 'valor_mensal'],
            'valor negativo' => [['valor_mensal' => '-10'], 'valor_mensal'],
            'valor absurdo' => [['valor_mensal' => '9.999.999,00'], 'valor_mensal'],
            'dia zero' => [['dia_vencimento' => '0'], 'dia_vencimento'],
            'dia 32' => [['dia_vencimento' => '32'], 'dia_vencimento'],
            'dia com texto' => [['dia_vencimento' => 'dez'], 'dia_vencimento'],
            'observação longa' => [['observacoes' => str_repeat('x', 1001)], 'observacoes'],
        ];
    }

    /** @param array<string, string> $extra */
    #[\PHPUnit\Framework\Attributes\DataProvider('entradasInvalidas')]
    public function testEntradaInvalidaNaoGravaNada(array $extra, string $campo): void
    {
        $this->logarComoAdmin();

        $r = $this->post("/admin/financeiro/{$this->a}", $this->dados($extra));

        $this->assertSame(422, $r->status);
        $this->assertSame(0, $this->contar('condominio_financeiro'));
        $this->assertStringContainsString('erro-texto', $r->body);
    }

    public function testValorEDiaSaoOpcionais(): void
    {
        $this->logarComoAdmin();

        $this->assertSame(303, $this->post("/admin/financeiro/{$this->a}", ['situacao' => 'em_dia'])->status);
        $linha = $this->pdo->query('SELECT valor_mensal, dia_vencimento, observacoes FROM condominio_financeiro')->fetch();
        $this->assertSame(['valor_mensal' => null, 'dia_vencimento' => null, 'observacoes' => null], $linha);
    }

    public function testConversaoDeValores(): void
    {
        $this->assertSame(125050, FinanceiroService::paraCentavos('1.250,50'));
        $this->assertSame(15000, FinanceiroService::paraCentavos('R$ 150'));
        $this->assertSame(15050, FinanceiroService::paraCentavos('150,5'));
        $this->assertSame(0, FinanceiroService::paraCentavos('0'));
        $this->assertNull(FinanceiroService::paraCentavos('1,234'));
        $this->assertNull(FinanceiroService::paraCentavos('12,5,0'));
        $this->assertNull(FinanceiroService::paraCentavos(''));
        $this->assertSame('R$ 1.250,50', FinanceiroService::formatar(125050));
        $this->assertSame('—', FinanceiroService::formatar(null));
    }

    // ---------- Bloqueio ----------

    public function testBloquearExigeMotivo(): void
    {
        $this->logarComoAdmin();

        $this->assertSame(422, $this->post("/admin/financeiro/{$this->a}/bloquear", ['motivo' => '  '])->status);
        $this->assertSame(422, $this->post("/admin/financeiro/{$this->a}/bloquear", ['motivo' => str_repeat('x', 256)])->status);
        $this->assertFalse((bool) $this->pdo->query("SELECT bloqueado FROM condominios WHERE id = {$this->a}")->fetchColumn());
    }

    public function testBloquearEDesbloquearGravamMotivoEAuditoria(): void
    {
        $this->logarComoAdmin();

        $this->assertSame(303, $this->post("/admin/financeiro/{$this->a}/bloquear", ['motivo' => 'Mensalidade de outubro em aberto'])->status);
        $linha = $this->pdo->query("SELECT bloqueado, bloqueio_motivo, bloqueado_em IS NOT NULL AS quando FROM condominios WHERE id = {$this->a}")->fetch();
        $this->assertTrue($linha['bloqueado']);
        $this->assertSame('Mensalidade de outubro em aberto', $linha['bloqueio_motivo']);
        $this->assertTrue($linha['quando']);
        $this->assertFalse((bool) $this->pdo->query("SELECT bloqueado FROM condominios WHERE id = {$this->b}")->fetchColumn(), 'Só o condomínio escolhido é bloqueado.');
        $this->assertStringContainsString('Mensalidade de outubro em aberto', $this->get("/admin/financeiro/{$this->a}/editar")->body);

        $this->assertSame(303, $this->post("/admin/financeiro/{$this->a}/desbloquear")->status);
        $linha = $this->pdo->query("SELECT bloqueado, bloqueio_motivo, bloqueado_em FROM condominios WHERE id = {$this->a}")->fetch();
        $this->assertSame(['bloqueado' => false, 'bloqueio_motivo' => null, 'bloqueado_em' => null], $linha);

        $acoes = $this->pdo->query('SELECT acao FROM auditoria ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertContains('condominio.bloqueado', $acoes);
        $this->assertContains('condominio.desbloqueado', $acoes);
        $detalhe = (string) $this->pdo->query("SELECT detalhes::text FROM auditoria WHERE acao = 'condominio.bloqueado'")->fetchColumn();
        $this->assertStringContainsString('Mensalidade de outubro em aberto', $detalhe);
    }

    public function testAlterarSituacaoVaiParaAuditoria(): void
    {
        $this->logarComoAdmin();
        $this->post("/admin/financeiro/{$this->a}", $this->dados());

        $this->assertSame(1, (int) $this->pdo->query("SELECT count(*) FROM auditoria WHERE acao = 'financeiro.atualizado' AND condominio_nome = 'Residencial Alfa'")->fetchColumn());
    }

    public function testBancoRecusaBloqueioSemMotivo(): void
    {
        $this->expectException(\PDOException::class);
        $this->pdo->exec("UPDATE condominios SET bloqueado = true WHERE id = {$this->a}");
    }

    // ---------- TV ----------

    public function testTvBloqueadaMostraSoORelogioESemMidias(): void
    {
        $this->criarUsuario('autor@exemplo.com.br');
        $this->avisoTv($this->pdo, $this->a, 'Aviso que não pode aparecer', -60, 3600);
        $antes = json_decode($this->get('/tv/' . self::TOKEN . '/state')->body, true);
        $this->assertFalse($antes['bloqueado']);
        $this->assertCount(1, $antes['avisos']);

        $this->logarComoAdmin();
        $this->post("/admin/financeiro/{$this->a}/bloquear", ['motivo' => 'Atraso']);

        $estado = json_decode($this->get('/tv/' . self::TOKEN . '/state')->body, true);
        $this->assertTrue($estado['bloqueado']);
        $this->assertTrue($estado['bloqueio_financeiro']);
        $this->assertSame([], $estado['avisos']);
        $this->assertStringContainsString('Bloqueado por pendências financeiras', $this->get('/tv/' . self::TOKEN)->body, 'A mensagem já vem na página (funciona offline).');
        $this->assertStringNotContainsString('Aviso que não pode aparecer', $this->get('/tv/' . self::TOKEN)->body);
        $this->assertStringNotContainsString('Atraso', $this->get('/tv/' . self::TOKEN . '/state')->body, 'A TV não vê o motivo.');

        $this->post("/admin/financeiro/{$this->a}/desbloquear");
        $depois = json_decode($this->get('/tv/' . self::TOKEN . '/state')->body, true);
        $this->assertFalse($depois['bloqueado']);
        $this->assertFalse($depois['bloqueio_financeiro']);
        $this->assertCount(1, $depois['avisos']);
    }

    public function testCondominioDesativadoNaoMostraAMensagemFinanceira(): void
    {
        $this->pdo->exec("UPDATE condominios SET ativo = false WHERE id = {$this->a}");

        $estado = json_decode($this->get('/tv/' . self::TOKEN . '/state')->body, true);

        $this->assertTrue($estado['bloqueado']);
        $this->assertFalse($estado['bloqueio_financeiro'], 'Desativar não é pendência financeira.');
    }

    public function testBloquearUmCondominioNaoAfetaATvDeOutro(): void
    {
        $this->dispositivoTv($this->pdo, $this->b, 'BCDEFGH23456', 'Hall');
        $this->criarUsuario('autor@exemplo.com.br');
        $this->avisoTv($this->pdo, $this->b, 'Aviso do Beta', -60, 3600);
        $this->logarComoAdmin();
        $this->post("/admin/financeiro/{$this->a}/bloquear", ['motivo' => 'Atraso']);

        $estado = json_decode($this->get('/tv/BCDEFGH23456/state')->body, true);

        $this->assertFalse($estado['bloqueado']);
        $this->assertCount(1, $estado['avisos']);
    }

    public function testGerenteDeCondominioBloqueadoContinuaNoPainel(): void
    {
        $gerente = $this->criarUsuario('gerente@exemplo.com.br');
        $this->pdo->exec("INSERT INTO usuario_condominio (usuario_id, condominio_id) VALUES ({$gerente}, {$this->a})");
        $this->pdo->exec("UPDATE condominios SET bloqueado = true, bloqueio_motivo = 'Atraso' WHERE id = {$this->a}");
        $this->logar();

        $r = $this->get('/gerente');
        $this->assertSame(302, $r->status);
        $this->assertSame(200, $this->get($r->headers['Location'])->status, 'O painel do gerente segue funcionando.');
        $this->assertSame(200, $this->get('/gerente/dispositivos')->status);
    }

    public function testFinanceiroSomeJuntoComOCondominioExcluido(): void
    {
        $this->logarComoAdmin();
        $this->post("/admin/financeiro/{$this->a}", $this->dados());
        $this->pdo->exec("DELETE FROM condominios WHERE id = {$this->a}");

        $this->assertSame(1, (int) $this->pdo->query("SELECT count(*) FROM auditoria WHERE acao = 'financeiro.atualizado' AND condominio_id IS NULL AND condominio_nome = 'Residencial Alfa'")->fetchColumn(), 'A auditoria sobrevive, com o nome copiado.');
        $this->assertSame(0, $this->contar('condominio_financeiro'));
    }
}
