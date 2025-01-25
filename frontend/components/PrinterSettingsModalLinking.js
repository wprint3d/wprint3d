import { useQuery } from "@tanstack/react-query";
import { View } from "react-native";
import { List, Text, ToggleButton, Tooltip } from "react-native-paper";
import API from "../includes/API";
import { useEffect } from "react";
import PrinterSettingsModalLinkingCamera from "./PrinterSettingsModalLinkingCamera";
import UserPaneLoadingIndicator from "./UserPaneLoadingIndicator";

const PrinterSettingsModalLinking = ({ printer, details, isLoading, error }) => {
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
                <UserPaneLoadingIndicator message={`Loading printer details...`} />
            </View>
        );
    }

    if (error) {
        return (
            <View>
                <Text>
                    Couldn't load printer details, please try again later.
                    {'\n'}
                    {error}
                </Text>
            </View>
        );
    }

    const cameras = allCameras?.data?.data;

    return (
        <View>
            <List.Section title="Cameras">
                {
                    !cameras || cameras.length === 0 
                        ? <List.Item title="No cameras were detected." />
                        : cameras.map(camera => (
                            <PrinterSettingsModalLinkingCamera key={camera._id} camera={camera} printerDetails={details} isLoading={allCameras.isLoading || isLoading} />
                        ))
                }
            </List.Section>
        </View>
    );
}

export default PrinterSettingsModalLinking;