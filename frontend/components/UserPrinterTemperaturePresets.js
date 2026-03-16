import { useEffect, useState } from "react";

import DropDown from "react-native-paper-dropdown";

import { useMutation, useQuery } from "@tanstack/react-query";
import { Icon, Text, TextInput, useTheme } from "react-native-paper";
import { View, useWindowDimensions } from "react-native";

import API from "../includes/API";
import SmallButton from "./SmallButton";

import { useSnackbar } from "react-native-paper-snackbar-stack";
import { useLocalization } from "../includes/LocalizationProvider";

export default function UserPrinterTemperaturePresets() {
    const { enqueueSnackbar } = useSnackbar();
    const { t } = useLocalization();

    const [ showDropDown,     setShowDropDown     ] = useState(false),
          [ selectedMaterial, setSelectedMaterial ] = useState(null),
          [ materials,        setMaterials        ] = useState([{ label: t("printer.controls.loadingMaterials"), value: null }]);

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
                    action:  { label: t("notifications.gotIt") }
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
                label: t("printer.controls.materialsLoadError"),
                value: null
            }]);

            return;
        }

        const nextMaterials = materialsQuery?.data?.data;

        if (!nextMaterials) {
            setMaterials([{
                label: t("printer.controls.materialsLoadError"),
                value: null
            }]);

            return;
        }

        if (!nextMaterials.length) {
            setMaterials([{
                label: t("printer.controls.noMaterialsDefined"),
                value: null
            }]);

            return;
        }

        setMaterials(nextMaterials.map(material => ({
            label: `${material.name ?? t("printer.controls.unknownMaterial")} (H: ${material.temperatures.hotend} °C, B: ${material.temperatures.bed} °C)`,
            value: material._id
        })));
    }, [ materialsQuery.data, materialsQuery.isError, materialsQuery.isFetched, t ]);

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
                    label={t("printer.controls.material")}
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
                            {t("printer.controls.warmUp")}
                        </Text>
                    }
                </SmallButton>
            </View>
        </View>
    );
}
