import test from "node:test";
import assert from "node:assert/strict";

import {
    AUTO_LANGUAGE,
    LANGUAGE_OPTIONS,
    getEffectiveLanguageFromLocaleTags,
    getInitialSelectedLanguage,
    getStoredLanguageSelectionValue,
    resolveSupportedLanguage,
} from "../utils/localization.js";
import { translations } from "../config/translations.js";
import {
    applyTranslationsToManifest,
    resolveLocalizedManifest,
} from "../utils/pluginLocalization.js";
import {
    localizeSystemEnumOptions,
    localizeSystemSectionLabel,
    localizeSystemSettingDefinition,
} from "../utils/systemSettingsLocalization.js";

const readTranslation = (locale, path) => (
    path.split(".").reduce((value, key) => (
        value && typeof value === "object" ? value[key] : undefined
    ), translations[locale])
);

const makeTranslator = (locale) => (
    (key, params = {}) => {
        const value = readTranslation(locale, key);

        if (typeof value !== "string") {
            return key;
        }

        return value.replace(/\{(\w+)\}/g, (_match, paramKey) => (
            params[paramKey] === undefined || params[paramKey] === null
                ? ""
                : String(params[paramKey])
        ));
    }
);

test("localization exposes the requested language options including autodetection", () => {
    assert.deepEqual(
        LANGUAGE_OPTIONS.map((option) => option.value),
        [
            AUTO_LANGUAGE,
            "en",
            "es",
            "fr",
            "pt",
            "it",
            "de",
            "es_AR",
        ]
    );
});

test("localization maps browser locales to the supported language set", () => {
    assert.equal(resolveSupportedLanguage("es-AR"), "es_AR");
    assert.equal(resolveSupportedLanguage("es_MX"), "es");
    assert.equal(resolveSupportedLanguage("pt-BR"), "pt");
    assert.equal(resolveSupportedLanguage("de-DE"), "de");
    assert.equal(resolveSupportedLanguage("ja-JP"), "en");
});

test("localization preserves Argentina autodetection edge cases", () => {
    assert.equal(
        getEffectiveLanguageFromLocaleTags(
            AUTO_LANGUAGE,
            ["es-419", "en-US"],
            "America/Argentina/Buenos_Aires"
        ),
        "es_AR"
    );

    assert.equal(
        getEffectiveLanguageFromLocaleTags(
            AUTO_LANGUAGE,
            ["en-US", "es-US", "es", "en"],
            "America/Buenos_Aires",
            "es-MX"
        ),
        "es_AR"
    );
});

test("localization only persists explicit non-auto selections", () => {
    assert.equal(getStoredLanguageSelectionValue("de"), "de");
    assert.equal(getStoredLanguageSelectionValue(AUTO_LANGUAGE), null);
    assert.equal(getStoredLanguageSelectionValue("unknown"), null);
});

test("localization reads the initial saved language synchronously when storage is available", () => {
    assert.equal(
        getInitialSelectedLanguage({
            getItem: () => "es-AR",
        }),
        "es_AR"
    );

    assert.equal(
        getInitialSelectedLanguage({
            getItem: () => "unknown",
        }),
        AUTO_LANGUAGE
    );
});

test("application translations preserve accented characters and ñ", () => {
    assert.equal(translations.es.language.label, "Idioma");
    assert.match(translations.es.login.forgotPasswordHint, /contraseña/i);
    assert.match(translations.es_AR.login.prompt, /podés/i);
    assert.equal(translations.es.settings.presetsTab, "Materiales");
    assert.equal(translations.es_AR.settings.presetsTab, "Materiales");
    assert.equal(translations.es_AR.camera.edit, "Editar");
    assert.equal(translations.es_AR.camera.delete, "Eliminar");
    assert.equal(translations.es_AR.presets.noMaterials, "No hay materiales disponibles");
    assert.equal(translations.es_AR.presets.add, "Agregar");
    assert.match(translations.fr.language.autoDetect, /Détection/);
    assert.match(translations.pt.login.welcomeBack, /Bem-vindo/);
    assert.match(translations.de.login.submit, /Anmelden/);
});

