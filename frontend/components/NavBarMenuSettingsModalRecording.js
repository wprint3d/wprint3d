import { useEffect, useState } from "react";
import UserPaneLoadingIndicator from "./UserPaneLoadingIndicator";
import { useMutation, useQuery } from "@tanstack/react-query";
import API from "../includes/API";
import { Button, List, Switch, Text, TextInput, useTheme } from "react-native-paper";
import { View } from "react-native";
import DropDown from "react-native-paper-dropdown";
import Slider from "@react-native-community/slider";
import { useLocalization } from "../includes/LocalizationProvider";

const NavBarMenuSettingsModalRecording = ({ isSmallTablet, isSmallLaptop, enqueueSnackbar }) => {
    const { colors } = useTheme();
    const { t } = useLocalization();

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
                message: t("settings.recordingUpdateError", { reason: (error.response?.data?.message ?? error.message).toLowerCase() }),
                variant: 'error',
                action:  { label: t("notifications.gotIt") }
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
        return <UserPaneLoadingIndicator message={t("settings.loadingRecordingSettings")} />;
    }

    if (recorderOptions.isFetching) {
        return <UserPaneLoadingIndicator message={t("settings.loadingRecorderOptions")} />;
    }

    const contentMaxWidth = isSmallLaptop || isSmallTablet ? 640 : 760;
    const controlWidth = isSmallTablet ? 150 : 180;

    return (
        <View style={{ width: '100%' }}>
            <View style={{ maxWidth: contentMaxWidth, width: '100%', margin: 'auto' }}>
                <List.Item
                    title={t("settings.toggleRecordingTitle")}
                    description={t("settings.toggleRecordingDescription")}
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
                    title={t("settings.resolutionTitle")}
                    description={t("settings.resolutionDescription")}
                    right={() =>
                        <View style={{ width: controlWidth, maxWidth: controlWidth }}>
                            <DropDown
                                label={t("settings.resolutionTitle")}
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
                    title={t("settings.frameRateTitle")}
                    description={t("settings.frameRateDescription")}
                    right={() =>
                        <View style={{ width: controlWidth, maxWidth: controlWidth }}>
                            <DropDown
                                label={t("settings.frameRateTitle")}
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
                    title={t("settings.captureIntervalTitle")}
                    description={t("settings.captureIntervalDescription")}
                    right={() =>
                        <View style={{ width: controlWidth, maxWidth: controlWidth }}>
                            <Slider
                                style={{ width: controlWidth, top: -5 }}
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
                                {captureInterval === 1
                                    ? t("settings.everySecondsOne", { count: captureInterval })
                                    : t("settings.everySecondsOther", { count: captureInterval })}
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
                        {t("settings.saveChanges")}
                    </Button>
                </View>
            </View>
        </View>
    );
}

export default NavBarMenuSettingsModalRecording;
