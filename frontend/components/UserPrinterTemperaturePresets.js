import { useEffect, useState } from "react";

import DropDown from "react-native-paper-dropdown";

import { useMutation, useQuery } from "@tanstack/react-query";
import { Icon, Text, TextInput, useTheme } from "react-native-paper";
import { View, useWindowDimensions } from "react-native";

import API from "../includes/API";
import SmallButton from "./SmallButton";

import { useSnackbar } from "react-native-paper-snackbar-stack";

export default function UserPrinterTemperaturePresets() {
    const { enqueueSnackbar } = useSnackbar();

    const [ showDropDown,     setShowDropDown     ] = useState(false);
    const [ selectedMaterial, setSelectedMaterial ] = useState(null);

    const windowWidth = useWindowDimensions().width;

    const { colors } = useTheme();

    const materialsList = useQuery({
        queryKey:   ['materialsList'],
        queryFn:    () => API.get('/user/materials')
    });

    console.debug('materialsList:', materialsList);

    const preheatMutation = useMutation({
        mutationKey: ['preheatMutation'],
        mutationFn:  materialId => API.post(`/user/printer/selected/preheat/${materialId}`),
        onError:     (
            error => {
                enqueueSnackbar({
                    message: error.response.data.message,
                    variant: 'error',
                    action:  { label: 'Got it' }
                });
            }
        )
    });

    console.debug('preheatMutation:', preheatMutation);

    useEffect(() => {
        if (
            !materialsList.isSuccess
            ||
            !materialsList.data.data
        ) { return; }

        setSelectedMaterial(materialsList.data.data[0]._id);
    }, [ materialsList.data ]);

    console.debug('selectedMaterial:', selectedMaterial);

    return (
        <View style={{ flexDirection: 'row', gap: 8, paddingTop: 10 }}>
            <View style={{ flex: 'auto' }}>
                <DropDown
                    label="Material"
                    mode="outlined"
                    visible={showDropDown}
                    showDropDown={() => setShowDropDown(true)}
                    onDismiss={()    => setShowDropDown(false)}
                    value={selectedMaterial}
                    list={
                        materialsList.isSuccess
                            ? materialsList.data.data.map(material => ({
                                label: `${material.name ?? 'Unknown'} (H: ${material.temperatures.hotend} °C, B: ${material.temperatures.bed} °C)`,
                                value: material._id
                            }))
                            : [{
                                label:  'Loading...',
                                value:  null
                            }]
                    }
                    setValue={newMaterialId => setSelectedMaterial(newMaterialId)}
                    inputProps={{
                        right: (
                            <TextInput.Icon
                                icon={showDropDown ? 'menu-up' : 'menu-down'}
                                onPress={() => setShowDropDown(true)}
                            />
                        )
                    }}
                />
            </View>
            <View style={{ alignSelf: 'end' }}>
                <SmallButton
                    style={{ backgroundColor: colors.primary }}
                    left={
                        <Icon
                            source="thermometer"
                            size={26}
                            color={colors.onPrimary}
                        />
                    }
                    textStyle={{
                        display: 'flex',
                        alignItems: 'center',
                        paddingHorizontal: (
                            windowWidth <= 1440 // medium laptop
                                ? 0
                                : 40
                        )
                    }}
                    loading={preheatMutation.isPending}
                    onPress={() => preheatMutation.mutate(selectedMaterial)}
                >
                    {windowWidth > 768 && // small tablets and large mobile phones
                        <Text style={{ color: colors.onPrimary }}>
                            Warm up
                        </Text>
                    }
                </SmallButton>
            </View>
        </View>
    );
}