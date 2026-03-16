import { DEFAULT_LANGUAGE, normalizeLanguageTag } from "./localization.js";

const deepClone = (value) => JSON.parse(JSON.stringify(value));

const mergeObjects = (baseValue, overrideValue) => {
    if (Array.isArray(baseValue) || Array.isArray(overrideValue)) {
        return deepClone(overrideValue);
    }

    if (
        baseValue
        && typeof baseValue === "object"
        && overrideValue
        && typeof overrideValue === "object"
    ) {
        const merged = { ...baseValue };

        for (const [key, value] of Object.entries(overrideValue)) {
            merged[key] = mergeObjects(baseValue[key], value);
        }

        return merged;
    }

    return deepClone(overrideValue);
};

const mapById = (items = []) => new Map(
    items
        .filter((item) => item && typeof item === "object" && item.id)
        .map((item) => [item.id, item])
);

export const applyTranslationsToManifest = (manifest, translations = {}) => {
    const localizedManifest = deepClone(manifest || {});

    if (translations.plugin?.name) {
        localizedManifest.name = translations.plugin.name;
    }

    if (translations.plugin?.description) {
        localizedManifest.description = translations.plugin.description;
    }

    if (Array.isArray(localizedManifest.actions) && translations.actions) {
        const actionTranslations = translations.actions || {};

        localizedManifest.actions = localizedManifest.actions.map((action) => {
            const translatedAction = actionTranslations[action.id];

            return translatedAction ? mergeObjects(action, translatedAction) : action;
        });
    }

    if (Array.isArray(localizedManifest.uiExtensions) && translations.uiExtensions) {
        const extensionTranslations = translations.uiExtensions || {};

        localizedManifest.uiExtensions = localizedManifest.uiExtensions.map((extension) => {
            const translatedExtension = extensionTranslations[extension.id];

            return translatedExtension ? mergeObjects(extension, translatedExtension) : extension;
        });
    }

    if (Array.isArray(localizedManifest.components) && translations.components) {
        const componentTranslations = translations.components || {};

        localizedManifest.components = localizedManifest.components.map((component) => {
            const translatedComponent = componentTranslations[component.id];

            return translatedComponent ? mergeObjects(component, translatedComponent) : component;
        });
    }

    return localizedManifest;
};

export const resolveLocalizedManifest = (
    manifest,
    translationsByLocale = {},
    effectiveLanguage = DEFAULT_LANGUAGE,
    fallbackLanguage = DEFAULT_LANGUAGE
) => {
    const normalizedLanguage = normalizeLanguageTag(effectiveLanguage);
    const normalizedFallback = normalizeLanguageTag(fallbackLanguage);
    const [baseLanguage] = normalizedLanguage.split("_");
    const candidateLocales = [
        normalizedFallback,
        baseLanguage,
        normalizedLanguage,
    ].filter(Boolean);

    let localizedManifest = deepClone(manifest || {});

    for (const locale of candidateLocales) {
        const translations = translationsByLocale[locale];

        if (!translations) {
            continue;
        }

        localizedManifest = applyTranslationsToManifest(localizedManifest, translations);
    }

    return localizedManifest;
};
