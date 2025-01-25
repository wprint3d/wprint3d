import { useEffect, useState } from "react";
import UserPaneLoadingIndicator from "./UserPaneLoadingIndicator";
import { useMutation, useQuery } from "@tanstack/react-query";
import API from "../includes/API";
import { Button, List, Switch, Text, TextInput, useTheme } from "react-native-paper";
import { View } from "react-native";
import DropDown from "react-native-paper-dropdown";
import Slider from "@react-native-community/slider";

const NavBarMenuSettingsModalRecording = ({ isSmallTablet, isSmallLaptop, enqueueSnackbar }) => {
    const { colors } = useTheme();

    const userRecordingSettings = useQuery({
        queryKey: ['user'],
        queryFn:  () => API.get('/user')
    });

    const recorderOptions = useQuery({
        queryKey: ['recorderOptions'],
        queryFn:  () => API.get('/recorder/options')
    });

    const updateRecordingSettings = useMutation({
        mutationFn:  ({ settings }) => API.put('/user/settings', { settings }),
        onSuccess:   (response, variables) => {
            console.debug('NavBarMenuSettingsModalRecording: updateRecordingSettings: success:', response, variables);

            setEnabled(variables?.settings?.recording?.enabled);
            setResolution(variables?.settings?.recording?.resolution);
            setFramerate(variables?.settings?.recording?.framerate);
            setCaptureInterval(variables?.settings?.recording?.captureInterval);

            setHasChanges(false);
        },
        onError:     (error) => {
            console.error('NavBarMenuSettingsModalRecording: updateRecordingSettings: error:', error);

            enqueueSnackbar({
                message: 'Failed to update recording settings: ' + (error.response?.data?.message ?? error.message).toLowerCase(),
                variant: 'error',
                action:  { label: 'Got it' }
            });
        }
    });

    const recordingSettings     = userRecordingSettings?.data?.data?.settings?.recording;

    const supportedResolutions  = recorderOptions?.data?.data?.resolutions  ?? [],
          supportedFrameRates   = recorderOptions?.data?.data?.framerates   ?? [];

    const [ enabled,                _setEnabled               ] = useState(false),
          [ resolution,             _setResolution            ] = useState(null),
          [ framerate,              _setFramerate             ] = useState(null),
          [ captureInterval,        _setCaptureInterval       ] = useState(null),
          [ hasChanges,             setHasChanges             ] = useState(false),
          [ showResolutionDropDown, setShowResolutionDropDown ] = useState(false),
          [ showFrameRateDropDown,  setShowFrameRateDropDown  ] = useState(false);

    const setEnabled = (value) => {
        if (value === enabled) { return; }

        _setEnabled(value);
        setHasChanges(true);
    };

    const setResolution = (value) => {
        if (value === resolution) { return; }

        _setResolution(value);
        setHasChanges(true);
    };

    const setFramerate = (value) => {
        if (value === framerate) { return; }

        _setFramerate(value);
        setHasChanges(true);
    };

    const setCaptureInterval = (value) => {
        if (value === captureInterval) { return; }

        _setCaptureInterval(value);
        setHasChanges(true);
    };

    const saveChanges = () => {
        const changes = { enabled, resolution, framerate, captureInterval };

        console.debug('NavBarMenuSettingsModalRecording: saveChanges:', changes);

        updateRecordingSettings.mutate({
            settings: {
                recording: {
                    ...recordingSettings,
                    ...changes
                }
            }
        });
    };

    useEffect(() => {
        if (!recordingSettings) { return; }

        setEnabled(recordingSettings?.enabled);
        setResolution(recordingSettings?.resolution);
        setFramerate(recordingSettings?.framerate);
        setCaptureInterval(recordingSettings?.captureInterval);

        if (userRecordingSettings.isFetched && recorderOptions.isFetched) {
            setHasChanges(false);
        }
    }, [ recordingSettings ]);

    useEffect(() => {
        console.debug('NavBarMenuSettingsModalRecording: hasChanges:', hasChanges);
    }, [ hasChanges ]);

    if (userRecordingSettings.isFetching) {
        return <UserPaneLoadingIndicator message={`Loading recording settings...`} />;
    }

    if (recorderOptions.isFetching) {
        return <UserPaneLoadingIndicator message={`Loading recorder options...`} />;
    }

    return (
        <View style={{ width: '100%' }}>
            <View style={{ maxWidth: 480, width: '100%', margin: 'auto' }}>
                <List.Item
                    title="Toggle recording"
                    description="Whether to enable or disable recording."
                    right={() =>
                        <Switch
                            value={enabled}
                            onValueChange={setEnabled}
                            disabled={updateRecordingSettings.isPending}
                            trackColor={{ false: colors.onError, true: colors.primary }}
                            thumbColor={enabled ? colors.primary : colors.error}
                        />
                    }
                />

                <List.Item
                    title="Resolution"
                    description="The resolution of the output video."
                    right={() =>
                        <View style={{ maxWidth: 125 }}>
                            <DropDown
                                label=" "
                                mode="outlined"
                                visible={showResolutionDropDown}
                                showDropDown={() => setShowResolutionDropDown(true)}
                                onDismiss={() => setShowResolutionDropDown(false)}
                                value={resolution}
                                setValue={(value) => setResolution(value)}
                                list={supportedResolutions.map((resolution) => ({ label: resolution, value: resolution }))}
                                inputProps={{ right: null }}
                            />
                        </View>
                    }
                />

                <List.Item
                    title="Frame rate"
                    description="The frame rate of the output video."
                    right={() =>
                        <View style={{ maxWidth: 125 }}>
                            <DropDown
                                label=" "
                                mode="outlined"
                                visible={showFrameRateDropDown}
                                showDropDown={() => setShowFrameRateDropDown(true)}
                                onDismiss={() => setShowFrameRateDropDown(false)}
                                value={parseInt(framerate)}
                                setValue={(value) => setFramerate(value)}
                                list={supportedFrameRates.map((framerate) => ({
                                    label: `${framerate} FPS`,
                                    value: framerate
                                }))}
                                inputProps={{ right: null }}
                            />
                        </View>
                    }
                />

                <List.Item
                    title="Capture interval"
                    description="The interval at which frames are captured."
                    right={() =>
                        <View style={{ maxWidth: 125 }}>
                            <Slider
                                style={{ width: 125, top: -5 }}
                                minimumValue={0.25}
                                maximumValue={5.0}
                                step={0.25}
                                value={captureInterval}
                                onValueChange={setCaptureInterval}
                                disabled={updateRecordingSettings.isPending}
                                thumbTintColor={colors.primary}
                                minimumTrackTintColor={colors.primary}
                                maximumTrackTintColor={colors.elevation.level5}
                            />
                            <Text style={{ textAlign: 'center' }}>
                                Every {captureInterval} second{captureInterval !== 1 ? 's' : ''}
                            </Text>
                        </View>
                    }
                />

                <View style={{ width: '100%', flexDirection: 'column', alignItems: 'center' }}>
                    <Button
                        mode="contained"
                        icon="content-save"
                        onPress={saveChanges}
                        loading={updateRecordingSettings.isPending}
                        style={{ maxWidth: 175, alignContent: 'center', marginTop: 24 }}
                        disabled={!hasChanges || updateRecordingSettings.isPending}
                    >
                        Save changes
                    </Button>
                </View>
            </View>
        </View>
    );
}

export default NavBarMenuSettingsModalRecording;