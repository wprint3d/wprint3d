import { useIsFetching, useMutation, useQueryClient } from "@tanstack/react-query";
import { useEffect, useState } from "react";
import { Button, TextInput } from "react-native-paper";
import SimpleDialog from "./SimpleDialog";
import API from "../includes/API";
import { useSnackbar } from "react-native-paper-snackbar-stack";

const NavBarMenuSettingsModalPresetsEditDialog = ({ material = null, visible, setVisible }) => {
    const queryClient = useQueryClient();

    const { enqueueSnackbar } = useSnackbar();

    const handleErrors = (error) => {
        console.error('NavBarMenuSettingsModalPresetsEditDialog: handleErrors:', error);

        enqueueSnackbar({
            message: 'An error occurred: ' + (error?.response?.data?.message ?? 'unknown error').toLowerCase(),
            variant: 'error',
            action:  { label: 'Got it' }
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
            title={material ? `Edit ${material.name}` : 'Add new material'}
            visible={visible}
            setVisible={setVisible}
            style={{ maxWidth: 350 }}
            actions={
                <>
                    <Button onPress={() => setVisible(false)}>Cancel</Button>
                    <Button onPress={() => saveMaterial()}>Save</Button>
                </>
            }
            content={
                <>
                    <TextInput
                        label="Material"
                        mode="outlined"
                        onChangeText={value => setName(value)}
                        value={name}
                    />

                    <TextInput
                        label="Hotend temperature"
                        mode="outlined"
                        onChangeText={value => setHotendTemperature(value)}
                        value={hotendTemperature}
                    />

                    <TextInput
                        label="Bed temperature"
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