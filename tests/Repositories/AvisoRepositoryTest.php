<?php

declare(strict_types=1);

namespace Tests\Repositories;

use App\Repositories\AvisoRepository;
use Tests\AppHttpTestCase;

/**
 * ISOLAMENTO DE TENANT no nível do repositório: com a lista de condomínios permitidos do gerente A,
 * nenhuma operação enxerga ou altera avisos do condomínio B, mesmo conhecendo o ID.
 */
final class AvisoRepositoryTest extends AppHttpTestCase
{
    private AvisoRepository $repo;
    private int $a;
    private int $b;
    private int $avisoA;
    private int $avisoB;
    private int $usuario;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new AvisoRepository($this->pdo);
        $this->a = $this->criarCondominio('Condomínio A');
        $this->b = $this->criarCondominio('Condomínio B');
        $this->usuario = $this->criarUsuario('g@exemplo.com.br');
        $this->avisoA = (int) $this->repo->criar([$this->a], $this->a, $this->dados('Aviso do A'), $this->usuario);
        $this->avisoB = (int) $this->repo->criar([$this->b], $this->b, $this->dados('Aviso do B'), $this->usuario);
    }

    /** @return array<string, mixed> */
    private function dados(string $titulo): array
    {
        return [
            'titulo' => $titulo, 'corpo' => 'Texto', 'tipo' => 'comum', 'midia' => null, 'midia_tipo' => null,
            'inicio_epoch' => 1_800_000_000, 'fim_epoch' => 1_800_086_400, 'ativo' => true,
        ];
    }

    private function tituloNoBanco(int $id): ?string
    {
        $v = $this->pdo->query("SELECT titulo FROM avisos WHERE id = {$id}")->fetchColumn();

        return $v === false ? null : (string) $v;
    }

    public function testListaSoOsAvisosDoCondominioPermitido(): void
    {
        $this->assertSame(['Aviso do A'], array_column($this->repo->listar([$this->a], $this->a), 'titulo'));
    }

    public function testListarOutroCondominioRetornaVazio(): void
    {
        $this->assertSame([], $this->repo->listar([$this->a], $this->b));
    }

    public function testListarSemPermissaoNenhumaRetornaVazio(): void
    {
        $this->assertSame([], $this->repo->listar([], $this->a));
    }

    public function testPermissaoParaOsDoisMostraSoOCondominioPedido(): void
    {
        $this->assertSame(['Aviso do B'], array_column($this->repo->listar([$this->a, $this->b], $this->b), 'titulo'));
    }

    public function testBuscarPorIdSoDentroDoEscopo(): void
    {
        $this->assertSame('Aviso do A', $this->repo->porId([$this->a], $this->a, $this->avisoA)['titulo']);
        $this->assertNull($this->repo->porId([$this->a], $this->b, $this->avisoB), 'Condomínio B não permitido.');
        $this->assertNull($this->repo->porId([$this->a], $this->a, $this->avisoB), 'Aviso do B pedido como se fosse do A.');
        $this->assertNull($this->repo->porId([$this->a], $this->b, $this->avisoA), 'Aviso do A pedido como se fosse do B.');
        $this->assertNull($this->repo->porId([], $this->a, $this->avisoA));
    }

    public function testAtualizarNaoAlteraAvisoDeOutroCondominio(): void
    {
        $dados = $this->dados('INVADIDO');

        $this->assertFalse($this->repo->atualizar([$this->a], $this->b, $this->avisoB, $dados));
        $this->assertFalse($this->repo->atualizar([$this->a], $this->a, $this->avisoB, $dados));
        $this->assertSame('Aviso do B', $this->tituloNoBanco($this->avisoB));

        $this->assertTrue($this->repo->atualizar([$this->a], $this->a, $this->avisoA, $dados));
        $this->assertSame('INVADIDO', $this->tituloNoBanco($this->avisoA));
    }

    public function testAtualizarNaoMoveOAvisoParaOutroCondominio(): void
    {
        $this->repo->atualizar([$this->a], $this->a, $this->avisoA, $this->dados('Mesmo aviso') + ['condominio_id' => $this->b]);

        $dono = (int) $this->pdo->query("SELECT condominio_id FROM avisos WHERE id = {$this->avisoA}")->fetchColumn();
        $this->assertSame($this->a, $dono);
    }

    public function testExcluirNaoApagaAvisoDeOutroCondominio(): void
    {
        $this->assertNull($this->repo->excluir([$this->a], $this->b, $this->avisoB));
        $this->assertNull($this->repo->excluir([$this->a], $this->a, $this->avisoB));
        $this->assertSame('Aviso do B', $this->tituloNoBanco($this->avisoB));

        $this->assertNotNull($this->repo->excluir([$this->a], $this->a, $this->avisoA));
        $this->assertNull($this->tituloNoBanco($this->avisoA));
    }

    public function testExcluirDevolveOArquivoDeMidiaParaSerApagado(): void
    {
        $id = (int) $this->repo->criar([$this->a], $this->a, ['midia' => 'abc.png', 'midia_tipo' => 'imagem'] + $this->dados('Com mídia'), $this->usuario);

        $this->assertSame(['midia' => 'abc.png'], $this->repo->excluir([$this->a], $this->a, $id));
    }

    public function testCriarEmCondominioNaoPermitidoNaoGravaNada(): void
    {
        $antes = $this->contar('avisos');

        $this->assertNull($this->repo->criar([$this->a], $this->b, $this->dados('Em B'), $this->usuario));
        $this->assertNull($this->repo->criar([], $this->a, $this->dados('Sem permissão'), $this->usuario));

        $this->assertSame($antes, $this->contar('avisos'));
    }

    public function testDatasSaoGuardadasEmUtcELidasComoEpoch(): void
    {
        $aviso = $this->repo->porId([$this->a], $this->a, $this->avisoA);

        $this->assertSame(1_800_000_000, $aviso['inicio_epoch']);
        $this->assertSame(1_800_086_400, $aviso['fim_epoch']);
        $this->assertSame($this->usuario, $aviso['criado_por']);
    }

    public function testGuardaELeDiasEJanelaDeHorario(): void
    {
        $id = (int) $this->repo->criar([$this->a], $this->a, ['dias_semana' => 0b0100100, 'hora_inicio' => '07:00', 'hora_fim' => '09:30'] + $this->dados('Coleta'), $this->usuario);

        $aviso = $this->repo->porId([$this->a], $this->a, $id);
        $this->assertSame([36, '07:00', '09:30'], [$aviso['dias_semana'], $aviso['hora_inicio'], $aviso['hora_fim']]);

        $this->assertTrue($this->repo->atualizar([$this->a], $this->a, $id, ['dias_semana' => null, 'hora_inicio' => null, 'hora_fim' => null] + $this->dados('Coleta')));
        $limpo = $this->repo->porId([$this->a], $this->a, $id);
        $this->assertSame([null, null, null], [$limpo['dias_semana'], $limpo['hora_inicio'], $limpo['hora_fim']]);
    }

    public function testRepeticaoNaoVazaNemMudaNoCondominioDeOutroGerente(): void
    {
        $this->assertFalse($this->repo->atualizar([$this->a], $this->b, $this->avisoB, ['dias_semana' => 1] + $this->dados('Invasor')));

        $this->assertNull($this->repo->porId([$this->b], $this->b, $this->avisoB)['dias_semana']);
    }
}
