<?php
declare(strict_types=1);

namespace Xande\NfeIntegracao\Empresa;

use RuntimeException;

/**
 * Cifra PFX e senha antes de irem para o banco (libsodium secretbox).
 *
 * A chave mestra vem de NFE_MASTER_KEY (variavel de ambiente, base64 de 32 bytes)
 * ou de 'masterKey' em config/nfe.local.php. Perder a chave = recadastrar certificados.
 */
final class CertificadoCofre
{
    private string $key;

    public function __construct(string $keyBase64)
    {
        $key = base64_decode($keyBase64, true);
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new RuntimeException('Chave mestra invalida: esperado base64 de 32 bytes.');
        }
        $this->key = $key;
    }

    public static function fromConfig(array $cfg): self
    {
        $key = (string)(getenv('NFE_MASTER_KEY') ?: ($cfg['masterKey'] ?? ''));
        if ($key === '') {
            throw new RuntimeException('Defina NFE_MASTER_KEY (ou masterKey em config/nfe.local.php).');
        }
        return new self($key);
    }

    public static function gerarChave(): string
    {
        return base64_encode(sodium_crypto_secretbox_keygen());
    }

    public function cifrar(string $plain): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return $nonce . sodium_crypto_secretbox($plain, $nonce, $this->key);
    }

    public function decifrar(string $blob): string
    {
        $nonce = substr($blob, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = substr($blob, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open($cipher, $nonce, $this->key);
        if ($plain === false) {
            throw new RuntimeException('Falha ao decifrar certificado (chave mestra diferente?).');
        }
        return $plain;
    }
}
