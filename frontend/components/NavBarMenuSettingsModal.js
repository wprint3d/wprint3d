import { memo, useEffect, useMemo, useState } from "react";
import { Card, Chip, Icon, Modal, Portal, Text, useTheme } from "react-native-paper";
import { Tabs, TabsProvider, TabScreen, useTabNavigation } from "react-native-paper-tabs";
import { View } from "react-native";
import NavBarMenuSettingsModalPrinters from "./NavBarMenuSettingsModalPrinters";
import { SnackbarProvider, useSnackbar } from "react-native-paper-snackbar-stack";
import NavBarMenuSettingsModalPresets from "./NavBarMenuSettingsModalPresets";
import NavBarMenuSettingsModalCameras from "./NavBarMenuSettingsModalCameras";
import NavBarMenuSettingsModalRecording from "./NavBarMenuSettingsModalRecording";
import NavBarMenuSettingsModalSystem from "./NavBarMenuSettingsModalSystem";
import NavBarMenuSettingsModalUsers from "./NavBarMenuSettingsModalUsers";
import NavBarMenuSettingsModalAbout from "./NavBarMenuSettingsModalAbout";
import BackButton from "./modules/BackButton";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import API from "../includes/API";
import NavBarMenuSettingsModalDeveloper from "./NavBarMenuSettingsModalDeveloper";
import NavBarMenuSystemUpdater from "./NavBarMenuSystemUpdater";
import NavBarMenuSettingsModalPlugins from "./NavBarMenuSettingsModalPlugins";
import PluginHostRenderer from "./PluginHostRenderer";
import usePluginExtensions from "../hooks/usePluginExtensions";

const PLUGINS_TAB_KEY = "plugins";

const buildPluginExtensionWarnings = (plugin, extension) => (
    arrayUnique([
        ...(plugin?.warnings || []),
        [ "webview", "custom_bundle" ].includes(extension?.mode || "declarative")
            ? "This plugin uses an elevated UI mode."
            : null,
        plugin?.enabled === false ? "This plugin is currently disabled. Interactive settings may not work until you enable it again." : null,
    ].filter(Boolean))
);

const arrayUnique = (items = []) => Array.from(new Set(items));

const resolvePluginIcon = (plugin, extension = null) => (
    extension?.icon
    || plugin?.manifest?.icon
    || plugin?.icon
    || "puzzle-outline"
);

const buildPluginUiExtensionPayload = (plugin, extension) => ({
    ...extension,
    pluginId: plugin.id,
    pluginName: plugin.name,
    pluginVersion: plugin.version,
    pluginEnabled: plugin.enabled,
    pluginTrustLevel: plugin.trustLevel,
    pluginDescription: plugin.description,
    pluginManifest: plugin.manifest || {},
    pluginIcon: resolvePluginIcon(plugin, extension),
    warnings: buildPluginExtensionWarnings(plugin, extension),
});

const buildPluginSettingsPages = (plugins = []) => (
    plugins.flatMap((plugin) => {
        const settingsExtensions = (plugin?.uiExtensions || []).filter((extension) => extension?.surface === "settings_tab");

        return settingsExtensions.map((extension, index) => ({
            key: `plugin-settings:${plugin.id}:${extension.id}`,
            pluginId: plugin.id,
            extensionId: extension.id,
            label: settingsExtensions.length > 1 ? `${plugin.name} ${index + 1}` : plugin.name,
            title: extension.title || plugin.name,
            icon: resolvePluginIcon(plugin, extension),
            extension: buildPluginUiExtensionPayload(plugin, extension),
        }));
    })
);

const buildPluginModalExtensions = (plugins = []) => (
    plugins.flatMap((plugin) => (
        (plugin?.uiExtensions || [])
            .filter((extension) => extension?.surface === "modal")
            .map((extension) => buildPluginUiExtensionPayload(plugin, extension))
    ))
);

