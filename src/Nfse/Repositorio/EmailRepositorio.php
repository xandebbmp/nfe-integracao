<?php
declare(strict_types=1);

namespace Xande\NfeIntegracao\Nfse\Repositorio;

use PDO;

/**
 * SQL da fila de e-mails (sistema_emails, script 0012).
 *
 * A tela so grava o e-mail aqui; o worker envia (Servico/FilaEmails).
 */
final class EmailRepositorio
{
    /** Tentativas antes de desistir (status ERRO). */
    public const MAX_TENTATIVAS = 5;

    public function __construct(private PDO $pdo)
    {
    }

    /** O script 0012 ja foi aplicado? */
    public function existe(): bool
    {
        return (bool)$this->pdo->query("SELECT to_regclass('public.sistema_emails') IS NOT NULL")->fetchColumn();
    }

    public function enfileirar(string $para, string $assunto, string $texto, ?string $html): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO sistema_emails (para, assunto, texto, html) VALUES (:para, :assunto, :texto, :html) RETURNING id'
        );
        $stmt->execute([':para' => $para, ':assunto' => mb_substr($assunto, 0, 200), ':texto' => $texto, ':html' => $html]);
        $id = (int)$stmt->fetchColumn();
        // Acorda o worker na hora (ele escuta o mesmo canal da fila de notas).
        $this->pdo->exec('NOTIFY nfse_jobs');
        return $id;
    }

    /**
     * Pega os proximos e-mails para enviar e "reserva" por 5 minutos: se o
     * worker cair no meio, outro tenta depois. Dois workers nunca pegam o
     * mesmo e-mail (SKIP LOCKED).
     *
     * @return list<array>
     */
    public function reservarProximos(int $limite): array
    {
        $stmt = $this->pdo->prepare(
            "UPDATE sistema_emails
                SET tentativas = tentativas + 1,
                    enviar_apos = CURRENT_TIMESTAMP + INTERVAL '5 minutes'
              WHERE id IN (
                    SELECT id FROM sistema_emails
                     WHERE status = 'PENDENTE' AND enviar_apos <= CURRENT_TIMESTAMP
                     ORDER BY id
                     FOR UPDATE SKIP LOCKED
                     LIMIT :limite
              )
          RETURNING *"
        );
        $stmt->bindValue(':limite', $limite, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function marcarEnviado(int $id): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE sistema_emails SET status = 'ENVIADO', enviado_em = CURRENT_TIMESTAMP, ultimo_erro = NULL WHERE id = :id"
        );
        $stmt->execute([':id' => $id]);
    }

    /** Falhou: tenta de novo mais tarde (1, 2, 4, 8 minutos) ou desiste. */
    public function marcarFalha(int $id, int $tentativas, string $erro): void
    {
        $desistir = $tentativas >= self::MAX_TENTATIVAS;
        $stmt = $this->pdo->prepare(
            "UPDATE sistema_emails
                SET status = :status, ultimo_erro = :erro,
                    enviar_apos = CURRENT_TIMESTAMP + make_interval(mins => :minutos)
              WHERE id = :id"
        );
        $stmt->bindValue(':status', $desistir ? 'ERRO' : 'PENDENTE');
        $stmt->bindValue(':erro', mb_substr($erro, 0, 500));
        $stmt->bindValue(':minutos', 2 ** max(0, $tentativas - 1), PDO::PARAM_INT);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
    }

    /** Ultimos e-mails (tela Sistema do painel). Sem o corpo: pode ter link de senha. */
    public function ultimos(int $limite = 10): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, para, assunto, status, tentativas, ultimo_erro, criado_em, enviado_em
               FROM sistema_emails ORDER BY id DESC LIMIT :limite'
        );
        $stmt->bindValue(':limite', $limite, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
