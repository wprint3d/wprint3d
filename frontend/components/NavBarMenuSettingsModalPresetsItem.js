import { useMutation, useQueryClient } from "@tanstack/react-query";
import { useState } from "react";
import { View } from "react-native";
import { Badge, Button, Card, List, Text, TextInput, useTheme } from "react-native-paper";
import API from "../includes/API";
import SimpleDialog from "./SimpleDialog";

const NavBarMenuSettingsModalPresetsItem = ({ material, isSmallTablet, isSmallLaptop, enqueueSnackbar, handleEditModal }) => {
    const { colors } = useTheme();

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
                message: 'An error occurred while deleting the material: ' + (error?.response?.data?.message ?? 'unknown error').toLowerCase(),
                variant: 'error',
                action:  { label: 'Got it' }
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
                title="Delete material"
                visible={showDeleteDialog}
                setVisible={setShowDeleteDialog}
                actions={
                    <>
                        <Button onPress={() => setShowDeleteDialog(false)}>Cancel</Button>
                        <Button onPress={() => deleteMaterial(material)}>Delete</Button>
                    </>
                }
                content={
                    <Text variant="bodyMedium">
                        Are you sure you want to delete the material "<Text style={{ fontWeight: 'bold' }}>{material?.name ?? 'Unknown material'}"</Text>?
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
                        title={material?.name ?? 'Unknown material'}
                        // subtitle={printer?.machine?.uuid}
                        subtitleNumberOfLines={2}
                        titleVariant="headlineSmall"
                    />
                    <Card.Content>
                        <View style={{ flexDirection: 'row', justifyContent: 'space-around' }}>
                            <List.Item title="Hotend" description={(material?.temperatures?.hotend ? `${material.temperatures.hotend}°C` : 'Unknown temperature')} />
                            <List.Item title="Bed"    description={(material?.temperatures?.bed    ? `${material.temperatures.bed}°C`    : 'Unknown temperature')} />
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
                            >Edit</Button>
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
                            >Delete</Button>
                        </View>
                    </Card.Actions>
                </Card>
            </View>
        </>
    );
}

export default NavBarMenuSettingsModalPresetsItem;