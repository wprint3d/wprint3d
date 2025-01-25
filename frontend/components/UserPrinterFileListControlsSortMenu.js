import { useQuery } from "@tanstack/react-query";

import { useEffect, useState } from "react";

import { View } from "react-native";

import { ActivityIndicator, Divider, Icon, List, Menu, TextInput, useTheme } from "react-native-paper";

import API from "../includes/API";
import SmallButton from "./SmallButton";

export default function UserPrinterFileListControlsSortMenu({ isLoading, loaderSize, sortingModes, sortingMode, setSortingMode, icons, titles }) {
    const { colors } = useTheme();

    const [ isVisible, setIsVisible ] = useState(false);

    useEffect(() => {
        console.debug('sortingMode:', sortingMode);
    }, [ sortingMode ]);

    return (
        <Menu
            visible={isVisible}
            onDismiss={() => setIsVisible(false)}
            anchor={
                <SmallButton
                    style={{
                        borderWidth:      0,
                        borderRightWidth: 1,
                        borderRadius:     0,
                        borderColor:      'transparent',
                        backgroundColor:  colors.primary
                    }}
                    left={
                        <Icon
                            source={icons[sortingMode] || 'progress-question'}
                            size={26}
                            color={colors.onPrimary}
                        />
                    }
                    textStyle={{ display: 'flex', alignItems: 'center' }}
                    loading={isLoading}
                    loaderSize={loaderSize}
                    onPress={() => setIsVisible(true)}
                    disabled={isLoading || sortingModes.isFetching}
                    tooltipText={'Sort by'}
                />
            }
            anchorPosition='bottom'
        >
            {
                sortingModes.isSuccess &&
                    Object.keys(sortingModes.data.data).map(sortingModeKey => {
                        return (
                            <Menu.Item
                                key={sortingModeKey}
                                onPress={() => {
                                    setSortingMode(sortingModeKey);
                                    setIsVisible(false);
                                }}
                                leadingIcon={icons[sortingModeKey] || 'progress-question'}
                                trailingIcon={sortingMode == sortingModeKey && 'check'}
                                title={titles[sortingModeKey] || 'Unknown mode'}
                            />
                        );
                    })
            }
        </Menu>
    );
}