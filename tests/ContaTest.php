<?php

declare(strict_types=1);

namespace Tests;

use App\Repositories\VinculoRepository;

/** Configurações da conta: engrenagem, aba Segurança (troca de senha) e a aba Aparência dentro das Configurações. */
final class ContaTest extends AppHttpTestCase
{
    private const NOVA = 'outra-senha-bem-forte-456';

    private int $gerente;

    private function entrarComoGerente(): void
    {
        $this->gerente = $this->criarUsuario('gerente@exemplo.com.br');
        $condominio = $this->criarCondominio('Residencial Sol');
        (new VinculoRepository($this->pdo))->sincronizarDoUsuario($this->gerente, [$condominio]);
        $this->logar();
    }

    /** @return array<string, string> */
    private function troca(array $extra = []): array
    {
        return $extra + ['senha_atual' => self::SENHA, 'nova_senha' => self::NOVA, 'confirmacao' => self::NOVA];
    }

    private function hashDoBanco(string $email = 'gerente@exemplo.com.br'): string
    {
        return (string) $this->pdo->query("SELECT senha_hash FROM usuarios WHERE email = '{$email}'")->fetchColumn();
    }

    // ---------- Acesso ----------

    public function testVisitanteVaiParaOLoginNasDuasRotas(): void
    {
        $this->assertSame('/login', $this->get('/conta/seguranca')->headers['Location']);
        $this->assertSame(302, $this->post('/conta/seguranca', $this->troca())->status);
    }

    public function testGerenteEAdministradorAcessamAPaginaDeSeguranca(): void
    {
        $this->entrarComoGerente();
        $gerente = $this->get('/conta/seguranca');
        $_SESSION = [];
        $this->logarComoAdmin();
        $admin = $this->get('/conta/seguranca');

        $this->assertSame(200, $gerente->status);
        $this->assertSame(200, $admin->status);
        foreach ([$gerente, $admin] as $r) {
            $this->assertStringContainsString('Trocar a senha', $r->body);
            $this->assertStringContainsString('name="senha_atual"', $r->body);
            $this->assertStringNotContainsString(' style=', $r->body);
            $this->assertStringNotContainsString('<script', $r->body);
        }
    }

    // ---------- Engrenagem e abas ----------

    public function testEngrenagemSubstituiAInicialDoUsuarioEAparenciaSaiDoMenuPrincipal(): void
    {
        $this->entrarComoGerente();

        $corpo = $this->get('/gerente/tipos')->body;
        $cabecalho = substr($corpo, 0, (int) strpos($corpo, '</header>'));

        $this->assertStringContainsString('<details class="config">', $cabecalho);
        $this->assertStringContainsString('aria-label="Configurações"', $cabecalho);
        $this->assertStringNotContainsString('class="avatar', $cabecalho, 'Sem a inicial do usuário.');
        $suspenso = substr($cabecalho, (int) strpos($cabecalho, '<ul class="config-lista">'));
        $this->assertStringContainsString('href="/conta/seguranca"', $suspenso);
        $this->assertStringContainsString('href="/gerente/aparencia"', $suspenso);
        // O menu principal não tem mais Aparência (só aparece no suspenso e, no celular, como item "so-celular").
        $menu = substr($cabecalho, (int) strpos($cabecalho, '<nav class="menu"'), (int) strpos($cabecalho, '</nav>') - (int) strpos($cabecalho, '<nav class="menu"'));
        $semCelular = preg_replace('#<a class="so-celular[^>]*>.*?</a>#s', '', $menu);
        $this->assertStringNotContainsString('Aparência', (string) $semCelular);
        $this->assertStringContainsString('href="/gerente/dispositivos"', $menu, 'Dispositivos entra no menu principal do gerente.');
    }

    public function testAdministradorSoTemSegurancaNaEngrenagem(): void
    {
        $this->logarComoAdmin();

        $cabecalho = substr($this->get('/admin')->body, 0, (int) strpos($this->get('/admin')->body, '</header>'));

        $this->assertStringContainsString('<details class="config">', $cabecalho);
        $this->assertStringContainsString('href="/conta/seguranca"', $cabecalho);
        $this->assertStringNotContainsString('/gerente/aparencia', $cabecalho, 'Aparência é do gerente.');
        $this->assertStringNotContainsString('/gerente/dispositivos', $cabecalho);
    }

