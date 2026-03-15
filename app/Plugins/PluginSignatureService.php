<?php

namespace App\Plugins;

use App\Plugins\Exceptions\PluginRuntimeException;

class PluginSignatureService
{
    public function signManifest(array $manifest, string $privateKeyPath, ?string $passphrase = null): array
    {
        if (! is_file($privateKeyPath)) {
            throw new PluginRuntimeException("Private signing key not found: {$privateKeyPath}");
        }

        $privateKey = openssl_pkey_get_private(file_get_contents($privateKeyPath), $passphrase ?? '');

        if ($privateKey === false) {
            throw new PluginRuntimeException('Unable to load plugin signing private key.');
        }

        $payload = $this->canonicalPayload($manifest);
        $signature = '';

        if (! openssl_sign($payload, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
            throw new PluginRuntimeException('Failed to sign plugin manifest.');
        }

        $publicKeyDetails = openssl_pkey_get_details($privateKey);
        $publicKey = $this->normalizePublicKeyPem($publicKeyDetails['key'] ?? null);

        if ($publicKey === null) {
            throw new PluginRuntimeException('Unable to derive plugin signing public key.');
        }

        $keyId = $this->keyIdForPublicKey($publicKey);

        $manifest['signature'] = [
            'algorithm' => 'openssl-sha256',
            'keyId' => $keyId,
            'publicKey' => $publicKey,
            'publicKeySha256' => $this->publicKeySha256($publicKey),
            'value' => base64_encode($signature),
        ];

        return $manifest;
    }

    public function verifyManifest(array $manifest, array $publicKeyPaths = []): bool
    {
        $signature = $manifest['signature'] ?? null;

        if (! is_array($signature) || ($signature['algorithm'] ?? 'none') === 'none') {
            return false;
        }

        if (($signature['algorithm'] ?? null) !== 'openssl-sha256') {
            return false;
        }

        $encodedSignature = $signature['value'] ?? null;

        if (! $encodedSignature) {
            return false;
        }

        foreach ($publicKeyPaths as $path) {
            if (! is_file($path)) {
                continue;
            }

            if ($this->verifyManifestWithPublicKeyContents($manifest, (string) file_get_contents($path))) {
                return true;
            }
        }

        return false;
    }

    public function canonicalizeManifest(array $manifest): array
    {
        unset($manifest['signature']);

        return $this->sortRecursive($manifest);
    }

    public function canonicalPayload(array $manifest): string
    {
        return json_encode(
            $this->canonicalizeManifest($manifest),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
        );
    }

    public function embeddedPublicKey(array $manifest): ?string
    {
        return $this->normalizePublicKeyPem($manifest['signature']['publicKey'] ?? null);
    }

    public function verifyManifestWithPublicKeyContents(array $manifest, string $publicKeyContents): bool
    {
        $signature = $manifest['signature'] ?? null;

        if (! is_array($signature) || ($signature['algorithm'] ?? null) !== 'openssl-sha256') {
            return false;
        }

        $encodedSignature = $signature['value'] ?? null;

        if (! is_string($encodedSignature) || $encodedSignature === '') {
            return false;
        }

        $decodedSignature = base64_decode($encodedSignature, true);

        if ($decodedSignature === false) {
            return false;
        }

        $publicKey = openssl_pkey_get_public($publicKeyContents);

        if ($publicKey === false) {
            return false;
        }

        return openssl_verify(
            $this->canonicalPayload($manifest),
            $decodedSignature,
            $publicKey,
            OPENSSL_ALGO_SHA256
        ) === 1;
    }

    public function keyIdForPublicKey(string $publicKeyPem): string
    {
        return sha1($this->normalizePublicKeyPem($publicKeyPem) ?? $publicKeyPem);
    }

    public function publicKeySha256(string $publicKeyPem): string
    {
        return hash('sha256', $this->normalizePublicKeyPem($publicKeyPem) ?? $publicKeyPem);
    }

    private function sortRecursive(array $input): array
    {
        if (array_is_list($input)) {
            return array_map(fn ($value) => is_array($value) ? $this->sortRecursive($value) : $value, $input);
        }

        ksort($input);

        foreach ($input as $key => $value) {
            if (is_array($value)) {
                $input[$key] = $this->sortRecursive($value);
            }
        }

        return $input;
    }

    private function normalizePublicKeyPem(mixed $publicKeyPem): ?string
    {
        if (! is_string($publicKeyPem)) {
            return null;
        }

        $trimmed = trim($publicKeyPem);

        if ($trimmed === '') {
            return null;
        }

        return $trimmed."\n";
    }
}
