<?php

declare(strict_types=1);

namespace Toppy\TwigPrerender\Service;

use Toppy\AsyncViewModel\Context\RequestContext;

/**
 * Encrypts RequestContext for URL transport.
 * Uses authenticated encryption to prevent tampering.
 */
// @mago-ignore analysis:mixed-assignment - json_decode() returns mixed; PHP limitation
final class ContextEncryptor
{
    private const string CIPHER = 'aes-256-gcm';

    public function __construct(
        private readonly string $secretKey,
    ) {}

    /**
     * @throws \Random\RandomException When random_bytes fails to generate IV
     * @throws \JsonException When context cannot be JSON encoded
     * @throws \RuntimeException When OpenSSL encryption fails
     */
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

    /**
     * @throws \RuntimeException When base64 decoding fails, data is invalid, or decryption fails
     * @throws \JsonException When decrypted payload is not valid JSON
     */
    public function decrypt(string $encrypted): RequestContext
    {
        $combined = $this->base64UrlDecode($encrypted);

        if (strlen($combined) < 28) { // 12 IV + 16 tag minimum
            throw new \RuntimeException('Invalid encrypted data');
        }

        $iv = substr($combined, offset: 0, length: 12);
        $tag = substr($combined, offset: 12, length: 16);
        $ciphertext = substr($combined, offset: 28);

        $payload = openssl_decrypt($ciphertext, self::CIPHER, $this->secretKey, OPENSSL_RAW_DATA, $iv, $tag);

        if ($payload === false) {
            throw new \RuntimeException('Decryption failed - data may be tampered');
        }

        $decoded = json_decode($payload, associative: true, depth: 512, flags: JSON_THROW_ON_ERROR);

        if (!is_array($decoded)) {
            throw new \RuntimeException('Invalid payload format');
        }

        /** @var array{'_type'?: string, 'params'?: array<string, mixed>, 'requestId'?: string} $decoded */
        return RequestContext::fromArray($decoded);
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), from: '+/', to: '-_'), characters: '=');
    }

    /**
     * @throws \RuntimeException When base64 decoding fails
     */
    private function base64UrlDecode(string $data): string
    {
        $decoded = base64_decode(strtr($data, from: '-_', to: '+/'), strict: true);

        if ($decoded === false) {
            throw new \RuntimeException('Invalid base64 data');
        }

        return $decoded;
    }
}
