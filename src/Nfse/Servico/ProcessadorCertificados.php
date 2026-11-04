<?php
declare(strict_types=1);

namespace Xande\NfeIntegracao\Nfse\Servico;

use NFePHP\Common\Certificate;
use PDO;
use Throwable;
use Xande\NfeIntegracao\Nfse\Infra\CofreCertificado;
use Xande\NfeIntegracao\Nfse\NfseConfig;
use Xande\NfeIntegracao\Nfse\Repositorio\CadastroRepositorio;

/**
 * Confere os certificados enviados pelo cadastro (Scriptcase):
 *
 *   1. abre o .pfx com a senha;
 *   2. confere se e e-CNPJ da mesma empresa (mesma raiz de 8 digitos);
 *   3. confere a validade;
 *   4. criptografa e guarda em fiscal_empresas_certificados;
 *   5. APAGA o arquivo e a senha que vieram do formulario.
 *
 * O resultado aparece para o usuario em fiscal_empresas.certificado_situacao
 * e certificado_mensagem. Chamado pelo worker a cada volta.
 */
final class ProcessadorCertificados
{
    private CadastroRepositorio $cadastro;

    public function __construct(private PDO $pdo, private string $chaveBase64)
    {
        $this->cadastro = new CadastroRepositorio($pdo);
    }

    /** @return int quantos certificados foram conferidos */
    public function processarPendentes(): int
    {
        $total = 0;
        while (true) {
            $this->pdo->beginTransaction();
            try {
                $pendente = $this->cadastro->travarCertificadoPendente();
                if ($pendente === null) {
                    $this->pdo->commit();
                    return $total;
                }
                $this->conferir($pendente);
                $this->pdo->commit();
                $total++;
            } catch (Throwable $e) {
                $this->pdo->rollBack();
                throw $e;
            }
        }
    }

    /** @param array{cnpj: string, arquivo: string, senha: string} $pendente */
    private function conferir(array $pendente): void
    {
        $cnpj = $pendente['cnpj'];

        try {
            $cofre = new CofreCertificado($this->chaveBase64);
        } catch (Throwable $e) {
            $this->cadastro->concluirCertificado($cnpj, 'ERRO', 'O servidor ainda nao tem a chave do cadastro configurada. Avise o suporte e depois envie o certificado de novo.');
            return;
        }

        [$pfx, $certificado] = self::abrir($pendente['arquivo'], $pendente['senha']);
        if ($certificado === null) {
            $this->cadastro->concluirCertificado($cnpj, 'ERRO', 'Senha incorreta ou o arquivo nao e um certificado A1 (.pfx/.p12). Envie de novo o arquivo e a senha.');
            return;
        }

        $cnpjCertificado = NfseConfig::normalizarCnpj($certificado->getCnpj() ?? '');
        if ($cnpjCertificado === '') {
            $this->cadastro->concluirCertificado($cnpj, 'ERRO', 'Este certificado nao e um e-CNPJ (nao tem CNPJ). Use o certificado A1 da empresa.');
            return;
        }
        if (substr($cnpjCertificado, 0, 8) !== substr($cnpj, 0, 8)) {
            $this->cadastro->concluirCertificado($cnpj, 'ERRO', "Este certificado e do CNPJ {$cnpjCertificado}, que nao e desta empresa ({$cnpj}).");
            return;
        }

        $validade = $certificado->getValidTo();
        $titular = (string)$certificado->getCompanyName();
        $vencido = $certificado->isExpired();
        $situacao = $vencido ? 'VENCIDO' : 'OK';
        $mensagem = $vencido
            ? 'Certificado VENCIDO em ' . $validade->format('d/m/Y') . '. Envie o certificado renovado.'
            : "Certificado de {$titular}, valido ate " . $validade->format('d/m/Y') . '.';

        $this->cadastro->concluirCertificado(
            $cnpj,
            $situacao,
            $mensagem,
            $vencido ? null : $cofre->cifrar($pfx, $pendente['senha']),
            $validade->format('Y-m-d'),
            $titular
        );
    }

    /**
     * Ferramentas de formulario as vezes gravam o arquivo em base64 ou em
     * texto hexadecimal; tenta os tres formatos.
     *
     * @return array{0: string, 1: ?Certificate}
     */
    private static function abrir(string $arquivo, string $senha): array
    {
        $candidatos = [$arquivo];
        $texto = trim($arquivo);
        if (preg_match('/^(\\\\x)?([0-9a-fA-F]{2})+$/', $texto)) {
            $candidatos[] = (string)hex2bin(preg_replace('/^\\\\x/', '', $texto));
        }
        $base64 = base64_decode(preg_replace('/^data:[^,]*,/', '', $texto) ?? '', true);
        if ($base64 !== false && $base64 !== '') {
            $candidatos[] = $base64;
        }

        foreach ($candidatos as $conteudo) {
            try {
                return [$conteudo, Certificate::readPfx($conteudo, $senha)];
            } catch (Throwable) {
                // tenta o proximo formato
            }
        }
        return [$arquivo, null];
    }
}
