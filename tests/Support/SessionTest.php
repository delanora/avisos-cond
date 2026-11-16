<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Support\Config;
use App\Support\Session;
use PHPUnit\Framework\TestCase;

final class SessionTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testParametrosDoCookie(): void
    {
        $params = (new Session('avisos_sess', true))->cookieParams();

        $this->assertTrue($params['httponly']);
        $this->assertTrue($params['secure']);
        $this->assertSame('Lax', $params['samesite']);
        $this->assertSame(0, $params['lifetime']);
    }

    public function testSecureDependeDoEsquemaDaUrlDoApp(): void
    {
        $https = Session::fromConfig(new Config(['APP_URL' => 'https://avisos.exemplo.com.br']));
        $http = Session::fromConfig(new Config(['APP_URL' => 'http://127.0.0.1:8080']));

        $this->assertTrue($https->cookieParams()['secure']);
        $this->assertFalse($http->cookieParams()['secure']);
    }

    public function testGetSetHasForget(): void
    {
        $s = new Session('x', false);

        $this->assertNull($s->get('usuario'));
        $this->assertSame('padrao', $s->get('usuario', 'padrao'));

        $s->set('usuario', 7);
        $this->assertTrue($s->has('usuario'));
        $this->assertSame(7, $s->get('usuario'));

        $s->forget('usuario');
        $this->assertFalse($s->has('usuario'));
    }

    public function testFlashApareceUmaUnicaVez(): void
    {
        $s = new Session('x', false);

        $this->assertSame('', $s->pegarFlash());

        $s->flash('Salvo com sucesso.');
        $this->assertSame('Salvo com sucesso.', $s->pegarFlash());
        $this->assertSame('', $s->pegarFlash());
    }

    public function testDestroyLimpaOsDados(): void
    {
        $s = new Session('x', false);
        $s->set('usuario', 7);

        $s->destroy();

        $this->assertSame([], $_SESSION);
    }
}
