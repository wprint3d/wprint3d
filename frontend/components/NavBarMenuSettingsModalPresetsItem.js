import { useMutation, useQueryClient } from "@tanstack/react-query";
import { useState } from "react";
import { View } from "react-native";
import { Badge, Button, Card, List, Text, TextInput, useTheme } from "react-native-paper";
import API from "../includes/API";
import SimpleDialog from "./SimpleDialog";
import { useLocalization } from "../includes/LocalizationProvider";

const NavBarMenuSettingsModalPresetsItem = ({ material, isSmallTablet, isSmallLaptop, enqueueSnackbar, handleEditModal }) => {
    const { colors } = useTheme();
    const { t } = useLocalization();

    const queryClient = useQueryClient();

    const [ showDeleteDialog, setShowDeleteDialog ] = useState(false);

    const deleteMaterialMutation = useMutation({
        mutationFn: (material) => API.delete(`/user/material/${material._id}`),
        onMutate: (material) => {
            console.debug('NavBarMenuSettingsModalPresetsItem: deleteMaterialMutation: onMutate:', material);

            setShowDeleteDialog(false);

            return material;
        },
        onSuccess: () => {
            console.debug('NavBarMenuSettingsModalPresetsItem: deleteMaterialMutation: onSuccess:', material);

            queryClient.invalidateQueries({ queryKey: ['materials'] });
        },
        onError: (error, material) => {
            console.error('NavBarMenuSettingsModalPresetsItem: deleteMaterialMutation: onError:', error, material);

            enqueueSnackbar({
                message: t("presets.deleteError", { reason: (error?.response?.data?.message ?? 'unknown error').toLowerCase() }),
                variant: 'error',
                action:  { label: t("notifications.gotIt") }
            });
        }
    });

    const deleteMaterial = (material) => {
        console.debug('NavBarMenuSettingsModalPresetsItem: deleteMaterial:', material);

        deleteMaterialMutation.mutate(material);
    };

    return (
        <>
            <SimpleDialog
                title={t("presets.deleteTitle")}
                visible={showDeleteDialog}
                setVisible={setShowDeleteDialog}
                actions={
                    <>
                        <Button onPress={() => setShowDeleteDialog(false)}>{t("presets.cancel")}</Button>
                        <Button onPress={() => deleteMaterial(material)}>{t("presets.delete")}</Button>
                    </>
                }
                content={
                    <Text variant="bodyMedium">
                        {t("presets.deleteBody", { name: material?.name ?? t("presets.unknownMaterial") })}
                    </Text>
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
                                : '25%'
                        )
                )
            }}>
                <Card style={{ padding: 8, backgroundColor: colors.surface }}>
                    <Card.Title
                        title={material?.name ?? t("presets.unknownMaterial")}
                        // subtitle={printer?.machine?.uuid}
                        subtitleNumberOfLines={2}
                        titleVariant="headlineSmall"
                    />
                    <Card.Content>
                        <View style={{ flexDirection: 'row', justifyContent: 'space-around' }}>
                            <List.Item title={t("presets.hotend")} description={(material?.temperatures?.hotend ? `${material.temperatures.hotend}°C` : t("presets.unknownTemperature"))} />
                            <List.Item title={t("presets.bed")}    description={(material?.temperatures?.bed    ? `${material.temperatures.bed}°C`    : t("presets.unknownTemperature"))} />
                        </View>
                    </Card.Content>
                    <Card.Actions>
                        <View style={{ flexDirection: 'row', justifyContent: 'center', width: '100%', gap: 16 }}>
                            <Button
                                mode="contained"
                                icon="pencil"
                                loading={deleteMaterialMutation.isLoading}
                                onPress={() => {
                                    console.debug('Edit material:', material);

                                    handleEditModal(material);
                                }}
                            >{t("presets.edit")}</Button>
                            <Button
                                mode="contained"
                                icon="delete"
                                loading={deleteMaterialMutation.isLoading}
                                onPress={() => {
                                    console.debug('Delete material:', material);

                                    setShowDeleteDialog(true);
                                }}
                                theme={{
                                    colors: {
                                        primary:    colors.error,
                                        onPrimary:  colors.white
                                    }
                                }}
                            >{t("presets.delete")}</Button>
                        </View>
                    </Card.Actions>
                </Card>
            </View>
        </>
    );
}

export default NavBarMenuSettingsModalPresetsItem;