test("application translations expose the remaining rollout section roots for every supported locale", () => {
    const locales = ["en", "es", "es_AR", "fr", "pt", "it", "de"];
    const requiredSections = [
        "password",
        "camera",
        "printer",
        "files",
        "recordings",
        "presets",
        "settings",
        "users",
        "plugins",
        "about",
        "notifications",
    ];

    for (const locale of locales) {
        for (const section of requiredSections) {
            assert.ok(
                translations[locale]?.[section],
                `Missing translation section "${section}" for locale "${locale}"`
            );
        }
    }
});

test("application translations include password flow keys for every supported locale", () => {
    const locales = ["en", "es", "es_AR", "fr", "pt", "it", "de"];
    const requiredPasswordKeys = [
        "title",
        "description",
        "currentPassword",
        "newPassword",
        "repeatNewPassword",
        "logoutOtherDevices",
        "saveChange",
        "saved",
        "saveError",
        "dismiss",
    ];

    for (const locale of locales) {
        for (const key of requiredPasswordKeys) {
            assert.ok(
                translations[locale]?.password?.[key],
                `Missing password translation key "${key}" for locale "${locale}"`
            );
        }
    }
});

test("application translations include camera flow keys for every supported locale", () => {
    const locales = ["en", "es", "es_AR", "fr", "pt", "it", "de"];
    const requiredCameraKeys = [
        "settingsTab",
        "statusSection",
        "connected",
        "enabled",
        "qualitySection",
        "format",
        "formatDescription",
        "miscSection",
        "requiresLibcamera",
        "url",
        "warningSection",
        "mjpegWarningTitle",
        "mjpegWarningDescription",
        "deleteTitle",
        "deleteBody",
        "preview",
        "edit",
        "delete",
        "close",
        "noPreview",
        "online",
        "offline",
        "slowMode",
        "cannotDeleteOnline",
        "previewingCamera",
        "notConnected",
        "notWorking",
        "bufferingStream",
    ];

    for (const locale of locales) {
        for (const key of requiredCameraKeys) {
            assert.ok(
                translations[locale]?.camera?.[key],
                `Missing camera translation key "${key}" for locale "${locale}"`
            );
        }
    }
});

test("application translations include files runtime keys for every supported locale", () => {
    const locales = ["en", "es", "es_AR", "fr", "pt", "it", "de"];
    const requiredFileKeys = [
        "files.startPrintError",
        "files.pausePrintError",
        "files.resumePrintError",
        "files.stopPrintError",
        "files.deleteFileError",
        "files.renameFileError",
        "files.createFolderError",
        "files.deleteFolderError",
        "files.options",
        "files.upload",
        "files.subdirectory",
        "files.goUp",
        "files.goHome",
        "files.sortBy",
        "files.createFolder",
        "files.loadingFilesList",
        "files.gettingSortingModes",
        "files.emptyRoot",
        "files.emptySubdirectory",
        "files.startConfirmTitle",
        "files.startConfirmBody",
        "files.renameTitle",
        "files.folderTitle",
    ];

    for (const locale of locales) {
        for (const key of requiredFileKeys) {
            assert.ok(
                readTranslation(locale, key),
                `Missing files translation key "${key}" for locale "${locale}"`
            );
        }
    }
});

test("application translations include terminal, recordings, and recovery keys for every supported locale", () => {
    const locales = ["en", "es", "es_AR", "fr", "pt", "it", "de"];
    const requiredKeys = [
        "printer.terminal.loadingSelectedPrinter",
        "printer.terminal.downloadingConsoleLog",
        "printer.terminal.gettingTerminalConfig",
        "printer.terminal.nothingHere",
        "printer.terminal.customCommandLabel",
        "printer.terminal.autoScrollToBottom",
        "printer.terminal.serialDriverError",
        "recordings.loading",
        "recordings.renderingProgress",
        "recordings.emptyEnabled",
        "recordings.emptyDisabled",
        "recordings.playingTitle",
        "recordings.deleteConfirmTitle",
        "recordings.thumbnailLoadError",
        "printer.recovery.title",
        "printer.recovery.loadingSettings",
        "printer.recovery.loadSettingsError",
        "printer.recovery.disabled",
        "printer.recovery.previewTitle",
        "printer.recovery.continueFromLine",
        "printer.recovery.adjustDescription",
        "printer.recovery.aboutTitle",
        "printer.recovery.cancelRecovery",
        "notifications.gotIt",
        "notifications.dismiss",
        "notifications.cancel",
        "notifications.delete",
        "notifications.close",
    ];

    for (const locale of locales) {
        for (const key of requiredKeys) {
            assert.ok(
                readTranslation(locale, key),
                `Missing translation key "${key}" for locale "${locale}"`
            );
        }
    }
});

