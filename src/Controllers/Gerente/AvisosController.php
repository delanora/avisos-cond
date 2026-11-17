<?php

declare(strict_types=1);

namespace App\Controllers\Gerente;

use App\Services\AvisoService;
use App\Services\ModeloAvisoService;
use App\Services\TipoAvisoService;
use App\Services\ValidacaoException;
use App\Support\DataHora;
use App\Support\EscopoGerente;
use App\Support\Recorrencia;
use App\Support\Request;
use App\Support\Response;
use App\Support\Session;
use App\Support\View;

/** CRUD de avisos de UM condomínio (o da URL), desde que o gerente tenha acesso a ele. */
final class AvisosController
{
    public function __construct(
        private readonly View $view,
        private readonly Session $sessao,
        private readonly AvisoService $avisos,
        private readonly TipoAvisoService $tipos,
        private readonly ModeloAvisoService $modelos,
    ) {
    }

    /** @param array<string, string> $vars */
    public function lista(Request $request, array $vars): Response
    {
        $condominio = EscopoGerente::exigir($request, (int) $vars['cid']);
        $permitidos = EscopoGerente::permitidos($request);

        return Response::html($this->view->render('gerente/avisos/lista.html.twig', EscopoGerente::contexto($request, $condominio) + [
            'avisos' => $this->avisos->listar($permitidos, $condominio['id']),
            'menu' => 'avisos',
        ]));
    }

    /** @param array<string, string> $vars */
    public function novo(Request $request, array $vars): Response
    {
        $condominio = EscopoGerente::exigir($request, (int) $vars['cid']);

        // Sugestão: começa no próximo quarto de hora e dura uma semana.
        $inicio = (int) (ceil(time() / 900) * 900);

        // "Começar de um modelo": ?modelo=ID preenche título, texto e tipo (modelo de outro condomínio é 404).
        $modelo = ctype_digit((string) ($request->query['modelo'] ?? ''))
            ? $this->modelos->paraUsar(EscopoGerente::permitidos($request), $condominio['id'], (int) $request->query['modelo'])
            : null;

        return $this->formulario($request, $condominio, null, [
            'titulo' => $modelo['titulo'] ?? '', 'corpo' => $modelo['corpo'] ?? '', 'tipo' => $modelo['tipo'] ?? 'comum', 'ativo' => true,
            'salvar_modelo' => false,
            'novo_tipo_nome' => '', 'novo_tipo_cor' => '#2F6FED', 'novo_tipo_fixo' => false,
            'dias' => [], 'hora_inicio' => '', 'hora_fim' => '',
            'inicio_em' => DataHora::paraFormulario($inicio),
            'fim_em' => DataHora::paraFormulario($inicio + 7 * 86400),
        ], []);
    }

    /** @param array<string, string> $vars */
    public function criar(Request $request, array $vars): Response
    {
        $condominio = EscopoGerente::exigir($request, (int) $vars['cid']);

        try {
            // O condomínio vem SEMPRE da URL validada; qualquer "condominio_id" no corpo é ignorado.
            $id = $this->avisos->criar(
                EscopoGerente::permitidos($request),
                $condominio['id'],
                $request->body,
                $request->files['midia'] ?? null,
                (int) $request->attribute('usuario')['id']
            );
        } catch (ValidacaoException $e) {
            return $this->formulario($request, $condominio, null, $this->valoresEnviados($request), $e->erros, 422);
        }
        $this->guardarComoModeloSePedido($request, $condominio['id'], $id);

        $this->sessao->flash('Aviso criado com sucesso.');

        return Response::redirect("/gerente/condominios/{$condominio['id']}/avisos", 303);
    }

    /** @param array<string, string> $vars */
    public function editar(Request $request, array $vars): Response
    {
        $condominio = EscopoGerente::exigir($request, (int) $vars['cid']);
        $aviso = $this->avisos->buscar(EscopoGerente::permitidos($request), $condominio['id'], (int) $vars['id']);

        return $this->formulario($request, $condominio, $aviso, [
            'titulo' => $aviso['titulo'],
            'corpo' => $aviso['corpo'],
            'tipo' => $aviso['tipo'],
            'salvar_modelo' => false,
            'novo_tipo_nome' => '', 'novo_tipo_cor' => '#2F6FED', 'novo_tipo_fixo' => false,
            'dias' => Recorrencia::listaDeDias($aviso['dias_semana']),
            'hora_inicio' => $aviso['hora_inicio'] ?? '',
            'hora_fim' => $aviso['hora_fim'] ?? '',
            'ativo' => $aviso['ativo'],
            'inicio_em' => DataHora::paraFormulario($aviso['inicio_epoch']),
            'fim_em' => DataHora::paraFormulario($aviso['fim_epoch']),
        ], []);
    }

