import React, { useEffect, useState, useRef } from 'react';
import { View, ActivityIndicator } from 'react-native';
import { Button, Divider, Icon, Modal, Portal, ProgressBar, Text, useTheme } from 'react-native-paper';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import API from '../includes/API';
import { SnackbarProvider, useSnackbar } from 'react-native-paper-snackbar-stack';
import GCodePreview from './modules/GCodePreview';
import TextBold from './TextBold';
import UserPaneLoadingIndicator from './UserPaneLoadingIndicator';
import NavBarMenuSettingsModalPlaceholderItem from './NavBarMenuSettingsModalPlaceholderItem';
import { useEcho } from '../hooks/useEcho';
import { useLocalization } from '../includes/LocalizationProvider';

const JobRecoveryModal = ({ printerId, isSmallTablet, isSmallLaptop, printStatus }) => {
    const queryClient = useQueryClient();
    const { t } = useLocalization();

    const echo = useEcho();

    const { colors } = useTheme();

    const { enqueueSnackbar } = useSnackbar();

    const BUFFER_MAX_LINES = 10000;

    const [minLine, setMinLine] = useState(0);
    const [maxLine, setMaxLine] = useState(0);

    const [recoveryStage,    setRecoveryStage   ] = useState('');
    const [recoveryProgress, setRecoveryProgress] = useState(0);

    const [isVisible, setIsVisible] = useState(false);

    const [isRendered, setIsRendered] = useState(false);

    const previewRef = useRef(null);

    const jobBackupInterval = useQuery({
        queryKey: ['jobBackupInterval'],
        queryFn: () => API.get('/config/jobBackupInterval'),
        enabled: isVisible
    });

    const backupIntervals = useQuery({
        queryKey: ['backupIntervals'],
        queryFn: () => API.get('/enum/BackupInterval/constants'),
        enabled: isVisible && jobBackupInterval.isFetched
    });

    const recoveryStages = useQuery({
        queryKey: ['recoveryStages'],
        queryFn: () => API.get('/enum/RecoveryStage/constants'),
        enabled: isVisible && jobBackupInterval.isFetched && backupIntervals.isFetched
    });

    const previewGcodeQuery = useQuery({
        queryKey: ['baseGcode'],
        queryFn:  () => API.get('/user/printer/selected/print/gcode/lines/stream', {
            startLine:  minLine,
            endLine:    maxLine
        }),
        enabled: false
    });

    const startRecoveryMutation = useMutation({
        mutationFn: () => API.post('/user/printer/selected/print/recover', { startFrom: maxLine }),
        onSuccess:  () => {
            enqueueSnackbar({
                message: t("printer.recovery.recoverSuccess"),
                variant: 'success',
                action: { label: t("notifications.gotIt") }
            });

            setIsVisible(false);

            queryClient.invalidateQueries({ queryKey: [ 'printStatus' ] });
            queryClient.refetchQueries({ queryKey: [ 'fileList' ] });
        },
        onError:    (error) => {
            enqueueSnackbar({
                message: t("printer.recovery.recoverError", {
                    reason: error.response?.data?.message || error.message || "unknown error",
                }),
                variant: 'error',
                action: { label: t("notifications.dismiss") }
            });
        }
    });

    const skipRecoveryMutation = useMutation({
        mutationFn: () => API.delete('/user/printer/selected/print/recover'),
        onSuccess:  () => {
            enqueueSnackbar({
                message: t("printer.recovery.cancelRecoverySuccess"),
                variant: 'success',
                action: { label: t("notifications.gotIt") }
            });

            setIsVisible(false);
        },
        onError:    (error) => {
            enqueueSnackbar({
                message: t("printer.recovery.cancelRecoveryError", {
                    reason: error.response?.data?.message || error.message || "unknown error",
                }),
                variant: 'error',
                action: { label: t("notifications.dismiss") }
            });
        }
    });

    const handleSkip = () => {
        skipRecoveryMutation.mutate();
    };

    const handleRecover = () => {
        setRecoveryStage(t("printer.recovery.waitingForServer"));
        setRecoveryProgress(0);

        startRecoveryMutation.mutate();
    };

    const makeChannel = ({ channelName, eventName, callback }) => {
        const channel = echo.private(channelName);

        console.log(`JobRecoveryModal: subscribing to channel ${channelName} for event ${eventName}...`);

        return channel.listen(eventName, (event) => {
            console.debug(`JobRecoveryModal: received event ${eventName}:`, event);

            callback(event);
        });
    };

    useEffect(() => {
        // console.debug(
        //     'jobBackupInterval:',   jobBackupInterval,
        //     'backupIntervals:',     backupIntervals,
        //     'previewGcodeQuery:',   previewGcodeQuery
        // );
    }, [jobBackupInterval, backupIntervals, previewGcodeQuery]);

    useEffect(() => {
        if (!printStatus) { return; }

        const { activeFile, hasActiveJob, lastJobHasFailed, lastLine } = printStatus;

        setMaxLine(lastLine);

        if (activeFile && (!hasActiveJob || lastJobHasFailed)) {
            setIsVisible(true);
        }
    }, [printStatus]);

    useEffect(() => {
        if (!isVisible) { return; }

        previewRef.current?.clear();

        if (!previewGcodeQuery?.data?.data) { return; }

        previewRef.current?.processGCode(previewGcodeQuery.data?.data);
    }, [
        isVisible,
        previewGcodeQuery,
        previewRef,
        previewRef.current,
        jobBackupInterval,
        backupIntervals,
        isRendered
    ]);

    useEffect(() => {
        if (!isVisible || !isRendered) { return; }

        if (!echo) {
            console.debug('JobRecoveryModal: Echo is not initialized yet');

            return;
        }

        if (!printerId) {
            console.warn('JobRecoveryModal: printerId is missing');

            return;
        }

        const stageChangeChannel = makeChannel({
            channelName: `recovery-stage-changed.${printerId}`,
            eventName:   'RecoveryStageChanged',
            callback:    (event) => {
                console.debug('JobRecoveryModal: Recovery stage changed:', event);
        
                if (event?.stage === undefined) {
                    console.warn('JobRecoveryModal: Invalid recovery stage event:', event);
        
                    return;
                }
        
                setRecoveryStage(() => {
                    switch (event.stage) {
                        case RECOVERY_STAGES?.COUNT_LINES:
                            return t("printer.recovery.countingLines");
                        case RECOVERY_STAGES?.PARSE_FILE:
                            return t("printer.recovery.parsingFile");
                        default:
                            return t("printer.recovery.waitingForServer");
                    }
                });
            }
        });

        const progressChangeChannel = makeChannel({
            channelName: `recovery-progress.${printerId}`,
            eventName:   'RecoveryProgress',
            callback:    (event) => {
                console.debug('JobRecoveryModal: recovery progress changed:', event);
        
                if (event?.percentage === undefined) {
                    console.warn('JobRecoveryModal: missing recovery progress percentage:', event);
        
                    return;
                }
        
                setRecoveryProgress(event.percentage);
            }
        });

        return () => {
            console.debug('JobRecoveryModal: unsubscribing from the recovery stage and progress channels...');

            if (stageChangeChannel) {
                stageChangeChannel.stopListening('RecoveryStage');
            }

            if (progressChangeChannel) {
                progressChangeChannel.stopListening('RecoveryProgress');
            }
        };
    }, [isVisible, isRendered, echo]);

    useEffect(() => {
        if (!isVisible || !isRendered) { return; }

        console.debug(`JobRecoveryModal: recovery stage: ${recoveryStage}`);
    }, [recoveryStage]);

    useEffect(() => {
        if (!isVisible) { return; }

        const nextMinLine = maxLine - BUFFER_MAX_LINES;

        setMinLine(
            nextMinLine >= 0
                ? nextMinLine
                : 0
        );
    }, [maxLine]);

    useEffect(() => {
        if (!isVisible) { return; }

        previewGcodeQuery.refetch();
    }, [minLine, maxLine]);

    const SELECTED_INTERVAL = jobBackupInterval?.data?.data,
          BACKUP_INTERVALS  = backupIntervals?.data?.data,
          RECOVERY_STAGES   = recoveryStages?.data?.data;

    // console.debug('JobRecoveryModal: SELECTED_INTERVAL:', SELECTED_INTERVAL);
    // console.debug('JobRecoveryModal: BACKUP_INTERVALS:',  BACKUP_INTERVALS);
    // console.debug('JobRecoveryModal: RECOVERY_STAGES:',   RECOVERY_STAGES);

    const IS_LOADING = startRecoveryMutation.isPending || skipRecoveryMutation.isPending;

    return (
        <Portal>
            <SnackbarProvider maxSnack={4}>
                <Modal
                    visible={isVisible}
                    contentContainerStyle={{
                        backgroundColor: colors.elevation.level1,
                        height: isSmallTablet ? '100%' : '95%',
                        width:  isSmallTablet ? '100%' : '95%',
                        maxWidth: 960,
                        alignSelf: 'center',
                        padding: 16,
                        paddingVertical: 32,
                        overflow: 'scroll'
                    }}
                >
                    <View style={{ justifyContent: 'center', alignItems: 'center' }}>
                        <Text variant='headlineLarge'>
                            {t("printer.recovery.title")}
                        </Text>

                        {(jobBackupInterval.isLoading || backupIntervals.isLoading || recoveryStages.isLoading) ? (
                            <UserPaneLoadingIndicator message={t("printer.recovery.loadingSettings")} />
                        ) : (
                            jobBackupInterval.isError || backupIntervals.isError || recoveryStages.isError ? (
                                <NavBarMenuSettingsModalPlaceholderItem
                                    icon={'alert-circle-outline'}
                                    message={t("printer.recovery.loadSettingsError", { reason: (
                                        jobBackupInterval.error?.response?.data?.message
                                        ||
                                        backupIntervals.error?.response?.data?.message
                                        ||
                                        recoveryStages.error?.response?.data?.message
                                    ) })}
                                />
                        ) : (
                            SELECTED_INTERVAL === BACKUP_INTERVALS?.NEVER ? (
                                <NavBarMenuSettingsModalPlaceholderItem
                                    icon='alert-circle-outline'
                                    message={t("printer.recovery.disabled")}
                                    actions={
                                        <>
                                            <Button mode="contained" onPress={handleSkip} style={{ marginTop: 16 }}>
                                                <Icon source="skip-next" color={colors.onPrimary} size={16} /> {t("notifications.close")}
                                            </Button>
                                        </>
                                    }
                                />
                            ) : (
                                <View style={{ width: '100%', alignItems: 'center' }} onLayout={() => setIsRendered(true)}>
                                    <Divider style={{ width: '85%', marginVertical: 20 }} />
                                
                                    <Text variant='headlineSmall' style={{ marginBottom: 16 }}>
                                        {t("printer.recovery.previewTitle")}
                                    </Text>
                                    <Text style={{ marginBottom: 20 }}>
                                        {t("printer.recovery.previewDescription")}
                                    </Text>
                                
                                    <View style={{ flexDirection: 'row', justifyContent: 'center', width: '100%', flexWrap: 'wrap', flex: 1 }}>
                                        <View style={{
                                            marginVertical: 8,
                                            justifyContent: 'center',
                                            width: '100%',
                                            maxWidth: 500,
                                            maxHeight: '100%'
                                        }}>
                                            <GCodePreview
                                                ref={previewRef}
                                                backgroundColor={colors.background}
                                                buildVolume={{ x: 220, y: 220, z: 250 }}
                                                renderExtrusion={true}
                                                renderTravel={true}
                                                key={colors.background}
                                            />

                                            <View style={{ minHeight: 64, flexDirection: 'column', width: '100%', marginTop: 16, justifyContent: 'center' }}>
                                                <View style={{ flexDirection: 'row', justifyContent: 'center' }}>
                                                    {
                                                        startRecoveryMutation.isPending
                                                            ? (
                                                                recoveryStage.length > 0 &&
                                                                    <View style={{ alignItems: 'center', flexDirection: 'column' }}>
                                                                        <Text style={{ marginTop: 16 }}>
                                                                            {
                                                                                recoveryProgress < 100
                                                                                    ? recoveryStage
                                                                                    : t("printer.recovery.finishingUp")
                                                                            }
                                                                        </Text>

                                                                        {
                                                                            recoveryProgress <= 0
                                                                                ? <ActivityIndicator
                                                                                    style={{ marginTop: 16 }}
                                                                                    color={colors.primary}
                                                                                />
                                                                                : <ProgressBar
                                                                                    style={{ marginTop: 16 }}
                                                                                    color={colors.primary}
                                                                                    progress={recoveryProgress / 100}
                                                                                />
                                                                        }
                                                                    </View>
                                                            ) : (
                                                                <>
                                                                    <Button
                                                                        mode='contained'
                                                                        loading={IS_LOADING}
                                                                        disabled={IS_LOADING}
                                                                        onPress={() => setMaxLine(maxLine => maxLine - 1)}
                                                                    >
                                                                        <Icon source="arrow-left" color={colors.onPrimary} size={16} />
                                                                    </Button>

                                                                    <Button
                                                                        mode="contained"
                                                                        loading={IS_LOADING}
                                                                        disabled={IS_LOADING}
                                                                        onPress={() => handleRecover()}
                                                                        style={{ marginHorizontal: 8 }}
                                                                    >
                                                                        <Icon source="play" color={colors.onPrimary} size={16} /> {t("printer.recovery.continueFromLine", { line: maxLine })}
                                                                    </Button>

                                                                    <Button
                                                                        mode='contained'
                                                                        loading={IS_LOADING}
                                                                        disabled={IS_LOADING}
                                                                        onPress={() => setMaxLine(maxLine => maxLine + 1)}
                                                                    >
                                                                        <Icon source="arrow-right" color={colors.onPrimary} size={16} />
                                                                    </Button>
                                                                </>
                                                            )
                                                    }
                                                </View>
                                            </View>
                                        </View>

                                        <Text style={{ marginTop: 16, textAlign: 'center', maxWidth: 640 }}>
                                            {t("printer.recovery.adjustDescription")}
                                        </Text>
                                    </View>
                                
                                    <Divider style={{ width: '85%', marginVertical: 20 }} />
                                
                                    <Text variant='headlineSmall' style={{ marginBottom: 16 }}>
                                        {t("printer.recovery.aboutTitle")}
                                    </Text>
                                    <Text style={{ maxWidth: 860, textAlign: 'center' }}>
                                        {t("printer.recovery.aboutDescription")}
                                    </Text>
                                    <View style={{ flexDirection: 'row', justifyContent: 'center', width: '100%' }}>
                                        <Button
                                            mode="contained"
                                            loading={IS_LOADING}
                                            disabled={IS_LOADING}
                                            onPress={handleSkip} style={{ marginVertical: 24 }}
                                        >
                                            <Icon source="skip-next" color={colors.onPrimary} size={16} /> {t("printer.recovery.cancelRecovery")}
                                        </Button>
                                    </View>
                                </View>
                            )
                        ))}
                    </View>
                </Modal>
            </SnackbarProvider>
        </Portal>
    );
};

export default JobRecoveryModal;
