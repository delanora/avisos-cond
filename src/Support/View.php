<?php

declare(strict_types=1);

namespace App\Support;

use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

/**
 * Renderização de templates Twig. O auto-escape HTML fica sempre ligado;
 * nunca use |raw com conteúdo vindo de usuário (AGENTS.md 6.4).
 */
final class View
{
    private Environment $twig;

    /** @var callable(): string */
    private $geradorCsrf;

    /** @var callable(): string */
    private $geradorFlash;

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

        // Padrão que falha alto: um formulário renderizado sem CSRF configurado vira erro,
        // em vez de sair sem token. A AppFactory define o gerador real com definirCsrf().
        $this->geradorCsrf = static function (): never {
            throw new \LogicException('A função csrf_token não foi configurada nesta View.');
        };
        $this->twig->addFunction(new TwigFunction('csrf_token', fn (): string => ($this->geradorCsrf)()));

        // Sem sessão configurada, não há mensagem a mostrar.
        $this->geradorFlash = static fn (): string => '';
        $this->twig->addFunction(new TwigFunction('flash', fn (): string => ($this->geradorFlash)()));
    }

    /** Define de onde vem o valor de {{ csrf_token() }}. A saída continua sujeita ao auto-escape. */
    public function definirCsrf(callable $gerador): void
    {
        $this->geradorCsrf = $gerador;
    }

    /** Define de onde vem a mensagem de {{ flash() }}. */
    public function definirFlash(callable $gerador): void
    {
        $this->geradorFlash = $gerador;
    }

    /** @param array<string, mixed> $dados */
    public function render(string $template, array $dados = []): string
    {
        return $this->twig->render($template, $dados);
    }
}
