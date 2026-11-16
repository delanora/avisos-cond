<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Support\Csrf;
use App\Support\Session;
use PHPUnit\Framework\TestCase;

final class CsrfTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testTokenAleatorioDe64HexEEstavelNaSessao(): void
    {
        $csrf = new Csrf(Session::emMemoria());

        $token = $csrf->token();

        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $token);
        $this->assertSame($token, $csrf->token());
    }

    public function testSessoesDiferentesTemTokensDiferentes(): void
    {
        $primeiro = (new Csrf(Session::emMemoria()))->token();
        $_SESSION = [];
        $segundo = (new Csrf(Session::emMemoria()))->token();

        $this->assertNotSame($primeiro, $segundo);
    }

    public function testValidaSomenteOTokenCorreto(): void
    {
        $csrf = new Csrf(Session::emMemoria());
        $token = $csrf->token();

        $this->assertTrue($csrf->valido($token));
        $this->assertFalse($csrf->valido('errado'));
        $this->assertFalse($csrf->valido(strrev($token)));
        $this->assertFalse($csrf->valido(''));
        $this->assertFalse($csrf->valido(null));
    }
}
