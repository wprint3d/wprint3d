import { useQuery } from "@tanstack/react-query";
import { View } from "react-native";
import { List, Text } from "react-native-paper";
import API from "../includes/API";
import { useEffect } from "react";
import PrinterSettingsModalLinkingCamera from "./PrinterSettingsModalLinkingCamera";
import UserPaneLoadingIndicator from "./UserPaneLoadingIndicator";
import { useLocalization } from "../includes/LocalizationProvider";

const PrinterSettingsModalLinking = ({ printer, details, isLoading, error }) => {
    const { t } = useLocalization();
    const allCameras = useQuery({
        queryKey: ['cameras'],
        queryFn:  () => API.get('/cameras')
    });

    useEffect(() => {
        console.debug('PrinterSettingsModalLinking: allCameras:', allCameras);
    }, [ allCameras ]);

    useEffect(() => {
        console.debug('PrinterSettingsModalLinking: details:', details);
    }, [ details ]);

    if (isLoading) {
        return (
            <View>
                <UserPaneLoadingIndicator message={t("settings.loadingPrinterDetails")} />
            </View>
        );
    }

    if (error) {
        return (
            <View style={{ flex: 1, justifyContent: 'center', alignItems: 'center' }}>
                <Text style={{ textAlign: 'center' }}>
                    {t("settings.linkingSettingsLoadError")}
                    {'\n\n'}
                    {error}
                </Text>
            </View>
        );
    }

    const cameras = allCameras?.data?.data;

    return (
        <View>
            <List.Section title={t("settings.camerasTab")}>
                {
                    !cameras || cameras.length === 0 
                        ? <List.Item title={t("settings.noCameras")} />
                        : cameras.map(camera => (
                            <PrinterSettingsModalLinkingCamera key={camera._id} camera={camera} printerDetails={details} isLoading={allCameras.isLoading || isLoading} />
                        ))
                }
            </List.Section>
        </View>
    );
}

export default PrinterSettingsModalLinking;
