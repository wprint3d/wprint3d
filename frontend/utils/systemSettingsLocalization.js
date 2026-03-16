const SYSTEM_SECTION_KEYS = {
    "System": "system",
    "Connection": "connection",
    "Limits": "limits",
    "Miscellaneous": "miscellaneous",
    "Advanced settings": "advancedSettings",
};

const SYSTEM_ENUM_OPTION_KEYS = {
    BackupInterval: {
        "Every second": "everySecond",
        "Every 5 minutes": "everyFiveMinutes",
        "Never": "never",
    },
};

const readTranslatedValue = (t, key, fallback) => {
    const translated = t(key);

    return translated === key ? fallback : translated;
};

export const localizeSystemSectionLabel = (section, t) => {
    const sectionKey = SYSTEM_SECTION_KEYS[section];

    if (!sectionKey) {
        return section;
    }

    return readTranslatedValue(t, `settings.systemSections.${sectionKey}`, section);
};

export const localizeSystemSettingDefinition = (setting, t) => {
    if (!setting || typeof setting !== "object") {
        return setting;
    }

    return {
        ...setting,
        section: localizeSystemSectionLabel(setting.section, t),
        hint: readTranslatedValue(t, `settings.systemConfig.${setting.key}.hint`, setting.hint),
        description: readTranslatedValue(t, `settings.systemConfig.${setting.key}.description`, setting.description),
    };
};

export const localizeSystemEnumOptions = (enumName, options, t) => {
    const keyMap = SYSTEM_ENUM_OPTION_KEYS[enumName] || {};

    return options.map((option) => {
        const optionKey = keyMap[option];

        if (!optionKey) {
            return option;
        }

        return readTranslatedValue(
            t,
            `settings.systemEnums.${enumName}.${optionKey}`,
            option
        );
    });
};
