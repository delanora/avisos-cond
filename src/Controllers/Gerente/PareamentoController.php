<?php

declare(strict_types=1);

namespace App\Controllers\Gerente;

use App\Services\AuditoriaService;
use App\Services\DispositivoService;
use App\Services\PareamentoService;
use App\Services\ValidacaoException;
use App\Support\EscopoGerente;
use App\Support\Request;
use App\Support\Response;
use App\Support\Session;
use App\Support\View;

/**
 * Pareamento de TV pelo gerente: só dos dispositivos dos condomínios dele (fora do escopo é 404 antes de qualquer
 * leitura ou escrita). Parear troca o token do dispositivo. O limite de códigos errados por usuário fica no PareamentoService.
 */
final class PareamentoController
{
    public function __construct(
        private readonly View $view,
        private readonly Session $sessao,
        private readonly DispositivoService $dispositivos,
        private readonly PareamentoService $pareamento,
        private readonly AuditoriaService $auditoria,
        private readonly string $urlBase,
    ) {
    }

    /** @param array<string, string> $vars */
    public function formulario(Request $request, array $vars): Response
    {
        return $this->pagina($request, $this->dispositivos->buscarDoGerente(EscopoGerente::permitidos($request), (int) $vars['id'], time()), []);
    }

    /** @param array<string, string> $vars */
    public function parear(Request $request, array $vars): Response
    {
        $dispositivo = $this->dispositivos->buscarDoGerente(EscopoGerente::permitidos($request), (int) $vars['id'], time());
        $codigo = is_string($request->body['codigo'] ?? null) ? $request->body['codigo'] : '';

        try {
            $ipDaTv = $this->pareamento->parear($dispositivo['id'], $codigo, (int) $request->attribute('usuario')['id']);
        } catch (ValidacaoException $e) {
            return $this->pagina($request, $dispositivo, $e->erros, 422);
        }

        // Só o IP da TV e o dispositivo: nunca o código, o segredo nem o token.
        $this->auditoria->registrarDaRequisicao(
            $request,
            'dispositivo.pareado',
            'dispositivo',
            $dispositivo['id'],
            ['id' => (int) $dispositivo['condominio_id'], 'nome' => (string) $dispositivo['condominio_nome']],
            ['nome' => $dispositivo['nome'], 'ativo' => $dispositivo['ativo'] ? 'sim' : 'não', 'ip_da_tv' => $ipDaTv],
        );
        $this->sessao->flash('TV pareada. Ela abre sozinha em alguns segundos. O endereço antigo deste dispositivo deixou de funcionar.');

        return Response::redirect("/gerente/dispositivos/{$dispositivo['id']}", 303);
    }

    /**
     * @param array<string, mixed>  $dispositivo
     * @param array<string, string> $erros
     */
    private function pagina(Request $request, array $dispositivo, array $erros, int $status = 200): Response
    {
        return Response::html($this->view->render('gerente/dispositivos/parear.html.twig', EscopoGerente::contexto($request) + [
            'dispositivo' => $dispositivo,
            'erros' => $erros,
            'url_pareamento' => rtrim($this->urlBase, '/') . '/tv',
            'base' => "/gerente/dispositivos/{$dispositivo['id']}",
            'menu' => 'dispositivos',
        ]), $status);
    }
}
