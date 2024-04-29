import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useEffect, useState } from "react";

import { StyleSheet, View } from "react-native";

import { Icon, Text, TextInput, useTheme } from "react-native-paper";

import API from "../includes/API";

import UserPrinterStatusConnection from "./UserPrinterStatusConnection";
import UserPrinterStatusExtruders from "./UserPrinterStatusExtruders";
import UserPrinterStatusBed from "./UserPrinterStatusBed";

export default function UserPrinterStatus({ refetchIntervalSecs }) {
    const connectionStatus = useQuery({
        queryKey: ['connectionStatus'],
        queryFn:  () => API.get('/user/printer/selected/status'),
        refetchInterval: refetchIntervalSecs * 1000
    });

    console.debug('connectionStatus (refetchIntervalSecs):', refetchIntervalSecs);
    console.debug('connectionStatus:', connectionStatus);

    return (
        <>
            <UserPrinterStatusConnection connectionStatus={connectionStatus} />

            <View style={{
                display:        'flex',
                flexDirection:  'row',
                justifyContent: 'space-evenly'
            }}>
                <UserPrinterStatusExtruders connectionStatus={connectionStatus} />
            </View>

            <UserPrinterStatusBed connectionStatus={connectionStatus} />
        </>
    );
}