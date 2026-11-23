<?php

declare(strict_types=1);

namespace App\Services;

interface Mailer
{
    /**
     * Envia um e-mail em texto puro e, se vier $html, também em HTML (o texto vira a versão alternativa).
     *
     * @throws MailerException quando o e-mail não pôde ser enviado
     */
    public function enviar(string $para, string $assunto, string $texto, ?string $html = null): void;
}
