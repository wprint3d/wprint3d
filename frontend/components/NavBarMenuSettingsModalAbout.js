import { Text } from "react-native-paper"
import React, { useMemo } from 'react';
import { FlatList, Linking, View } from 'react-native';
import { useTheme } from 'react-native-paper';
import { useQuery } from "@tanstack/react-query";
import API from "../includes/API";
import UserPaneLoadingIndicator from "./UserPaneLoadingIndicator";
import { useLocalization } from "../includes/LocalizationProvider";

const LICENSE_SEPARATOR = '='.repeat(80);

const splitLicenseBlocks = (content) => {
    const sections = String(content ?? '').split(LICENSE_SEPARATOR);

    if (sections.length < 3) {
        return [ String(content ?? '') ];
    }

    const blocks = [ sections[0] ];

    for (let index = 1; index < sections.length; index += 2) {
        blocks.push(
            `${LICENSE_SEPARATOR}${sections[index]}${LICENSE_SEPARATOR}${sections[index + 1] ?? ''}`
        );
    }

    return blocks.filter(Boolean);
};

const renderLicenseBlock = ({ item }) => (
    <Text selectable variant="bodySmall" style={{ marginBottom: 8 }}>
        {item}
    </Text>
);

const NavBarMenuSettingsModalAbout = ({ isSmallTablet, isSmallLaptop, enqueueSnackbar }) => {
    const { colors } = useTheme();
    const { t } = useLocalization();

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

    const licenseBlocks = useMemo(
        () => splitLicenseBlocks(licenses?.data?.data),
        [ licenses?.data?.data ]
    );

    let licensesContent = null;

    if (licenses.isLoading) {
        licensesContent = <UserPaneLoadingIndicator message={t("about.downloadingLicenses")} />;
    } else if (licenses.isError) {
        licensesContent = (
            <Text style={{ color: colors.error, paddingVertical: 32 }}>
                {t("about.downloadError")}
            </Text>
        );
    } else {
        licensesContent = (
            <FlatList
                testID="about-license-list"
                data={licenseBlocks.length > 0 ? licenseBlocks : [ t("about.noLicenses") ]}
                keyExtractor={(_, index) => String(index)}
                renderItem={renderLicenseBlock}
                initialNumToRender={2}
                maxToRenderPerBatch={2}
                windowSize={3}
                removeClippedSubviews
                style={{ width: '100%', flex: 1, minHeight: 0, marginTop: 16, backgroundColor: colors.background }}
                contentContainerStyle={{ padding: 16 }}
            />
        );
    }

    return (
        <View style={{ alignItems: 'center', paddingVertical: 16, flex: 1, minHeight: 0 }}>
            <Text variant="headlineLarge" style={{ fontWeight: 'bold', textAlign: 'center' }}>
                {APP_NAME ?? '…'}
                {'\n'}
                <Text variant="bodyMedium" style={{ textAlign: 'center' }}>
                    ({APP_REVISION ?? '…'})
                </Text>
            </Text>
            <Text style={{ textAlign: 'center', marginVertical: 8 }}>
                {t("about.openSourceBlurb")}
            </Text>
            <Text style={{ textAlign: 'center', marginVertical: 8, marginTop: 24 }}>
                {t("about.repositoryPrefix")}
                {' '}
                <Text style={{ fontWeight: 'bold', color: colors.primary, textDecorationStyle: 'solid', textDecorationLine: 'underline' }} onPress={() => Linking.openURL('https://github.com/wprint3d/wprint3d')}>{t("about.repositoryLinkLabel")}</Text>
                {t("about.repositorySuffix")}
            </Text>
            <Text style={{ textAlign: 'center', marginVertical: 8 }}>
                {t("about.thirdPartyInfo")}
            </Text>
            {licensesContent}
        </View>
    );
}

export default NavBarMenuSettingsModalAbout;
