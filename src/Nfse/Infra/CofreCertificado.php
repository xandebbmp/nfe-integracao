<?php
declare(strict_types=1);

namespace Xande\NfeIntegracao\Nfse\Infra;

use RuntimeException;

/**
 * Guarda o certificado A1 (.pfx) e a senha CRIPTOGRAFADOS no banco.
 *
 * Usa libsodium (secretbox: XSalsa20 + Poly1305), que ja vem no PHP 8.
 * A chave (32 bytes em base64) fica so no servidor, em
 * config/nfse.local.php -> 'cadastro' => ['chave' => '...'], ou na variavel
 * de ambiente NFSE_CHAVE_CADASTRO. Gere com:
 *
 *     php bin/fiscal_cadastro.php --gerar-chave
 *
 * Sem a chave, quem copiar o banco nao consegue usar os certificados.
 * Se a chave for perdida, basta reenviar os certificados pelo cadastro.
 */
final class CofreCertificado
{
    private string $chave;

    public function __construct(string $chaveBase64)
    {
        $chave = base64_decode(trim($chaveBase64), true);
        if ($chave === false || strlen($chave) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new RuntimeException(
                'Chave do cadastro ausente ou invalida no servidor. Gere com "php bin/fiscal_cadastro.php --gerar-chave" '
                . "e coloque em config/nfse.local.php ('cadastro' => ['chave' => '...'])."
            );
        }
        $this->chave = $chave;
    }

    public static function gerarChave(): string
    {
        return base64_encode(sodium_crypto_secretbox_keygen());
    }

    public function cifrar(string $pfx, string $senha): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $claro = json_encode(['pfx' => base64_encode($pfx), 'senha' => $senha], JSON_THROW_ON_ERROR);
        return $nonce . sodium_crypto_secretbox($claro, $nonce, $this->chave);
    }

    /**
     * @return array{pfx: string, senha: string}
     */
    public function decifrar(string $cifrado): array
    {
        $nonce = substr($cifrado, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $corpo = substr($cifrado, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $claro = strlen($nonce) === SODIUM_CRYPTO_SECRETBOX_NONCEBYTES
            ? sodium_crypto_secretbox_open($corpo, $nonce, $this->chave)
            : false;
        if ($claro === false) {
            throw new RuntimeException('Nao foi possivel abrir o certificado guardado no banco: a chave do cadastro mudou. Reenvie o certificado pelo cadastro.');
        }
        $dados = json_decode($claro, true, 512, JSON_THROW_ON_ERROR);
        return ['pfx' => (string)base64_decode((string)$dados['pfx']), 'senha' => (string)$dados['senha']];
    }
}