const buildPluginHeaderBadges = (extension, theme) => {
    const badges = [];

    badges.push(extension?.pluginEnabled
        ? {
            icon: "check-circle",
            label: "Active",
            style: { backgroundColor: theme.colors.primaryContainer },
            textStyle: { color: theme.colors.onPrimaryContainer },
        }
        : {
            icon: "pause-circle",
            label: "Disabled",
            style: { backgroundColor: theme.colors.secondaryContainer },
            textStyle: { color: theme.colors.onSecondaryContainer },
        });

    const trustBadgeMap = {
        official: {
            icon: "shield-check",
            label: "Official",
            style: { backgroundColor: theme.colors.tertiaryContainer },
            textStyle: { color: theme.colors.onTertiaryContainer },
        },
        signed: {
            icon: "certificate",
            label: "Signed",
            style: { backgroundColor: theme.colors.tertiaryContainer },
            textStyle: { color: theme.colors.onTertiaryContainer },
        },
        trusted: {
            icon: "shield-check",
            label: "Trusted",
            style: { backgroundColor: theme.colors.tertiaryContainer },
            textStyle: { color: theme.colors.onTertiaryContainer },
        },
        development: {
            icon: "flask",
            label: "Live source",
            style: { backgroundColor: theme.colors.inversePrimary },
            textStyle: { color: theme.colors.onPrimaryContainer },
        },
        invalid_signature: {
            icon: "shield-remove",
            label: "Bad signature",
            style: { backgroundColor: theme.colors.errorContainer },
            textStyle: { color: theme.colors.onErrorContainer },
        },
        unsigned: {
            icon: "shield-alert",
            label: "Unsigned",
            style: { backgroundColor: theme.colors.errorContainer },
            textStyle: { color: theme.colors.onErrorContainer },
        },
    };

    badges.push(trustBadgeMap[extension?.pluginTrustLevel] || {
        icon: "shield-outline",
        label: extension?.pluginTrustLevel || "Unknown",
        style: { backgroundColor: theme.colors.surfaceVariant },
        textStyle: { color: theme.colors.onSurfaceVariant },
    });

    badges.push({
        icon: "puzzle",
        label: "Settings page",
        style: { backgroundColor: theme.colors.surfaceVariant },
        textStyle: { color: theme.colors.onSurfaceVariant },
    });

    return badges;
};

const PluginSettingsTabIcon = ({ iconSource, color, size = 24 }) => {
    const theme = useTheme();
    const badgeSize = Math.max(11, Math.round(size * 0.46));
    const badgeIconSize = Math.max(8, Math.round(badgeSize * 0.52));

    return (
        <View
            style={{
                width: size,
                height: size,
                position: "relative",
                overflow: "visible",
                alignItems: "center",
                justifyContent: "center",
            }}
        >
            <Icon source={iconSource} size={size} color={color} />
            <View
                style={{
                    position: "absolute",
                    right: -2,
                    bottom: -2,
                    width: badgeSize,
                    height: badgeSize,
                    borderRadius: badgeSize / 2,
                    backgroundColor: theme.colors.tertiaryContainer,
                    borderWidth: 1.5,
                    borderColor: theme.colors.elevation.level1,
                    alignItems: "center",
                    justifyContent: "center",
                }}
            >
                <Icon source="puzzle" size={badgeIconSize} color={theme.colors.onTertiaryContainer} />
            </View>
        </View>
    );
};

