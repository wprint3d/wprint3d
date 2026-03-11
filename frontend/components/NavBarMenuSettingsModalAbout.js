import { Text } from "react-native-paper"
import React, { useEffect, useState } from 'react';
import { View, ActivityIndicator, Linking, ScrollView } from 'react-native';
import { useTheme } from 'react-native-paper';
import { useQuery } from "@tanstack/react-query";
import API from "../includes/API";
import TextBold from "./TextBold";
import UserPaneLoadingIndicator from "./UserPaneLoadingIndicator";

const NavBarMenuSettingsModalAbout = ({ isSmallTablet, isSmallLaptop, enqueueSnackbar }) => {
    const { colors } = useTheme();

    const appName = useQuery({
        queryKey: ['getAppNameAbout'],
        queryFn:  () => API.get('/app/name')
    });

    const appRevision = useQuery({
        queryKey: ['getAppRevision'],
        queryFn:  () => API.get('/app/revision')
    });

    const licenses = useQuery({
        queryKey: ['getLicenses'],
        queryFn:  () => API.get('/app/licenses')
    });

    const APP_NAME      = appName?.data?.data,
          APP_REVISION  = appRevision?.data?.data;

    let licensesContent = null;

    if (licenses.isLoading) {
        licensesContent = <UserPaneLoadingIndicator message={'Downloading licenses…'} />;
    } else if (licenses.isError) {
        licensesContent = (
            <Text style={{ color: colors.error, paddingVertical: 32 }}>
                Failed to download licenses, please try again later.
            </Text>
        );
    } else {
        licensesContent = (
            <ScrollView style={{ width: '100%', flex: 1, maxHeight: '100%', padding: 16, marginTop: 16, backgroundColor: colors.background }}>
                <Text variant="bodySmall" style={{ marginBottom: 8 }}>
                    {licenses?.data?.data ?? 'No licenses found'}
                </Text>
            </ScrollView>
        );
    }

    return (
        <View style={{ alignItems: 'center', paddingVertical: 16, flex: 1 }}>
            <Text variant="headlineLarge" style={{ fontWeight: 'bold', textAlign: 'center' }}>
                {APP_NAME ?? '…'}
                {'\n'}
                <Text variant="bodyMedium" style={{ textAlign: 'center' }}>
                    ({APP_REVISION ?? '…'})
                </Text>
            </Text>
            <Text style={{ textAlign: 'center', marginVertical: 8 }}>
                Open-source software licensed under the <TextBold>MIT license</TextBold>.
            </Text>
            <Text style={{ textAlign: 'center', marginVertical: 8, marginTop: 24 }}>
                For more information about licensing, security and other administrative procedures please refer to the <TextBold>README.md</TextBold> file provided as part of 
                {' '}
                <Text style={{ fontWeight: 'bold', color: colors.primary, textDecorationStyle: 'solid', textDecorationLine: 'underline' }} onPress={() => Linking.openURL('https://github.com/wprint3d/wprint3d')}>the repository</Text>.
            </Text>
            <Text style={{ textAlign: 'center', marginVertical: 8 }}>
                If you want to know more about the licensing specifications of most of our third-party dependencies, please refer to the documentation provided below.
            </Text>
            {licensesContent}
        </View>
    );
}

export default NavBarMenuSettingsModalAbout;