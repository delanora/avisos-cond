<?php

declare(strict_types=1);

namespace Tests\Middleware;

use App\Middleware\EscopoGerenteMiddleware;
use App\Repositories\CondominioRepository;
use App\Repositories\VinculoRepository;
use App\Support\EscopoGerente;
use App\Support\NaoEncontradoException;
use App\Support\Request;
use App\Support\Response;
use Tests\BancoMigradoTestCase;

final class EscopoGerenteMiddlewareTest extends BancoMigradoTestCase
{
    private function executar(int $usuarioId): Request
    {
        $capturada = null;
        $mw = new EscopoGerenteMiddleware(new CondominioRepository($this->pdo));

        $mw->process(
            (new Request('GET', '/gerente'))->withAttribute('usuario', ['id' => $usuarioId]),
            static function (Request $req) use (&$capturada): Response {
                $capturada = $req;

                return Response::html('ok');
            }
        );

        return $capturada;
    }

    private function condominio(string $nome, bool $ativo = true): int
    {
        return (new CondominioRepository($this->pdo))->criar($nome, null, $ativo);
    }

    public function testCarregaSoOsCondominiosAtivosVinculadosAoGerente(): void
    {
        $g = $this->criarUsuario('g@exemplo.com.br');
        $outro = $this->criarUsuario('o@exemplo.com.br');
        [$a, $b, $inativo, $dele] = [$this->condominio('B Bravo'), $this->condominio('A Alfa'), $this->condominio('Inativo', false), $this->condominio('Do outro')];
        $vinculos = new VinculoRepository($this->pdo);
        $vinculos->sincronizarDoUsuario($g, [$a, $b, $inativo]);
        $vinculos->sincronizarDoUsuario($outro, [$dele]);

        $req = $this->executar($g);

        $this->assertSame(['A Alfa', 'B Bravo'], array_column(EscopoGerente::condominios($req), 'nome'));
        $this->assertSame([$b, $a], EscopoGerente::permitidos($req));
    }

    public function testListaEReLidaDoBancoACadaRequisicao(): void
    {
        $g = $this->criarUsuario('g@exemplo.com.br');
        $a = $this->condominio('A');
        $vinculos = new VinculoRepository($this->pdo);
        $vinculos->sincronizarDoUsuario($g, [$a]);
        $this->assertSame([$a], EscopoGerente::permitidos($this->executar($g)));

        // O admin remove o vínculo: a requisição seguinte já não enxerga o condomínio.
        $vinculos->sincronizarDoUsuario($g, []);

        $this->assertSame([], EscopoGerente::permitidos($this->executar($g)));
    }

    public function testDesativarOCondominioTiraOAcessoDoGerente(): void
    {
        $g = $this->criarUsuario('g@exemplo.com.br');
        $a = $this->condominio('A');
        (new VinculoRepository($this->pdo))->sincronizarDoUsuario($g, [$a]);

        $this->pdo->exec("UPDATE condominios SET ativo = false WHERE id = {$a}");

        $this->assertSame([], EscopoGerente::permitidos($this->executar($g)));
    }

    public function testExigirSoAceitaCondominioDaLista(): void
    {
        $g = $this->criarUsuario('g@exemplo.com.br');
        $a = $this->condominio('A');
        $b = $this->condominio('B');
        (new VinculoRepository($this->pdo))->sincronizarDoUsuario($g, [$a]);
        $req = $this->executar($g);

        $this->assertSame($a, EscopoGerente::exigir($req, $a)['id']);

        $this->expectException(NaoEncontradoException::class);
        EscopoGerente::exigir($req, $b);
    }

    public function testSemUsuarioNaRequisicaoNaoHaCondominios(): void
    {
        $capturada = null;
        $mw = new EscopoGerenteMiddleware(new CondominioRepository($this->pdo));

        $mw->process(new Request('GET', '/gerente'), static function (Request $req) use (&$capturada): Response {
            $capturada = $req;

            return Response::html('ok');
        });

        $this->assertSame([], EscopoGerente::permitidos($capturada));
    }

    public function testContextoReuneUsuarioCondominiosECondominioAtual(): void
    {
        $g = $this->criarUsuario('g@exemplo.com.br');
        $a = $this->condominio('A');
        (new VinculoRepository($this->pdo))->sincronizarDoUsuario($g, [$a]);
        $req = $this->executar($g);

        $ctx = EscopoGerente::contexto($req, EscopoGerente::exigir($req, $a));

        $this->assertSame($g, $ctx['usuario']['id']);
        $this->assertCount(1, $ctx['condominios']);
        $this->assertSame('A', $ctx['condominio']['nome']);
    }
}
