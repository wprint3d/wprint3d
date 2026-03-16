import { useState } from "react";
import { View } from "react-native";
import { Badge, Button, Card, Icon, Text, useTheme } from "react-native-paper";
import { useMutation, useQueryClient } from "@tanstack/react-query";

import API from "../includes/API";

import SimpleDialog from "./SimpleDialog";
import UserPrinterCamera from "./UserPrinterCamera";
import { useLocalization } from "../includes/LocalizationProvider";

const NavBarMenuSettingsModalCamerasItem = ({ camera, isSmallTablet, isSmallLaptop, enqueueSnackbar, handleSettingsModal }) => {
    const { colors } = useTheme();
    const { t } = useLocalization();

    const queryClient = useQueryClient();

    const [ thumbLoadError, setThumbLoadError ] = useState(null);

    const [ showPreviewDialog,  setShowPreviewDialog ] = useState(false);
    const [ showDeleteDialog,   setShowDeleteDialog  ] = useState(false);

    const deleteCameraMutation = useMutation({
        mutationFn: (printer) => API.delete(`/camera/${printer._id}`),
        onMutate: (printer) => {
            console.debug('NavBarMenuSettingsModalCamerasItem: deletePrinterMutation: onMutate:', printer);

            setShowDeleteDialog(false);

            return printer;
        },
        onSuccess: () => {
            console.debug('NavBarMenuSettingsModalCamerasItem: deletePrinterMutation: onSuccess:', printer);

            queryClient.invalidateQueries({ queryKey: ['camerasList'] });
        },
        onError: (error, printer) => {
            console.error('NavBarMenuSettingsModalCamerasItem: deletePrinterMutation: onError:', error, printer);

            enqueueSnackbar({
                message: `${t("camera.deleteTitle")}: ${(error?.response?.data?.message ?? 'unknown error').toLowerCase()}`,
                variant: 'error',
                action:  { label: t("password.dismiss") }
            });
        }
    });

    const deleteCamera = (camera) => {
        console.debug('NavbarMenuSettingsModalCamerasItem: deleteCamera:', camera);

        deleteCameraMutation.mutate(camera);

        setShowDeleteDialog(false);
    }

    return (
        <>
            <SimpleDialog
                visible={showDeleteDialog}
                setVisible={setShowDeleteDialog}
                title={t("camera.deleteTitle")}
                content={
                    <Text variant="bodyMedium">
                        {t("camera.deleteBody", { name: camera?.label ?? t("camera.unknownCamera") })}
                    </Text>
                }
                actions={
                    <>
                        <Button onPress={() => setShowDeleteDialog(false)} loading={deleteCameraMutation.isLoading}>
                            {t("camera.no")}
                        </Button>
                        <Button onPress={() => deleteCamera(camera)} loading={deleteCameraMutation.isLoading}>
                            {t("camera.yes")}
                        </Button>
                    </>
                }
            />

            <View style={{
                padding: 8,
                width: (
                    isSmallTablet
                        ? '100%'
                        : (
                            isSmallLaptop
                                ? '50%'
                                : '33%'
                        )
                )
            }}>
                <Card style={{ backgroundColor: colors.surface, padding: 4 }}>
                    <View>
                        {
                            thumbLoadError === null && camera?.url
                                ? (
                                    <Card.Cover
                                        source={{ uri: `${camera.url}?${new URLSearchParams({ action: 'snapshot', t: (new Date()).getTime() })}` }}
                                        onError={(error) => {
                                            console.error('NavbarMenuSettingsModalCamerasItem: error:', error);

                                            setThumbLoadError(error);
                                        }}
                                    />
                                )
                                : (
                                    <View style={{ height: 195, borderRadius: 12, backgroundColor: colors.primary, alignItems: 'center' }}>
                                        <View style={{ flexDirection: 'column', justifyContent: 'center', alignItems: 'center', height: '100%' }}>
                                            <Icon source={'eye-off'} color={colors.onPrimary} size={48} />
                                            <Text style={{ color: colors.onPrimary, fontSize: 16, textAlign: 'center', paddingVertical: 8 }}>
                                                {t("camera.noPreview")}
                                            </Text>
                                        </View>
                                    </View>
                                )
                        }

                        <Badge
                            style={{ position: 'absolute', top: 8, right: 8, paddingHorizontal: 8 }}
                            theme={{
                                colors: {
                                    error:   camera?.connected ? colors.success : colors.error,
                                    onError: colors.white
                                }
                            }}
                        >
                            {camera?.connected ? t("camera.online") : t("camera.offline")}
                        </Badge>
                    </View>
                    <Card.Title
                        title={camera?.label ?? t("camera.unknownCamera")}
                        subtitleNumberOfLines={4}
                        subtitle={
                            <Text>
                                {camera?.node}
                                {'\n'}
                                {!(camera?.supportsMjpeg ?? true) && (camera?.streamsMjpeg ?? true) && (
                                    <Text>
                                        <Icon source="alert" size={12} />

                                        <Text style={{ marginLeft: 2, fontSize: 12, color: colors.onSurfaceVariant }}>
                                            {t("camera.slowMode")}
                                        </Text>
                                    </Text>
                                )}
                            </Text>
                        }
                        titleVariant="headlineSmall"
                    />
                    <Card.Actions>
                        <Button
                            icon="eye"
                            onPress={() => {
                                console.debug('Preview camera:', camera);

                                setShowPreviewDialog(true);
                            }}
                        >{t("camera.preview")}</Button>
                        <Button
                            icon="pencil"
                            loading={deleteCameraMutation.isLoading}
                            onPress={() => {
                                console.debug('Edit camera:', camera);

                                handleSettingsModal(camera);
                            }}
                        >{t("camera.edit")}</Button>
                        <Button
                            icon="delete"
                            loading={deleteCameraMutation.isLoading}
                            onPress={() => {
                                console.debug('Delete camera:', camera);

                                if (!camera.connected) {
                                    console.debug('Camera is offline, can delete');

                                    setShowDeleteDialog(true);

                                    return;
                                }

                                console.debug('Camera is online, cannot delete');

                                enqueueSnackbar({
                                    message: t("camera.cannotDeleteOnline"),
                                    variant: 'error',
                                    action:  { label: t("password.dismiss") }
                                });
                            }}
                            theme={{
                                colors: {
                                    primary:    colors.error,
                                    onPrimary:  colors.white
                                }
                            }}
                        >{t("camera.delete")}</Button>
                    </Card.Actions>
                </Card>
            </View>

            <SimpleDialog
                visible={showPreviewDialog}
                setVisible={setShowPreviewDialog}
                title={t("camera.previewingCamera", { name: camera.label })}
                content={<UserPrinterCamera url={camera.url} isConnected={camera.connected} streamsMjpeg={camera?.streamsMjpeg ?? camera?.supportsMjpeg ?? true} />}
                style={{ maxWidth: 1000, width: '95%' }}
                actions={
                    <Button mode="text" onPress={() => setShowPreviewDialog(false)}>
                        {t("camera.close")}
                    </Button>
                }
            />
        </>
    );
}

export default NavBarMenuSettingsModalCamerasItem;
