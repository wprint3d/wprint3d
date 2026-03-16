<?php

namespace App\Plugins;

class PluginManifestLocalizer
{
    public function localizeManifest(array $manifest, array $translationsByLocale = [], ?string $locale = null, string $fallbackLocale = 'en'): array
    {
        $normalizedLocale = $this->normalizeLocale($locale);
        $normalizedFallback = $this->normalizeLocale($fallbackLocale);
        $baseLanguage = explode('_', $normalizedLocale)[0] ?? null;
        $candidateLocales = array_values(array_unique(array_filter([
            $normalizedFallback,
            $baseLanguage,
            $normalizedLocale,
        ])));

        $localizedManifest = $manifest;

        foreach ($candidateLocales as $candidateLocale) {
            $translations = $translationsByLocale[$candidateLocale] ?? null;

            if (! is_array($translations)) {
                continue;
            }

            $localizedManifest = $this->applyTranslationsToManifest($localizedManifest, $translations);
        }

        return $localizedManifest;
    }

    public function applyTranslationsToManifest(array $manifest, array $translations): array
    {
        if (is_string($translations['plugin']['name'] ?? null)) {
            $manifest['name'] = $translations['plugin']['name'];
        }

        if (is_string($translations['plugin']['description'] ?? null)) {
            $manifest['description'] = $translations['plugin']['description'];
        }

        if (isset($manifest['actions']) && is_array($manifest['actions']) && is_array($translations['actions'] ?? null)) {
            $manifest['actions'] = array_map(function (array $action) use ($translations) {
                $translatedAction = $translations['actions'][$action['id'] ?? ''] ?? null;

                return is_array($translatedAction)
                    ? $this->mergeRecursive($action, $translatedAction)
                    : $action;
            }, $manifest['actions']);
        }

        if (isset($manifest['uiExtensions']) && is_array($manifest['uiExtensions']) && is_array($translations['uiExtensions'] ?? null)) {
            $manifest['uiExtensions'] = array_map(function (array $extension) use ($translations) {
                $translatedExtension = $translations['uiExtensions'][$extension['id'] ?? ''] ?? null;

                return is_array($translatedExtension)
                    ? $this->mergeRecursive($extension, $translatedExtension)
                    : $extension;
            }, $manifest['uiExtensions']);
        }

        if (isset($manifest['components']) && is_array($manifest['components']) && is_array($translations['components'] ?? null)) {
            $manifest['components'] = array_map(function (array $component) use ($translations) {
                $translatedComponent = $translations['components'][$component['id'] ?? ''] ?? null;

                return is_array($translatedComponent)
                    ? $this->mergeRecursive($component, $translatedComponent)
                    : $component;
            }, $manifest['components']);
        }

        return $manifest;
    }

    private function mergeRecursive(mixed $baseValue, mixed $overrideValue): mixed
    {
        if (! is_array($baseValue) || ! is_array($overrideValue) || array_is_list($baseValue) || array_is_list($overrideValue)) {
            return $overrideValue;
        }

        $merged = $baseValue;

        foreach ($overrideValue as $key => $value) {
            $merged[$key] = array_key_exists($key, $merged)
                ? $this->mergeRecursive($merged[$key], $value)
                : $value;
        }

        return $merged;
    }

    private function normalizeLocale(?string $locale): string
    {
        if (! is_string($locale) || trim($locale) === '') {
            return 'en';
        }

        [$language, $region] = array_pad(explode('_', str_replace('-', '_', trim($locale)), 2), 2, null);
        $language = strtolower((string) $language);
        $region = $region ? strtoupper($region) : null;

        return $region ? "{$language}_{$region}" : $language;
    }
}
