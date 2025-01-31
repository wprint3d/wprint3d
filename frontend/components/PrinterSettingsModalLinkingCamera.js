import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { View } from "react-native";
import { Button, Icon, List, Text, ToggleButton, Tooltip, useTheme } from "react-native-paper";
import API from "../includes/API";
import { useEffect, useState } from "react";
import { useSnackbar } from "react-native-paper-snackbar-stack";
import TooltipToggleButton from "./modules/TooltipToggleButton";
import SimpleDialog from "./SimpleDialog";
import UserPrinterCamera from "./UserPrinterCamera";

const PrinterSettingsModalLinkingCamera = ({ camera, printerDetails, isLoading }) => {
    const { colors } = useTheme();

    const queryClient = useQueryClient();

    const { enqueueSnackbar } = useSnackbar();

    const [ isLinked,       setIsLinked       ] = useState(false);
    const [ isRecordable,   setIsRecordable   ] = useState(false);
    const [ previewVisible, setPreviewVisible ] = useState(false);

    useEffect(() => {
        console.debug('PrinterSettingsModalLinkingCamera: isLinked:', isLinked);

        setIsLinked(printerDetails?.cameras?.includes(camera._id));
        setIsRecordable(printerDetails?.recordableCameras?.includes(camera._id));
    }, [ printerDetails ]);

    const linkCameraMutation = useMutation({
        mutationFn: ({ isLinked, cameraId }) => API.post(`/user/printer/selected/camera/${cameraId}/${isLinked ? 'unlink' : 'link'}`),
        onMutate: ({ cameraId }) => {
            console.debug('PrinterSettingsModalLinkingCamera: linkCameraMutation: onMutate:', cameraId);

            queryClient.invalidateQueries({ queryKey: ['printerDetails', printerDetails._id] });

            return cameraId;
        },
        onSuccess: (cameraId) => {
            console.debug('PrinterSettingsModalLinkingCamera: linkCameraMutation: onSuccess:', cameraId);
        },
        onError: (error, variables, context) => {
            console.error('PrinterSettingsModalLinkingCamera: linkCameraMutation: onError:', error, variables, context);

            enqueueSnackbar({
                message: `An error occurred while ${isLinked ? 'unlinking' : 'linking'} the camera: ${(error?.response?.data?.message ?? 'unknown error').toLowerCase()}`,
                variant: 'error',
                action:  { label: 'Got it' }
            });

            setIsRecordable(variables.isLinked); // reset to previous state
        }
    });

    const recordCameraMutation = useMutation({
        mutationFn: ({ isRecordable, cameraId }) => API.post(`/user/printer/selected/camera/${cameraId}/recording/${isRecordable ? 'disable' : 'enable'}`),
        onMutate: ({ cameraId }) => {
            console.debug('PrinterSettingsModalLinkingCamera: recordCameraMutation: onMutate:', cameraId);

            queryClient.invalidateQueries({ queryKey: ['printerDetails', printerDetails._id] });

            return cameraId;
        },
        onSuccess: (data, variables) => {
            console.debug('PrinterSettingsModalLinkingCamera: recordCameraMutation: onSuccess:', data, variables);

            if (!isLinked && !variables.isRecordable) { setIsLinked(true); }

            setIsRecordable(!variables.isRecordable);
        },
        onError: (error, variables) => {
            console.error('PrinterSettingsModalLinkingCamera: recordCameraMutation: onError:', error, variables, context);

            enqueueSnackbar({
                message: `An error occurred while ${isRecordable ? 'disabling' : 'enabling'} recording for the camera: ${(error?.response?.data?.message ?? 'unknown error').toLowerCase()}`,
                variant: 'error',
                action:  { label: 'Got it' }
            });

            setIsRecordable(variables.isRecordable); // reset to previous state
        }
    });

    return (
        <List.Item
            title={camera.label}
            description={() =>
                <Text>
                    <Text style={{
                        fontSize: 14,
                        color: colors.onSurfaceVariant,
                        textDecorationLine: camera.connected ? 'none' : 'line-through'
                    }}>
                        {camera.node}
                    </Text>
                    {' - '}
                    {!camera.enabled && (
                        <>
                            <Text style={{ marginRight: 5 }}> disabled </Text>
                            <Icon source="power" />
                        </>
                    ) || (!camera.connected && (
                        <>
                            <Text style={{ marginRight: 5 }}> offline </Text>
                            <Icon source="connection" />
                        </>
                    ))}
                </Text>
            }
            right={() => (
                <View style={{ flexDirection: 'row', gap: 4 }}>
                    <TooltipToggleButton
                        onPress={({ wasChecked }) => linkCameraMutation.mutate({ isLinked: wasChecked, cameraId: camera._id })}
                        tooltip="Unlink"
                        disabledTooltip="Link"
                        icon="link-variant"
                        isChecked={isLinked}
                        checkedColor="green"
                        disabled={isLoading || linkCameraMutation.isPending}
                    />

                    <TooltipToggleButton
                        onPress={({ wasChecked }) => recordCameraMutation.mutate({ isRecordable: wasChecked, cameraId: camera._id })}
                        tooltip="Disable recording"
                        disabledTooltip="Enable recording"
                        icon="record"
                        isChecked={isRecordable}
                        checkedColor="red"
                        disabled={isLoading || recordCameraMutation.isPending}
                    />

                    <Tooltip title="Preview">
                        <ToggleButton disabled={isLoading} icon="eye" status="unchecked" onPress={() => setPreviewVisible(true)} />
                    </Tooltip>

                    <SimpleDialog
                        visible={previewVisible}
                        setVisible={setPreviewVisible}
                        title={`Previewing camera "${camera.label}"`}
                        content={<UserPrinterCamera url={camera.url} isConnected={camera.connected} />}
                        style={{ maxWidth: '100%', width: '95%' }}
                        actions={
                            <>
                                <Button
                                    mode="text"
                                    onPress={() => setPreviewVisible(false)}
                                >
                                    Close
                                </Button>
                            </>
                        }
                    />
                </View>
            )}
        />
    );
}

export default PrinterSettingsModalLinkingCamera;