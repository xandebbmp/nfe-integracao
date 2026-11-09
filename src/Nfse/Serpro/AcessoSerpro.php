<?php
declare(strict_types=1);

namespace Xande\NfeIntegracao\Nfse\Serpro;

use NFePHP\Common\Certificate;
use PDO;
use RuntimeException;
use Throwable;
use Xande\NfeIntegracao\Nfse\Infra\CertificadoCliente;
use Xande\NfeIntegracao\Nfse\Infra\CofreCertificado;
use Xande\NfeIntegracao\Nfse\Infra\PfxCompativel;
use Xande\NfeIntegracao\Nfse\NfseConfig;
use Xande\NfeIntegracao\Nfse\Repositorio\ClienteRepositorio;
use Xande\NfeIntegracao\Nfse\Repositorio\SistemaRepositorio;

/**
 * Dados de acesso da PRODUCAO do Integra Contador (SERPRO), cadastrados pelo
 * painel admin (Sistema > Integração com a Receita). Nada vai para arquivo.
 *
 * O SERPRO entrega, depois do contrato (loja.serpro.gov.br):
 *   - Consumer Key e Consumer Secret (as "chaves" do contrato);
 * e o login usa o certificado e-CNPJ A1 (.pfx) de quem contratou (o escritorio).
 *
 * Tudo fica CRIPTOGRAFADO na tabela sistema_ajustes, com a mesma chave dos
 * certificados das empresas ('chave_certificados'). Nada volta para a tela:
 * so "cadastrado / falta", o titular e a validade do certificado.
 */
final class AcessoSerpro
{
    public const CHAVE_CHAVES = 'serpro.chaves_cifradas';
    public const CHAVE_CERTIFICADO = 'serpro.certificado_cifrado';
    public const CHAVE_CERTIFICADO_INFO = 'serpro.certificado_info';

    private SistemaRepositorio $sistema;

    public function __construct(private PDO $pdo, private string $chaveCertificados)
    {
        $this->sistema = new SistemaRepositorio($pdo);
    }

    /** O que a tela mostra (nunca as chaves nem o certificado). */
    public function situacao(): array
    {
        $ajustes = $this->sistema->ajustes();
        $info = is_array($ajustes[self::CHAVE_CERTIFICADO_INFO] ?? null) ? $ajustes[self::CHAVE_CERTIFICADO_INFO] : null;
        $vencido = $info !== null && ($info['validade'] ?? '') < date('Y-m-d');
        return [
            'chaves' => is_string($ajustes[self::CHAVE_CHAVES] ?? null),
            'certificado' => $info,
            'certificado_vencido' => $vencido,
            'completo' => is_string($ajustes[self::CHAVE_CHAVES] ?? null) && $info !== null && !$vencido,
        ];
    }

    /** Grava Consumer Key e Consumer Secret (criptografados). */
    public function salvarChaves(string $key, string $secret, ?string $ip): array
    {
        $key = trim($key);
        $secret = trim($secret);
        if (!preg_match('/^[\w\-.]{8,200}$/', $key) || !preg_match('/^[\w\-.]{8,200}$/', $secret)) {
            return self::falha(422, 'CHAVES_INVALIDAS', 'Confira a Consumer Key e o Consumer Secret: copie exatamente como aparecem na Área do Cliente do SERPRO.');
        }
        $cofre = $this->cofre();
        if (is_array($cofre)) {
            return $cofre;
        }
        $this->sistema->salvarAjuste(self::CHAVE_CHAVES, $cofre->cifrarTexto((string)json_encode(['key' => $key, 'secret' => $secret])));
        (new ClienteRepositorio($this->pdo))->registrarLog(null, 'CONFIG_ALTERADA', ['serpro.chaves' => 'alteradas no painel'], $ip);
        return ['ok' => true, 'mensagem' => 'Chaves do SERPRO salvas (guardadas criptografadas).'];
    }

