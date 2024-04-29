import { useQuery } from "@tanstack/react-query";

import { useState } from "react";

import { View } from "react-native";

import { ActivityIndicator, Divider, Icon, List, TextInput, useTheme } from "react-native-paper";

import API from "../includes/API";
import SmallButton from "./SmallButton";
import UserPrinterFileListControlsSortMenu from "./UserPrinterFileListControlsSortMenu";

export default function UserPrinterFileListControls({
    isLoading,
    subDirectory,
    setSubDirectory,
    sortingMode,
    setSortingMode,
    sortingModesIcons,
    sortingModesTitles,
    sortingModes
}) {
    const { colors } = useTheme();

    const handleGoUp = () => {
        let nextSubDirectory = subDirectory.split('/');

        nextSubDirectory.pop();

        setSubDirectory( nextSubDirectory.join('/') );
    };

    const handleGoHome = () => setSubDirectory('');

    return (
        <View style={{ flexDirection: 'row', gap: 8, paddingTop: 10 }}>
            <View style={{ flex: 'auto' }}>
                <TextInput
                    mode="outlined"
                    label="Subdirectory"
                    readOnly={true}
                    value={subDirectory.length == 0 ? '/' : subDirectory}
                />
            </View>

            <View style={{ alignSelf: 'end', flexFlow: 'wrap' }}>
                <SmallButton
                    style={{
                        borderWidth:             0,
                        borderRightWidth:        1,
                        borderTopRightRadius:    0,
                        borderBottomRightRadius: 0,
                        borderColor:             colors.outline,
                        backgroundColor:         colors.primary
                    }}
                    left={
                        <Icon
                            source="chevron-up"
                            size={26}
                            color={colors.onPrimary}
                        />
                    }
                    textStyle={{ display: 'flex', alignItems: 'center' }}
                    loading={isLoading}
                    onPress={handleGoUp}
                    disabled={isLoading || subDirectory.length == 0}
                />

                <SmallButton
                    style={{
                        borderWidth:      0,
                        borderRightWidth: 1,
                        borderRadius:     0,
                        borderColor:      colors.outline,
                        backgroundColor:  colors.primary
                    }}
                    left={
                        <Icon
                            source="home"
                            size={26}
                            color={colors.onPrimary}
                        />
                    }
                    textStyle={{ display: 'flex', alignItems: 'center' }}
                    loading={isLoading}
                    onPress={handleGoHome}
                    disabled={isLoading || subDirectory.length == 0}
                />

                <UserPrinterFileListControlsSortMenu
                    icons={sortingModesIcons}
                    titles={sortingModesTitles}
                    sortingMode={sortingMode}
                    setSortingMode={setSortingMode}
                    sortingModes={sortingModes}
                    isLoading={isLoading}
                />
            </View>
        </View>
    );
}