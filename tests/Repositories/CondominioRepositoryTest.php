<?php

declare(strict_types=1);

namespace Tests\Repositories;

use App\Repositories\CondominioRepository;
use App\Repositories\VinculoRepository;
use Tests\AppHttpTestCase;

final class CondominioRepositoryTest extends AppHttpTestCase
{
    private CondominioRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new CondominioRepository($this->pdo);
    }

    public function testCriarEBuscar(): void
    {
        $id = $this->repo->criar('Residencial Sol', 'Rua A, 10', true);

        $c = $this->repo->porId($id);

        $this->assertSame('Residencial Sol', $c['nome']);
        $this->assertSame('Rua A, 10', $c['endereco']);
        $this->assertTrue($c['ativo']);
        $this->assertNull($c['logo']);
        $this->assertNull($c['cor_primaria']);
    }

    public function testEnderecoOpcionalFicaNulo(): void
    {
        $id = $this->repo->criar('Sem Endereço', null, false);

        $c = $this->repo->porId($id);

        $this->assertNull($c['endereco']);
        $this->assertFalse($c['ativo']);
    }

    public function testBuscarInexistenteRetornaNull(): void
    {
        $this->assertNull($this->repo->porId(987654));
    }

    public function testAtualizar(): void
    {
        $id = $this->repo->criar('Antigo', null, true);

        $this->assertTrue($this->repo->atualizar($id, 'Novo', 'Av. B', false));
        $this->assertFalse($this->repo->atualizar(987654, 'X', null, true));

        $c = $this->repo->porId($id);
        $this->assertSame('Novo', $c['nome']);
        $this->assertSame('Av. B', $c['endereco']);
        $this->assertFalse($c['ativo']);
    }

    public function testListarOrdenadoPorNomeComTotalDeGerentes(): void
    {
        $b = $this->repo->criar('bravo', null, true);
        $a = $this->repo->criar('Alfa', null, true);
        $g = $this->criarUsuario('g@exemplo.com.br');
        (new VinculoRepository($this->pdo))->sincronizarDoUsuario($g, [$a]);

        $lista = $this->repo->listar();

        $this->assertSame(['Alfa', 'bravo'], array_column($lista, 'nome'));
        $this->assertSame(1, $lista[0]['total_gerentes']);
        $this->assertSame(0, $lista[1]['total_gerentes']);
        $this->assertSame($b, $lista[1]['id']);
    }

    public function testDependenciasContamAvisosDispositivosEGerentes(): void
    {
        $id = $this->repo->criar('A', null, true);
        $outro = $this->repo->criar('B', null, true);
        $g = $this->criarUsuario('g@exemplo.com.br');
        (new VinculoRepository($this->pdo))->sincronizarDoUsuario($g, [$id]);
        $this->pdo->exec("INSERT INTO avisos (condominio_id, titulo, inicio_em, fim_em) VALUES ({$id}, 'x', now(), now() + interval '1 day'), ({$id}, 'y', now(), now() + interval '1 day'), ({$outro}, 'z', now(), now() + interval '1 day')");
        $this->pdo->exec("INSERT INTO dispositivos (condominio_id, nome, token) VALUES ({$id}, 'TV', 'AAAAAAAAAAAA')");

        $this->assertSame(['avisos' => 2, 'dispositivos' => 1, 'gerentes' => 1], $this->repo->dependencias($id));
        $this->assertSame(['avisos' => 1, 'dispositivos' => 0, 'gerentes' => 0], $this->repo->dependencias($outro));
    }

    public function testExcluirApagaEmCascataSoOsDadosDoCondominio(): void
    {
        $id = $this->repo->criar('A', null, true);
        $outro = $this->repo->criar('B', null, true);
        $this->pdo->exec("INSERT INTO avisos (condominio_id, titulo, inicio_em, fim_em) VALUES ({$id}, 'x', now(), now() + interval '1 day'), ({$outro}, 'z', now(), now() + interval '1 day')");

        $this->assertTrue($this->repo->excluir($id));
        $this->assertFalse($this->repo->excluir($id));

        $this->assertNull($this->repo->porId($id));
        $this->assertSame(1, $this->contar('avisos'));
        $this->assertNotNull($this->repo->porId($outro));
    }

    public function testIdsExistentes(): void
    {
        $a = $this->repo->criar('A', null, true);
        $b = $this->repo->criar('B', null, true);

        $this->assertSame([$a, $b], $this->repo->idsExistentes([$b, $a, 987654]));
        $this->assertSame([], $this->repo->idsExistentes([]));
        $this->assertSame([], $this->repo->idsExistentes([987654]));
    }
}
