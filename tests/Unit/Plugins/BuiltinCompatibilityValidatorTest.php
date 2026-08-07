<?php

namespace Tests\Unit\Plugins;

use App\Plugins\Builtins\BuiltinCompatibilityValidator;
use RuntimeException;
use Tests\TestCase;

class BuiltinCompatibilityValidatorTest extends TestCase
{
    private const IMAGE = 'ghcr.io/wprint3d/cura-web-ui-gateway:0.1.0@sha256:'.'0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

    public function test_it_accepts_a_complete_release_record(): void
    {
        $validator = new BuiltinCompatibilityValidator;

        $validator->validate(
            $this->record(),
            'cura-web-ui',
            '0.1.0',
            str_repeat('a', 64),
            $this->manifest(),
            'cura-web-ui-0.1.0.w3dp',
        );

        $this->addToAssertionCount(1);
    }

    public function test_it_rejects_a_partial_record_without_platforms_or_minimum_core(): void
    {
        $record = $this->record();
        unset($record['minimumWPrintCoreVersion'], $record['runtimeImage']['platforms']);

        $this->expectException(RuntimeException::class);
        (new BuiltinCompatibilityValidator)->validate(
            $record,
            'cura-web-ui',
            '0.1.0',
            str_repeat('a', 64),
            $this->manifest(),
            'cura-web-ui-0.1.0.w3dp',
        );
    }

    public function test_it_rejects_a_record_pointing_at_a_different_image_or_filename(): void
    {
        $record = $this->record();
        $record['runtimeImage']['reference'] = 'ghcr.io/example/other@sha256:'.str_repeat('b', 64);

        $this->expectException(RuntimeException::class);
        (new BuiltinCompatibilityValidator)->validate(
            $record,
            'cura-web-ui',
            '0.1.0',
            str_repeat('a', 64),
            $this->manifest(),
            'cura-web-ui-0.1.0.w3dp',
        );
    }

    public function test_it_rejects_a_signed_manifest_with_a_different_record_signer(): void
    {
        $record = $this->record();
        $manifest = $this->manifest();
        $manifest['signature'] = [
            'algorithm' => 'openssl-sha256',
            'publicKeySha256' => str_repeat('b', 64),
        ];
        $record['w3dp']['signerFingerprint'] = str_repeat('a', 64);

        $this->expectException(RuntimeException::class);
        (new BuiltinCompatibilityValidator)->validate(
            $record,
            'cura-web-ui',
            '0.1.0',
            str_repeat('a', 64),
            $manifest,
            'cura-web-ui-0.1.0.w3dp',
        );
    }

    private function manifest(): array
    {
        return [
            'id' => 'cura-web-ui',
            'version' => '0.1.0',
            'sdkVersion' => 1,
            'sdkRevision' => 5,
            'images' => [['image' => self::IMAGE]],
        ];
    }

    private function record(): array
    {
        return [
            'schemaVersion' => 1,
            'minimumWPrintCoreVersion' => '0.0.0',
            'plugin' => [
                'id' => 'cura-web-ui',
                'version' => '0.1.0',
                'sdkVersion' => 1,
                'sdkRevision' => 5,
            ],
            'runtimeImage' => [
                'reference' => self::IMAGE,
                'platforms' => ['linux/amd64', 'linux/arm64'],
            ],
            'gateway' => [
                'image' => self::IMAGE,
                'platforms' => ['linux/amd64', 'linux/arm64'],
            ],
            'w3dp' => [
                'fileName' => 'cura-web-ui-0.1.0.w3dp',
                'sha256' => str_repeat('a', 64),
            ],
        ];
    }
}