test("application translations include printer control and status keys for every supported locale", () => {
    const locales = ["en", "es", "es_AR", "fr", "pt", "it", "de"];
    const requiredKeys = [
        "printer.preparingActionsMenu",
        "printer.status.waitingForServer",
        "printer.status.connecting",
        "printer.status.offline",
        "printer.status.online",
        "printer.status.connectionStatus",
        "printer.status.bedLabel",
        "printer.status.extruderLabel",
        "printer.status.targeting",
        "printer.controls.failedToSendCommand",
        "printer.controls.movement",
        "printer.controls.feedrate",
        "printer.controls.distance",
        "printer.controls.unitsPerSecond",
        "printer.controls.millimeters",
        "printer.controls.extrusion",
        "printer.controls.retract",
        "printer.controls.extrude",
        "printer.controls.noExtrudersAvailable",
        "printer.controls.extruderOption",
        "printer.controls.extruder",
        "printer.controls.temperature",
        "printer.controls.hotend",
        "printer.controls.bed",
        "printer.controls.material",
        "printer.controls.loadingMaterials",
        "printer.controls.materialsLoadError",
        "printer.controls.noMaterialsDefined",
        "printer.controls.unknownMaterial",
        "printer.controls.warmUp",
    ];

    for (const locale of locales) {
        for (const key of requiredKeys) {
            assert.ok(
                readTranslation(locale, key),
                `Missing translation key "${key}" for locale "${locale}"`
            );
        }
    }
});

test("application translations include settings, presets, users, and about keys for every supported locale", () => {
    const locales = ["en", "es", "es_AR", "fr", "pt", "it", "de"];
    const requiredKeys = [
        "settings.printersTab",
        "settings.detailsTab",
        "settings.linkingTab",
        "settings.presetsTab",
        "settings.camerasTab",
        "settings.recordingTab",
        "settings.systemTab",
        "settings.usersTab",
        "settings.pluginsTab",
        "settings.developerTab",
        "settings.loggingTab",
        "settings.fakeSerialTab",
        "settings.aboutTab",
        "settings.loadingPrinters",
        "settings.loadingPrinterDetails",
        "settings.baudRateValue",
        "settings.noPrinters",
        "settings.loadingCameras",
        "settings.noCameras",
        "settings.troubleshooting",
        "settings.printerDetailsLoadError",
        "settings.printerMeta.firmwareName",
        "settings.printerMeta.connectionType",
        "settings.printerMeta.simulated",
        "settings.printerMetaValues.connectionType.fakeSerial",
        "settings.printerCapabilities.binaryFileTransfer",
        "settings.loadingRecordingSettings",
        "settings.saveChanges",
        "settings.checkForUpdates",
        "settings.noUpdatesFound",
        "settings.nothingToDo",
        "presets.loadingMaterials",
        "presets.noMaterials",
        "presets.add",
        "presets.addPreset",
        "presets.deleteTitle",
        "presets.materialLabel",
        "users.loadingRoles",
        "users.loadingUsers",
        "users.noUsers",
        "users.addUser",
        "users.resetPassword",
        "users.createUserSuccess",
        "users.saveUserSuccess",
        "users.usernameLabel",
        "users.emailAddressLabel",
        "users.roleLabel",
        "users.createUserCta",
        "users.deleteConfirmTitle",
        "about.downloadingLicenses",
        "about.downloadError",
        "about.noLicenses",
        "about.openSourceBlurb",
    ];

    for (const locale of locales) {
        for (const key of requiredKeys) {
            assert.ok(
                readTranslation(locale, key),
                `Missing translation key "${key}" for locale "${locale}"`
            );
        }
    }
});