const PluginSettingsTabPanel = ({ extension, modalExtensions = [] }) => {
    const theme = useTheme();
    const headerBadges = buildPluginHeaderBadges(extension, theme);

    return (
        <View style={{ gap: 16, paddingBottom: 24 }}>
            <Card
                style={{
                    borderRadius: 22,
                    overflow: "hidden",
                    borderWidth: 1,
                    borderColor: theme.colors.outlineVariant,
                    backgroundColor: theme.colors.elevation.level2,
                }}
            >
                <Card.Content style={{ gap: 16, paddingVertical: 18 }}>
                    <View style={{ flexDirection: "row", alignItems: "flex-start", gap: 16 }}>
                        <View
                            style={{
                                width: 68,
                                height: 68,
                                borderRadius: 20,
                                backgroundColor: theme.colors.surfaceVariant,
                                alignItems: "center",
                                justifyContent: "center",
                                position: "relative",
                                borderWidth: 1,
                                borderColor: theme.colors.outlineVariant,
                            }}
                        >
                            <Icon source={extension.pluginIcon || "puzzle-outline"} size={30} color={theme.colors.onSurfaceVariant} />
                            <View
                                style={{
                                    position: "absolute",
                                    right: -6,
                                    bottom: -6,
                                    width: 26,
                                    height: 26,
                                    borderRadius: 13,
                                    backgroundColor: theme.colors.tertiaryContainer,
                                    borderWidth: 2,
                                    borderColor: theme.colors.elevation.level2,
                                    alignItems: "center",
                                    justifyContent: "center",
                                }}
                            >
                                <Icon source="puzzle" size={14} color={theme.colors.onTertiaryContainer} />
                            </View>
                        </View>

                        <View style={{ flex: 1, gap: 4 }}>
                            <Text variant="headlineSmall">{extension.pluginName}</Text>
                            <Text style={{ color: theme.colors.onSurfaceVariant, fontWeight: "600" }}>
                                {extension.title}
                            </Text>
                            <Text style={{ color: theme.colors.onSurfaceVariant }}>
                                {extension.pluginId}{extension.pluginVersion ? ` • ${extension.pluginVersion}` : ""}
                            </Text>
                        </View>
                    </View>

                    {!!extension.pluginDescription && (
                        <Text style={{ color: theme.colors.onSurfaceVariant, maxWidth: 820 }}>
                            {extension.pluginDescription}
                        </Text>
                    )}

                    <View style={{ flexDirection: "row", flexWrap: "wrap", gap: 8 }}>
                        {headerBadges.map((badge) => (
                            <Chip
                                key={`${extension.pluginId}-${extension.id}-${badge.label}`}
                                icon={badge.icon}
                                style={badge.style}
                                textStyle={badge.textStyle}
                            >
                                {badge.label}
                            </Chip>
                        ))}
                    </View>

                    {!!extension.warnings?.length && (
                        <View style={{ gap: 8 }}>
                            {extension.warnings.map((warning) => (
                                <View
                                    key={`${extension.pluginId}-${extension.id}-${warning}`}
                                    style={{
                                        flexDirection: "row",
                                        alignItems: "flex-start",
                                        gap: 10,
                                        paddingHorizontal: 14,
                                        paddingVertical: 12,
                                        borderRadius: 16,
                                        backgroundColor: theme.colors.errorContainer,
                                        borderWidth: 1,
                                        borderColor: theme.colors.outlineVariant,
                                    }}
                                >
                                    <Icon source="alert-circle-outline" size={18} color={theme.colors.onErrorContainer} />
                                    <Text style={{ color: theme.colors.onErrorContainer, flex: 1 }}>
                                        {warning}
                                    </Text>
                                </View>
                            ))}
                        </View>
                    )}
                </Card.Content>
            </Card>

            <PluginHostRenderer
                extension={extension}
                modalExtensions={modalExtensions}
            />
        </View>
    );
};

const SettingsTabNavigationBridge = ({ targetTabKey, tabDefinitions }) => {
    const goTo = useTabNavigation();

    useEffect(() => {
        const targetIndex = tabDefinitions.findIndex((tab) => tab.key === targetTabKey);

        if (targetIndex >= 0) {
            goTo(targetIndex);
        }
    }, [ goTo, tabDefinitions, targetTabKey ]);

    return null;
};

