<?php

declare(strict_types=1);

namespace Tests\Repositories;

use App\Repositories\VinculoRepository;
use Tests\AppHttpTestCase;

final class VinculoRepositoryTest extends AppHttpTestCase
{
    private VinculoRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new VinculoRepository($this->pdo);
    }

    public function testSincronizarDoUsuarioAdicionaERemove(): void
    {
        $u = $this->criarUsuario('g@exemplo.com.br');
        [$a, $b, $c] = [$this->criarCondominio('A'), $this->criarCondominio('B'), $this->criarCondominio('C')];

        $this->repo->sincronizarDoUsuario($u, [$a, $b]);
        $this->assertSame([$a, $b], $this->repo->condominiosDoUsuario($u));

        $this->repo->sincronizarDoUsuario($u, [$b, $c]);
        $this->assertSame([$b, $c], $this->repo->condominiosDoUsuario($u));
    }

    public function testSincronizarComListaVaziaRemoveTodos(): void
    {
        $u = $this->criarUsuario('g@exemplo.com.br');
        $a = $this->criarCondominio();
        $this->repo->sincronizarDoUsuario($u, [$a]);

        $this->repo->sincronizarDoUsuario($u, []);

        $this->assertSame([], $this->repo->condominiosDoUsuario($u));
    }

    public function testSincronizarEIdempotente(): void
    {
        $u = $this->criarUsuario('g@exemplo.com.br');
        $a = $this->criarCondominio();

        $this->repo->sincronizarDoUsuario($u, [$a]);
        $this->repo->sincronizarDoUsuario($u, [$a]);

        $this->assertSame(1, $this->contar('usuario_condominio'));
    }

    public function testSincronizarUmUsuarioNaoMexeNosVinculosDeOutro(): void
    {
        $u1 = $this->criarUsuario('g1@exemplo.com.br');
        $u2 = $this->criarUsuario('g2@exemplo.com.br');
        $a = $this->criarCondominio('A');
        $b = $this->criarCondominio('B');
        $this->repo->sincronizarDoUsuario($u1, [$a]);
        $this->repo->sincronizarDoUsuario($u2, [$a, $b]);

        $this->repo->sincronizarDoUsuario($u1, []);

        $this->assertSame([], $this->repo->condominiosDoUsuario($u1));
        $this->assertSame([$a, $b], $this->repo->condominiosDoUsuario($u2));
    }

    public function testSincronizarDoCondominioAdicionaERemoveSemAfetarOutrosCondominios(): void
    {
        [$g1, $g2, $g3] = [
            $this->criarUsuario('g1@exemplo.com.br'),
            $this->criarUsuario('g2@exemplo.com.br'),
            $this->criarUsuario('g3@exemplo.com.br'),
        ];
        $a = $this->criarCondominio('A');
        $b = $this->criarCondominio('B');
        $this->repo->sincronizarDoCondominio($b, [$g1]);

        $this->repo->sincronizarDoCondominio($a, [$g1, $g2]);
        $this->assertSame([$g1, $g2], $this->repo->usuariosDoCondominio($a));

        $this->repo->sincronizarDoCondominio($a, [$g2, $g3]);
        $this->assertSame([$g2, $g3], $this->repo->usuariosDoCondominio($a));
        // O vínculo do g1 com o condomínio B continua.
        $this->assertSame([$g1], $this->repo->usuariosDoCondominio($b));
    }

    public function testIdInexistenteNaoCriaVinculoEFalhaPelaChaveEstrangeira(): void
    {
        $u = $this->criarUsuario('g@exemplo.com.br');

        $this->expectException(\PDOException::class);
        $this->repo->sincronizarDoUsuario($u, [987654]);
    }
}
