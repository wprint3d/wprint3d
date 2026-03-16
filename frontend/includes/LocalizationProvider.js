import AsyncStorage from "@react-native-async-storage/async-storage";
import { Fragment, createContext, useContext, useEffect, useMemo, useState } from "react";

import { translations } from "../config/translations";
import { setBackendLocale } from "./Backend";
import {
    AUTO_LANGUAGE,
    DEFAULT_LANGUAGE,
    FALLBACK_LANGUAGE,
    LANGUAGE_STORAGE_KEY,
    LANGUAGE_OPTIONS,
    getBrowserLocaleSnapshot,
    getEffectiveLanguageFromLocaleTags,
    getLanguageOption,
    getInitialSelectedLanguage,
    getStoredLanguageSelectionValue,
} from "../utils/localization";

const readByPath = (object, path) => (
    path.split(".").reduce((value, key) => (
        value && typeof value === "object" ? value[key] : undefined
    ), object)
);

const interpolate = (value, params = {}) => {
    if (typeof value !== "string") {
        return value;
    }

    return value.replace(/\{(\w+)\}/g, (_match, key) => (
        params[key] === undefined || params[key] === null ? "" : String(params[key])
    ));
};

export const LocalizationContext = createContext({
    effectiveLanguage: DEFAULT_LANGUAGE,
    selectedLanguage: AUTO_LANGUAGE,
    setSelectedLanguage: async () => {},
    languageOptions: LANGUAGE_OPTIONS,
    getLanguageOption,
    strings: translations.en,
    t: (key, params = {}) => interpolate(readByPath(translations.en, key) || key, params),
});

export const LocalizationProvider = ({ children }) => {
    const [selectedLanguage, setSelectedLanguageState] = useState(() => getInitialSelectedLanguage());
    const [localeSnapshot, setLocaleSnapshot] = useState(() => getBrowserLocaleSnapshot());

    useEffect(() => {
        let isMounted = true;

        (async () => {
            try {
                const storedValue = await AsyncStorage.getItem(LANGUAGE_STORAGE_KEY);
                const persistedLanguage = getStoredLanguageSelectionValue(storedValue);

                if (isMounted && persistedLanguage) {
                    setSelectedLanguageState((currentValue) => (
                        currentValue === persistedLanguage ? currentValue : persistedLanguage
                    ));
                }
            } catch (_error) {}
        })();

        return () => {
            isMounted = false;
        };
    }, []);

    useEffect(() => {
        if (typeof window === "undefined") {
            return undefined;
        }

        const syncLocaleSnapshot = () => setLocaleSnapshot(getBrowserLocaleSnapshot());

        window.addEventListener("languagechange", syncLocaleSnapshot);

        return () => {
            window.removeEventListener("languagechange", syncLocaleSnapshot);
        };
    }, []);

    const effectiveLanguage = getEffectiveLanguageFromLocaleTags(
        selectedLanguage,
        localeSnapshot.deviceLanguageTags,
        localeSnapshot.timeZone,
        localeSnapshot.resolvedLocaleTag
    );

    const strings = translations[effectiveLanguage] || translations[FALLBACK_LANGUAGE] || translations.en;

    useEffect(() => {
        setBackendLocale(effectiveLanguage);

        if (typeof document === "undefined") {
            return;
        }

        const normalizedLanguage = effectiveLanguage.replace("_", "-");
        document.documentElement.lang = normalizedLanguage;

        const metaLanguage = document.querySelector('meta[name="language"]');
        if (metaLanguage) {
            metaLanguage.setAttribute("content", normalizedLanguage);
        }
    }, [effectiveLanguage]);

    const setSelectedLanguage = async (value) => {
        const persistedLanguage = getStoredLanguageSelectionValue(value);
        const nextSelection = persistedLanguage || AUTO_LANGUAGE;

        setSelectedLanguageState(nextSelection);

        try {
            if (persistedLanguage) {
                await AsyncStorage.setItem(LANGUAGE_STORAGE_KEY, persistedLanguage);
                return;
            }

            await AsyncStorage.removeItem(LANGUAGE_STORAGE_KEY);
        } catch (_error) {}
    };

    const t = useMemo(() => (
        (key, params = {}) => {
            const localized = readByPath(strings, key);
            const fallback = readByPath(translations[FALLBACK_LANGUAGE] || translations.en, key);

            return interpolate(localized ?? fallback ?? key, params);
        }
    ), [strings]);

    return (
        <LocalizationContext.Provider
            value={{
                effectiveLanguage,
                selectedLanguage,
                setSelectedLanguage,
                languageOptions: LANGUAGE_OPTIONS,
                getLanguageOption,
                strings,
                t,
            }}
        >
            <Fragment key={effectiveLanguage}>
                {children}
            </Fragment>
        </LocalizationContext.Provider>
    );
};

export const useLocalization = () => useContext(LocalizationContext);
