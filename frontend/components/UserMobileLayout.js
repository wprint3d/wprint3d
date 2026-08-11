import { useCallback, useEffect, useState } from "react";
import { StyleSheet, View } from "react-native";
import { BottomNavigation, Icon, Text, TouchableRipple, useTheme } from "react-native-paper";
import UserLeftPane from "./UserLeftPane";
import { useQuery } from "@tanstack/react-query";
import API from "../includes/API";
import UserPrinterTerminal from "./UserPrinterTerminal";
import UserPrinterPreview from "./UserPrinterPreview";
import UserPrinterControl from "./UserPrinterControl";
import UserPrinterRecordings from "./UserPrinterRecordings";
import usePluginExtensions from "../hooks/usePluginExtensions";
import PluginHostRenderer from "./PluginHostRenderer";
import { useLocalization } from "../includes/LocalizationProvider";
import { getPluginHostNavigationIndex, getPluginNavigationRoute } from "../utils/pluginNavigation";

const CompactNavigationTouchable = ({ route, children: _children, style, accessibilityState, ...touchableProps }) => {
    const { colors } = useTheme();
    const focused = !!accessibilityState?.selected;

    return (
        <TouchableRipple
            {...touchableProps}
            accessibilityState={accessibilityState}
            accessibilityLabel={route.accessibilityLabel || route.title}
            style={[style, styles.compactNavigationItem]}
        >
            <View style={styles.compactNavigationContent}>
                <View style={[
                    styles.compactNavigationIcon,
                    focused && { backgroundColor: colors.secondaryContainer },
                ]}>
                    <Icon
                        source={focused ? route.focusedIcon : (route.unfocusedIcon || route.focusedIcon)}
                        color={focused ? colors.onSecondaryContainer : colors.onSurfaceVariant}
                        size={21}
                    />
                </View>
                {focused && (
                    <Text
                        numberOfLines={1}
                        variant="labelSmall"
                        style={[styles.compactNavigationLabel, { color: colors.onSurface }]}
                    >
                        {route.title}
                    </Text>
                )}
            </View>
        </TouchableRipple>
    );
};

const UserMobileLayout = ({
    isLoadingPrinter = true, printerId,
    maxHeight, printStatus,
    isSmallLaptop, isSmallTablet,
}) => {
    const { colors } = useTheme();
    const { t } = useLocalization();
    const pageExtensions = usePluginExtensions('page');
    const modalExtensions = usePluginExtensions('modal');

    const [ index, setIndex ] = useState(0);

    const routes = [
        { key: 'home',       title: t("mobile.home"),       focusedIcon: 'home',           unfocusedIcon: 'home-outline'           },
        { key: 'terminal',   title: t("mobile.terminal"),   focusedIcon: 'console',        unfocusedIcon: 'console'                },
        { key: 'preview',    title: t("mobile.preview"),    focusedIcon: 'eye',            unfocusedIcon: 'eye-outline'            },
        { key: 'control',    title: t("mobile.control"),    focusedIcon: 'camera-control', unfocusedIcon: 'camera-control'         },
        { key: 'recordings', title: t("mobile.recordings"), focusedIcon: 'record-circle',  unfocusedIcon: 'record-circle-outline'  },
        ...((pageExtensions?.data?.data || []).map(getPluginNavigationRoute)),
    ];
    const navigateFromPlugin = useCallback((destination) => {
        if (destination === "printer-slicing" && typeof window !== "undefined") {
            window.dispatchEvent(new CustomEvent("wprint3d:open-printer-slicing", {
                detail: { printerId },
            }));
            return true;
        }
        const destinationIndex = getPluginHostNavigationIndex(destination, { mobile: true });
        if (destinationIndex === null) { return false; }
        setIndex(destinationIndex);
        return true;
    }, [printerId]);

    const renderScene = ({ route }) => {
        switch (route.key) {
            case 'home':
                return <UserLeftPane isLoadingPrinter={isLoadingPrinter} printerId={printerId} maxHeight={maxHeight} printStatus={printStatus} />;
            case 'terminal':
                return <UserPrinterTerminal isLoadingPrinter={isLoadingPrinter} printerId={printerId} isSmallLaptop={isSmallLaptop} isSmallTablet={isSmallTablet} />;
            case 'preview':
                return <UserPrinterPreview isSmallTablet={isSmallTablet} printerId={printerId} />;
            case 'control':
                return <UserPrinterControl isSmallLaptop={isSmallLaptop} isSmallTablet={isSmallTablet} printerId={printerId} />;
            case 'recordings':
                return <UserPrinterRecordings isLoadingPrinter={isLoadingPrinter} printerId={printerId} isSmallLaptop={isSmallLaptop} isSmallTablet={isSmallTablet} />;
            default:
                return (
                    <PluginHostRenderer
                        extension={route.extension}
                        modalExtensions={modalExtensions?.data?.data || []}
                        printerId={printerId}
                        onHostNavigate={navigateFromPlugin}
                    />
                );
        }
    };

    return (
        <View style={{ flex: 1, width: '100%', minHeight: 0 }}>
            <BottomNavigation
                style={{ flex: 1, width: '100%' }}
                barStyle={{ backgroundColor: colors.surface }}
                navigationState={{ index, routes }}
                onIndexChange={setIndex}
                renderScene={renderScene}
                renderTouchable={props => <CompactNavigationTouchable {...props} />}
                shifting={false}
                labeled={false}
                compact={true}
            />
        </View>
    );
}

export default UserMobileLayout;

const styles = StyleSheet.create({
    compactNavigationItem: {
        minHeight: 60,
        paddingVertical: 0,
    },
    compactNavigationContent: {
        minHeight: 60,
        alignItems: 'center',
        justifyContent: 'center',
        gap: 1,
        paddingHorizontal: 2,
    },
    compactNavigationIcon: {
        width: 38,
        height: 28,
        borderRadius: 14,
        alignItems: 'center',
        justifyContent: 'center',
    },
    compactNavigationLabel: {
        width: '100%',
        maxWidth: 64,
        fontSize: 10,
        lineHeight: 12,
        textAlign: 'center',
    },
});
