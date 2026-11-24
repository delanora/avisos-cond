<?php

declare(strict_types=1);

namespace Tests;

use App\Repositories\AuditoriaRepository;
use App\Services\AuditoriaService;
use App\Services\Tarefas\LimparAuditoria;
use App\Support\Logger;
use PDOException;

/** Auditoria: registro, filtros, imutabilidade, retenção, ganchos nas ações e acesso só do superadmin. */
final class AuditoriaTest extends AppHttpTestCase
{
    private function repo(): AuditoriaRepository
    {
        return new AuditoriaRepository($this->pdo);
    }

    private function servico(): AuditoriaService
    {
        return new AuditoriaService($this->repo(), new Logger(sys_get_temp_dir() . '/auditoria-teste-' . getmypid()));
    }

    /** @return list<array<string, mixed>> */
    private function registros(string $acao = ''): array
    {
        $filtros = $acao === '' ? [] : ['acao' => $acao];

        return $this->repo()->listar($filtros, 100, 0)['linhas'];
    }

    // ---------- Serviço e repositório ----------

    public function testRegistraComQuemQuandoECondominio(): void
    {
        $ana = $this->criarUsuario('ana@exemplo.com.br');
        $cond = $this->criarCondominio('Cond X');
        $this->servico()->registrar('aviso.criado', ['id' => $ana, 'nome' => 'Ana'], '203.0.113.5', 'aviso', 9, ['id' => $cond, 'nome' => 'Cond X'], ['titulo' => 'Água']);

        $r = $this->registros()[0];
        $this->assertSame('aviso.criado', $r['acao']);
        $this->assertSame('Ana', $r['usuario_nome']);
        $this->assertSame('Cond X', $r['condominio_nome']);
        $this->assertSame(['titulo' => 'Água'], $r['detalhes']);
        $this->assertSame('203.0.113.5', $r['ip']);
        $this->assertNull($r['como_usuario_id']);
    }

    public function testEmModoSuporteGuardaOAdminEOGerente(): void
    {
        $admin = $this->criarUsuario('adm@exemplo.com.br', 'superadmin');
        $gerente = $this->criarUsuario('ger@exemplo.com.br');
        $this->servico()->registrar('aviso.criado', ['id' => $gerente, 'nome' => 'Gerente', 'suporte_admin' => ['id' => $admin, 'nome' => 'Admin']], null);

        $r = $this->registros()[0];
        $this->assertSame($admin, $r['usuario_id']);
        $this->assertSame('Admin', $r['usuario_nome']);
        $this->assertSame($gerente, $r['como_usuario_id']);
        $this->assertSame('Gerente', $r['como_usuario_nome']);
    }

    public function testLimparTiraSegredosEValoresComplexos(): void
    {
        $limpo = AuditoriaService::limpar([
            'nome' => 'x', 'senha' => 'segredo', 'senha_nova' => 'y', 'token' => 't', 'api_chave' => 'k', 'hash' => 'h',
            'ativo' => true, 'qtd' => 3, 'lista' => [1, 2], 'longo' => str_repeat('a', 1000), 'objeto' => new \stdClass(),
        ]);

        $this->assertSame(['nome', 'ativo', 'qtd', 'lista', 'longo'], array_keys($limpo));
        $this->assertSame('1, 2', $limpo['lista']);
        $this->assertLessThan(1000, mb_strlen((string) $limpo['longo']));
    }

    public function testFalhaNoBancoNaoDerrubaAAcao(): void
    {
        $this->pdo->exec('DROP TABLE auditoria');

        $this->servico()->registrar('login.entrou', ['id' => 1, 'nome' => 'A'], null);

        $this->addToAssertionCount(1); // chegou aqui sem exceção
    }

    public function testRegistroNaoPodeSerAlterado(): void
    {
        $this->servico()->registrar('login.entrou', ['id' => null, 'nome' => 'A'], null);

        $this->expectException(PDOException::class);
        $this->pdo->exec("UPDATE auditoria SET acao = 'outra'");
    }

    public function testExcluirCondominioEUsuarioComAuditoriaMantemORegistroSemOVinculo(): void
    {
        $ana = $this->criarUsuario('ana@exemplo.com.br');
        $cond = $this->criarCondominio('Cond X');
        $this->servico()->registrar('aviso.criado', ['id' => $ana, 'nome' => 'Ana'], null, 'aviso', 9, ['id' => $cond, 'nome' => 'Cond X'], ['titulo' => 'Água']);

        $this->pdo->exec("DELETE FROM condominios WHERE id = {$cond}");
        $this->pdo->exec("DELETE FROM usuarios WHERE id = {$ana}");

        $l = $this->pdo->query('SELECT usuario_id, usuario_nome, condominio_id, condominio_nome, acao, detalhes::text AS d FROM auditoria')->fetch();
        $this->assertNull($l['usuario_id']);
        $this->assertNull($l['condominio_id']);
        $this->assertSame('Ana', $l['usuario_nome']);
        $this->assertSame('Cond X', $l['condominio_nome']);
        $this->assertStringContainsString('Água', $l['d']);
    }

