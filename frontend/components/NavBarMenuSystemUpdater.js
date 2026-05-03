import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useEffect, useState } from "react";
import SimpleDialog from "./SimpleDialog";
import { ActivityIndicator, Badge, Button, Icon, Portal, Snackbar, Text, useTheme } from "react-native-paper";
import { useEcho } from "../hooks/useEcho";
import API from "../includes/API";
import { View } from "react-native";
import FormattedTextView from "./FormattedTextView";
import { useLocalization } from "../includes/LocalizationProvider";

const NavBarMenuSystemUpdater = ({ enqueueSnackbar, checkForUpdatesMutation }) => {
    const [ isUpdateDialogOpen, setIsUpdateDialogOpen ] = useState(false),
          [ lastUpdateLog,      setLastUpdateLog      ] = useState(null);

    const { colors } = useTheme();
    const { t } = useLocalization();

    const echo = useEcho();

    const queryClient = useQueryClient();

    const checkForUpdatesToggleQuery = useQuery({
        queryKey: ['checkForUpdatesToggle'],
        queryFn:  () => API.get('/config/checkForUpdates')
    });

    const updatesStatusQuery = useQuery({
        queryKey: ['updateStatus'],
        queryFn:  () => API.get('/app/update/status'),
        enabled:  checkForUpdatesToggleQuery.isSuccess && checkForUpdatesToggleQuery.data?.data
    });

    const updateSystemMutation = useMutation({
        mutationFn: () => API.post('/app/update/install'),
        onMutate: () => {
            setLastUpdateLog(null);
        },
        onSuccess:  () => {
            queryClient.invalidateQueries({ queryKey: ['checkLogin'] });

            setIsUpdateDialogOpen(false);

            enqueueSnackbar({
                message: 'The system has been updated successfully!',
                variant: 'success',
                action:  { label: 'Got it' }
            });
        },
        onError: (error) => {
            enqueueSnackbar({
                message: 'An error occurred while updating the system: ' + (error?.response?.data?.message || error.message),
                variant: 'error',
                action:  { label: 'Got it' }
            });
        }
    });

    useEffect(() => {
        console.debug('NavBarMenuSystemUpdater: checkForUpdatesToggleQuery:', checkForUpdatesToggleQuery);
        console.debug('NavBarMenuSystemUpdater: updatesStatusQuery:', updatesStatusQuery);

        if (
            !checkForUpdatesToggleQuery.isSuccess
            ||
            !checkForUpdatesToggleQuery.isFetched
            ||
            !updatesStatusQuery.isSuccess
            ||
            !updatesStatusQuery.isFetched
        ) { return; }

        const isCheckEnabled = !!checkForUpdatesToggleQuery.data?.data;

        if (!isCheckEnabled) {
            console.debug('NavBarMenuSystemUpdater: automatic check for updates is disabled.');

            return;
        }

        const updatableImages = updatesStatusQuery.data?.data;

        if (!updatableImages || updatableImages.length === 0) {
            console.debug('NavBarMenuSystemUpdater: no updates available.');

            return;
        }

        console.debug('NavBarMenuSystemUpdater: an update is available!');

        setIsUpdateDialogOpen(true);
    }, [ checkForUpdatesToggleQuery.data, updatesStatusQuery.data ]);

    useEffect(() => {
        if (!echo) {
            console.debug('NavBarMenuSystemUpdater: echo is not ready.');

            return;
        }

        const channel = echo.channel('update-log-changed');

        channel.listen('UpdateLogChanged', (event) => {
            console.debug('NavBarMenuSystemUpdater: UpdateLogChanged:', event);

            setLastUpdateLog(event?.message);
        });

        return () => {
            channel.stopListening('UpdateLogChanged');
        };
    }, [ echo ]);

    return (
        <>
            <Portal>
                <Snackbar
                    visible={checkForUpdatesToggleQuery.isFetched && (updatesStatusQuery.isFetching || checkForUpdatesMutation.isPending)}
                    style={{
                        backgroundColor: colors.elevation.level2,
                        position: 'absolute',
                        bottom: 16,
                        right: 16,
                        maxWidth: 520,
                        padding: 2
                    }}
                >
                    <View style={{ flexDirection: 'row', alignItems: 'center' }}>
                        <ActivityIndicator animating={true} color={colors.primary} size={24} style={{ marginRight: 12 }} />
                        <Text> {t("settings.updateChecking")} </Text>
                    </View>
                </Snackbar>
            </Portal>

            <SimpleDialog
                title={
                    <Text>
                        {t("settings.updateAvailableTitle")}
                        <Badge
                            style={{
                                marginLeft: 8,
                                paddingHorizontal: 6,
                                paddingVertical: 2,
                                fontSize: 12,
                                position: 'relative',
                                top: -12
                            }}
                            theme={{ colors: { onError: colors.white } }}
                            size={48}
                        >
                            {t("settings.updateExperimentalBadge")}
                        </Badge>
                    </Text>
                }
                content={
                    <>
                        <Text>
                            {t("settings.updateConfirmQuestion")}
                            {'\n\n'}
                            {t("settings.updateUnavailableNotice")}
                            {'\n\n'}
                            {t("settings.updateRestartNotice")}
                        </Text>
                        {(lastUpdateLog !== null || updateSystemMutation.isPending) && (
                            <View style={{ marginTop: 24 }}>
                                <FormattedTextView text={lastUpdateLog} />
                            </View>
                        )}
                    </>
                }
                left={<Icon name='update' size={48} />}
                visible={isUpdateDialogOpen}
                setVisible={setIsUpdateDialogOpen}
                onDismiss={() => {
                    if (updateSystemMutation.isPending) { return; }

                    setIsUpdateDialogOpen(false);
                }}
                actions={
                    <>
                        <Button
                            mode="text"
                            onPress={() => setIsUpdateDialogOpen(false)}
                            loading={updateSystemMutation.isPending}
                            disabled={updateSystemMutation.isPending}
                        >
                            {t("notifications.cancel")}
                        </Button>

                        <Button
                            mode="contained"
                            onPress={() => { updateSystemMutation.mutate(); }}
                            loading={updateSystemMutation.isPending}
                            disabled={updateSystemMutation.isPending}
                        >
                            {t("settings.updateAction")}
                        </Button>
                    </>
                }
            />
        </>
    );
}

export default NavBarMenuSystemUpdater;
