<?php

declare(strict_types=1);

namespace App\Services;

interface Mailer
{
    /** @throws MailerException quando o e-mail não pôde ser enviado */
    public function enviar(string $para, string $assunto, string $texto): void;
}
