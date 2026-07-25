<?php

namespace Dashcore\Bridge\Crypto;

class Keypair
{
    public function __construct(
        public readonly string $privateKey,
        public readonly string $publicKey,
    ) {}

    /**
     * Generate a fresh Ed25519 keypair, base64-encoded.
     */
    public static function generate(): self
    {
        $pair = sodium_crypto_sign_keypair();

        return new self(
            privateKey: base64_encode(sodium_crypto_sign_secretkey($pair)),
            publicKey: base64_encode(sodium_crypto_sign_publickey($pair)),
        );
    }
}
