<?php

declare(strict_types=1);

namespace Toppy\TwigPrerender\Service;

use Toppy\AsyncViewModel\Context\RequestContext;

/**
 * Encrypts RequestContext for URL transport.
 * Uses authenticated encryption to prevent tampering.
 */
final class ContextEncryptor
{
    private const CIPHER = 'aes-256-gcm';

    public function __construct(
        private readonly string $secretKey,
    ) {}

    public function encrypt(RequestContext $context): string
    {
        $payload = json_encode($context->toArray(), JSON_THROW_ON_ERROR);

        $iv = random_bytes(12);
        $tag = '';

        $encrypted = openssl_encrypt(
            data: $payload,
            cipher_algo: self::CIPHER,
            passphrase: $this->secretKey,
            options: OPENSSL_RAW_DATA,
            iv: $iv,
            tag: $tag,
            aad: '',
            tag_length: 16,
        );

        if ($encrypted === false) {
            throw new \RuntimeException('Encryption failed');
        }

        // Combine IV + tag + ciphertext and base64url encode
        $combined = $iv . $tag . $encrypted;

        return $this->base64UrlEncode($combined);
    }

    public function decrypt(string $encrypted): RequestContext
    {
        $combined = $this->base64UrlDecode($encrypted);

        if (strlen($combined) < 28) { // 12 IV + 16 tag minimum
            throw new \RuntimeException('Invalid encrypted data');
        }

        $iv = substr($combined, 0, 12);
        $tag = substr($combined, 12, 16);
        $ciphertext = substr($combined, 28);

        $payload = openssl_decrypt($ciphertext, self::CIPHER, $this->secretKey, OPENSSL_RAW_DATA, $iv, $tag);

        if ($payload === false) {
            throw new \RuntimeException('Decryption failed - data may be tampered');
        }

        $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($decoded)) {
            throw new \RuntimeException('Invalid payload format');
        }

        return RequestContext::fromArray($decoded);
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $data): string
    {
        $decoded = base64_decode(strtr($data, '-_', '+/'), true);

        if ($decoded === false) {
            throw new \RuntimeException('Invalid base64 data');
        }

        return $decoded;
    }
}