test("application translations include plugin and developer tooling keys for every supported locale", () => {
    const locales = ["en", "es", "es_AR", "fr", "pt", "it", "de"];
    const requiredKeys = [
        "plugins.title",
        "plugins.manageDescription",
        "plugins.addPlugin",
        "plugins.marketplace",
        "plugins.checkForUpdates",
        "plugins.updateAll",
        "plugins.disableAll",
        "plugins.enableAll",
        "plugins.automaticUpdates",
        "plugins.safeMode",
        "plugins.installedPlugins",
        "plugins.emptyInstalled",
        "plugins.installTitle",
        "plugins.installFromUrl",
        "plugins.uploadFromFile",
        "plugins.installUnpacked",
        "plugins.marketplaceTitle",
        "plugins.registryTitle",
        "plugins.pluginLogsTitle",
        "plugins.confirmAction",
        "plugins.removePlugin",
        "plugins.pluginRemoved",
        "plugins.registrySourcesUpdated",
        "plugins.developerLoggingTitle",
        "plugins.developerLoggingDescription",
        "plugins.loadingLogsIndex",
        "plugins.clearAllLogs",
        "plugins.noLogsYet",
        "plugins.fakeSerialTitle",
        "plugins.fakeSerialDescription",
        "plugins.liveTranscript",
        "plugins.noFakeSerialActivity",
        "plugins.path",
        "plugins.actions",
        "plugins.preview",
        "plugins.download",
        "plugins.badges.active",
        "plugins.badges.failedToLoad",
        "plugins.requirements.minimumHostTarget",
        "plugins.feedback.updated",
        "plugins.feedback.noUpdates",
        "plugins.loadingReadyTitle",
        "plugins.loadingDiscovering",
        "plugins.pluginUiFailedToRender",
        "plugins.run",
        "plugins.submit",
        "plugins.noBundleUrl",
        "plugins.localPluginsMountLabel",
        "plugins.settingsShell.settingsPage",
        "plugins.settingsShell.expandDetails",
    ];

    for (const locale of locales) {
        for (const key of requiredKeys) {
            assert.ok(
                readTranslation(locale, key),
                `Missing translation key "${key}" for locale "${locale}"`
            );
        }
    }
});

test("application translations include printer picker and camera linking shell keys for every supported locale", () => {
    const locales = ["en", "es", "es_AR", "fr", "pt", "it", "de"];
    const requiredKeys = [
        "printer.picker.loading",
        "printer.picker.selectPrinter",
        "printer.picker.label",
        "printer.picker.emptyState",
        "printer.picker.loadingDetails",
        "camera.linkAction",
        "camera.unlinkAction",
        "camera.enableRecordingAction",
        "camera.disableRecordingAction",
        "camera.linkError",
        "camera.recordingLinkError",
        "camera.troubleshootingOptions",
    ];

    for (const locale of locales) {
        for (const key of requiredKeys) {
            assert.ok(
                readTranslation(locale, key),
                `Missing translation key "${key}" for locale "${locale}"`
            );
        }
    }
});

test("application translations include notification center and preview shell keys for every supported locale", () => {
    const locales = ["en", "es", "es_AR", "fr", "pt", "it", "de"];
    const requiredKeys = [
        "notifications.centerTitle",
        "notifications.loading",
        "notifications.empty",
        "printer.preview.showExtrusion",
        "printer.preview.showTravelMoves",
        "printer.preview.livePreview",
    ];

    for (const locale of locales) {
        for (const key of requiredKeys) {
            assert.ok(
                readTranslation(locale, key),
                `Missing translation key "${key}" for locale "${locale}"`
            );
        }
    }
});

test("system settings children expose translated section, label, and description copy", () => {
    const t = makeTranslator("es_AR");

    assert.equal(
        localizeSystemSectionLabel("Advanced settings", t),
        "Configuración avanzada"
    );

    assert.deepEqual(
        localizeSystemSettingDefinition({
            key: "developerMode",
            section: "Advanced settings",
            hint: "Enable developer mode",
            description: "Whether to enable the developer mode which shows a Development tab with tools for core and plugin developers. A page reload is required to apply changes to this setting.",
        }, t),
        {
            key: "developerMode",
            section: "Configuración avanzada",
            hint: "Habilitar modo desarrollador",
            description: "Activa el modo desarrollador, que muestra una pestaña de Desarrollo con herramientas para desarrolladores del núcleo y de plugins. Es necesario recargar la página para aplicar este cambio.",
        }
    );
});

