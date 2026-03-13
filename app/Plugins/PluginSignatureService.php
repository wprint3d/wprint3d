<?php

namespace App\Plugins;

use App\Plugins\Exceptions\PluginRuntimeException;

class PluginSignatureService
{
    public function signManifest(array $manifest, string $privateKeyPath, ?string $passphrase = null): array
    {
        if (!is_file($privateKeyPath)) {
            throw new PluginRuntimeException("Private signing key not found: {$privateKeyPath}");
        }

        $privateKey = openssl_pkey_get_private(file_get_contents($privateKeyPath), $passphrase ?? '');

        if ($privateKey === false) {
            throw new PluginRuntimeException('Unable to load plugin signing private key.');
        }

        $payload = $this->canonicalPayload($manifest);
        $signature = '';

        if (!openssl_sign($payload, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
            throw new PluginRuntimeException('Failed to sign plugin manifest.');
        }

        $publicKeyDetails = openssl_pkey_get_details($privateKey);
        $keyId = sha1($publicKeyDetails['key'] ?? basename($privateKeyPath));

        $manifest['signature'] = [
            'algorithm' => 'openssl-sha256',
            'keyId' => $keyId,
            'value' => base64_encode($signature),
        ];

        return $manifest;
    }

    public function verifyManifest(array $manifest, array $publicKeyPaths = []): bool
    {
        $signature = $manifest['signature'] ?? null;

        if (!is_array($signature) || ($signature['algorithm'] ?? 'none') === 'none') {
            return false;
        }

        if (($signature['algorithm'] ?? null) !== 'openssl-sha256') {
            return false;
        }

        $encodedSignature = $signature['value'] ?? null;

        if (!$encodedSignature) {
            return false;
        }

        foreach ($publicKeyPaths as $path) {
            if (!is_file($path)) {
                continue;
            }

            $publicKey = openssl_pkey_get_public(file_get_contents($path));

            if ($publicKey === false) {
                continue;
            }

            $result = openssl_verify(
                $this->canonicalPayload($manifest),
                base64_decode($encodedSignature, true) ?: '',
                $publicKey,
                OPENSSL_ALGO_SHA256
            );

            if ($result === 1) {
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
}
