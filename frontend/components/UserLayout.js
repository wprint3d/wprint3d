

import { StyleSheet, View, useWindowDimensions } from "react-native";

import { useQuery } from "@tanstack/react-query";

import UserLeftPane  from "./UserLeftPane";
import UserRightPane from "./UserRightPane";

import API from "../includes/API";

export default function UserLayout({ navbarHeight }) {
    const dimensions = useWindowDimensions();

    const selectedPrinter = useQuery({
        queryKey: ['selectedPrinter'],
        queryFn:  () => API.get('/user/printer/selected')
    });

    const maxPaneHeight = dimensions.height - navbarHeight - (styles.root.padding * 2);

    return (
        <View style={[
            styles.root,
            (
                dimensions.width >= 1600
                    ? { paddingHorizontal: (dimensions.width >= 1920 ? 128 : 32) }
                    : {}
            ),
            {
                flexWrap: (
                    dimensions.width <= 425 // mobile large
                        ? 'wrap'
                        : 'nowrap'
                )
            }
        ]}>
            <UserLeftPane   selectedPrinter={selectedPrinter} maxHeight={maxPaneHeight} />
            <UserRightPane  selectedPrinter={selectedPrinter} maxHeight={maxPaneHeight} />
        </View>
    );
}

const styles = StyleSheet.create({
    root: {
        width: '100%',
        display: 'flex',
        flexDirection: 'row',
        padding: 8,
        gap: 8
    }
});