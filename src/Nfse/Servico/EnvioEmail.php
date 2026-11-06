<?php
declare(strict_types=1);

namespace Xande\NfeIntegracao\Nfse\Servico;

use PHPMailer\PHPMailer\Exception as ErroPhpMailer;
use PHPMailer\PHPMailer\PHPMailer;
use RuntimeException;

/**
 * Envia um e-mail pelo SMTP da configuracao (bloco 'email' do config/nfse.php).
 *
 * Usa a biblioteca PHPMailer. A senha fica so no config/nfse.local.php (ou na
 * variavel de ambiente SMTP_PASSWORD); o resto pode ser mudado no painel,
 * tela Sistema > Configuracoes > E-mail.
 *
 * Nos testes, passe $transporte: uma funcao que recebe a mensagem no lugar do
 * SMTP. Assim nenhum e-mail de verdade sai.
 */
final class EnvioEmail
{
    private array $opcoes;

    /** @var null|callable(array): void */
    private $transporte;

    public function __construct(array $cfg, ?callable $transporte = null)
    {
        $this->opcoes = ($cfg['email'] ?? []) + [
            'host' => '', 'porta' => 465, 'seguranca' => 'ssl', 'usuario' => '', 'senha' => '',
            'remetente' => '', 'nome_remetente' => 'Gestão Fiscal', 'timeout_segundos' => 20,
        ];
        $this->transporte = $transporte;
    }

    /** O que falta para enviar (vazio = pronto). */
    public function pendencias(): array
    {
        $faltam = [];
        foreach (['host' => 'servidor (host)', 'usuario' => 'usuário', 'senha' => 'senha', 'remetente' => 'remetente'] as $chave => $nome) {
            if (trim((string)$this->opcoes[$chave]) === '') {
                $faltam[] = $nome;
            }
        }
        return $faltam;
    }

    public function configurado(): bool
    {
        return $this->pendencias() === [];
    }

    /** Remetente que aparece no e-mail (para mostrar no painel). */
    public function remetente(): string
    {
        return (string)$this->opcoes['remetente'];
    }

    /**
     * @throws RuntimeException com a mensagem do servidor de e-mail
     */
    public function enviar(string $para, string $assunto, string $texto, ?string $html = null): void
    {
        $mensagem = ['para' => $para, 'assunto' => $assunto, 'texto' => $texto, 'html' => $html];
        if ($this->transporte !== null) {
            ($this->transporte)($mensagem);
            return;
        }
        if (!$this->configurado()) {
            throw new RuntimeException('E-mail não configurado. Falta: ' . implode(', ', $this->pendencias()) . '.');
        }

        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host = (string)$this->opcoes['host'];
            $mail->Port = (int)$this->opcoes['porta'];
            $mail->SMTPAuth = true;
            $mail->Username = (string)$this->opcoes['usuario'];
            $mail->Password = (string)$this->opcoes['senha'];
            // ssl = TLS direto (porta 465); tls = STARTTLS (porta 587); vazio = sem criptografia.
            $mail->SMTPSecure = match ((string)$this->opcoes['seguranca']) {
                'ssl' => PHPMailer::ENCRYPTION_SMTPS,
                'tls' => PHPMailer::ENCRYPTION_STARTTLS,
                default => '',
            };
            $mail->SMTPAutoTLS = (string)$this->opcoes['seguranca'] !== '';
            $mail->Timeout = (int)$this->opcoes['timeout_segundos'];
            $mail->CharSet = PHPMailer::CHARSET_UTF8;
            $mail->setFrom((string)$this->opcoes['remetente'], (string)$this->opcoes['nome_remetente']);
            $mail->addAddress($para);
            $mail->Subject = $assunto;
            if ($html !== null) {
                $mail->isHTML(true);
                $mail->Body = $html;
                $mail->AltBody = $texto;
            } else {
                $mail->Body = $texto;
            }
            $mail->send();
        } catch (ErroPhpMailer $e) {
            throw new RuntimeException($mail->ErrorInfo !== '' ? $mail->ErrorInfo : $e->getMessage(), 0, $e);
        }
    }
}
