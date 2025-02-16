import { useQuery } from "@tanstack/react-query"
import API from "../includes/API"
import { useEffect } from "react";
import UserPaneLoadingIndicator from "./UserPaneLoadingIndicator";
import NavBarMenuSettingsModalPlaceholderItem from "./NavBarMenuSettingsModalPlaceholderItem";
import { Text, useTheme } from "react-native-paper";
import { View } from "react-native";

const FormattedLogView = ({ fileName, wrap = true }) => {
    const { colors } = useTheme();

    const logContentsQuery = useQuery({
        queryKey: ['logContents', fileName],
        queryFn:  () => API.get(`/developer/logs/${fileName}`),
    });

    useEffect(() => {
        console.debug('FormattedLogView', logContentsQuery);
    }, [logContentsQuery]);

    if (logContentsQuery.isLoading) {
        return <UserPaneLoadingIndicator message={`Loading ${fileName}...`} />;
    }

    if (logContentsQuery.isError) {
        return (
            <NavBarMenuSettingsModalPlaceholderItem
                message={`Failed to load ${fileName}`}
                icon="exclamation-circle"
            />
        );
    }

    return (
        <View style={{ flexGrow: 1, flexShrink: 1, maxHeight: '50vh' }}>
            <Text style={{
                fontFamily: 'monospace', overflow: 'scroll',
                backgroundColor: colors.elevation.level1,
                padding: 8, whiteSpace: wrap ? 'pre-line' : 'pre',
            }}>
                {logContentsQuery.data?.data}
            </Text>
        </View>
    );
};

export default FormattedLogView;