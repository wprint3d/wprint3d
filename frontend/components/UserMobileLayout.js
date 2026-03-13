import { useEffect, useState } from "react";
import { View } from "react-native"
import { BottomNavigation, Text, useTheme } from "react-native-paper";
import UserLeftPane from "./UserLeftPane";
import { useQuery } from "@tanstack/react-query";
import API from "../includes/API";
import UserPrinterTerminal from "./UserPrinterTerminal";
import UserPrinterPreview from "./UserPrinterPreview";
import UserPrinterControl from "./UserPrinterControl";
import UserPrinterRecordings from "./UserPrinterRecordings";
import usePluginExtensions from "../hooks/usePluginExtensions";
import PluginHostRenderer from "./PluginHostRenderer";

const UserMobileLayout = ({
    isLoadingPrinter = true, printerId,
    maxHeight, printStatus,
    isSmallLaptop, isSmallTablet,
}) => {
    const { colors } = useTheme();
    const pageExtensions = usePluginExtensions('page');
    const modalExtensions = usePluginExtensions('modal');

    const [ index, setIndex ] = useState(0);

    const routes = [
        { key: 'home',       title: 'Home',       focusedIcon: 'home',           unfocusedIcon: 'home-outline'           },
        { key: 'terminal',   title: 'Terminal',   focusedIcon: 'console',        unfocusedIcon: 'console'                },
        { key: 'preview',    title: 'Preview',    focusedIcon: 'eye',            unfocusedIcon: 'eye-outline'            },
        { key: 'control',    title: 'Control',    focusedIcon: 'camera-control', unfocusedIcon: 'camera-control'         },
        { key: 'recordings', title: 'Recordings', focusedIcon: 'record-circle',  unfocusedIcon: 'record-circle-outline'  },
        ...((pageExtensions?.data?.data || []).map((extension) => ({
            key: `plugin:${extension.pluginId}:${extension.id}`,
            title: extension.title,
            focusedIcon: 'puzzle',
            unfocusedIcon: 'puzzle-outline',
            extension,
        }))),
    ];

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
                    />
                );
        }
    };

    return (
        <BottomNavigation
            barStyle={{ backgroundColor: colors.surface }}
            navigationState={{ index, routes }}
            onIndexChange={setIndex}
            renderScene={renderScene}
            shifting={true}
            compact={true}
        />
    );
}

export default UserMobileLayout;
