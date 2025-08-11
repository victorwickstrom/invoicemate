<?php
namespace Invoicemate\Uploads;

use Invoicemate\Utils\Crypto;

/**
 * Service for handling encrypted file uploads.
 */
class UploadService
{
    private string $uploadsDir;
    private Crypto $crypto;

    public function __construct(string $uploadsDir, Crypto $crypto)
    {
        $this->uploadsDir = rtrim($uploadsDir, '/');
        $this->crypto = $crypto;
        if (!is_dir($this->uploadsDir)) {
            mkdir($this->uploadsDir, 0770, true);
        }
    }

    /**
     * Save an uploaded file, encrypting its contents and returning a unique ID.
     */
    public function saveEncrypted(string $tmpPath, string $originalName): string
    {
        $payload = $this->crypto->encryptFile($tmpPath);
        $id = bin2hex(random_bytes(16));
        $meta = [
            'originalName' => $originalName,
            'iv' => $payload['iv'],
            'ct' => $payload['ct'],
        ];
        $path = $this->uploadsDir . '/' . $id . '.enc';
        file_put_contents($path, json_encode($meta));
        return $id;
    }

    /**
     * Open a decrypted stream for the given file ID.
     */
    public function streamDecrypted(string $id)
    {
        $path = $this->uploadsDir . '/' . $id . '.enc';
        if (!is_file($path)) {
            throw new \RuntimeException('Attachment not found');
        }
        $meta = json_decode(file_get_contents($path), true);
        if (!is_array($meta) || !isset($meta['iv'], $meta['ct'])) {
            throw new \RuntimeException('Invalid attachment metadata');
        }
        $data = $this->crypto->decrypt(['iv' => $meta['iv'], 'ct' => $meta['ct']]);
        $stream = fopen('php://temp', 'rb+');
        fwrite($stream, $data);
        rewind($stream);
        return $stream;
    }
}