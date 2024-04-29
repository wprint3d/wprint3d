import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useEffect, useState } from "react";

import { StyleSheet, View } from "react-native";

import { Icon, Text, TextInput, useTheme } from "react-native-paper";

import API from "../includes/API";

import UserPrinterStatusConnection from "./UserPrinterStatusConnection";
import UserPrinterStatusExtruders from "./UserPrinterStatusExtruders";
import UserPrinterStatusBed from "./UserPrinterStatusBed";
import UserPaneLoadingIndicator from "./UserPaneLoadingIndicator";
import UserPrinterStatus from "./UserPrinterStatus";

export default function UserPrinterStatusWrapper() {
    const statusRefreshConfig = useQuery({
        queryKey: ['statusRefreshConfig'],
        queryFn:  () => API.get('/config/lastSeenPollIntervalSecs')
    });

    console.debug('statusRefreshConfig:', statusRefreshConfig);

    if (!statusRefreshConfig.isFetched) {
        return <UserPaneLoadingIndicator message={"Setting up status monitor..."} />;
    }

    if (statusRefreshConfig.isFetched && !statusRefreshConfig.isSuccess) {
        return (
            <Text> Something went wrong while fetching system settings. </Text>
        );
    }

    return <UserPrinterStatus refetchIntervalSecs={statusRefreshConfig.data.data} />;
}