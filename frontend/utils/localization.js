export const AUTO_LANGUAGE = "auto";
export const DEFAULT_LANGUAGE = "en";
export const FALLBACK_LANGUAGE = "en";
export const LANGUAGE_STORAGE_KEY = "wprint3d.language";

export const LANGUAGE_OPTIONS = [
    { value: AUTO_LANGUAGE, flag: "🌐", nativeLabel: "Auto" },
    { value: "en", flag: "🇺🇸", nativeLabel: "English" },
    { value: "es", flag: "🇪🇸", nativeLabel: "Español" },
    { value: "fr", flag: "🇫🇷", nativeLabel: "Français" },
    { value: "pt", flag: "🇵🇹", nativeLabel: "Português" },
    { value: "it", flag: "🇮🇹", nativeLabel: "Italiano" },
    { value: "de", flag: "🇩🇪", nativeLabel: "Deutsch" },
    { value: "es_AR", flag: "🇦🇷", nativeLabel: "Español (Argentina)" },
];

export const SUPPORTED_LANGUAGES = new Set(
    LANGUAGE_OPTIONS
        .map((option) => option.value)
        .filter((value) => value !== AUTO_LANGUAGE)
);

const ARGENTINA_TIMEZONE_ALIASES = new Set([
    "America/Buenos_Aires",
    "America/Catamarca",
    "America/Cordoba",
    "America/Jujuy",
    "America/Mendoza",
]);

export const normalizeLanguageTag = (value) => {
    if (typeof value !== "string") {
        return "";
    }

    const [rawLanguage = "", rawRegion = ""] = value.replace("-", "_").split("_");
    const language = rawLanguage.toLowerCase();
    const region = rawRegion.toUpperCase();

    return region ? `${language}_${region}` : language;
};

const isSpanishLanguageTag = (value) => normalizeLanguageTag(value).startsWith("es");

const isArgentinaTimeZone = (timeZone) => (
    typeof timeZone === "string" && (
        timeZone.startsWith("America/Argentina/")
        || ARGENTINA_TIMEZONE_ALIASES.has(timeZone)
    )
);

export const resolveSupportedLanguage = (languageTag) => {
    const normalized = normalizeLanguageTag(languageTag);

    if (!normalized) {
        return DEFAULT_LANGUAGE;
    }

    if (normalized === "es_AR") {
        return "es_AR";
    }

    const [language] = normalized.split("_");

    if (SUPPORTED_LANGUAGES.has(language)) {
        return language;
    }

    return DEFAULT_LANGUAGE;
};

export const resolveSupportedLanguageFromLocaleTags = (languageTags = []) => {
    const normalizedTags = Array.isArray(languageTags)
        ? languageTags.map(normalizeLanguageTag).filter(Boolean)
        : [];

    for (const normalized of normalizedTags) {
        if (SUPPORTED_LANGUAGES.has(normalized)) {
            return normalized;
        }
    }

    for (const normalized of normalizedTags) {
        const [language] = normalized.split("_");

        if (SUPPORTED_LANGUAGES.has(language)) {
            return language;
        }
    }

    return DEFAULT_LANGUAGE;
};

const shouldUseArgentinianSpanish = (normalizedTags, timeZone, resolvedLocaleTag) => {
    if (!isArgentinaTimeZone(timeZone)) {
        return false;
    }

    if (isSpanishLanguageTag(resolvedLocaleTag)) {
        return true;
    }

    return normalizedTags.includes("es_419") || normalizedTags.includes("es_AR");
};

export const getStoredLanguageSelectionValue = (selectedLanguage) => {
    if (typeof selectedLanguage !== "string") {
        return null;
    }

    const normalized = normalizeLanguageTag(selectedLanguage);

    if (!normalized || normalized === AUTO_LANGUAGE) {
        return null;
    }

    return SUPPORTED_LANGUAGES.has(normalized) ? normalized : null;
};

export const getInitialSelectedLanguage = (storage = globalThis.localStorage) => {
    try {
        return getStoredLanguageSelectionValue(storage?.getItem?.(LANGUAGE_STORAGE_KEY)) || AUTO_LANGUAGE;
    } catch (_error) {
        return AUTO_LANGUAGE;
    }
};

export const getEffectiveLanguage = (selectedLanguage, deviceLanguageTag) => {
    const manualLanguage = getStoredLanguageSelectionValue(selectedLanguage);

    if (manualLanguage) {
        return manualLanguage;
    }

    return resolveSupportedLanguage(deviceLanguageTag);
};

export const getEffectiveLanguageFromLocaleTags = (
    selectedLanguage,
    deviceLanguageTags = [],
    timeZone = null,
    resolvedLocaleTag = null
) => {
    const manualLanguage = getStoredLanguageSelectionValue(selectedLanguage);

    if (manualLanguage) {
        return manualLanguage;
    }

    const normalizedTags = Array.isArray(deviceLanguageTags)
        ? deviceLanguageTags.map(normalizeLanguageTag).filter(Boolean)
        : [];

    if (shouldUseArgentinianSpanish(normalizedTags, timeZone, resolvedLocaleTag)) {
        return "es_AR";
    }

    return resolveSupportedLanguageFromLocaleTags(normalizedTags);
};

export const getLanguageOption = (value) => (
    LANGUAGE_OPTIONS.find((option) => option.value === value) || LANGUAGE_OPTIONS[1]
);

export const getBrowserLocaleSnapshot = () => {
    const navigatorLanguages = Array.isArray(globalThis.navigator?.languages)
        ? globalThis.navigator.languages
        : [];
    const navigatorLanguage = globalThis.navigator?.language ? [globalThis.navigator.language] : [];
    const resolvedLocaleTag = (
        typeof globalThis.Intl !== "undefined"
            ? globalThis.Intl.DateTimeFormat().resolvedOptions().locale || null
            : null
    );
    const timeZone = (
        typeof globalThis.Intl !== "undefined"
            ? globalThis.Intl.DateTimeFormat().resolvedOptions().timeZone || null
            : null
    );

    return {
        deviceLanguageTags: [...navigatorLanguages, ...navigatorLanguage, resolvedLocaleTag].filter(Boolean),
        resolvedLocaleTag,
        timeZone,
    };
};
