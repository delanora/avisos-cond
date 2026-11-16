<?php

declare(strict_types=1);

namespace App\Services;

/** Usado quando MAIL_HOST está vazio: nada é enviado e o motivo vai para o log. */
final class MailerNaoConfigurado implements Mailer
{
    public function enviar(string $para, string $assunto, string $texto): void
    {
        throw new MailerException('E-mail não configurado (defina MAIL_HOST e demais variáveis MAIL_* no .env).');
    }
}
