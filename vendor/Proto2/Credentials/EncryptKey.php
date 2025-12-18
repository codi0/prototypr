<?php

namespace Proto2\Credentials;

class EncryptKey
{

    private string $privateKeyPath;

    public function __construct(string $privateKeyPath)
    {
        $this->privateKeyPath = $privateKeyPath;
    }

    /**
     * Returns the public encryption key as a JWK
     */
    public function getPublicJwk(): array
    {
        $key = $this->loadOrCreateKey();
        $details = openssl_pkey_get_details($key);

        if (!$details || empty($details['ec'])) {
            throw new \RuntimeException('Invalid EC key');
        }

        return [
            'kty' => 'EC',
            'crv' => 'P-256',
            'x'   => $this->b64urlEncode($details['ec']['x']),
            'y'   => $this->b64urlEncode($details['ec']['y']),
            'use' => 'enc',
            'alg' => 'ECDH-ES',
        ];
    }

    /**
     * Returns the private key PEM (for decryption)
     */
    public function getPrivateKeyPem(): string
    {
        $key = $this->loadOrCreateKey();
        openssl_pkey_export($key, $pem);
        return $pem;
    }

    /**
     * Load existing key or create a new one
     */
    private function loadOrCreateKey()
    {
        if (file_exists($this->privateKeyPath)) {
            $pem = file_get_contents($this->privateKeyPath);
            $key = openssl_pkey_get_private($pem);
            if ($key !== false) {
                return $key;
            }
        }

        // Create new EC P-256 key
        $key = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name'       => 'prime256v1',
        ]);

        if (!$key) {
            throw new \RuntimeException('Failed to generate EC key');
        }

        openssl_pkey_export($key, $pem);
        file_put_contents($this->privateKeyPath, $pem, LOCK_EX);

        return $key;
    }

    private function b64urlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

}