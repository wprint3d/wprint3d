import { useMutation, useQueryClient } from "@tanstack/react-query";
import { useEffect, useState } from "react";
import { View } from "react-native";
import { List, Switch, Text } from "react-native-paper";
import DropDown from "react-native-paper-dropdown";
import API from "../includes/API";
import { useLocalization } from "../includes/LocalizationProvider";

const CameraSettingsModalConfiguration = ({ camera, enqueueSnackbar }) => {
    const queryClient = useQueryClient();
    const { t } = useLocalization();

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

            queryClient.invalidateQueries({ queryKey: ['cameraList'] });
        },
        onError: (error, variables, context) => {
            console.error('CameraSettingsModalConfiguration: toggleCameraEnabledMutation: onError:', error, variables, context);

            enqueueSnackbar({
                message: `${variables.enabled ? t("camera.enabled") : t("camera.delete").toLowerCase()} camera: ${(error?.response?.data?.message ?? 'unknown error').toLowerCase()}`,
                variant: 'error',
                action:  { label: t("password.dismiss") }
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
                message: `${t("camera.format")}: ${(error?.response?.data?.message ?? 'unknown error').toLowerCase()}`,
                variant: 'error',
                action:  { label: t("password.dismiss") }
            });
        }
    });

    const [ showDropDown, setShowDropDown ] = useState(false);

    const [ enabled, setEnabled ] = useState(camera?.enabled);
    const [ format,  setFormat  ] = useState(camera?.format);

    const parseBoolean = (value) => value ? t("camera.yes") : t("camera.no");

    const handleFormatChange = (format) => {
        console.debug('CameraSettingsModalConfiguration: handleFormatChange:', format);

        updateFormatMutation.mutate({ cameraId: camera._id, format });
    };

    return (
        <View>
            <List.Section title={t("camera.statusSection")}>
                <List.Item title={t("camera.connected")} description={parseBoolean(camera?.connected)} />

                <List.Item
                    title={t("camera.enabled")}
                    description={parseBoolean(enabled)}
                    right={() => (
                        <Switch
                            value={enabled}
                            onValueChange={() => toggleCameraEnabledMutation.mutate({ cameraId: camera._id, enabled: !enabled })}
                            disabled={toggleCameraEnabledMutation.isPending}
                        />
                    )}
                />
            </List.Section>
            <List.Section title={t("camera.qualitySection")}>
                <List.Item
                    title={t("camera.format")}
                    description={t("camera.formatDescription")}
                    right={() => (
                        <View style={{ maxWidth: 200 }}>
                            <DropDown
                                label={t("camera.format")}
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
            <List.Section title={t("camera.miscSection")}>
                <List.Item title={t("camera.requiresLibcamera")} description={parseBoolean(camera?.requiresLibCamera)} />
                <List.Item title={t("camera.url")} description={camera?.url} />
            </List.Section>
            {!(camera?.supportsMjpeg ?? true) && (camera?.streamsMjpeg ?? true) && (
                <List.Section title={t("camera.warningSection")}>
                    <List.Item
                        title={t("camera.mjpegWarningTitle")}
                        description={t("camera.mjpegWarningDescription")}
                        left={() => <List.Icon icon="alert" color="red" style={{ marginLeft: 16 }} />}
                    />
                </List.Section>
            )}
        </View>
    );
}

export default CameraSettingsModalConfiguration;
