<?php
namespace Invoicemate\Utils;

/**
 * Simple AES-256-CBC encryption/decryption helper.
 *
 * Uses OpenSSL to encrypt binary data with a 256-bit key.
 */
class Crypto
{
    private string $key;

    public function __construct(string $key)
    {
        // Ensure the key is 32 bytes (256 bits). If shorter, pad; if longer, truncate.
        if (strlen($key) < 32) {
            $key = str_pad($key, 32, '\0');
        } elseif (strlen($key) > 32) {
            $key = substr($key, 0, 32);
        }
        $this->key = $key;
    }

    /**
     * Encrypt the contents of a file and return an array with iv and ciphertext.
     */
    public function encryptFile(string $filePath): array
    {
        $data = file_get_contents($filePath);
        if ($data === false) {
            throw new \RuntimeException('Failed to read file for encryption');
        }
        $iv = random_bytes(openssl_cipher_iv_length('aes-256-cbc'));
        $ciphertext = openssl_encrypt($data, 'aes-256-cbc', $this->key, OPENSSL_RAW_DATA, $iv);
        return [
            'iv' => base64_encode($iv),
            'ct' => base64_encode($ciphertext),
        ];
    }

    /**
     * Decrypt an encrypted payload and return plain data.
     */
    public function decrypt(array $payload): string
    {
        $iv = base64_decode($payload['iv']);
        $ct = base64_decode($payload['ct']);
        $plaintext = openssl_decrypt($ct, 'aes-256-cbc', $this->key, OPENSSL_RAW_DATA, $iv);
        if ($plaintext === false) {
            throw new \RuntimeException('Decryption failed');
        }
        return $plaintext;
    }
}