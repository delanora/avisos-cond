<?php

declare(strict_types=1);

namespace Tests\Repositories;

use App\Repositories\DispositivoRepository;
use Tests\AppHttpTestCase;

final class DispositivoRepositoryTest extends AppHttpTestCase
{
    private DispositivoRepository $repo;
    private int $a;
    private int $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new DispositivoRepository($this->pdo);
        $this->a = $this->criarCondominio('Alfa');
        $this->b = $this->criarCondominio('Beta');
    }

    public function testCriarEBuscarPorIdETokenComODadosDoCondominio(): void
    {
        $id = $this->repo->criar($this->a, 'Elevador', 'ABCDEFGH2345');

        $porId = $this->repo->porId((int) $id);
        $this->assertSame('Elevador', $porId['nome']);
        $this->assertSame('Alfa', $porId['condominio_nome']);
        $this->assertTrue($porId['ativo']);
        $this->assertNull($porId['ultimo_ping_epoch']);

        $porToken = $this->repo->porToken('ABCDEFGH2345');
        $this->assertSame($id, $porToken['dispositivo']['id']);
        $this->assertSame($this->a, $porToken['condominio']['id']);
        $this->assertTrue($porToken['condominio']['ativo']);
        $this->assertNull($porToken['condominio']['logo']);
    }

    public function testTokenDuplicadoNaoCriaERetornaNull(): void
    {
        $this->repo->criar($this->a, 'Um', 'ABCDEFGH2345');

        $this->assertNull($this->repo->criar($this->b, 'Dois', 'ABCDEFGH2345'));
        $this->assertSame(1, $this->contar('dispositivos'));
    }

    public function testTokenInexistenteRetornaNull(): void
    {
        $this->assertNull($this->repo->porToken('ZZZZZZZZZZZZ'));
        $this->assertNull($this->repo->porId(987654));
    }

    public function testAtualizarNomeCondominioEAtivo(): void
    {
        $id = (int) $this->repo->criar($this->a, 'Antigo', 'ABCDEFGH2345');

        $this->assertTrue($this->repo->atualizar($id, $this->b, 'Novo', false));
        $this->assertFalse($this->repo->atualizar(987654, $this->b, 'x', true));

        $d = $this->repo->porId($id);
        $this->assertSame('Novo', $d['nome']);
        $this->assertSame($this->b, $d['condominio_id']);
        $this->assertFalse($d['ativo']);
    }

    public function testTrocarTokenInvalidaOAntigoELimpaOUltimoContato(): void
    {
        $id = (int) $this->repo->criar($this->a, 'TV', 'ABCDEFGH2345');
        $this->repo->registrarPing($id);
        $this->assertNotNull($this->repo->porId($id)['ultimo_ping_epoch']);

        $this->assertTrue($this->repo->trocarToken($id, 'JKMNPQRS6789'));

        $this->assertNull($this->repo->porToken('ABCDEFGH2345'));
        $this->assertSame($id, $this->repo->porToken('JKMNPQRS6789')['dispositivo']['id']);
        $this->assertNull($this->repo->porId($id)['ultimo_ping_epoch']);
    }

    public function testTrocarParaTokenJaEmUsoNaoFazNada(): void
    {
        $um = (int) $this->repo->criar($this->a, 'Um', 'ABCDEFGH2345');
        $this->repo->criar($this->a, 'Dois', 'JKMNPQRS6789');

        $this->assertFalse($this->repo->trocarToken($um, 'JKMNPQRS6789'));
        $this->assertSame('ABCDEFGH2345', $this->repo->porId($um)['token']);
    }

    public function testHeartbeatRegistraOUltimoContato(): void
    {
        $id = (int) $this->repo->criar($this->a, 'TV', 'ABCDEFGH2345');

        $this->repo->registrarPing($id);

        $this->assertEqualsWithDelta(time(), $this->repo->porId($id)['ultimo_ping_epoch'], 3);
    }

    public function testListarOrdenaPorCondominioENome(): void
    {
        $this->repo->criar($this->b, 'Zeta', 'ABCDEFGH2345');
        $this->repo->criar($this->a, 'Portaria', 'JKMNPQRS6789');
        $this->repo->criar($this->a, 'Elevador', 'BCDEFGHJ2345');

        $this->assertSame(['Elevador', 'Portaria', 'Zeta'], array_column($this->repo->listar(), 'nome'));
    }

    public function testExcluirERemoverComOCondominio(): void
    {
        $id = (int) $this->repo->criar($this->a, 'TV', 'ABCDEFGH2345');
        $this->repo->criar($this->b, 'Outra', 'JKMNPQRS6789');

        $this->assertTrue($this->repo->excluir($id));
        $this->assertFalse($this->repo->excluir($id));
        $this->assertSame(1, $this->contar('dispositivos'));

        $this->pdo->exec("DELETE FROM condominios WHERE id = {$this->b}");
        $this->assertSame(0, $this->contar('dispositivos'), 'Dispositivos saem junto com o condomínio (ON DELETE CASCADE).');
    }
}
