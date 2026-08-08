const test = require('node:test');
const assert = require('node:assert/strict');

const {
    createStartupTranslator,
    resolveStartupLanguage,
    translations
} = require('./startup-localization.js');

const SUPPORTED_LOCALES = ['en', 'es', 'es_AR', 'fr', 'pt', 'it', 'de'];

test('provides every startup translation key for every supported locale', () => {
    assert.deepEqual(Object.keys(translations).sort(), [...SUPPORTED_LOCALES].sort());

    const englishKeys = Object.keys(translations.en).sort();

    for (const locale of SUPPORTED_LOCALES) {
        assert.deepEqual(
            Object.keys(translations[locale]).sort(),
            englishKeys,
            `Translation keys differ for ${locale}`
        );

        for (const key of englishKeys) {
            assert.equal(typeof translations[locale][key], 'string', `${locale}.${key} must be a string`);
            assert.notEqual(translations[locale][key].trim(), '', `${locale}.${key} must not be empty`);
        }
    }
});

test('resolves the saved language before browser autodetection', () => {
    const language = resolveStartupLanguage({
        storage: { getItem: () => 'de' },
        navigatorObject: { languages: ['es-AR'], language: 'es-AR' },
        intlObject: {
            DateTimeFormat: () => ({
                resolvedOptions: () => ({ locale: 'es-AR', timeZone: 'America/Argentina/Cordoba' })
            })
        }
    });

    assert.equal(language, 'de');
});

test('autodetects Argentinian Spanish and falls back by base language', () => {
    const makeIntl = (locale, timeZone) => ({
        DateTimeFormat: () => ({ resolvedOptions: () => ({ locale, timeZone }) })
    });

    assert.equal(resolveStartupLanguage({
        storage: { getItem: () => null },
        navigatorObject: { languages: ['es-419'] },
        intlObject: makeIntl('es-419', 'America/Buenos_Aires')
    }), 'es_AR');

    assert.equal(resolveStartupLanguage({
        storage: { getItem: () => 'auto' },
        navigatorObject: { languages: ['fr-CA'] },
        intlObject: makeIntl('fr-CA', 'America/Toronto')
    }), 'fr');
});

test('interpolates localized diagnostic hints without falling back to English', () => {
    const spanish = createStartupTranslator('es');
    const german = createStartupTranslator('de');

    assert.match(spanish('hintCpuMetro', { process: 'Metro' }), /CPU.*Metro.*bundle/);
    assert.match(german('metricsDelayed'), /Host-Daten/);
    assert.equal(spanish('progressValue', { running: 8, total: 11 }), '8 de 11 servicios activos');
});
