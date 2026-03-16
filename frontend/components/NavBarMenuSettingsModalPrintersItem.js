import { useState } from "react";
import { View } from "react-native";
import { Badge, Button, Card, Icon, Text, useTheme } from "react-native-paper";
import SimpleDialog from "./SimpleDialog";
import { useMutation, useQueryClient } from "@tanstack/react-query";
import API from "../includes/API";
import PrinterSettingsModal from "./PrinterSettingsModal";
import { useLocalization } from "../includes/LocalizationProvider";


const NavbarMenuSettingsModalPrintersItem = ({ printer, isSmallTablet, isSmallLaptop, enqueueSnackbar, handleSettingsModal }) => {
    const { colors } = useTheme();
    const { t } = useLocalization();

    const queryClient = useQueryClient();

    const [ thumbLoadError, setThumbLoadError ] = useState(null);

    const [ showDeleteDialog, setShowDeleteDialog   ] = useState(false);

    const deletePrinterMutation = useMutation({
        mutationFn: (printer) => API.delete(`/printer/${printer._id}`),
        onMutate: (printer) => {
            console.debug('NavbarMenuSettingsModalPrintersItem: deletePrinterMutation: onMutate:', printer);

            setShowDeleteDialog(false);

            return printer;
        },
        onSuccess: () => {
            console.debug('NavbarMenuSettingsModalPrintersItem: deletePrinterMutation: onSuccess:', printer);

            queryClient.invalidateQueries({ queryKey: ['printersList'] });
        },
        onError: (error, printer) => {
            console.error('NavbarMenuSettingsModalPrintersItem: deletePrinterMutation: onError:', error, printer);

            enqueueSnackbar({
                message: t("settings.printerDeleteError", {
                    reason: (error?.response?.data?.message ?? 'unknown error').toLowerCase(),
                }),
                variant: 'error',
                action:  { label: t("notifications.gotIt") }
            });
        }
    });

    const deletePrinter = (printer) => {
        console.debug('NavbarMenuSettingsModalPrintersItem: deletePrinter:', printer);

        deletePrinterMutation.mutate(printer);

        setShowDeleteDialog(false);
    }

    return (
        <>
            <SimpleDialog
                visible={showDeleteDialog}
                setVisible={setShowDeleteDialog}
                title={t("settings.deletePrinterTitle")}
                content={
                    <Text variant="bodyMedium">
                        {t("settings.deletePrinterBody", {
                            name: printer?.machine?.machineType ?? t("settings.unknownPrinter"),
                        })}
                    </Text>
                }
                actions={
                    <>
                        <Button onPress={() => setShowDeleteDialog(false)} loading={deletePrinterMutation.isLoading}>
                            {t("notifications.no")}
                        </Button>
                        <Button onPress={() => deletePrinter(printer)} loading={deletePrinterMutation.isLoading}>
                            {t("notifications.yes")}
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
                            thumbLoadError === null && printer?.mainCamera?.url
                                ? (
                                    <Card.Cover
                                        source={{ uri: `${printer.mainCamera.url}?${new URLSearchParams({ action: 'snapshot' })}` }}
                                        onError={(error) => {
                                            console.error('NavbarMenuSettingsModalPrintersItem: error:', error);

                                            setThumbLoadError(error);
                                        }}
                                    />
                                )
                                : (
                                    <View style={{ height: 195, borderRadius: 12, backgroundColor: colors.primary, alignItems: 'center' }}>
                                        <View style={{ flexDirection: 'column', justifyContent: 'center', alignItems: 'center', height: '100%' }}>
                                            <Icon source={'eye-off'} color={colors.onPrimary} size={48} />
                                            <Text style={{ color: colors.onPrimary, fontSize: 16, textAlign: 'center', paddingVertical: 8 }}>
                                                {t("settings.previewUnavailable")}
                                            </Text>
                                        </View>
                                    </View>
                                )
                        }

                        <Badge
                            style={{ position: 'absolute', top: 8, right: 8, paddingHorizontal: 8 }}
                            theme={{
                                colors: {
                                    error:   printer?.connected ? colors.success : colors.error,
                                    onError: colors.white
                                }
                            }}
                        >
                            {printer?.connected ? t("camera.online") : t("camera.offline")}
                        </Badge>
                    </View>
                    <Card.Title
                        title={printer?.machine?.machineType ?? t("settings.unknownPrinter")}
                        subtitle={printer?.machine?.uuid}
                        titleVariant="headlineSmall"
                    />
                    <Card.Actions>
                        <Button
                            mode="contained"
                            icon="pencil"
                            loading={deletePrinterMutation.isLoading}
                            onPress={() => {
                                console.debug('Edit printer:', printer);

                                handleSettingsModal(printer);
                            }}
                        >{t("camera.edit")}</Button>
                        <Button
                            icon="delete"
                            loading={deletePrinterMutation.isLoading}
                            onPress={() => {
                                console.debug('Delete printer:', printer);

                                if (!printer.connected) {
                                    console.debug('Printer is offline, can delete');

                                    setShowDeleteDialog(true);

                                    return;
                                }

                                console.debug('Printer is online, cannot delete');

                                enqueueSnackbar({
                                    message: t("settings.cannotDeleteOnlinePrinter"),
                                    variant: 'error',
                                    action:  { label: t("notifications.gotIt") }
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
        </>
    );
}

export default NavbarMenuSettingsModalPrintersItem;
