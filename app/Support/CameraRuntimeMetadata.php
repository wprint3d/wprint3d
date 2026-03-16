<?php

namespace App\Support;

class CameraRuntimeMetadata
{
    public static function normalize(array $camera): array
    {
        [$resolution, $framerate] = array_pad(explode('@', (string) ($camera['format'] ?? '')), 2, null);

        $supportsMjpeg = (bool) ($camera['supportsMjpeg'] ?? false);
        $requiresLibCamera = (bool) ($camera['requiresLibCamera'] ?? false);
        $captureEncoding = self::normalizeCaptureEncoding(
            $camera['captureEncoding'] ?? null,
            $supportsMjpeg
        );

        $camera['captureEncoding'] = $captureEncoding;
        $camera['streamsMjpeg'] = $requiresLibCamera || self::supportsSoftwareMjpeg($captureEncoding);
        $camera['resolution'] = $resolution;
        $camera['framerate'] = $framerate;

        return $camera;
    }

    public static function normalizeCaptureEncoding(mixed $captureEncoding, bool $supportsMjpeg): ?string
    {
        if (is_string($captureEncoding) && trim($captureEncoding) !== '') {
            return strtoupper(trim($captureEncoding));
        }

        return $supportsMjpeg ? 'MJPG' : null;
    }

    public static function supportsSoftwareMjpeg(?string $captureEncoding): bool
    {
        return in_array(strtoupper((string) $captureEncoding), ['MJPG', 'YUYV'], true);
    }
}
