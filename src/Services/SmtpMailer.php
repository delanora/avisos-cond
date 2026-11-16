<?php

declare(strict_types=1);

namespace App\Services;

use PHPMailer\PHPMailer\Exception as PhpMailerException;
use PHPMailer\PHPMailer\PHPMailer;

/** Envio por SMTP (PHPMailer), com TLS e timeout definido. */
final class SmtpMailer implements Mailer
{
    public function __construct(
        private readonly string $host,
        private readonly int $porta,
        private readonly string $usuario,
        private readonly string $senha,
        private readonly string $remetente,
    ) {
    }

    public function enviar(string $para, string $assunto, string $texto): void
    {
        $email = new PHPMailer(true);

        try {
            $email->isSMTP();
            $email->Host = $this->host;
            $email->Port = $this->porta;
            $email->SMTPAuth = $this->usuario !== '';
            $email->Username = $this->usuario;
            $email->Password = $this->senha;
            // 465 = TLS direto; demais portas (ex.: 587) usam STARTTLS.
            $email->SMTPSecure = $this->porta === 465 ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
            $email->Timeout = 10;
            $email->CharSet = PHPMailer::CHARSET_UTF8;
            $email->isHTML(false);

            $email->setFrom($this->remetente, 'Avisos-Cond');
            $email->addAddress($para);
            $email->Subject = $assunto;
            $email->Body = $texto;

            $email->send();
        } catch (PhpMailerException $erro) {
            // A mensagem do PHPMailer não contém a senha SMTP.
            throw new MailerException('Falha ao enviar e-mail: ' . $erro->getMessage(), 0, $erro);
        }
    }
}
