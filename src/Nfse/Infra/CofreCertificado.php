<?php
declare(strict_types=1);

namespace Xande\NfeIntegracao\Nfse\Infra;

use RuntimeException;

/**
 * Guarda o certificado A1 (.pfx) e a senha CRIPTOGRAFADOS no banco.
 *
 * Usa libsodium (secretbox), que ja vem no PHP 8. A chave (32 bytes em
 * base64) fica so no servidor: config/nfse.local.php -> 'chave_certificados'
 * (ou variavel de ambiente NFSE_CHAVE_CERTIFICADOS). Gere com:
 *
 *     php -r 'echo base64_encode(random_bytes(32)), PHP_EOL;'
 *
 * Quem copiar o banco sem a chave nao consegue usar os certificados.
 * Se a chave for perdida, basta reenviar os certificados pelo painel admin.
 */
final class CofreCertificado
{
    private string $chave;

    public function __construct(string $chaveBase64)
    {
        $chave = base64_decode(trim($chaveBase64), true);
        if ($chave === false || strlen($chave) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new RuntimeException(
                "Chave dos certificados ausente ou invalida no servidor ('chave_certificados' em config/nfse.local.php). "
                . "Gere com: php -r 'echo base64_encode(random_bytes(32)), PHP_EOL;'"
            );
        }
        $this->chave = $chave;
    }

    public function cifrar(string $pfx, string $senha): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $claro = json_encode(['pfx' => base64_encode($pfx), 'senha' => $senha], JSON_THROW_ON_ERROR);
        return $nonce . sodium_crypto_secretbox($claro, $nonce, $this->chave);
    }

    /** Cifra um texto curto (ex.: senha do e-mail). Devolve base64, pronto para guardar. */
    public function cifrarTexto(string $texto): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return base64_encode($nonce . sodium_crypto_secretbox($texto, $nonce, $this->chave));
    }

    public function decifrarTexto(string $base64): string
    {
        $cifrado = (string)base64_decode($base64, true);
        $nonce = substr($cifrado, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $claro = strlen($nonce) === SODIUM_CRYPTO_SECRETBOX_NONCEBYTES
            ? sodium_crypto_secretbox_open(substr($cifrado, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, $this->chave)
            : false;
        if ($claro === false) {
            throw new RuntimeException('A senha guardada nao abriu: a chave dos certificados mudou. Digite a senha de novo no painel.');
        }
        return $claro;
    }

    /** @return array{pfx: string, senha: string} */
    public function decifrar(string $cifrado): array
    {
        $nonce = substr($cifrado, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $corpo = substr($cifrado, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $claro = strlen($nonce) === SODIUM_CRYPTO_SECRETBOX_NONCEBYTES
            ? sodium_crypto_secretbox_open($corpo, $nonce, $this->chave)
            : false;
        if ($claro === false) {
            throw new RuntimeException('Certificado guardado nao abriu: a chave dos certificados mudou. Reenvie o certificado pelo painel admin.');
        }
        $dados = json_decode($claro, true, 512, JSON_THROW_ON_ERROR);
        return ['pfx' => (string)base64_decode((string)$dados['pfx']), 'senha' => (string)$dados['senha']];
    }
}
