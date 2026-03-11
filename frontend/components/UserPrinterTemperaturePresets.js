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

    const [ showDropDown,     setShowDropDown     ] = useState(false),
          [ selectedMaterial, setSelectedMaterial ] = useState(null),
          [ materials,        setMaterials        ] = useState([{ label: 'Loading...', value: null }]);

    const windowWidth = useWindowDimensions().width;

    const { colors } = useTheme();

    const materialsQuery = useQuery({
        queryKey:   ['materialsList'],
        queryFn:    () => API.get('/user/materials')
    });

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

    useEffect(() => {
        console.debug('preheatMutation:', preheatMutation);
    }, [ preheatMutation.isPending ]);

    useEffect(() => {
        console.debug('UserPrinterTemperaturePresets: materialsQuery:', materialsQuery);

        if (!materialsQuery.isFetched) { return; }

        if (materialsQuery.isError) {
            setMaterials([{
                label: 'Couldn\'t load materials',
                value: null
            }]);

            return;
        }

        const nextMaterials = materialsQuery?.data?.data;

        if (!nextMaterials) {
            setMaterials([{
                label: 'Couldn\'t load materials',
                value: null
            }]);

            return;
        }

        if (!nextMaterials.length) {
            setMaterials([{
                label: 'No materials were defined',
                value: null
            }]);

            return;
        }

        setMaterials(nextMaterials.map(material => ({
            label: `${material.name ?? 'Unknown'} (H: ${material.temperatures.hotend} °C, B: ${material.temperatures.bed} °C)`,
            value: material._id
        })));
    }, [ materialsQuery.data ]);

    useEffect(() => {
        if (!materials.length) { return; }

        setSelectedMaterial(materials[0].value);
    }, [ materials ]);

    useEffect(() => {
        console.debug('selectedMaterial:', selectedMaterial);
    }, [ selectedMaterial ]);

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
                    list={materials}
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
                    loaderSize={26}
                    disabled={selectedMaterial === null}
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