test("system settings children keep backend copy when no translation override exists", () => {
    const t = makeTranslator("de");

    assert.equal(
        localizeSystemSectionLabel("Totally custom section", t),
        "Totally custom section"
    );

    assert.deepEqual(
        localizeSystemSettingDefinition({
            key: "customSetting",
            section: "Totally custom section",
            hint: "Custom hint",
            description: "Custom description",
        }, t),
        {
            key: "customSetting",
            section: "Totally custom section",
            hint: "Custom hint",
            description: "Custom description",
        }
    );
});

test("system settings enum options localize dropdown labels with safe fallbacks", () => {
    const t = makeTranslator("fr");

    assert.deepEqual(
        localizeSystemEnumOptions("BackupInterval", ["Every second", "Every 5 minutes", "Never"], t),
        ["Chaque seconde", "Toutes les 5 minutes", "Jamais"]
    );

    assert.deepEqual(
        localizeSystemEnumOptions("BackupInterval", ["Every second", "Custom option"], t),
        ["Chaque seconde", "Custom option"]
    );
});

test("application translations include system settings child keys for every supported locale", () => {
    const locales = ["en", "es", "es_AR", "fr", "pt", "it", "de"];
    const requiredKeys = [
        "settings.systemSections.system",
        "settings.systemSections.connection",
        "settings.systemSections.limits",
        "settings.systemSections.miscellaneous",
        "settings.systemSections.advancedSettings",
        "settings.systemEnums.BackupInterval.everySecond",
        "settings.systemEnums.BackupInterval.everyFiveMinutes",
        "settings.systemEnums.BackupInterval.never",
        "settings.systemConfig.machineUUID.hint",
        "settings.systemConfig.machineUUID.description",
        "settings.systemConfig.renderFileBlockingSecs.hint",
        "settings.systemConfig.renderFileBlockingSecs.description",
        "settings.systemConfig.showFirstLoginHints.hint",
        "settings.systemConfig.showFirstLoginHints.description",
        "settings.systemConfig.checkForUpdates.hint",
        "settings.systemConfig.checkForUpdates.description",
        "settings.systemConfig.streamMaxLengthBytes.hint",
        "settings.systemConfig.streamMaxLengthBytes.description",
        "settings.systemConfig.negotiationWaitSecs.hint",
        "settings.systemConfig.negotiationWaitSecs.description",
        "settings.systemConfig.negotiationTimeoutSecs.hint",
        "settings.systemConfig.negotiationTimeoutSecs.description",
        "settings.systemConfig.negotiationMaxRetries.hint",
        "settings.systemConfig.negotiationMaxRetries.description",
        "settings.systemConfig.commandTimeoutSecs.hint",
        "settings.systemConfig.commandTimeoutSecs.description",
        "settings.systemConfig.runningTimeoutSecs.hint",
        "settings.systemConfig.runningTimeoutSecs.description",
        "settings.systemConfig.lastSeenThresholdSecs.hint",
        "settings.systemConfig.lastSeenThresholdSecs.description",
        "settings.systemConfig.lastSeenPollIntervalSecs.hint",
        "settings.systemConfig.lastSeenPollIntervalSecs.description",
        "settings.systemConfig.autoSerialIntervalSecs.hint",
        "settings.systemConfig.autoSerialIntervalSecs.description",
        "settings.systemConfig.controlDistanceDefault.hint",
        "settings.systemConfig.controlDistanceDefault.description",
        "settings.systemConfig.controlDistanceMin.hint",
        "settings.systemConfig.controlDistanceMin.description",
        "settings.systemConfig.controlDistanceMax.hint",
        "settings.systemConfig.controlDistanceMax.description",
        "settings.systemConfig.controlFeedrateDefault.hint",
        "settings.systemConfig.controlFeedrateDefault.description",
        "settings.systemConfig.controlFeedrateMin.hint",
        "settings.systemConfig.controlFeedrateMin.description",
        "settings.systemConfig.controlFeedrateMax.hint",
        "settings.systemConfig.controlFeedrateMax.description",
        "settings.systemConfig.controlExtrusionFeedrate.hint",
        "settings.systemConfig.controlExtrusionFeedrate.description",
        "settings.systemConfig.controlExtrusionMinTemp.hint",
        "settings.systemConfig.controlExtrusionMinTemp.description",
        "settings.systemConfig.jobBackupInterval.hint",
        "settings.systemConfig.jobBackupInterval.description",
        "settings.systemConfig.jobStatisticsQueryIntervalSecs.hint",
        "settings.systemConfig.jobStatisticsQueryIntervalSecs.description",
        "settings.systemConfig.terminalMaxLines.hint",
        "settings.systemConfig.terminalMaxLines.description",
        "settings.systemConfig.enableHaptics.hint",
        "settings.systemConfig.enableHaptics.description",
        "settings.systemConfig.debugSerial.hint",
        "settings.systemConfig.debugSerial.description",
        "settings.systemConfig.enableLibCamera.hint",
        "settings.systemConfig.enableLibCamera.description",
        "settings.systemConfig.jobRestorationHomingTemperature.hint",
        "settings.systemConfig.jobRestorationHomingTemperature.description",
        "settings.systemConfig.developerMode.hint",
        "settings.systemConfig.developerMode.description",
        "settings.systemConfig.fakeSerialEnabled.hint",
        "settings.systemConfig.fakeSerialEnabled.description",
        "settings.systemConfig.fakeSerialBaudRate.hint",
        "settings.systemConfig.fakeSerialBaudRate.description",
        "settings.systemConfig.fakeSerialNode.hint",
        "settings.systemConfig.fakeSerialNode.description",
    ];

    for (const locale of locales) {
        for (const key of requiredKeys) {
            assert.ok(
                readTranslation(locale, key),
                `Missing system settings translation key "${key}" for locale "${locale}"`
            );
        }
    }
});

