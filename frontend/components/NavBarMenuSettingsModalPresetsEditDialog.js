import { useIsFetching, useMutation, useQueryClient } from "@tanstack/react-query";
import { useEffect, useState } from "react";
import { Button, TextInput } from "react-native-paper";
import SimpleDialog from "./SimpleDialog";
import API from "../includes/API";
import { useSnackbar } from "react-native-paper-snackbar-stack";
import { useLocalization } from "../includes/LocalizationProvider";

const NavBarMenuSettingsModalPresetsEditDialog = ({ material = null, visible, setVisible }) => {
    const queryClient = useQueryClient();
    const { t } = useLocalization();

    const { enqueueSnackbar } = useSnackbar();

    const handleErrors = (error) => {
        console.error('NavBarMenuSettingsModalPresetsEditDialog: handleErrors:', error);

        enqueueSnackbar({
            message: t("presets.saveError", { reason: (error?.response?.data?.message ?? 'unknown error').toLowerCase() }),
            variant: 'error',
            action:  { label: t("notifications.gotIt") }
        });
    };

    const addMaterialMutation = useMutation({
        mutationFn: (material) => API.post('/user/material', material),
        onError:    handleErrors,
        onSuccess:  () => {
            queryClient.invalidateQueries({ queryKey: ['materials'] });

            setName('');
            setHotendTemperature('');
            setBedTemperature('');
        }
    });

    const editMaterialMutation = useMutation({
        mutationFn: (material) => API.put(`/user/material/${material._id}`, material),
        onError:    handleErrors,
        onSuccess:  () => queryClient.invalidateQueries({ queryKey: ['materials'] })
    });

    const [ name,               setName              ] = useState(material?.name                ?? '');
    const [ hotendTemperature,  setHotendTemperature ] = useState(material?.temperatures?.hotend ?? '');
    const [ bedTemperature,     setBedTemperature    ] = useState(material?.temperatures?.bed    ?? '');

    const saveMaterial = () => {
        console.debug('NavBarMenuSettingsModalPresetsEditDialog: saveMaterial:', material);

        const nextMaterial = {
            _id: material?._id,
            name,
            temperatures: { hotend: hotendTemperature, bed: bedTemperature }
        };

        if (nextMaterial._id) {
            editMaterialMutation.mutate(nextMaterial);
        } else {
            addMaterialMutation.mutate(nextMaterial);
        }

        setVisible(false);
    };

    useEffect(() => {
        if (!material) { return; }

        setName(material.name);
        setHotendTemperature(material.temperatures?.hotend);
        setBedTemperature(material.temperatures?.bed);
    }, [ material ]);

    return (
        <SimpleDialog
            title={material ? t("presets.editTitle", { name: material.name }) : t("presets.addTitle")}
            visible={visible}
            setVisible={setVisible}
            style={{ maxWidth: 350 }}
            actions={
                <>
                    <Button onPress={() => setVisible(false)}>{t("presets.cancel")}</Button>
                    <Button onPress={() => saveMaterial()}>{t("presets.save")}</Button>
                </>
            }
            content={
                <>
                    <TextInput
                        label={t("presets.materialLabel")}
                        mode="outlined"
                        onChangeText={value => setName(value)}
                        value={name}
                    />

                    <TextInput
                        label={t("presets.hotendTemperatureLabel")}
                        mode="outlined"
                        onChangeText={value => setHotendTemperature(value)}
                        value={hotendTemperature}
                    />

                    <TextInput
                        label={t("presets.bedTemperatureLabel")}
                        mode="outlined"
                        onChangeText={value => setBedTemperature(value)}
                        value={bedTemperature}
                    />
                </>
            }
        />
    );
}

export default NavBarMenuSettingsModalPresetsEditDialog;
