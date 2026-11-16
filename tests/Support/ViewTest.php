<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Support\View;
use PHPUnit\Framework\TestCase;
use Twig\Error\RuntimeError;

final class ViewTest extends TestCase
{
    private function view(): View
    {
        return new View(dirname(__DIR__) . '/fixtures/templates');
    }

    public function testRenderizaVariaveis(): void
    {
        $this->assertSame("Olá, Maria!\n", $this->view()->render('ola.html.twig', ['nome' => 'Maria']));
    }

    public function testAutoEscapeLigadoImpedeXss(): void
    {
        $html = $this->view()->render('ola.html.twig', ['nome' => '<script>alert(1)</script>']);

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testVariavelInexistenteGeraErro(): void
    {
        $this->expectException(RuntimeError::class);
        $this->view()->render('falta.html.twig');
    }
}
