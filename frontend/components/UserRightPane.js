import { TabScreen, Tabs, TabsProvider, useTabIndex, useTabNavigation } from "react-native-paper-tabs";
import UserPane                 from "./UserPane";
import UserPaneLoadingIndicator from "./UserPaneLoadingIndicator";

import { useTheme } from "react-native-paper";

import UserPrinterTerminal from "./UserPrinterTerminal";
import UserPrinterPreview from "./UserPrinterPreview";
import UserPrinterControl from "./UserPrinterControl";
import UserPrinterRecordings from "./UserPrinterRecordings";
import { useConnectionStatus } from "../hooks/useConnectionStatus";
import usePluginExtensions from "../hooks/usePluginExtensions";
import PluginHostRenderer from "./PluginHostRenderer";
import { useLocalization } from "../includes/LocalizationProvider";
import { useCallback } from "react";
import { useWindowDimensions, View } from "react-native";
import {
    getPluginHostNavigationIndex,
    getPluginNavigationIcon,
    getPluginNavigationLabel,
    shouldUseCompactWorkspaceTabs,
} from "../utils/pluginNavigation";

const CORE_WORKSPACE_TAB_COUNT = 4;

function UserRightPaneTabs({
    colors,
    connectionStatus,
    isLoadingPrinter,
    isSmallLaptop,
    isSmallTablet,
    modalExtensions,
    pageExtensions,
    printerId,
    t,
    windowWidth,
}) {
    const activeIndex = useTabIndex();
    const navigateToIndex = useTabNavigation();
    const navigateFromPlugin = useCallback((destination) => {
        if (destination === "files") {
            return true;
        }
        if (destination === "printer-slicing" && typeof window !== "undefined") {
            window.dispatchEvent(new CustomEvent("wprint3d:open-printer-slicing", {
                detail: { printerId },
            }));
            return true;
        }
        const destinationIndex = getPluginHostNavigationIndex(destination);
        if (destinationIndex === null) { return false; }
        navigateToIndex(destinationIndex);
        return true;
    }, [navigateToIndex, printerId]);

    return (
        <View style={{ flex: 1, minHeight: 0, position: "relative" }}>
                        <Tabs
                style={{ backgroundColor: colors.background, flex: 1 }}
                            mode="scrollable"
                            showLeadingSpace={false}
                showTextLabel={!shouldUseCompactWorkspaceTabs(windowWidth)}
                        >
                            <TabScreen label={t("mobile.terminal")} icon="console">
                                <UserPrinterTerminal isLoadingPrinter={isLoadingPrinter} printerId={printerId} isSmallTablet={isSmallTablet} />
                            </TabScreen>
                            <TabScreen label={t("mobile.preview")} icon="eye">
                                <UserPrinterPreview printerId={printerId} isSmallTablet={isSmallTablet} />
                            </TabScreen>
                            <TabScreen label={t("mobile.control")} icon="camera-control">
                                <UserPrinterControl  isSmallLaptop={isSmallLaptop} isSmallTablet={isSmallTablet} connectionStatus={connectionStatus} printerId={printerId} />
                            </TabScreen>
                            <TabScreen label={t("mobile.recordings")} icon="record-circle-outline">
                                <UserPrinterRecordings
                                    isLoadingPrinter={isLoadingPrinter}
                                    printerId={printerId}
                                    isSmallLaptop={isSmallLaptop}
                                    isSmallTablet={isSmallTablet}
                                />
                            </TabScreen>
                {pageExtensions.map((extension) => (
                    <TabScreen
                        key={`${extension.pluginId}-${extension.id}`}
                        label={getPluginNavigationLabel(extension)}
                        icon={getPluginNavigationIcon(extension)}
                    >
                        <View style={{ flex: 1 }} />
                    </TabScreen>
                ))}
            </Tabs>

            {pageExtensions.map((extension, extensionIndex) => {
                const extensionTabIndex = CORE_WORKSPACE_TAB_COUNT + extensionIndex;
                const isActive = activeIndex === extensionTabIndex;
                return (
                    <View
                        key={`persistent-${extension.pluginId}-${extension.id}`}
                        pointerEvents={isActive ? "auto" : "none"}
                        aria-hidden={!isActive}
                        style={{
                            position: "absolute",
                            top: 48,
                            right: 0,
                            bottom: 0,
                            left: 0,
                            display: isActive ? "flex" : "none",
                            backgroundColor: colors.background,
                        }}
                    >
                                    <PluginHostRenderer
                                        extension={extension}
                            modalExtensions={modalExtensions}
                            printerId={printerId}
                            onHostNavigate={navigateFromPlugin}
                        />
                    </View>
                );
            })}
        </View>
    );
}

export default function UserRightPane({ isLoadingPrinter = true, printerId = null, isSmallLaptop, isSmallTablet }) {
    const { colors } = useTheme();
    const { t } = useLocalization();
    const { width: windowWidth } = useWindowDimensions();

    const { connectionStatus } = useConnectionStatus({ printerId });
    const pageExtensions = usePluginExtensions('page');
    const modalExtensions = usePluginExtensions('modal');

    return (
        <UserPane style={{
            display:    'flex',
            flexShrink: 1,
            flexGrow:   1,
            width:      '1%' // NOTE: I have no idea why this works but I'm not willing to ask any further questions.
        }}>
            {
                isLoadingPrinter
                    ? <UserPaneLoadingIndicator message={t("printer.preparingActionsMenu")} />
                    : <TabsProvider defaultIndex={0}>
                        <UserRightPaneTabs
                            colors={colors}
                            connectionStatus={connectionStatus}
                            isLoadingPrinter={isLoadingPrinter}
                            isSmallLaptop={isSmallLaptop}
                            isSmallTablet={isSmallTablet}
                                        modalExtensions={modalExtensions?.data?.data || []}
                            pageExtensions={pageExtensions?.data?.data || []}
                                        printerId={printerId}
                            t={t}
                            windowWidth={windowWidth}
                                    />
                      </TabsProvider>
            }
        </UserPane>
    )
}