    public function testAbasDasConfiguracoesParaOGerenteEParaOAdmin(): void
    {
        $this->entrarComoGerente();
        $seguranca = $this->get('/conta/seguranca')->body;
        $aparencia = $this->get('/gerente/aparencia')->body;
        $_SESSION = [];
        $this->logarComoAdmin();
        $admin = $this->get('/conta/seguranca')->body;

        $this->assertStringContainsString('class="ativa" aria-current="page"><svg', $seguranca);
        $this->assertStringContainsString('href="/gerente/aparencia"', $seguranca, 'O gerente vê as duas abas.');
        $this->assertStringContainsString('href="/conta/seguranca"', $aparencia);
        $this->assertStringContainsString('<h1>Configurações</h1>', $aparencia);
        $this->assertStringNotContainsString('<nav class="abas" aria-label="Configurações"><a href="/conta/seguranca" class="ativa" aria-current="page"><svg', 'x');
        $abasAdmin = substr($admin, (int) strpos($admin, '<nav class="abas"'), 600);
        $this->assertStringNotContainsString('/gerente/aparencia', $abasAdmin, 'O admin só tem a aba Segurança.');
    }

    // ---------- Troca de senha ----------

    public function testTrocaDeSenhaFuncionaMantemASessaoEFazAAntigaDeixarDeEntrar(): void
    {
        $this->entrarComoGerente();
        $antes = $this->hashDoBanco();

        $r = $this->post('/conta/seguranca', $this->troca());

        $this->assertSame(303, $r->status);
        $this->assertNotSame($antes, $this->hashDoBanco());
        $this->assertStringNotContainsString(self::NOVA, $this->hashDoBanco(), 'Só o hash é guardado.');
        $pagina = $this->get('/conta/seguranca');
        $this->assertSame(200, $pagina->status, 'A sessão de quem trocou continua válida.');
        $this->assertStringContainsString('Senha alterada com sucesso.', $pagina->body);
        $this->assertSame(200, $this->get('/gerente/tipos')->status);

        $_SESSION = [];
        $this->assertSame(401, $this->logar('gerente@exemplo.com.br', self::SENHA)->status, 'A senha antiga não entra mais.');
        $_SESSION = [];
        $this->assertSame(303, $this->logar('gerente@exemplo.com.br', self::NOVA)->status);
    }

    public function testOutrosAparelhosSaoDesconectadosNaTroca(): void
    {
        $this->entrarComoGerente();
        $outroAparelho = $_SESSION;

        // Os logins são contados em segundos: a troca precisa acontecer depois do outro aparelho entrar.
        $this->pdo->exec("UPDATE usuarios SET senha_alterada_em = NULL WHERE email = 'gerente@exemplo.com.br'");
        sleep(1);
        $this->post('/conta/seguranca', $this->troca());
        $esteAparelho = $_SESSION;

        $_SESSION = $outroAparelho;
        $this->assertSame('/login', $this->get('/gerente')->headers['Location'] ?? '', 'O outro aparelho caiu.');
        $_SESSION = $esteAparelho;
        $this->assertSame(200, $this->get('/conta/seguranca')->status, 'Este continua conectado.');
    }

    public function testAdministradorTambemTrocaASenha(): void
    {
        $this->logarComoAdmin();

        $this->assertSame(303, $this->post('/conta/seguranca', $this->troca())->status);

        $_SESSION = [];
        $this->assertSame(303, $this->logar('admin@exemplo.com.br', self::NOVA)->status);
    }

    public function testSenhaAtualErradaNaoTrocaNada(): void
    {
        $this->entrarComoGerente();
        $antes = $this->hashDoBanco();

        $r = $this->post('/conta/seguranca', $this->troca(['senha_atual' => 'senha-errada-qualquer']));

        $this->assertSame(422, $r->status);
        $this->assertStringContainsString('A senha atual não confere.', $r->body);
        $this->assertSame($antes, $this->hashDoBanco());
        $this->assertStringNotContainsString('senha-errada-qualquer', $r->body, 'Senha nunca volta na tela.');
    }

    /** @return array<string, array{array<string, string>, string}> */
    public static function senhasNovasInvalidas(): array
    {
        return [
            'curta demais' => [['nova_senha' => 'curta', 'confirmacao' => 'curta'], 'no mínimo 8 caracteres'],
            'confirmação diferente' => [['confirmacao' => 'outra-coisa-bem-diferente'], 'A confirmação não coincide'],
            'igual à atual' => [['nova_senha' => self::SENHA, 'confirmacao' => self::SENHA], 'diferente da atual'],
            'vazia' => [['nova_senha' => '', 'confirmacao' => ''], 'Informe a senha'],
            'longa demais' => [['nova_senha' => str_repeat('a', 129), 'confirmacao' => str_repeat('a', 129)], 'no máximo 128'],
        ];
    }