    public function testSoOsVinculosPodemVirarNuloNaImutabilidade(): void
    {
        $ana = $this->criarUsuario('ana@exemplo.com.br');
        $this->servico()->registrar('login.entrou', ['id' => $ana, 'nome' => 'Ana'], null);

        foreach (["usuario_nome = 'Outra'", "acao = 'outra'", "usuario_id = {$ana} + 1", "detalhes = '{}'::jsonb || '{\"x\":1}'::jsonb"] as $set) {
            try {
                $this->pdo->exec("UPDATE auditoria SET {$set}");
                $this->fail("UPDATE {$set} deveria ser barrado");
            } catch (PDOException $e) {
                $this->assertStringContainsString('não podem ser alterados', $e->getMessage());
            }
        }
    }

    public function testFiltros(): void
    {
        $s = $this->servico();
        $s->registrar('aviso.criado', ['id' => null, 'nome' => 'Ana'], null, null, null, ['id' => null, 'nome' => 'C1']);
        $s->registrar('login.entrou', ['id' => null, 'nome' => 'Bia'], null);
        $s->registrar('senha.alterada', ['id' => null, 'nome' => 'Bia'], null);

        $this->assertSame(1, $this->repo()->listar(['acao' => 'login.entrou'], 10, 0)['total']);
        $this->assertSame(2, $this->repo()->listar(['grupo' => 'senha'], 10, 0)['total'] + $this->repo()->listar(['grupo' => 'login'], 10, 0)['total']);
        $this->assertSame(3, $this->repo()->listar([], 10, 0)['total']);
        $this->assertCount(2, $this->repo()->listar([], 2, 0)['linhas']);
        $this->assertCount(1, $this->repo()->listar([], 2, 2)['linhas']);
        $this->assertSame(0, $this->repo()->listar(['de_epoch' => time() + 3600], 10, 0)['total']);
        $this->assertSame(3, $this->repo()->listar(['ate_epoch' => time() + 3600], 10, 0)['total']);
    }

    public function testRetencaoApagaSoOsAntigos(): void
    {
        $s = $this->servico();
        $s->registrar('login.entrou', ['id' => null, 'nome' => 'Velho'], null);
        $s->registrar('login.entrou', ['id' => null, 'nome' => 'Novo'], null);
        $this->pdo->exec("ALTER TABLE auditoria DISABLE TRIGGER USER");
        $this->pdo->exec("UPDATE auditoria SET criado_em = now() - interval '400 days' WHERE usuario_nome = 'Velho'");
        $this->pdo->exec("ALTER TABLE auditoria ENABLE TRIGGER USER");

        $msg = (new LimparAuditoria($this->repo(), 365))->executar();

        $this->assertStringContainsString('1 registro', $msg);
        $this->assertSame(['Novo'], array_column($this->registros(), 'usuario_nome'));
    }

    public function testRetencaoTemMinimoDe30Dias(): void
    {
        $this->servico()->registrar('login.entrou', ['id' => null, 'nome' => 'A'], null);
        $this->pdo->exec("ALTER TABLE auditoria DISABLE TRIGGER USER");
        $this->pdo->exec("UPDATE auditoria SET criado_em = now() - interval '10 days'");
        $this->pdo->exec("ALTER TABLE auditoria ENABLE TRIGGER USER");

        (new LimparAuditoria($this->repo(), 1))->executar();

        $this->assertCount(1, $this->registros());
    }

    // ---------- Ganchos nas ações ----------

    public function testLoginERecusaNaoRegistramSenha(): void
    {
        $this->criarUsuario('g@exemplo.com.br');
        $this->logar('g@exemplo.com.br');

        $r = $this->registros('login.entrou');
        $this->assertCount(1, $r);
        $this->assertSame('g@exemplo.com.br', $this->pdo->query('SELECT email FROM usuarios')->fetchColumn());
        $this->assertStringNotContainsString(self::SENHA, json_encode($this->registros(), JSON_THROW_ON_ERROR));
    }

    public function testLoginComSenhaErradaNaoRegistra(): void
    {
        $this->criarUsuario('g@exemplo.com.br');
        $this->logar('g@exemplo.com.br', 'errada-errada');

        $this->assertSame([], $this->registros('login.entrou'));
    }

