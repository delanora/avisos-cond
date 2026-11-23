<?php

declare(strict_types=1);

namespace Tests\Services;

use App\Services\SmtpMailer;
use PHPMailer\PHPMailer\Exception as PhpMailerException;
use PHPMailer\PHPMailer\PHPMailer;
use PHPUnit\Framework\TestCase;

/** A montagem do e-mail usa só o que vem da configuração (nada de servidor, usuário ou remetente fixo no código). */
final class SmtpMailerTest extends TestCase
{
    public function testTudoVemDaConfiguracaoInclusiveONomeDoRemetente(): void
    {
        $mailer = new SmtpMailer('smtp.exemplo.test', 587, 'suporte@exemplo.test', 'senha-de-app', 'suporte@exemplo.test', 'Suporte Exemplo');

        $email = $mailer->montar('pessoa@exemplo.test', 'Assunto', "Corpo\nem texto");

        $this->assertSame('smtp.exemplo.test', $email->Host);
        $this->assertSame(587, $email->Port);
        $this->assertTrue($email->SMTPAuth);
        $this->assertSame('suporte@exemplo.test', $email->Username);
        $this->assertSame('senha-de-app', $email->Password);
        $this->assertSame(PHPMailer::ENCRYPTION_STARTTLS, $email->SMTPSecure);
        $this->assertSame('suporte@exemplo.test', $email->From);
        $this->assertSame('Suporte Exemplo', $email->FromName);
        $this->assertSame(['pessoa@exemplo.test'], array_keys($email->getAllRecipientAddresses()));
        $this->assertSame('Assunto', $email->Subject);
        $this->assertSame("Corpo\nem texto", $email->Body);
        $this->assertSame(10, $email->Timeout, 'Sempre com tempo limite.');
    }

    public function testNomeDoRemetentePadraoQuandoNaoConfigurado(): void
    {
        $email = (new SmtpMailer('smtp.exemplo.test', 587, 'u@exemplo.test', 's', 'u@exemplo.test'))->montar('p@exemplo.test', 'A', 'B');

        $this->assertSame('Avisos-Cond', $email->FromName);
    }

    public function testPorta465UsaTlsDiretoEOutrasUsamStartTls(): void
    {
        $direto = (new SmtpMailer('h', 465, 'u@exemplo.test', 's', 'u@exemplo.test'))->montar('p@exemplo.test', 'A', 'B');
        $start = (new SmtpMailer('h', 2525, 'u@exemplo.test', 's', 'u@exemplo.test'))->montar('p@exemplo.test', 'A', 'B');

        $this->assertSame(PHPMailer::ENCRYPTION_SMTPS, $direto->SMTPSecure);
        $this->assertSame(PHPMailer::ENCRYPTION_STARTTLS, $start->SMTPSecure);
    }

    public function testSemUsuarioNaoTentaAutenticar(): void
    {
        $email = (new SmtpMailer('h', 25, '', '', 'remetente@exemplo.test'))->montar('p@exemplo.test', 'A', 'B');

        $this->assertFalse($email->SMTPAuth);
    }

    public function testDestinatarioInvalidoFalhaSemEnviar(): void
    {
        $this->expectException(PhpMailerException::class);

        (new SmtpMailer('h', 587, 'u@exemplo.test', 's', 'u@exemplo.test'))->montar('isto-nao-e-email', 'A', 'B');
    }

    public function testFalhaDeConexaoViraMailerExceptionSemExporASenha(): void
    {
        // Porta 1 em 127.0.0.1: recusa a conexão na hora, sem sair da máquina.
        $mailer = new SmtpMailer('127.0.0.1', 1, 'u@exemplo.test', 'SENHA-SECRETA-123', 'u@exemplo.test');

        try {
            $mailer->enviar('p@exemplo.test', 'A', 'B');
            $this->fail('Deveria falhar.');
        } catch (\App\Services\MailerException $e) {
            $this->assertStringContainsString('Falha ao enviar e-mail', $e->getMessage());
            $this->assertStringNotContainsString('SENHA-SECRETA-123', $e->getMessage());
        }
    }
}
