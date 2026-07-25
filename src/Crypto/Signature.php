<?php

namespace Dashcore\Bridge\Crypto;

class Signature
{
    /**
     * Sign a canonical string with a base64-encoded Ed25519 private key.
     */
    public static function sign(string $canonical, string $privateKey): string
    {
        return base64_encode(
            sodium_crypto_sign_detached($canonical, base64_decode($privateKey))
        );
    }

    /**
     * Verify a base64 signature over a canonical string against a
     * base64-encoded Ed25519 public key.
     */
    public static function verify(string $canonical, string $signature, string $publicKey): bool
    {
        $decodedSignature = base64_decode($signature, strict: true);
        $decodedKey = base64_decode($publicKey, strict: true);

        if ($decodedSignature === false || $decodedKey === false) {
            return false;
        }

        if (strlen($decodedSignature) !== SODIUM_CRYPTO_SIGN_BYTES || strlen($decodedKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            return false;
        }

        return sodium_crypto_sign_verify_detached($decodedSignature, $canonical, $decodedKey);
    }
}