    public function testCrudDeUsuarioCondominioEDispositivoEGravadoSemSegredos(): void
    {
        $admin = $this->logarComoAdmin();
        $this->post('/admin/condominios', ['nome' => 'Cond Teste', 'endereco' => 'Rua 1', 'ativo' => '1']);
        $cid = (int) $this->pdo->query("SELECT id FROM condominios WHERE nome = 'Cond Teste'")->fetchColumn();
        $this->post('/admin/usuarios', ['nome' => 'Novo Gerente', 'email' => 'novo@exemplo.com.br', 'papel' => 'gerente', 'senha' => 'senha-bem-longa-1', 'confirmacao' => 'senha-bem-longa-1', 'ativo' => '1', 'condominios' => [(string) $cid]]);
        $uid = (int) $this->pdo->query("SELECT id FROM usuarios WHERE email = 'novo@exemplo.com.br'")->fetchColumn();
        $this->post("/admin/usuarios/{$uid}/excluir");
        $this->post("/admin/condominios/{$cid}", ['nome' => 'Cond Teste 2', 'endereco' => 'Rua 1', 'ativo' => '1']);

        $acoes = array_column($this->registros(), 'acao');
        foreach (['condominio.criado', 'usuario.criado', 'usuario.excluido', 'condominio.atualizado'] as $esperada) {
            $this->assertContains($esperada, $acoes);
        }
        $criado = $this->registros('usuario.criado')[0];
        $this->assertSame($admin, $criado['usuario_id']);
        $this->assertSame($uid, $criado['entidade_id']);
        $this->assertStringNotContainsString('senha-bem-longa-1', json_encode($this->registros(), JSON_THROW_ON_ERROR));
    }

    public function testTrocaDeSenhaERegistradaSemASenha(): void
    {
        $this->criarUsuario('g@exemplo.com.br');
        $this->logar('g@exemplo.com.br');
        $this->post('/conta/seguranca', ['senha_atual' => self::SENHA, 'nova_senha' => 'nova-senha-longa-9', 'confirmacao' => 'nova-senha-longa-9']);

        $this->assertCount(1, $this->registros('senha.alterada'));
        $json = json_encode($this->registros(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('nova-senha-longa-9', $json);
        $this->assertStringNotContainsString(self::SENHA, $json);
    }

    // ---------- Tela ----------

    public function testTelaDoAdminListaFiltraEPagina(): void
    {
        $this->logarComoAdmin();
        $s = $this->servico();
        for ($i = 1; $i <= 55; $i++) {
            $s->registrar('aviso.criado', ['id' => null, 'nome' => 'Pessoa ' . $i], null, null, null, null, ['titulo' => "Aviso {$i}"]);
        }

        $pagina1 = $this->get('/admin/auditoria');
        $this->assertSame(200, $pagina1->status);
        $this->assertStringContainsString('Criou um aviso', $pagina1->body);
        $this->assertStringContainsString('página 1 de 2', $pagina1->body);
        $this->assertStringContainsString('Mais antigos', $pagina1->body);

        $pagina2 = $this->get('/admin/auditoria?pagina=2');
        $this->assertStringContainsString('página 2 de 2', $pagina2->body);

        $vazio = $this->get('/admin/auditoria?grupo=senha');
        $this->assertStringContainsString('Nenhum registro encontrado', $vazio->body);
    }

    public function testTelaIgnoraFiltrosInvalidosSemErro(): void
    {
        $this->logarComoAdmin();

        $r = $this->get('/admin/auditoria?grupo=%27%3B--&pessoa=abc&condominio=-1&de=ontem&ate=2026-13-99&pagina=0');

        $this->assertSame(200, $r->status);
    }

    public function testTelaEscapaTextosDeUsuario(): void
    {
        $this->logarComoAdmin();
        $this->servico()->registrar('aviso.criado', ['id' => null, 'nome' => '<script>alert(1)</script>'], null);

        $r = $this->get('/admin/auditoria');

        $this->assertStringNotContainsString('<script>alert(1)', $r->body);
        $this->assertStringContainsString('&lt;script&gt;', $r->body);
    }

    public function testGerenteNaoVeAAuditoria(): void
    {
        $this->criarUsuario('g@exemplo.com.br');
        $this->servico()->registrar('login.entrou', ['id' => null, 'nome' => 'Segredo Admin'], null);
        $this->logar('g@exemplo.com.br');

        $r = $this->get('/admin/auditoria');

        $this->assertSame(403, $r->status);
        $this->assertStringNotContainsString('Segredo Admin', $r->body);
    }
}
