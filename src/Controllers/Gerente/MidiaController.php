<?php

declare(strict_types=1);

namespace App\Controllers\Gerente;

use App\Services\AvisoService;
use App\Services\UploadService;
use App\Support\EscopoGerente;
use App\Support\NaoEncontradoException;
use App\Support\Request;
use App\Support\Response;

/**
 * Entrega mídias (do aviso e a logo) só para quem tem acesso ao condomínio dono delas. Os arquivos
 * ficam fora do webroot; com o Nginx, a entrega é feita por X-Accel-Redirect.
 */
final class MidiaController
{
    public function __construct(
        private readonly AvisoService $avisos,
        private readonly UploadService $upload,
        private readonly ?string $aceleracao,
    ) {
    }

    /** @param array<string, string> $vars */
    public function aviso(Request $request, array $vars): Response
    {
        $condominio = EscopoGerente::exigir($request, (int) $vars['cid']);
        $aviso = $this->avisos->buscar(EscopoGerente::permitidos($request), $condominio['id'], (int) $vars['id']);

        return $this->entregar($aviso['midia']);
    }

    /** @param array<string, string> $vars */
    public function logo(Request $request, array $vars): Response
    {
        $condominio = EscopoGerente::exigir($request, (int) $vars['cid']);

        return $this->entregar($condominio['logo']);
    }

    private function entregar(?string $nome): Response
    {
        $caminho = $nome === null ? null : $this->upload->caminho($nome);
        if ($caminho === null) {
            throw new NaoEncontradoException();
        }

        return Response::arquivo($caminho, UploadService::mimeDoNome($nome), $this->aceleracao)
            // Mídia de condomínio é privada: nada de cache compartilhado.
            ->withHeader('Cache-Control', 'private, max-age=60');
    }
}
