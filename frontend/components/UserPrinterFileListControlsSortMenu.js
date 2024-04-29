import { useQuery } from "@tanstack/react-query";

import { useState } from "react";

import { View } from "react-native";

import { ActivityIndicator, Divider, Icon, List, Menu, TextInput, useTheme } from "react-native-paper";

import API from "../includes/API";
import SmallButton from "./SmallButton";

export default function UserPrinterFileListControlsSortMenu({ isLoading, sortingModes, sortingMode, setSortingMode, icons, titles }) {
    console.debug('sortingMode:', sortingMode);

    const { colors } = useTheme();

    const [ isVisible, setIsVisible ] = useState(false);

    return (
        <Menu
            visible={isVisible}
            onDismiss={() => setIsVisible(false)}
            anchor={
                <SmallButton
                    style={{
                        borderWidth:             0,
                        borderLeftWidth:         1,
                        borderTopLeftRadius:     0,
                        borderBottomLeftRadius:  0,
                        borderColor:             'transparent',
                        backgroundColor:         colors.primary
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
                    onPress={() => setIsVisible(true)}
                    disabled={isLoading || sortingModes.isFetching}
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