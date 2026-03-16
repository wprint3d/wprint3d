<?php

namespace Tests\Unit\Plugins;

use App\Plugins\PluginManifestLocalizer;
use App\Plugins\PluginManifestValidator;
use PHPUnit\Framework\TestCase;

class PluginManifestLocalizationTest extends TestCase
{
    public function test_it_allows_manifest_translation_files_declared_as_assets(): void
    {
        $validator = new PluginManifestValidator(null, null, null, null, 1, 1);

        $manifest = $validator->validate([
            'id' => 'acme.demo',
            'name' => 'ACME Demo',
            'version' => '1.2.3',
            'sdkVersion' => 1,
            'runtime' => [
                'type' => 'php',
                'entry' => 'plugin.php',
            ],
            'permissions' => [
                'ui.settings_tab',
            ],
            'assets' => [
                ['path' => 'translations/es.json'],
            ],
            'i18n' => [
                'defaultLocale' => 'en',
                'files' => [
                    'es' => 'asset://translations/es.json',
                ],
            ],
            'uiExtensions' => [
                [
                    'id' => 'settings',
                    'surface' => 'settings_tab',
                    'mode' => 'declarative',
                    'title' => 'Demo',
                    'schema' => [
                        'component' => 'host.section',
                    ],
                ],
            ],
        ]);

        $this->assertSame('asset://translations/es.json', $manifest['i18n']['files']['es']);
        $this->assertSame('en', $manifest['i18n']['defaultLocale']);
    }

    public function test_it_localizes_plugin_payloads_from_translation_files_using_locale_fallbacks(): void
    {
        $localizer = new PluginManifestLocalizer;

        $manifest = [
            'name' => 'Hello World',
            'description' => 'Base description',
            'actions' => [
                [
                    'id' => 'ping',
                    'label' => 'Ping',
                ],
            ],
            'uiExtensions' => [
                [
                    'id' => 'settings',
                    'title' => 'Hello World',
                    'schema' => [
                        'component' => 'host.section',
                        'title' => 'Lightweight declarative UI',
                    ],
                ],
            ],
            'components' => [
                [
                    'id' => 'metricsCard',
                    'kind' => 'remote_component',
                    'schema' => [
                        'component' => 'host.card',
                        'title' => 'CPU',
                    ],
                ],
            ],
        ];

        $translations = [
            'en' => [
                'plugin' => [
                    'name' => 'Hello World',
                ],
            ],
            'es' => [
                'plugin' => [
                    'name' => 'Hola Mundo',
                    'description' => 'Descripción en español',
                ],
                'actions' => [
                    'ping' => [
                        'label' => 'Probar',
                    ],
                ],
                'uiExtensions' => [
                    'settings' => [
                        'title' => 'Configuración',
                        'schema' => [
                            'title' => 'Interfaz declarativa liviana',
                        ],
                    ],
                ],
                'components' => [
                    'metricsCard' => [
                        'schema' => [
                            'title' => 'Procesador',
                        ],
                    ],
                ],
            ],
        ];

        $localized = $localizer->localizeManifest($manifest, $translations, 'es_AR');

        $this->assertSame('Hola Mundo', $localized['name']);
        $this->assertSame('Descripción en español', $localized['description']);
        $this->assertSame('Probar', $localized['actions'][0]['label']);
        $this->assertSame('Configuración', $localized['uiExtensions'][0]['title']);
        $this->assertSame('Interfaz declarativa liviana', $localized['uiExtensions'][0]['schema']['title']);
        $this->assertSame('Procesador', $localized['components'][0]['schema']['title']);
    }
}
