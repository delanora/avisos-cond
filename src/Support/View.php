<?php

declare(strict_types=1);

namespace App\Support;

use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * Renderização de templates Twig. O auto-escape HTML fica sempre ligado;
 * nunca use |raw com conteúdo vindo de usuário (AGENTS.md 6.4).
 */
final class View
{
    private Environment $twig;

    public function __construct(string $diretorio, string $nomeApp = 'Avisos-Cond', bool $debug = false)
    {
        $this->twig = new Environment(new FilesystemLoader($diretorio), [
            'autoescape' => 'html',
            // Variável inexistente no template vira erro, em vez de sumir em silêncio.
            'strict_variables' => true,
            'debug' => $debug,
            'cache' => false,
        ]);
        $this->twig->addGlobal('app_nome', $nomeApp);
    }

    /** @param array<string, mixed> $dados */
    public function render(string $template, array $dados = []): string
    {
        return $this->twig->render($template, $dados);
    }
}
