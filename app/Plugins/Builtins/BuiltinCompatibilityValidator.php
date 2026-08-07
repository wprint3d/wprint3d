<?php

namespace App\Plugins\Builtins;

use RuntimeException;

final class BuiltinCompatibilityValidator
{
    /**
     * Validate the release compatibility record against the archive manifest.
     *
     * A compatibility record is optional for development inventories, but when
     * present it is a complete release identity and must not be a partial,
     * internally inconsistent hint.
     */
    public function validate(
        array $record,
        string $pluginId,
        string $pluginVersion,
        string $archiveSha256,
        array $manifest,
        ?string $archiveFileName = null,
    ): void {
        if (($record['schemaVersion'] ?? null) !== 1) {
            throw new RuntimeException('Built-in compatibility record has an unsupported schema.');
        }

        $plugin = $record['plugin'] ?? null;
        if (! is_array($plugin)
            || (string) ($plugin['id'] ?? '') !== $pluginId
            || (string) ($plugin['version'] ?? '') !== $pluginVersion
            || (int) ($plugin['sdkVersion'] ?? 0) !== (int) ($manifest['sdkVersion'] ?? 0)
            || (int) ($plugin['sdkRevision'] ?? 0) !== (int) ($manifest['sdkRevision'] ?? 0)) {
            throw new RuntimeException('Built-in compatibility record does not match the plugin manifest.');
        }

        if ((string) data_get($record, 'w3dp.sha256', '') !== $archiveSha256) {
            throw new RuntimeException('Built-in compatibility record does not match the archive checksum.');
        }

        $manifestSignature = $manifest['signature'] ?? [];
        if (($manifestSignature['algorithm'] ?? 'none') !== 'none') {
            $manifestFingerprint = strtolower((string) ($manifestSignature['publicKeySha256'] ?? ''));
            $recordFingerprint = strtolower((string) data_get($record, 'w3dp.signerFingerprint', ''));
            if (! preg_match('/^[a-f0-9]{64}$/', $manifestFingerprint)
                || $recordFingerprint !== $manifestFingerprint) {
                throw new RuntimeException('Built-in compatibility record signer fingerprint does not match the signed manifest.');
            }
        }

        if ($archiveFileName !== null) {
            $recordedFileName = (string) data_get($record, 'w3dp.fileName', '');
            if ($recordedFileName !== '' && $recordedFileName !== $archiveFileName) {
                throw new RuntimeException('Built-in compatibility record does not match the archive filename.');
            }
        }

        if (trim((string) ($record['minimumWPrintCoreVersion'] ?? '')) === '') {
            throw new RuntimeException('Built-in compatibility record is missing minimumWPrintCoreVersion.');
        }

        $manifestImage = (string) data_get($manifest, 'images.0.image', '');
        $runtimeImage = $record['runtimeImage'] ?? null;
        $gateway = $record['gateway'] ?? null;
        if (! is_array($runtimeImage) || ! is_array($gateway)) {
            throw new RuntimeException('Built-in compatibility record is missing canonical runtime image metadata.');
        }

        $canonicalImage = (string) ($runtimeImage['reference'] ?? '');
        $legacyImage = (string) ($gateway['image'] ?? '');
        if ($canonicalImage === '' || $legacyImage === '' || $canonicalImage !== $legacyImage || $canonicalImage !== $manifestImage) {
            throw new RuntimeException('Built-in compatibility record does not match the runtime image.');
        }

        foreach (['runtimeImage.platforms', 'gateway.platforms'] as $platformPath) {
            $platforms = data_get($record, $platformPath);
            if (! is_array($platforms) || ! in_array('linux/amd64', $platforms, true) || ! in_array('linux/arm64', $platforms, true)) {
                throw new RuntimeException('Built-in compatibility record must declare linux/amd64 and linux/arm64.');
            }
        }
    }
}
