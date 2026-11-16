<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Services\Mailer;
use App\Services\MailerException;

/** Mailer de teste: guarda os e-mails em memória, ou falha quando $falhar é true. */
final class FakeMailer implements Mailer
{
    /** @var list<array{para: string, assunto: string, texto: string}> */
    public array $enviados = [];

    public bool $falhar = false;

    public function enviar(string $para, string $assunto, string $texto): void
    {
        if ($this->falhar) {
            throw new MailerException('SMTP indisponível');
        }

        $this->enviados[] = ['para' => $para, 'assunto' => $assunto, 'texto' => $texto];
    }

    /** Extrai o token do link de redefinição do último e-mail enviado. */
    public function ultimoToken(): string
    {
        $ultimo = end($this->enviados);
        if ($ultimo === false || preg_match('#/redefinir-senha/([a-f0-9]{64})#', $ultimo['texto'], $m) !== 1) {
            throw new \LogicException('Nenhum token no último e-mail.');
        }

        return $m[1];
    }
}
