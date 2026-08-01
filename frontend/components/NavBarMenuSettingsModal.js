import { memo, useEffect, useMemo, useRef, useState } from "react";
import { Card, Chip, Icon, Modal, Portal, Text, useTheme } from "react-native-paper";
import { Tabs, TabsProvider, TabScreen, useTabNavigation } from "react-native-paper-tabs";
import { Animated, Easing, Pressable, View } from "react-native";
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
import { LocalizationContext, useLocalization } from "../includes/LocalizationProvider";

const PLUGINS_TAB_KEY = "plugins";

const buildBadgeIcon = (source, color) => color
    ? ({ size }) => <Icon source={source} size={size} color={color} />
    : source;

const PluginBadgeChip = ({ badge }) => (
    <Chip
        icon={buildBadgeIcon(badge.icon, badge.textStyle?.color)}
        style={badge.style}
        textStyle={badge.textStyle}
    >
        {badge.label}
    </Chip>
);

const buildPluginExtensionWarnings = (plugin, extension, t) => (
    arrayUnique([
        ...(plugin?.warnings || []),
        [ "webview", "custom_bundle" ].includes(extension?.mode || "declarative")
            ? t("plugins.settingsShell.elevatedUiMode")
            : null,
        plugin?.enabled === false ? t("plugins.settingsShell.disabledWarning") : null,
    ].filter(Boolean))
);

const arrayUnique = (items = []) => Array.from(new Set(items));

const resolvePluginIcon = (plugin, extension = null) => (
    extension?.icon
    || plugin?.manifest?.icon
    || plugin?.icon
    || "puzzle-outline"
);

const buildPluginUiExtensionPayload = (plugin, extension, t) => ({
    ...extension,
    pluginId: plugin.id,
    pluginName: plugin.name,
    pluginVersion: plugin.version,
    pluginEnabled: plugin.enabled,
    pluginTrustLevel: plugin.trustLevel,
    pluginDescription: plugin.description,
    pluginManifest: plugin.manifest || {},
    pluginIcon: resolvePluginIcon(plugin, extension),
    warnings: buildPluginExtensionWarnings(plugin, extension, t),
});

const buildPluginSettingsPages = (plugins = [], t) => (
    plugins.flatMap((plugin) => {
        if (plugin?.loadStatus === "failed") {
            return [];
        }

        const settingsExtensions = (plugin?.uiExtensions || []).filter((extension) => extension?.surface === "settings_tab");

        return settingsExtensions.map((extension, index) => ({
            key: `plugin-settings:${plugin.id}:${extension.id}`,
            pluginId: plugin.id,
            extensionId: extension.id,
            label: settingsExtensions.length > 1 ? `${plugin.name} ${index + 1}` : plugin.name,
            title: extension.title || plugin.name,
            icon: resolvePluginIcon(plugin, extension),
            extension: buildPluginUiExtensionPayload(plugin, extension, t),
        }));
    })
);

const buildPluginModalExtensions = (plugins = [], t) => (
    plugins.flatMap((plugin) => (
        (plugin?.uiExtensions || [])
            .filter((extension) => extension?.surface === "modal")
            .map((extension) => buildPluginUiExtensionPayload(plugin, extension, t))
    ))
);

