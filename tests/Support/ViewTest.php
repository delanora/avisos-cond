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

    public function testCsrfTokenNaoConfiguradoFalhaEmVezDeRenderizarSemToken(): void
    {
        $dir = sys_get_temp_dir() . '/tpl_' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir . '/form.twig', '<input value="{{ csrf_token() }}">');

        try {
            $this->expectException(\Twig\Error\RuntimeError::class);
            $this->expectExceptionMessage('csrf_token não foi configurada');
            (new View($dir))->render('form.twig');
        } finally {
            unlink($dir . '/form.twig');
            rmdir($dir);
        }
    }

    public function testCsrfDefinidoEhUsadoEEscapado(): void
    {
        $dir = sys_get_temp_dir() . '/tpl_' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir . '/form.twig', '<input value="{{ csrf_token() }}">');

        try {
            $view = new View($dir);
            $view->definirCsrf(static fn (): string => 'abc"<x>');

            $this->assertSame('<input value="abc&quot;&lt;x&gt;">', $view->render('form.twig'));
        } finally {
            unlink($dir . '/form.twig');
            rmdir($dir);
        }
    }

    public function testVariavelInexistenteGeraErro(): void
    {
        $this->expectException(RuntimeError::class);
        $this->view()->render('falta.html.twig');
    }
}