    /** @param array<string, string> $extra */
    #[\PHPUnit\Framework\Attributes\DataProvider('senhasNovasInvalidas')]
    public function testSenhaNovaInvalidaMostraErroESemTrocar(array $extra, string $mensagem): void
    {
        $this->entrarComoGerente();
        $antes = $this->hashDoBanco();

        $r = $this->post('/conta/seguranca', $this->troca($extra));

        $this->assertSame(422, $r->status);
        $this->assertStringContainsString($mensagem, $r->body);
        $this->assertSame($antes, $this->hashDoBanco());
    }

    public function testNovaSenhaDeOitoCaracteresVale(): void
    {
        $this->entrarComoGerente();

        $this->assertSame(422, $this->post('/conta/seguranca', $this->troca(['nova_senha' => 'abcd123', 'confirmacao' => 'abcd123']))->status, '7 caracteres: recusada.');
        $this->assertSame(303, $this->post('/conta/seguranca', $this->troca(['nova_senha' => 'abcd1234', 'confirmacao' => 'abcd1234']))->status, '8 caracteres: aceita.');
        $this->assertStringContainsString('pelo menos 8 caracteres', $this->get('/conta/seguranca')->body);
        $this->assertStringNotContainsString('pelo menos 10', $this->get('/conta/seguranca')->body);
    }

    public function testErrarASenhaAtualVariasVezesBloqueiaAsTentativas(): void
    {
        $this->entrarComoGerente();

        for ($i = 0; $i < 6; $i++) {
            $this->post('/conta/seguranca', $this->troca(['senha_atual' => "errada-numero-{$i}-abc"]));
        }
        $bloqueada = $this->post('/conta/seguranca', $this->troca());

        $this->assertSame(429, $bloqueada->status);
        $this->assertArrayHasKey('Retry-After', $bloqueada->headers);
        $this->assertStringContainsString('Muitas tentativas', $bloqueada->body);
        $this->assertStringContainsString('$argon2id$', $this->hashDoBanco(), 'A senha continua a de antes.');
        $this->assertTrue(password_verify(self::SENHA, $this->hashDoBanco()));
    }

    public function testSenhaCertaLimpaOContadorDeErros(): void
    {
        $this->entrarComoGerente();
        for ($i = 0; $i < 3; $i++) {
            $this->post('/conta/seguranca', $this->troca(['senha_atual' => "errada-{$i}-xyz-abc"]));
        }

        $this->assertSame(303, $this->post('/conta/seguranca', $this->troca())->status);
        $this->assertSame(0, (int) $this->pdo->query("SELECT count(*) FROM rate_limits WHERE chave LIKE 'senha:usuario:%'")->fetchColumn());
    }

    public function testUmUsuarioNaoTrocaASenhaDeOutro(): void
    {
        $this->entrarComoGerente();
        $outro = $this->criarUsuario('outro@exemplo.com.br');
        $hashDoOutro = $this->hashDoBanco('outro@exemplo.com.br');

        // O formulário não aceita id de usuário: mesmo mandando um, só a senha de quem está logado muda.
        $this->post('/conta/seguranca', $this->troca(['usuario_id' => (string) $outro, 'id' => (string) $outro]));

        $this->assertSame($hashDoOutro, $this->hashDoBanco('outro@exemplo.com.br'));
        $this->assertTrue(password_verify(self::NOVA, $this->hashDoBanco()));
    }

    public function testTrocaDeSenhaSemCsrfEhBloqueada(): void
    {
        $this->entrarComoGerente();
        $antes = $this->hashDoBanco();

        $this->assertSame(403, $this->post('/conta/seguranca', $this->troca(), comCsrf: false)->status);
        $this->assertSame($antes, $this->hashDoBanco());
    }

    public function testRehashNaoTocaNaSessao(): void
    {
        $this->entrarComoGerente();

        $this->post('/conta/seguranca', $this->troca());
        $this->post('/conta/seguranca', ['senha_atual' => self::NOVA, 'nova_senha' => 'terceira-senha-forte-789', 'confirmacao' => 'terceira-senha-forte-789']);

        $this->assertTrue(password_verify('terceira-senha-forte-789', $this->hashDoBanco()), 'Dá para trocar mais de uma vez seguida na mesma sessão.');
    }
}