    /** Confere e grava o e-CNPJ do escritorio (quem contratou o SERPRO). */
    public function salvarCertificado(string $pfx, string $senha, ?string $ip): array
    {
        $pfx = PfxCompativel::normalizar($pfx, $senha);
        try {
            $certificado = Certificate::readPfx($pfx, $senha);
        } catch (Throwable) {
            return self::falha(422, 'CERTIFICADO_INVALIDO', 'Não foi possível abrir o certificado: confira o arquivo .pfx e a senha.');
        }
        $cnpj = NfseConfig::normalizarCnpj($certificado->getCnpj() ?? '');
        if (strlen($cnpj) !== 14) {
            return self::falha(422, 'CERTIFICADO_SEM_CNPJ', 'Este certificado não é um e-CNPJ. O SERPRO pede o e-CNPJ da empresa que contratou.');
        }
        if ($certificado->isExpired()) {
            return self::falha(422, 'CERTIFICADO_VENCIDO', 'Este certificado venceu em ' . $certificado->getValidTo()->format('d/m/Y') . '.');
        }
        $cofre = $this->cofre();
        if (is_array($cofre)) {
            return $cofre;
        }
        $info = [
            'cnpj' => $cnpj,
            'titular' => (string)$certificado->getCompanyName(),
            'validade' => $certificado->getValidTo()->format('Y-m-d'),
        ];
        $this->sistema->salvarAjuste(self::CHAVE_CERTIFICADO, base64_encode($cofre->cifrar($pfx, $senha)));
        $this->sistema->salvarAjuste(self::CHAVE_CERTIFICADO_INFO, $info);
        (new ClienteRepositorio($this->pdo))->registrarLog(null, 'CONFIG_ALTERADA', ['serpro.certificado' => $info['titular'] . ' (' . $cnpj . ')'], $ip);
        return ['ok' => true, 'mensagem' => 'Certificado do escritório salvo: ' . $info['titular'] . '.', 'certificado' => $info];
    }

    /** Apaga chaves e certificado do SERPRO. */
    public function apagar(?string $ip): array
    {
        foreach ([self::CHAVE_CHAVES, self::CHAVE_CERTIFICADO, self::CHAVE_CERTIFICADO_INFO] as $chave) {
            $this->sistema->removerAjuste($chave);
        }
        (new ClienteRepositorio($this->pdo))->registrarLog(null, 'CONFIG_ALTERADA', ['serpro' => 'acesso apagado no painel'], $ip);
        return ['ok' => true, 'mensagem' => 'Acesso ao SERPRO apagado do sistema.'];
    }

    /**
     * Credenciais abertas, para o IntegraContador falar com a producao.
     *
     * @return array{key: string, secret: string, certificado: CertificadoCliente, cnpj: string}
     */
    public function credenciais(): array
    {
        $ajustes = $this->sistema->ajustes();
        $chaves = $ajustes[self::CHAVE_CHAVES] ?? null;
        $cert = $ajustes[self::CHAVE_CERTIFICADO] ?? null;
        $info = $ajustes[self::CHAVE_CERTIFICADO_INFO] ?? null;
        if (!is_string($chaves) || !is_string($cert) || !is_array($info)) {
            throw new RuntimeException('Falta cadastrar o acesso ao SERPRO (chaves e certificado do escritório) em Sistema > Integração com a Receita.');
        }
        $cofre = new CofreCertificado($this->chaveCertificados);
        $abertas = json_decode($cofre->decifrarTexto($chaves), true);
        $aberto = $cofre->decifrar((string)base64_decode($cert, true));
        return [
            'key' => (string)($abertas['key'] ?? ''),
            'secret' => (string)($abertas['secret'] ?? ''),
            'certificado' => CertificadoCliente::doConteudo($aberto['pfx'], $aberto['senha']),
            'cnpj' => (string)$info['cnpj'],
        ];
    }

    private function cofre(): CofreCertificado|array
    {
        try {
            return new CofreCertificado($this->chaveCertificados);
        } catch (Throwable) {
            return self::falha(503, 'CHAVE_AUSENTE', 'Falta a chave de segurança do servidor (a mesma dos certificados). Rode o Diagnóstico para ver como resolver.');
        }
    }

    private static function falha(int $http, string $codigo, string $mensagem): array
    {
        return ['ok' => false, 'http' => $http, 'codigo' => $codigo, 'mensagem' => $mensagem];
    }
}
