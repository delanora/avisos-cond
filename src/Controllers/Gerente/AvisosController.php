<?php

declare(strict_types=1);

namespace App\Controllers\Gerente;

use App\Services\AvisoService;
use App\Services\ValidacaoException;
use App\Support\DataHora;
use App\Support\EscopoGerente;
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
    ) {
    }

    /** @param array<string, string> $vars */
    public function lista(Request $request, array $vars): Response
    {
        $condominio = EscopoGerente::exigir($request, (int) $vars['cid']);
        $permitidos = EscopoGerente::permitidos($request);

        return Response::html($this->view->render('gerente/avisos/lista.html.twig', EscopoGerente::contexto($request, $condominio) + [
            'avisos' => $this->avisos->listar($permitidos, $condominio['id']),
            'tipos' => AvisoService::TIPOS,
            'menu' => 'avisos',
        ]));
    }

    /** @param array<string, string> $vars */
    public function novo(Request $request, array $vars): Response
    {
        $condominio = EscopoGerente::exigir($request, (int) $vars['cid']);

        // Sugestão: começa no próximo quarto de hora e dura uma semana.
        $inicio = (int) (ceil(time() / 900) * 900);

        return $this->formulario($request, $condominio, null, [
            'titulo' => '', 'corpo' => '', 'tipo' => 'comum', 'ativo' => true,
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
            $this->avisos->criar(
                EscopoGerente::permitidos($request),
                $condominio['id'],
                $request->body,
                $request->files['midia'] ?? null,
                (int) $request->attribute('usuario')['id']
            );
        } catch (ValidacaoException $e) {
            return $this->formulario($request, $condominio, null, $this->valoresEnviados($request), $e->erros, 422);
        }

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
            'tipos' => AvisoService::TIPOS,
            'url_midia' => "/gerente/condominios/{$condominio['id']}/avisos/{$aviso['id']}/midia",
            'hora' => DataHora::paraFormulario(time()),
            'menu' => 'avisos',
        ]));
    }

    /** @return array<string, mixed> */
    private function valoresEnviados(Request $request): array
    {
        $texto = static fn (string $chave): string => is_string($request->body[$chave] ?? null) ? $request->body[$chave] : '';

        return [
            'titulo' => trim($texto('titulo')),
            'corpo' => trim($texto('corpo')),
            'tipo' => $texto('tipo'),
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
            'tipos' => AvisoService::TIPOS,
            'menu' => 'avisos',
        ]), $status);
    }
}