const buildPluginHeaderBadges = (extension, theme, t) => {
    const badges = [];

    badges.push(extension?.pluginEnabled
        ? {
            icon: "check-circle",
            label: t("plugins.badges.active"),
            style: { backgroundColor: theme.colors.primaryContainer },
            textStyle: { color: theme.colors.onPrimaryContainer },
        }
        : {
            icon: "pause-circle",
            label: t("plugins.badges.disabled"),
            style: { backgroundColor: theme.colors.secondaryContainer },
            textStyle: { color: theme.colors.onSecondaryContainer },
        });

    const trustBadgeMap = {
        official: {
            icon: "shield-check",
            label: t("plugins.badges.official"),
            style: { backgroundColor: theme.colors.tertiaryContainer },
            textStyle: { color: theme.colors.onTertiaryContainer },
        },
        signed: {
            icon: "certificate",
            label: t("plugins.badges.signed"),
            style: { backgroundColor: theme.colors.success || "#0a9900" },
            textStyle: { color: theme.colors.onSuccess || "#ffffff" },
        },
        trusted: {
            icon: "shield-check",
            label: t("plugins.badges.trusted"),
            style: { backgroundColor: theme.colors.tertiaryContainer },
            textStyle: { color: theme.colors.onTertiaryContainer },
        },
        development: {
            icon: "flask",
            label: t("plugins.badges.liveSource"),
            style: { backgroundColor: theme.colors.inversePrimary },
            textStyle: { color: theme.colors.onPrimaryContainer },
        },
        invalid_signature: {
            icon: "shield-remove",
            label: t("plugins.badges.badSignature"),
            style: { backgroundColor: theme.colors.errorContainer },
            textStyle: { color: theme.colors.onErrorContainer },
        },
        unsigned: {
            icon: "shield-alert",
            label: t("plugins.badges.unsigned"),
            style: { backgroundColor: theme.colors.errorContainer },
            textStyle: { color: theme.colors.onErrorContainer },
        },
    };

    badges.push(trustBadgeMap[extension?.pluginTrustLevel] || {
        icon: "shield-outline",
        label: extension?.pluginTrustLevel || t("plugins.badges.unknown"),
        style: { backgroundColor: theme.colors.surfaceVariant },
        textStyle: { color: theme.colors.onSurfaceVariant },
    });

    badges.push({
        icon: "puzzle",
        label: t("plugins.settingsShell.settingsPage"),
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
    const { effectiveLanguage, t } = useLocalization();
    const headerBadges = buildPluginHeaderBadges(extension, theme, t);
    const [ isExpanded, setIsExpanded ] = useState(false);
    const [ isDetailsMounted, setIsDetailsMounted ] = useState(false);
    const [ measuredDetailsHeight, setMeasuredDetailsHeight ] = useState(0);
    const expansionAnimation = useRef(new Animated.Value(0)).current;

    useEffect(() => {
        setIsExpanded(false);
        setIsDetailsMounted(false);
        setMeasuredDetailsHeight(0);
        expansionAnimation.setValue(0);
    }, [ expansionAnimation, extension?.id, extension?.pluginId ]);

    useEffect(() => {
        if (isExpanded) {
            setIsDetailsMounted(true);
        }
    }, [ isExpanded ]);

    useEffect(() => {
        if (!isDetailsMounted) {
            return undefined;
        }

        const animation = Animated.timing(expansionAnimation, {
            toValue: isExpanded ? 1 : 0,
            duration: 220,
            easing: Easing.out(Easing.cubic),
            useNativeDriver: false,
        });

        animation.start(({ finished }) => {
            if (finished && !isExpanded) {
                setIsDetailsMounted(false);
            }
        });

        return () => {
            animation.stop();
        };
    }, [ expansionAnimation, isDetailsMounted, isExpanded ]);

    const detailsMaxHeight = expansionAnimation.interpolate({
        inputRange: [ 0, 1 ],
        outputRange: [ 0, Math.max(measuredDetailsHeight, 320) ],
    });

    const detailsOpacity = expansionAnimation.interpolate({
        inputRange: [ 0, 1 ],
        outputRange: [ 0, 1 ],
    });

    const detailsTranslateY = expansionAnimation.interpolate({
        inputRange: [ 0, 1 ],
        outputRange: [ -8, 0 ],
    });

    const chevronRotation = expansionAnimation.interpolate({
        inputRange: [ 0, 1 ],
        outputRange: [ "0deg", "180deg" ],
    });

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
                <Card.Content style={{ gap: 14, paddingVertical: 18 }}>
                    <View style={{ flexDirection: "row", alignItems: "center", gap: 16 }}>
                        <View
                            style={{
                                width: 58,
                                height: 58,
                                borderRadius: 18,
                                backgroundColor: theme.colors.surfaceVariant,
                                alignItems: "center",
                                justifyContent: "center",
                                position: "relative",
                                borderWidth: 1,
                                borderColor: theme.colors.outlineVariant,
                            }}
                        >
                            <Icon source={extension.pluginIcon || "puzzle-outline"} size={26} color={theme.colors.onSurfaceVariant} />
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

                        <View style={{ flex: 1, minWidth: 0, gap: 2 }}>
                            <Text variant="headlineSmall">{extension.pluginName}</Text>
                            {isExpanded && (
                                <Text style={{ color: theme.colors.onSurfaceVariant, fontWeight: "600" }}>
                                    {extension.title}
                                </Text>
                            )}
                        </View>

                        <Pressable
                            accessibilityRole="button"
                            accessibilityLabel={isExpanded ? t("plugins.settingsShell.collapseDetails") : t("plugins.settingsShell.expandDetails")}
                            onPress={() => setIsExpanded((current) => !current)}
                            style={{
                                width: 38,
                                height: 38,
                                borderRadius: 19,
                                alignItems: "center",
                                justifyContent: "center",
                                borderWidth: 1,
                                borderColor: theme.colors.outlineVariant,
                                backgroundColor: theme.colors.elevation.level1,
                            }}
                        >
                            <Animated.View style={{ transform: [{ rotate: chevronRotation }] }}>
                                <Icon source="chevron-down" size={20} color={theme.colors.onSurfaceVariant} />
                            </Animated.View>
                        </Pressable>
                    </View>

                    {isDetailsMounted && (
                        <Animated.View
                            style={{
                                maxHeight: detailsMaxHeight,
                                opacity: detailsOpacity,
                                overflow: "hidden",
                                transform: [{ translateY: detailsTranslateY }],
                            }}
                        >
                            <View
                                onLayout={(event) => {
                                    const nextHeight = event.nativeEvent.layout.height;

                                    if (nextHeight > 0 && Math.abs(nextHeight - measuredDetailsHeight) > 1) {
                                        setMeasuredDetailsHeight(nextHeight);
                                    }
                                }}
                                style={{ gap: 16, paddingTop: 4 }}
                            >
                                <Text style={{ color: theme.colors.onSurfaceVariant }}>
                                    {extension.pluginId}{extension.pluginVersion ? ` • ${extension.pluginVersion}` : ""}
                                </Text>

                                {!!extension.pluginDescription && (
                                    <Text style={{ color: theme.colors.onSurfaceVariant, maxWidth: 820 }}>
                                        {extension.pluginDescription}
                                    </Text>
                                )}

                                <View style={{ flexDirection: "row", flexWrap: "wrap", gap: 8 }}>
                                    {headerBadges.map((badge) => (
                                        <PluginBadgeChip
                                            key={`${extension.pluginId}-${extension.id}-${badge.label}`}
                                            badge={badge}
                                        />
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
                            </View>
                        </Animated.View>
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
    const localization = useLocalization();
    const { effectiveLanguage, t } = localization;
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

    useEffect(() => {
        if (!isVisible) {
            return;
        }

        queryClient.invalidateQueries({ queryKey: ["plugins"] });
        queryClient.invalidateQueries({ queryKey: ["pluginExtensions"] });
    }, [ isVisible, queryClient ]);

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
                    message: t("settings.noUpdatesFound"),
                    variant: 'info',
                    action:  { label: t("notifications.gotIt") }
                });

                return;
            }

            queryClient.invalidateQueries({ queryKey: ['updateStatus'] });
        },
        onError: (error) => {
            enqueueSnackbar({
                message: t("settings.checkUpdatesError", { reason: error?.response?.data?.message || error.message }),
                variant: 'error',
                action:  { label: t("notifications.gotIt") }
            });
        }
    });

    const pluginSettingsPages = useMemo(() => {
        if (installedPluginsQuery?.data?.data?.length) {
            return buildPluginSettingsPages(installedPluginsQuery.data.data || [], t);
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
    }, [ fallbackSettingsExtensionsQuery?.data?.data, installedPluginsQuery?.data?.data, t ]);

    const pluginModalExtensions = useMemo(() => {
        if (installedPluginsQuery?.data?.data?.length) {
            return buildPluginModalExtensions(installedPluginsQuery.data.data || [], t);
        }

        return (fallbackModalExtensionsQuery?.data?.data || []).map((extension) => ({
            ...extension,
            pluginIcon: extension.icon || "puzzle-outline",
        }));
    }, [ fallbackModalExtensionsQuery?.data?.data, installedPluginsQuery?.data?.data, t ]);

    const tabs = useMemo(() => {
        const baseTabs = [
            {
                key: "printers",
                label: t("settings.printersTab"),
                icon: "printer-3d",
                content: (
                    <Wrapper>
                        <NavBarMenuSettingsModalPrinters isSmallTablet={isSmallTablet} isSmallLaptop={isSmallLaptop} enqueueSnackbar={enqueueSnackbar} />
                    </Wrapper>
                ),
            },
            {
                key: "presets",
                label: t("settings.presetsTab"),
                icon: "printer-3d-nozzle",
                content: (
                    <Wrapper>
                        <NavBarMenuSettingsModalPresets isSmallTablet={isSmallTablet} isSmallLaptop={isSmallLaptop} enqueueSnackbar={enqueueSnackbar} />
                    </Wrapper>
                ),
            },
            {
                key: "cameras",
                label: t("settings.camerasTab"),
                icon: "camera",
                content: (
                    <Wrapper>
                        <NavBarMenuSettingsModalCameras isSmallTablet={isSmallTablet} isSmallLaptop={isSmallLaptop} enqueueSnackbar={enqueueSnackbar} />
                    </Wrapper>
                ),
            },
            {
                key: "recording",
                label: t("settings.recordingTab"),
                icon: "record",
                content: (
                    <Wrapper>
                        <NavBarMenuSettingsModalRecording isSmallTablet={isSmallTablet} isSmallLaptop={isSmallLaptop} enqueueSnackbar={enqueueSnackbar} />
                    </Wrapper>
                ),
            },
            {
                key: "system",
                label: t("settings.systemTab"),
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
                label: t("settings.usersTab"),
                icon: "account",
                content: (
                    <Wrapper>
                        <NavBarMenuSettingsModalUsers isSmallTablet={isSmallTablet} isSmallLaptop={isSmallLaptop} enqueueSnackbar={enqueueSnackbar} />
                    </Wrapper>
                ),
            },
            {
                key: PLUGINS_TAB_KEY,
                label: t("settings.pluginsTab"),
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
                label: t("settings.developerTab"),
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
            label: t("settings.aboutTab"),
            icon: "information",
            content: (
                <Wrapper style={{ flex: 1, minHeight: 0, overflow: 'hidden' }}>
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
        t,
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
                        key={`settings-tabs:${effectiveLanguage}`}
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
                                <TabScreen key={`${tab.key}:${effectiveLanguage}`} label={tab.label} icon={tab.icon} badge={tab.badge}>
                                    <LocalizationContext.Provider value={localization}>
                                        {tab.content}
                                    </LocalizationContext.Provider>
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
