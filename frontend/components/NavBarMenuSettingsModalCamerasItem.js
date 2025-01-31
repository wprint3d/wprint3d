import { useState } from "react";
import { View } from "react-native";
import { Badge, Button, Card, Icon, Text, useTheme } from "react-native-paper";
import { useMutation, useQueryClient } from "@tanstack/react-query";

import API from "../includes/API";

import SimpleDialog from "./SimpleDialog";
import UserPrinterCamera from "./UserPrinterCamera";

const NavBarMenuSettingsModalCamerasItem = ({ camera, isSmallTablet, isSmallLaptop, enqueueSnackbar, handleSettingsModal }) => {
    const { colors } = useTheme();

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
                message: 'An error occurred while deleting the printer: ' + (error?.response?.data?.message ?? 'unknown error').toLowerCase(),
                variant: 'error',
                action:  { label: 'Got it' }
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
                title="Delete camera"
                content={
                    <Text variant="bodyMedium">
                        Are you sure you want to delete the camera "<Text style={{ fontWeight: 'bold' }}>{camera?.label ?? 'Unknown camera'}"</Text>?
                    </Text>
                }
                actions={
                    <>
                        <Button onPress={() => setShowDeleteDialog(false)} loading={deleteCameraMutation.isLoading}>
                            No
                        </Button>
                        <Button onPress={() => deleteCamera(camera)} loading={deleteCameraMutation.isLoading}>
                            Yes
                        </Button>
                    </>
                }
            />

            <Card style={{
                backgroundColor: colors.surface,
                padding: 4,
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
                <View>
                    {
                        thumbLoadError === null && camera?.url
                            ? (
                                <Card.Cover
                                    source={{ uri: `${camera.url}/?action=snapshot` }}
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
                                            No preview available
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
                        {camera?.connected ? 'Online' : 'Offline'}
                    </Badge>
                </View>
                <Card.Title
                    title={camera?.label ?? 'Unknown camera'}
                    subtitle={camera?.node}
                    titleVariant="headlineSmall"
                />
                <Card.Actions>
                    <Button
                        icon="eye"
                        onPress={() => {
                            console.debug('Preview camera:', camera);

                            setShowPreviewDialog(true);
                        }}
                    >Preview</Button>
                    <Button
                        icon="pencil"
                        loading={deleteCameraMutation.isLoading}
                        onPress={() => {
                            console.debug('Edit camera:', camera);

                            handleSettingsModal(camera);
                        }}
                    >Edit</Button>
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
                                message: 'Cannot delete a camera while it\'s online',
                                variant: 'error',
                                action:  { label: 'Got it' }
                            });
                        }}
                        theme={{
                            colors: {
                                primary:    colors.error,
                                onPrimary:  colors.white
                            }
                        }}
                    >Delete</Button>
                </Card.Actions>
            </Card>

            <SimpleDialog
                visible={showPreviewDialog}
                setVisible={setShowPreviewDialog}
                title={`Previewing camera "${camera.label}"`}
                content={<UserPrinterCamera url={camera.url} isConnected={camera.connected} />}
                style={{ maxWidth: '100%', width: '95%' }}
                actions={
                    <>
                        <Button
                            mode="text"
                            onPress={() => setShowPreviewDialog(false)}
                        >
                            Close
                        </Button>
                    </>
                }
            />
        </>
    );
}

export default NavBarMenuSettingsModalCamerasItem;