<?php
namespace Invoicemate\Utils;

/**
 * Simple JWT helper for HS256 token encoding/decoding.
 *
 * The token payload should at minimum include `exp` (expiry timestamp).
 */
class JWT
{
    private string $secret;

    public function __construct(string $secret)
    {
        $this->secret = $secret;
    }

    /**
     * Encode an array of claims into a JWT string.
     */
    public function encode(array $claims): string
    {
        $header = ['alg' => 'HS256', 'typ' => 'JWT'];
        $segments = [
            $this->base64UrlEncode(json_encode($header)),
            $this->base64UrlEncode(json_encode($claims)),
        ];
        $signingInput = implode('.', $segments);
        $signature = hash_hmac('sha256', $signingInput, $this->secret, true);
        $segments[] = $this->base64UrlEncode($signature);
        return implode('.', $segments);
    }

    /**
     * Decode a JWT string into an array of claims.
     *
     * Throws \RuntimeException on invalid token or expired token.
     */
    public function decode(string $jwt): array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            throw new \RuntimeException('Invalid token format');
        }
        [$encHeader, $encPayload, $encSignature] = $parts;
        $signature = $this->base64UrlDecode($encSignature);
        $signingInput = $encHeader . '.' . $encPayload;
        $expected = hash_hmac('sha256', $signingInput, $this->secret, true);
        if (!hash_equals($expected, $signature)) {
            throw new \RuntimeException('Invalid token signature');
        }
        $payloadJson = $this->base64UrlDecode($encPayload);
        $claims = json_decode($payloadJson, true);
        if (!is_array($claims)) {
            throw new \RuntimeException('Invalid token payload');
        }
        if (isset($claims['exp']) && time() >= $claims['exp']) {
            throw new \RuntimeException('Token expired');
        }
        return $claims;
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $data): string
    {
        $remainder = strlen($data) % 4;
        if ($remainder) {
            $data .= str_repeat('=', 4 - $remainder);
        }
        return base64_decode(strtr($data, '-_', '+/'));
    }
}