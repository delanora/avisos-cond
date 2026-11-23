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
        $this->assertArrayNotHasKey('logo', $porToken['condominio']);
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

    // ---------- Detecção de quedas e retornos (Fase 2, 2.1) ----------

    private function pingHa(int $id, ?int $segundos): void
    {
        $this->pdo->exec($segundos === null
            ? "UPDATE dispositivos SET ultimo_ping = NULL WHERE id = {$id}"
            : "UPDATE dispositivos SET ultimo_ping = now() - interval '{$segundos} seconds' WHERE id = {$id}");
    }

    public function testQuedaGeraUmEventoOfflineEORepeticaoNaoDuplica(): void
    {
        $id = (int) $this->repo->criar($this->a, 'Elevador', 'ABCDEFGH2345');
        $this->pingHa($id, 600);

        $this->assertSame(['offline' => 1, 'online' => 0], $this->repo->registrarMudancasDeSituacao(180));
        $this->assertSame(['offline' => 0, 'online' => 0], $this->repo->registrarMudancasDeSituacao(180), 'Idempotente.');
        $this->assertSame(['offline' => 0, 'online' => 0], $this->repo->registrarMudancasDeSituacao(180));

        $eventos = $this->repo->eventos($id);
        $this->assertCount(1, $eventos);
        $this->assertSame('offline', $eventos[0]['tipo']);
        $this->assertNotNull($eventos[0]['ultimo_ping_epoch']);
    }

    public function testEventosPaginamEContam(): void
    {
        $id = (int) $this->repo->criar($this->a, 'Elevador', 'ABCDEFGH2345');
        $outro = (int) $this->repo->criar($this->a, 'Portaria', 'ZZZZ2222XXXX');
        for ($i = 1; $i <= 7; $i++) {
            $this->pdo->exec("INSERT INTO dispositivo_eventos (dispositivo_id, tipo, registrado_em) VALUES ({$id}, 'offline', now() - interval '{$i} hours')");
        }
        $this->pdo->exec("INSERT INTO dispositivo_eventos (dispositivo_id, tipo) VALUES ({$outro}, 'offline')");

        $this->assertSame(7, $this->repo->contarEventos($id));
        $this->assertSame(1, $this->repo->contarEventos($outro));
        $this->assertSame(0, $this->repo->contarEventos(999999));
        $primeira = $this->repo->eventos($id, 5, 0);
        $segunda = $this->repo->eventos($id, 5, 5);
        $this->assertCount(5, $primeira);
        $this->assertCount(2, $segunda);
        $this->assertSame([], $this->repo->eventos($id, 5, 10));
        $this->assertCount(7, array_unique(array_merge(array_column($primeira, 'registrado_epoch'), array_column($segunda, 'registrado_epoch'))));
    }

    public function testVoltarOnlineGeraEventoOnlineUmaSoVezECicloPodeRepetir(): void
    {
        $id = (int) $this->repo->criar($this->a, 'Elevador', 'ABCDEFGH2345');
        $this->pingHa($id, 600);
        $this->repo->registrarMudancasDeSituacao(180);

        $this->pingHa($id, 5);
        $this->assertSame(['offline' => 0, 'online' => 1], $this->repo->registrarMudancasDeSituacao(180));
        $this->assertSame(['offline' => 0, 'online' => 0], $this->repo->registrarMudancasDeSituacao(180));

        $this->pingHa($id, 900);
        $this->assertSame(['offline' => 1, 'online' => 0], $this->repo->registrarMudancasDeSituacao(180));

        $this->assertSame(['offline', 'online', 'offline'], array_reverse(array_column($this->repo->eventos($id), 'tipo')));
    }

    public function testQuemSempreEstevaOnlineNuncaConectouOuFoiRevogadoNaoGeraEvento(): void
    {
        $online = (int) $this->repo->criar($this->a, 'Online', 'ABCDEFGH2345');
        $nunca = (int) $this->repo->criar($this->a, 'Nunca', 'JKMNPQRS6789');
        $revogado = (int) $this->repo->criar($this->b, 'Revogado', 'TUVWXYZ23456');
        $this->pingHa($online, 10);
        $this->pingHa($nunca, null);
        $this->pingHa($revogado, 9000);
        $this->pdo->exec("UPDATE dispositivos SET ativo = false WHERE id = {$revogado}");

        $this->assertSame(['offline' => 0, 'online' => 0], $this->repo->registrarMudancasDeSituacao(180));
        $this->assertSame(0, $this->contar('dispositivo_eventos'));
    }

    public function testEventosSaoPorDispositivoDoMaisRecenteParaOMaisAntigoEUsamLimite(): void
    {
        $um = (int) $this->repo->criar($this->a, 'Um', 'ABCDEFGH2345');
        $dois = (int) $this->repo->criar($this->a, 'Dois', 'JKMNPQRS6789');
        $this->pingHa($um, 600);
        $this->pingHa($dois, 600);
        $this->repo->registrarMudancasDeSituacao(180);
        $this->pingHa($um, 1);
        $this->repo->registrarMudancasDeSituacao(180);

        $this->assertSame(['online', 'offline'], array_column($this->repo->eventos($um), 'tipo'));
        $this->assertSame(['offline'], array_column($this->repo->eventos($dois), 'tipo'));
        $this->assertCount(1, $this->repo->eventos($um, 1));
    }

    public function testEventosSaemJuntoComODispositivo(): void
    {
        $id = (int) $this->repo->criar($this->a, 'Elevador', 'ABCDEFGH2345');
        $this->pingHa($id, 600);
        $this->repo->registrarMudancasDeSituacao(180);

        $this->repo->excluir($id);

        $this->assertSame(0, $this->contar('dispositivo_eventos'));
    }

    public function testTipoInvalidoDeEventoEhRejeitadoPeloBanco(): void
    {
        $id = (int) $this->repo->criar($this->a, 'Elevador', 'ABCDEFGH2345');

        $this->expectException(\PDOException::class);
        $this->pdo->exec("INSERT INTO dispositivo_eventos (dispositivo_id, tipo) VALUES ({$id}, 'quebrado')");
    }
}