test("plugin localization falls back from locale variants to English defaults", () => {
    const manifest = {
        name: "Host Metrics",
        description: "Base description",
        actions: [
            {
                id: "ping",
                label: "Ping",
            },
        ],
        uiExtensions: [
            {
                id: "settings",
                title: "Host Metrics",
                schema: {
                    component: "host.section",
                    title: "Host telemetry",
                    description: "Base schema copy",
                },
            },
        ],
        components: [
            {
                id: "metricsCard",
                kind: "remote_component",
                schema: {
                    component: "host.card",
                    title: "CPU",
                },
            },
        ],
    };

    const translations = {
        en: {
            plugin: {
                name: "Host Metrics",
            },
            uiExtensions: {
                settings: {
                    title: "Host Metrics",
                },
            },
        },
        es: {
            plugin: {
                name: "Métricas del host",
                description: "Descripción base en español",
            },
            actions: {
                ping: {
                    label: "Probar",
                },
            },
            uiExtensions: {
                settings: {
                    title: "Métricas del host",
                    schema: {
                        title: "Telemetría del host",
                        description: "Con ñ y acentos válidos",
                    },
                },
            },
            components: {
                metricsCard: {
                    schema: {
                        title: "Procesador",
                    },
                },
            },
        },
    };

    const localizedManifest = resolveLocalizedManifest(manifest, translations, "es_AR");

    assert.equal(localizedManifest.name, "Métricas del host");
    assert.equal(localizedManifest.description, "Descripción base en español");
    assert.equal(localizedManifest.actions[0].label, "Probar");
    assert.equal(localizedManifest.uiExtensions[0].schema.title, "Telemetría del host");
    assert.equal(localizedManifest.uiExtensions[0].schema.description, "Con ñ y acentos válidos");
    assert.equal(localizedManifest.components[0].schema.title, "Procesador");
});

test("plugin localization merges translated sections without removing untranslated defaults", () => {
    const manifest = {
        name: "Hello World",
        uiExtensions: [
            {
                id: "settings",
                title: "Hello World",
                schema: {
                    component: "host.section",
                    title: "Lightweight declarative UI",
                    children: [
                        {
                            component: "host.text",
                            text: "Base body",
                        },
                    ],
                },
            },
        ],
    };

    const localized = applyTranslationsToManifest(manifest, {
        plugin: {
            name: "Hola Mundo",
        },
        uiExtensions: {
            settings: {
                schema: {
                    title: "Interfaz declarativa liviana",
                },
            },
        },
    });

    assert.equal(localized.name, "Hola Mundo");
    assert.equal(localized.uiExtensions[0].title, "Hello World");
    assert.equal(localized.uiExtensions[0].schema.title, "Interfaz declarativa liviana");
    assert.equal(localized.uiExtensions[0].schema.children[0].text, "Base body");
});
