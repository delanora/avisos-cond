<?php

declare(strict_types=1);

namespace Tests\Services;

use App\Repositories\AvisoRepository;
use App\Repositories\DispositivoRepository;
use App\Services\TvService;
use App\Services\UploadService;
use App\Support\NaoEncontradoException;
use Tests\BancoMigradoTestCase;
use Tests\Support\AvisosDeTeste;

final class TvServiceTest extends BancoMigradoTestCase
{
    use AvisosDeTeste;

    private int $a;
    private int $b;
    private int $gerente;
    private string $uploads;

    protected function setUp(): void
    {
        parent::setUp();
        $this->uploads = sys_get_temp_dir() . '/tv_srv_' . bin2hex(random_bytes(5));
        $this->gerente = $this->criarUsuario('g@exemplo.com.br');
        $this->a = $this->condominio('Alfa');
        $this->b = $this->condominio('Beta');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->uploads . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->uploads);
    }

    private function condominio(string $nome): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO condominios (nome) VALUES (:n) RETURNING id');
        $stmt->execute(['n' => $nome]);

        return (int) $stmt->fetchColumn();
    }

    private function servico(int $intervalo = 45): TvService
    {
        return new TvService(
            new AvisoRepository($this->pdo), new DispositivoRepository($this->pdo),
            new UploadService($this->uploads, 1048576), $intervalo, 'v123'
        );
    }

    /** @return array{dispositivo: array<string, mixed>, condominio: array<string, mixed>} */
    private function acesso(int $condominioId, string $token = 'ABCDEFGH2345'): array
    {
        $this->dispositivoTv($this->pdo, $condominioId, $token);

        return (new DispositivoRepository($this->pdo))->porToken($token);
    }

    public function testSoAvisosAtivosEDentroDoPeriodoOuComecandoEm24Horas(): void
    {
        $this->avisoTv($this->pdo, $this->a, 'No ar', -3600, 3600);
        $this->avisoTv($this->pdo, $this->a, 'Começa em 2h', 7200, 10800);
        $this->avisoTv($this->pdo, $this->a, 'Começa em 23h50', 85800, 90000);
        $this->avisoTv($this->pdo, $this->a, 'Começa em 25h', 90000, 100000);
        $this->avisoTv($this->pdo, $this->a, 'Começa em 3 dias', 259200, 300000);
        $this->avisoTv($this->pdo, $this->a, 'Já terminou', -7200, -3600);
        $this->avisoTv($this->pdo, $this->a, 'Terminou agora', -7200, -1);
        $this->avisoTv($this->pdo, $this->a, 'Desativado', -3600, 3600, ['ativo' => false]);
        $this->avisoTv($this->pdo, $this->b, 'De outro condomínio', -3600, 3600);

        $titulos = array_column($this->servico()->estado($this->acesso($this->a), time())['avisos'], 'titulo');

        $this->assertSame(['No ar', 'Começa em 2h', 'Começa em 23h50'], $titulos, 'Ordenados pelo início.');
    }

    public function testEstruturaDoEstadoEFormatoDasDatas(): void
    {
        $agora = time();
        $this->avisoTv($this->pdo, $this->a, 'Aviso', -60, 3600, ['tipo' => 'urgente', 'corpo' => "Linha 1\nLinha 2"]);

        $estado = $this->servico(30)->estado($this->acesso($this->a), $agora);

        $this->assertSame(['bloqueado', 'avisos', 'version', 'server_time', 'server_epoch', 'intervalo', 'app'], array_keys($estado), 'Só o necessário para exibir.');
        $this->assertFalse($estado['bloqueado']);
        $this->assertSame($agora, $estado['server_epoch']);
        $this->assertSame(gmdate('Y-m-d\TH:i:s\Z', $agora), $estado['server_time']);
        $this->assertSame(30, $estado['intervalo']);
        $this->assertSame('v123', $estado['app']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{16}$/', $estado['version']);

        $aviso = $estado['avisos'][0];
        $this->assertSame(['id', 'titulo', 'corpo', 'tipo', 'tipo_nome', 'fixo', 'visual', 'inicio_em', 'fim_em', 'midia'], array_keys($aviso));
        $this->assertSame('urgente', $aviso['tipo']);
        $this->assertSame('Urgente', $aviso['tipo_nome']);
        $this->assertTrue($aviso['fixo'], 'Urgente é um tipo fixo.');
        $this->assertSame('#B42318', $aviso['visual']['principal']);
        $this->assertSame(['principal', 'texto_tag', 'fundo', 'borda', 'titulo'], array_keys($aviso['visual']));
        $this->assertSame("Linha 1\nLinha 2", $aviso['corpo']);
        $this->assertSame(gmdate('Y-m-d\TH:i:s\Z', $agora - 60), $aviso['inicio_em']);
        $this->assertSame(gmdate('Y-m-d\TH:i:s\Z', $agora + 3600), $aviso['fim_em']);
        $this->assertNull($aviso['midia']);
    }

    public function testMidiaVemComUrlDaTvESemExporONomeDeOutroCondominio(): void
    {
        $nome = str_repeat('a', 32) . '.png';
        $this->avisoTv($this->pdo, $this->a, 'Com foto', -60, 3600, ['midia' => $nome, 'midia_tipo' => 'imagem']);

        $aviso = $this->servico()->estado($this->acesso($this->a, 'ABCDEFGH2345'), time())['avisos'][0];

        $this->assertSame(['tipo' => 'imagem', 'url' => "/tv/ABCDEFGH2345/media/{$nome}"], $aviso['midia']);
    }

    public function testNaoVazaDadosDeGerentesNemDeOutrosCondominiosNemEndereco(): void
    {
        $this->pdo->exec("UPDATE condominios SET endereco = 'Rua Secreta, 99' WHERE id = {$this->a}");
        $this->avisoTv($this->pdo, $this->a, 'Meu aviso', -60, 3600);
        $this->avisoTv($this->pdo, $this->b, 'SEGREDO-DO-OUTRO', -60, 3600);

        $json = json_encode($this->servico()->estado($this->acesso($this->a), time()), JSON_THROW_ON_ERROR);

        foreach (['SEGREDO-DO-OUTRO', 'g@exemplo.com.br', 'Rua Secreta', 'criado_por', 'senha', 'Beta', 'condominio_id', 'endereco'] as $proibido) {
            $this->assertStringNotContainsString($proibido, $json, "Vazou: {$proibido}");
        }
    }

    public function testVersaoEstavelMudaSoQuandoAExibicaoMuda(): void
    {
        $id = $this->avisoTv($this->pdo, $this->a, 'Original', -60, 3600);
        $acesso = $this->acesso($this->a);
        $agora = time();

        $v1 = $this->servico()->estado($acesso, $agora)['version'];
        $this->assertSame($v1, $this->servico()->estado($acesso, $agora + 5)['version'], 'Passar do tempo não muda a versão.');
        $this->assertSame($v1, $this->servico(60)->estado($acesso, $agora)['version'], 'Intervalo de consulta não faz parte da exibição.');

        $this->pdo->exec("UPDATE avisos SET titulo = 'Editado' WHERE id = {$id}");
        $v2 = $this->servico()->estado($acesso, $agora)['version'];
        $this->assertNotSame($v1, $v2);

        $v3 = $v2;

        $this->avisoTv($this->pdo, $this->a, 'Novo', -60, 3600);
        $this->assertNotSame($v3, $this->servico()->estado((new DispositivoRepository($this->pdo))->porToken('ABCDEFGH2345'), $agora)['version']);
    }

    public function testEstadoNaoTemLogoNemCoresPersonalizadas(): void
    {
        $estado = $this->servico()->estado($this->acesso($this->a), time());

        $this->assertArrayNotHasKey('logo', $estado, 'A TV não é personalizável.');
        $this->assertArrayNotHasKey('tema', $estado);
    }

    public function testCondominioDesativadoOuBloqueadoMostraSoORelogio(): void
    {
        $this->avisoTv($this->pdo, $this->a, 'Não deve aparecer', -60, 3600);
        $this->dispositivoTv($this->pdo, $this->a);

        $this->pdo->exec("UPDATE condominios SET ativo = false WHERE id = {$this->a}");
        $desativado = $this->servico()->estado((new DispositivoRepository($this->pdo))->porToken($this->tokenDe($this->a)), time());
        $this->assertTrue($desativado['bloqueado']);
        $this->assertSame([], $desativado['avisos']);

        // Coluna "bloqueado" (Fase 3): o serviço já respeita quando ela existir.
        $this->pdo->exec("UPDATE condominios SET ativo = true WHERE id = {$this->a}");
        $acesso = (new DispositivoRepository($this->pdo))->porToken($this->tokenDe($this->a));
        $acesso['condominio']['bloqueado'] = true;
        $bloqueado = $this->servico()->estado($acesso, time());
        $this->assertTrue($bloqueado['bloqueado']);
        $this->assertSame([], $bloqueado['avisos']);
    }

    private function tokenDe(int $condominioId): string
    {
        return (string) $this->pdo->query("SELECT token FROM dispositivos WHERE condominio_id = {$condominioId} LIMIT 1")->fetchColumn();
    }

    public function testMidiaSoDeAvisoVisivelDoProprioCondominio(): void
    {
        $dir = $this->uploads;
        mkdir($dir, 0750, true);
        $visivel = bin2hex(random_bytes(16)) . '.png';
        $futuro = bin2hex(random_bytes(16)) . '.png';
        $longe = bin2hex(random_bytes(16)) . '.png';
        $vencido = bin2hex(random_bytes(16)) . '.png';
        $desativado = bin2hex(random_bytes(16)) . '.png';
        $doOutro = bin2hex(random_bytes(16)) . '.png';
        foreach ([$visivel, $futuro, $longe, $vencido, $desativado, $doOutro] as $arquivo) {
            file_put_contents("{$dir}/{$arquivo}", 'x');
        }
        $m = static fn (string $n): array => ['midia' => $n, 'midia_tipo' => 'imagem'];
        $this->avisoTv($this->pdo, $this->a, 'v', -60, 3600, $m($visivel));
        $this->avisoTv($this->pdo, $this->a, 'f', 7200, 9000, $m($futuro));
        $this->avisoTv($this->pdo, $this->a, 'l', 259200, 300000, $m($longe));
        $this->avisoTv($this->pdo, $this->a, 'x', -7200, -3600, $m($vencido));
        $this->avisoTv($this->pdo, $this->a, 'd', -60, 3600, $m($desativado) + ['ativo' => false]);
        $this->avisoTv($this->pdo, $this->b, 'o', -60, 3600, $m($doOutro));
        $acesso = $this->acesso($this->a);

        $this->assertSame("{$dir}/{$visivel}", $this->servico()->midia($acesso, $visivel, time())['caminho']);
        $this->assertSame("{$dir}/{$futuro}", $this->servico()->midia($acesso, $futuro, time())['caminho'], 'Começa em breve: já pode ser pré-carregada.');

        foreach ([$longe, $vencido, $desativado, $doOutro, str_repeat('0', 32) . '.png'] as $negado) {
            try {
                $this->servico()->midia($acesso, $negado, time());
                $this->fail("{$negado} não deveria ser entregue.");
            } catch (NaoEncontradoException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testTipoCriadoPeloGerenteVaiParaATvComNomeFixoECores(): void
    {
        $this->pdo->exec("INSERT INTO tipos_aviso (condominio_id, chave, nome, cor, fixo) VALUES ({$this->a}, 'zeladoria', 'Zeladoria', '#336699', true), ({$this->a}, 'dedetizacao', 'Dedetização', NULL, false)");
        $this->avisoTv($this->pdo, $this->a, 'Escada interditada', -60, 3600, ['tipo' => 'zeladoria']);
        $this->avisoTv($this->pdo, $this->a, 'Sem cor', -30, 3600, ['tipo' => 'dedetizacao']);

        [$fixo, $neutro] = $this->servico()->estado($this->acesso($this->a), time())['avisos'];

        $this->assertSame(['zeladoria', 'Zeladoria', true], [$fixo['tipo'], $fixo['tipo_nome'], $fixo['fixo']]);
        $this->assertSame('#336699', $fixo['visual']['principal']);
        $this->assertSame(['dedetizacao', 'Dedetização', false, null], [$neutro['tipo'], $neutro['tipo_nome'], $neutro['fixo'], $neutro['visual']]);
    }

    public function testMudarACorOuOFixoDoTipoMudaAVersaoParaATvAtualizar(): void
    {
        $this->pdo->exec("INSERT INTO tipos_aviso (condominio_id, chave, nome, cor, fixo) VALUES ({$this->a}, 'zeladoria', 'Zeladoria', '#336699', false)");
        $this->avisoTv($this->pdo, $this->a, 'Aviso', -60, 3600, ['tipo' => 'zeladoria']);
        $acesso = $this->acesso($this->a);
        $agora = time();
        $v1 = $this->servico()->estado($acesso, $agora)['version'];

        $this->pdo->exec("UPDATE tipos_aviso SET cor = '#AA0000' WHERE chave = 'zeladoria'");
        $v2 = $this->servico()->estado($acesso, $agora)['version'];
        $this->pdo->exec("UPDATE tipos_aviso SET fixo = true WHERE chave = 'zeladoria'");
        $v3 = $this->servico()->estado($acesso, $agora)['version'];

        $this->assertNotSame($v1, $v2);
        $this->assertNotSame($v2, $v3);
    }
}
