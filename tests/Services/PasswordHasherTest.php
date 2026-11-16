<?php

declare(strict_types=1);

namespace Tests\Services;

use App\Services\PasswordHasher;
use PHPUnit\Framework\TestCase;

final class PasswordHasherTest extends TestCase
{
    public function testGeraHashArgon2idQueNaoContemASenha(): void
    {
        $hash = (new PasswordHasher())->hash('minha-senha-secreta');

        $this->assertStringStartsWith('$argon2id$', $hash);
        $this->assertStringNotContainsString('minha-senha-secreta', $hash);
    }

    public function testUsaParametrosDeMemoriaETempoRecomendados(): void
    {
        $info = password_get_info((new PasswordHasher())->hash('x'));

        $this->assertSame('argon2id', $info['algoName']);
        $this->assertGreaterThanOrEqual(19456, $info['options']['memory_cost']);
        $this->assertGreaterThanOrEqual(2, $info['options']['time_cost']);
    }

    public function testVerificaSenhaCorretaEIncorreta(): void
    {
        $hasher = new PasswordHasher();
        $hash = $hasher->hash('senha-correta-123');

        $this->assertTrue($hasher->verificar('senha-correta-123', $hash));
        $this->assertFalse($hasher->verificar('senha-errada-123', $hash));
        $this->assertFalse($hasher->verificar('', $hash));
    }

    public function testMesmaSenhaGeraHashesDiferentesPorCausaDoSalt(): void
    {
        $hasher = new PasswordHasher();

        $this->assertNotSame($hasher->hash('igual'), $hasher->hash('igual'));
    }

    public function testPrecisaRehashQuandoOsParametrosMudaram(): void
    {
        $fraco = new PasswordHasher(['memory_cost' => 1024, 'time_cost' => 1, 'threads' => 1]);
        $forte = new PasswordHasher();

        $this->assertTrue($forte->precisaRehash($fraco->hash('x')));
        $this->assertFalse($forte->precisaRehash($forte->hash('x')));
    }

    public function testSenhasComEspacosEUnicodeFuncionam(): void
    {
        $hasher = new PasswordHasher(['memory_cost' => 1024, 'time_cost' => 1, 'threads' => 1]);
        $hash = $hasher->hash('  senha com espaço e ação  ');

        $this->assertTrue($hasher->verificar('  senha com espaço e ação  ', $hash));
        $this->assertFalse($hasher->verificar('senha com espaço e ação', $hash));
    }
}