    /** @param array<string, string> $vars */
    public function atualizar(Request $request, array $vars): Response
    {
        $condominio = EscopoGerente::exigir($request, (int) $vars['cid']);
        $permitidos = EscopoGerente::permitidos($request);
        $id = (int) $vars['id'];

        try {
            $this->avisos->atualizar($permitidos, $condominio['id'], $id, $request->body, $request->files['midia'] ?? null);
        } catch (ValidacaoException $e) {
            $aviso = $this->avisos->buscar($permitidos, $condominio['id'], $id);

            return $this->formulario($request, $condominio, $aviso, $this->valoresEnviados($request), $e->erros, 422);
        }
        $this->guardarComoModeloSePedido($request, $condominio['id'], $id);

        $this->sessao->flash('Aviso atualizado com sucesso.');

        return Response::redirect("/gerente/condominios/{$condominio['id']}/avisos", 303);
    }

    /** @param array<string, string> $vars */
    public function confirmarExclusao(Request $request, array $vars): Response
    {
        $condominio = EscopoGerente::exigir($request, (int) $vars['cid']);
        $aviso = $this->avisos->buscar(EscopoGerente::permitidos($request), $condominio['id'], (int) $vars['id']);

        return Response::html($this->view->render('gerente/avisos/excluir.html.twig', EscopoGerente::contexto($request, $condominio) + [
            'aviso' => $aviso,
            'menu' => 'avisos',
        ]));
    }

    /** @param array<string, string> $vars */
    public function excluir(Request $request, array $vars): Response
    {
        $condominio = EscopoGerente::exigir($request, (int) $vars['cid']);
        $this->avisos->excluir(EscopoGerente::permitidos($request), $condominio['id'], (int) $vars['id']);

        $this->sessao->flash('Aviso excluído.');

        return Response::redirect("/gerente/condominios/{$condominio['id']}/avisos", 303);
    }

    /** @param array<string, string> $vars */
    public function previa(Request $request, array $vars): Response
    {
        $condominio = EscopoGerente::exigir($request, (int) $vars['cid']);
        $aviso = $this->avisos->buscar(EscopoGerente::permitidos($request), $condominio['id'], (int) $vars['id']);

        return Response::html($this->view->render('gerente/avisos/previa.html.twig', EscopoGerente::contexto($request, $condominio) + [
            'aviso' => $aviso,
            'url_midia' => "/gerente/condominios/{$condominio['id']}/avisos/{$aviso['id']}/midia",
            'hora' => DataHora::paraFormulario(time()),
            'menu' => 'avisos',
        ]));
    }

    /** "Salvar este aviso como modelo": guarda título, texto e tipo do aviso que acabou de ser salvo. */
    private function guardarComoModeloSePedido(Request $request, int $condominioId, int $avisoId): void
    {
        if (($request->body['salvar_modelo'] ?? '') !== '1') {
            return;
        }
        $permitidos = EscopoGerente::permitidos($request);
        $aviso = $this->avisos->buscar($permitidos, $condominioId, $avisoId);
        $this->modelos->criarDoAviso($permitidos, $condominioId, $aviso['titulo'], $aviso['corpo'], $aviso['tipo']);
    }

    /** @return array<string, mixed> */
    private function valoresEnviados(Request $request): array
    {
        $texto = static fn (string $chave): string => is_string($request->body[$chave] ?? null) ? $request->body[$chave] : '';

        return [
            'titulo' => trim($texto('titulo')),
            'corpo' => trim($texto('corpo')),
            'tipo' => $texto('tipo'),
            'salvar_modelo' => $texto('salvar_modelo') === '1',
            'dias' => array_values(array_filter(array_map('intval', is_array($request->body['dias'] ?? null) ? array_filter($request->body['dias'], 'is_string') : []), static fn (int $d): bool => $d >= 0 && $d <= 6)),
            'hora_inicio' => $texto('hora_inicio'),
            'hora_fim' => $texto('hora_fim'),
            'novo_tipo_nome' => trim($texto('novo_tipo_nome')),
            'novo_tipo_cor' => $texto('novo_tipo_cor') !== '' ? $texto('novo_tipo_cor') : '#2F6FED',
            'novo_tipo_fixo' => $texto('novo_tipo_fixo') === '1',
            'ativo' => $texto('ativo') === '1',
            'inicio_em' => $texto('inicio_em'),
            'fim_em' => $texto('fim_em'),
        ];
    }

    /**
     * @param array<string, mixed>      $condominio
     * @param array<string, mixed>|null $aviso aviso em edição
     * @param array<string, mixed>      $valores
     * @param array<string, string>     $erros
     */
    private function formulario(Request $request, array $condominio, ?array $aviso, array $valores, array $erros, int $status = 200): Response
    {
        $base = "/gerente/condominios/{$condominio['id']}/avisos";

        return Response::html($this->view->render('gerente/avisos/form.html.twig', EscopoGerente::contexto($request, $condominio) + [
            'editando' => $aviso !== null,
            'aviso' => $aviso,
            'acao' => $aviso === null ? $base : "{$base}/{$aviso['id']}",
            'url_midia' => $aviso === null ? '' : "{$base}/{$aviso['id']}/midia",
            'valores' => $valores,
            'erros' => $erros,
            'dias_semana' => Recorrencia::DIAS,
            'tipos' => $this->tipos->listar(EscopoGerente::permitidos($request), $condominio['id']),
            'modelos' => $aviso === null ? $this->modelos->listar(EscopoGerente::permitidos($request), $condominio['id']) : [],
            'menu' => 'avisos',
        ]), $status);
    }
}
