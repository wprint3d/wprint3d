import { useQuery } from "@tanstack/react-query"
import API from "../includes/API"
import { useEffect } from "react";
import UserPaneLoadingIndicator from "./UserPaneLoadingIndicator";
import NavBarMenuSettingsModalPlaceholderItem from "./NavBarMenuSettingsModalPlaceholderItem";
import { Text, useTheme } from "react-native-paper";
import { View } from "react-native";
import FormattedTextView from "./FormattedTextView";

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
        <FormattedTextView
            text={logContentsQuery.data?.data}
            wrap={wrap}
        />
    );
};

export default FormattedLogView;