const NavBarMenuSettingsModal = ({ isVisible, setIsVisible, isSmallTablet, isSmallLaptop }) => {
    const queryClient = useQueryClient();
    const [ activeTabKey, setActiveTabKey ] = useState("printers");

    const developerModeQuery = useQuery({
        queryKey:   ['developerMode'],
        queryFn:    () => API.get('/config/developerMode'),
    });

    const currentUserQuery = useQuery({
        queryKey: ["currentUser"],
        queryFn: () => API.get("/user"),
        retry: false,
        refetchOnWindowFocus: false,
        staleTime: 60000,
    });

    const installedPluginsQuery = useQuery({
        queryKey: ["plugins"],
        queryFn: () => API.get("/plugins"),
        enabled: currentUserQuery?.data?.data?.role === 0,
        retry: false,
        refetchOnWindowFocus: false,
        staleTime: 60000,
    });

    const fallbackSettingsExtensionsQuery = usePluginExtensions("settings_tab");
    const fallbackModalExtensionsQuery = usePluginExtensions("modal");

    const theme = useTheme();

    const { enqueueSnackbar } = useSnackbar();

    const Wrapper = ({ children, style = {} }) => (
        <View style={[{ width: '100%', flexGrow: 1, maxWidth: 1400, alignSelf: 'center' }, style]}>
            {children}
        </View>
    );

    const checkForUpdatesMutation = useMutation({
        mutationFn: () => API.post('/app/update/check'),
        onSuccess: (response) => {
            console.debug('checkForUpdatesMutation', response);

            const updatableImages = response?.data;

            if (!updatableImages || updatableImages.length === 0) {
                enqueueSnackbar({
                    message: 'No updates found.',
                    variant: 'info',
                    action:  { label: 'Got it' }
                });

                return;
            }

            queryClient.invalidateQueries({ queryKey: ['updateStatus'] });
        },
        onError: (error) => {
            enqueueSnackbar({
                message: 'An error occurred while checking for updates: ' + (error?.response?.data?.message || error.message),
                variant: 'error',
                action:  { label: 'Got it' }
            });
        }
    });

    const pluginSettingsPages = useMemo(() => {
        if (installedPluginsQuery?.data?.data?.length) {
            return buildPluginSettingsPages(installedPluginsQuery.data.data || []);
        }

        return (fallbackSettingsExtensionsQuery?.data?.data || []).map((extension) => ({
            key: `plugin-settings:${extension.pluginId}:${extension.id}`,
            pluginId: extension.pluginId,
            extensionId: extension.id,
            label: extension.pluginName,
            title: extension.title || extension.pluginName,
            icon: extension.icon || "puzzle-outline",
            extension: {
                ...extension,
                pluginIcon: extension.icon || "puzzle-outline",
            },
        }));
    }, [ fallbackSettingsExtensionsQuery?.data?.data, installedPluginsQuery?.data?.data ]);

    const pluginModalExtensions = useMemo(() => {
        if (installedPluginsQuery?.data?.data?.length) {
            return buildPluginModalExtensions(installedPluginsQuery.data.data || []);
        }

        return (fallbackModalExtensionsQuery?.data?.data || []).map((extension) => ({
            ...extension,
            pluginIcon: extension.icon || "puzzle-outline",
        }));
    }, [ fallbackModalExtensionsQuery?.data?.data, installedPluginsQuery?.data?.data ]);

    const tabs = useMemo(() => {
        const baseTabs = [
            {
                key: "printers",
                label: "Printers",
                icon: "printer-3d",
                content: (
                    <Wrapper>
                        <NavBarMenuSettingsModalPrinters isSmallTablet={isSmallTablet} isSmallLaptop={isSmallLaptop} enqueueSnackbar={enqueueSnackbar} />
                    </Wrapper>
                ),
            },
            {
                key: "presets",
                label: "Presets",
                icon: "printer-3d-nozzle",
                content: (
                    <Wrapper>
                        <NavBarMenuSettingsModalPresets isSmallTablet={isSmallTablet} isSmallLaptop={isSmallLaptop} enqueueSnackbar={enqueueSnackbar} />
                    </Wrapper>
                ),
            },
            {
                key: "cameras",
                label: "Cameras",
                icon: "camera",
                content: (
                    <Wrapper>
                        <NavBarMenuSettingsModalCameras isSmallTablet={isSmallTablet} isSmallLaptop={isSmallLaptop} enqueueSnackbar={enqueueSnackbar} />
                    </Wrapper>
                ),
            },
            {
                key: "recording",
                label: "Recording",
                icon: "record",
                content: (
                    <Wrapper>
                        <NavBarMenuSettingsModalRecording isSmallTablet={isSmallTablet} isSmallLaptop={isSmallLaptop} enqueueSnackbar={enqueueSnackbar} />
                    </Wrapper>
                ),
            },
            {
                key: "system",
                label: "System",
                icon: "cog",
                content: (
                    <Wrapper>
                        <NavBarMenuSettingsModalSystem
                            isSmallTablet={isSmallTablet}
                            isSmallLaptop={isSmallLaptop}
                            enqueueSnackbar={enqueueSnackbar}
                            checkForUpdatesMutation={checkForUpdatesMutation}
                        />
                    </Wrapper>
                ),
            },
            {
                key: "users",
                label: "Users",
                icon: "account",
                content: (
                    <Wrapper>
                        <NavBarMenuSettingsModalUsers isSmallTablet={isSmallTablet} isSmallLaptop={isSmallLaptop} enqueueSnackbar={enqueueSnackbar} />
                    </Wrapper>
                ),
            },
            {
                key: PLUGINS_TAB_KEY,
                label: "Plugins",
                icon: "puzzle",
                content: (
                    <Wrapper>
                        <NavBarMenuSettingsModalPlugins
                            pluginSettingsPages={pluginSettingsPages}
                            onOpenSettingsPage={(tabKey) => setActiveTabKey(tabKey)}
                        />
                    </Wrapper>
                ),
            },
            ...pluginSettingsPages.map((page) => ({
                key: page.key,
                label: page.label,
                icon: ({ color, size }) => <PluginSettingsTabIcon iconSource={page.icon} color={color} size={size} />,
                content: (
                    <Wrapper>
                        <PluginSettingsTabPanel
                            extension={page.extension}
                            modalExtensions={pluginModalExtensions}
                        />
                    </Wrapper>
                ),
            })),
        ];

        if (developerModeQuery?.data?.data === true) {
            baseTabs.push({
                key: "developer",
                label: "Developer",
                icon: "code-tags",
                content: (
                    <Wrapper style={{ flexShrink: 1, overflow: 'scroll' }}>
                        <NavBarMenuSettingsModalDeveloper isSmallTablet={isSmallTablet} isSmallLaptop={isSmallLaptop} enqueueSnackbar={enqueueSnackbar} />
                    </Wrapper>
                ),
            });
        }

        baseTabs.push({
            key: "about",
            label: "About",
            icon: "information",
            content: (
                <Wrapper>
                    <NavBarMenuSettingsModalAbout isSmallTablet={isSmallTablet} isSmallLaptop={isSmallLaptop} enqueueSnackbar={enqueueSnackbar} />
                </Wrapper>
            ),
        });

        return baseTabs;
    }, [
        checkForUpdatesMutation,
        developerModeQuery?.data?.data,
        enqueueSnackbar,
        isSmallLaptop,
        isSmallTablet,
        pluginModalExtensions,
        pluginSettingsPages,
    ]);

    useEffect(() => {
        if (!tabs.some((tab) => tab.key === activeTabKey)) {
            setActiveTabKey(PLUGINS_TAB_KEY);
        }
    }, [ activeTabKey, tabs ]);

    const doDismiss = () => {
        setIsVisible(false);
    };

    return (
        <Portal>
            <SnackbarProvider maxSnack={4}>
                <Modal
                    visible={isVisible}
                    onDismiss={doDismiss}
                    contentContainerStyle={{
                        backgroundColor: theme.colors.elevation.level1,
                        height: (isSmallLaptop || isSmallTablet) ? '100%' : '95%',
                        width:  (isSmallLaptop || isSmallTablet) ? '100%' : '95%',
                        alignSelf: 'center',
                        paddingVertical: 16,
                        paddingHorizontal: isSmallTablet ? 4 : 16,
                        overflow: 'scroll'
                    }}
                >
                    {(isSmallLaptop || isSmallTablet) && <BackButton onPress={doDismiss} />}

                    <NavBarMenuSystemUpdater enqueueSnackbar={enqueueSnackbar} checkForUpdatesMutation={checkForUpdatesMutation} />

                    <TabsProvider
                        defaultIndex={tabs.findIndex((tab) => tab.key === activeTabKey) >= 0 ? tabs.findIndex((tab) => tab.key === activeTabKey) : 0}
                        onChangeIndex={(index) => setActiveTabKey(tabs[index]?.key || "printers")}
                    >
                        <SettingsTabNavigationBridge targetTabKey={activeTabKey} tabDefinitions={tabs} />
                        <Tabs
                            style={{
                                backgroundColor: theme.colors.elevation.level1,
                                marginBottom: 16,
                                marginLeft: (isSmallLaptop || isSmallTablet) ? 78 : 0,
                            }}
                            tabHeaderStyle={{
                                alignSelf: 'center',
                                maxWidth: '100%',
                                overflow: 'scroll'
                            }}
                            mode="scrollable"
                            showLeadingSpace={false}
                        >
                            {tabs.map((tab) => (
                                <TabScreen key={tab.key} label={tab.label} icon={tab.icon} badge={tab.badge}>
                                    {tab.content}
                                </TabScreen>
                            ))}
                        </Tabs>
                    </TabsProvider>
                </Modal>
            </SnackbarProvider>
        </Portal>
    );
}

export default memo(NavBarMenuSettingsModal);
