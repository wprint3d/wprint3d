import { useMutation } from "@tanstack/react-query";
import { useEffect, useState } from "react";
import { View } from "react-native";
import { List, Switch, Text } from "react-native-paper";
import DropDown from "react-native-paper-dropdown";
import API from "../includes/API";

const CameraSettingsModalConfiguration = ({ camera, enqueueSnackbar }) => {
    console.debug('CameraSettingsModalConfiguration: camera:', camera);

    const toggleCameraEnabledMutation = useMutation({
        mutationFn: ({ cameraId, enabled }) => API.post(`/camera/${cameraId}/${enabled ? 'enable' : 'disable'}`),
        onMutate: ({ cameraId }) => {
            console.debug('CameraSettingsModalConfiguration: toggleCameraEnabledMutation: onMutate:', cameraId);

            return cameraId;
        },
        onSuccess: (response) => {
            console.debug('CameraSettingsModalConfiguration: toggleCameraEnabledMutation: onSuccess:', response);

            setEnabled(!enabled);
        },
        onError: (error, variables, context) => {
            console.error('CameraSettingsModalConfiguration: toggleCameraEnabledMutation: onError:', error, variables, context);

            enqueueSnackbar({
                message: `An error occurred while ${variables.enabled ? 'enabling' : 'disabling'} the camera: ${(error?.response?.data?.message ?? 'unknown error').toLowerCase()}`,
                variant: 'error',
                action:  { label: 'Got it' }
            });
        }
    });

    const updateFormatMutation = useMutation({
        mutationFn: ({ cameraId, format }) => API.put(`/camera/${cameraId}`, { format }),
        onMutate: ({ cameraId }) => {
            console.debug('CameraSettingsModalConfiguration: setFormatMutation: onMutate:', cameraId);

            return cameraId;
        },
        onSuccess: (response, variables) => {
            console.debug('CameraSettingsModalConfiguration: setFormatMutation: onSuccess:', response);

            setFormat(variables.format);
        },
        onError: (error, variables, context) => {
            console.error('CameraSettingsModalConfiguration: setFormatMutation: onError:', error, variables, context);

            enqueueSnackbar({
                message: `An error occurred while setting the camera format: ${(error?.response?.data?.message ?? 'unknown error').toLowerCase()}`,
                variant: 'error',
                action:  { label: 'Got it' }
            });
        }
    });

    const [ showDropDown, setShowDropDown ] = useState(false);

    const [ enabled, setEnabled ] = useState(camera?.enabled);
    const [ format,  setFormat  ] = useState(camera?.format);

    const parseBoolean = (value) => value ? 'Yes' : 'No';

    const handleFormatChange = (format) => {
        console.debug('CameraSettingsModalConfiguration: handleFormatChange:', format);

        updateFormatMutation.mutate({ cameraId: camera._id, format });
    };

    return (
        <View>
            <List.Section title="Status">
                <List.Item title="Connected" description={parseBoolean(camera?.connected)} />

                <List.Item
                    title="Enabled"
                    description={parseBoolean(enabled)}
                    right={() => (
                        <Switch
                            value={enabled}
                            onValueChange={() => toggleCameraEnabledMutation.mutate({ cameraId: camera._id, enabled: !camera.enabled })}
                            disabled={toggleCameraEnabledMutation.isPending}
                        />
                    )}
                />
            </List.Section>
            <List.Section title="Quality">
                <List.Item
                    title="Format"
                    description="The resolution and frame rate of the camera."
                    right={() => (
                        <View style={{ maxWidth: 200 }}>
                            <DropDown
                                label=" "
                                mode="outlined"
                                value={format}
                                setValue={handleFormatChange}
                                visible={showDropDown}
                                showDropDown={() => !updateFormatMutation.isPending && setShowDropDown(true)}
                                onDismiss={() => setShowDropDown(false)}
                                list={camera?.availableFormats.map(format => ({ label: format, value: format }))}
                            />
                        </View>
                    )}
                />
            </List.Section>
            <List.Section title="Miscellaneous">
                <List.Item title="Requires libcamera" description={parseBoolean(camera?.requiresLibCamera)} />
                <List.Item title="URL" description={camera?.url} />
            </List.Section>
        </View>
    );
}

export default CameraSettingsModalConfiguration;