import Slider from '@react-native-community/slider';
import { useMutation } from '@tanstack/react-query';
import React, { useEffect, useState } from 'react';
import { useWindowDimensions, View } from 'react-native';
import { Button, Text, TextInput, ActivityIndicator, Picker, useTheme, Divider, Icon } from 'react-native-paper';
import DropDown from 'react-native-paper-dropdown';
import { useSnackbar } from 'react-native-paper-snackbar-stack';
import API from '../includes/API';

const UserPrinterControlTemperature = ({ connectionStatus }) => {
    const { colors } = useTheme();

    const { enqueueSnackbar } = useSnackbar();

    const [ hotendTemperature, setHotendTemperature ] = useState('0');
    const [ bedTemperature,    setBedTemperature    ] = useState('0');
    const [ lastTarget,        setLastTarget        ] = useState(null);

    const sendTemperatureMutation = useMutation({
        mutationFn:  ({ target, temperature }) => (
            API.post(`/user/printer/selected/control/temperature/${target}`, {
                temperature: temperature
            })
        ),
        onSuccess:   afterMutation,
        onError:     (error) => {
            enqueueSnackbar({
                message: 'Failed to send command: ' + (error.response?.data?.message ?? error.message).toLowerCase(),
                variant: 'error',
                action:  { label: 'Got it' }
            });

            afterMutation();
        }
    });

    const handleTemperature = ({ target, temperature }) => {
        console.debug('UserPrinterControlTemperature: handleTemperature:', target, temperature);

        setLastTarget(target);

        sendTemperatureMutation.mutate({
            target:      target,
            temperature: temperature
        });
    }

    const afterMutation = () => {
        console.debug('UserPrinterControlTemperature: afterMutation:', lastTarget);

        setLastTarget(null);
    }

    useEffect(() => {
        const runningExtruder = connectionStatus?.statistics?.extruders?.find(extruder => extruder.target > 0);

        if (!runningExtruder) {
            if (hotendTemperature === null) {
                setHotendTemperature(0);
            }

            return;
        }
    
        setHotendTemperature(runningExtruder.target);
    }, [ connectionStatus ]);

    useEffect(() => {
        const bed = connectionStatus?.statistics?.bed;

        if (!bed) {
            if (bedTemperature === null) {
                setBedTemperature(0);
            }

            return;
        }

        setBedTemperature(bed.target);
    }, [ connectionStatus ]);

    return (
        <>
            <Text style={{ color: colors.primary, textAlign: 'center', paddingVertical: 4, marginTop: 16 }} variant="titleSmall">
                Temperature
            </Text>
            <Divider />

            <View style={{ flexDirection: 'row', flexWrap: 'wrap', alignItems: 'center', justifyContent: 'center', gap: 8, paddingTop: 16 }}>
                <View style={{ minWidth: 100, maxWidth: 150, width: '50%', flexDirection: 'column', flexWrap: 'wrap', justifyContent: 'center', alignItems: 'center', gap: 8 }}>
                    <Text style={{ color: colors.primary, textAlign: 'center', paddingVertical: 4 }}>
                        Hotend (°C)
                    </Text>
                    <TextInput
                        mode="outlined"
                        keyboardType="numeric"
                        placeholder="0"
                        value={hotendTemperature}
                        onChangeText={setHotendTemperature}
                        style={{ width: '100%' }}
                        right={
                            <TextInput.Icon
                                icon={'check-bold'} loading={lastTarget === 'hotend'}
                                onPress={() => handleTemperature({ target: 'hotend', temperature: hotendTemperature })}
                            />
                        }
                    />
                </View>

                <View style={{ minWidth: 100, maxWidth: 150, width: '50%', flexDirection: 'column', flexWrap: 'wrap', justifyContent: 'center', alignItems: 'center', gap: 8 }}>
                    <Text style={{ color: colors.primary, textAlign: 'center', paddingVertical: 4 }}>
                        Bed (°C)
                    </Text>
                    <TextInput
                        mode="outlined"
                        keyboardType="numeric"
                        placeholder="0"
                        value={bedTemperature}
                        onChangeText={setBedTemperature}
                        style={{ width: '100%' }}
                        right={
                            <TextInput.Icon
                                icon={'check-bold'} loading={lastTarget === 'bed'}
                                onPress={() => handleTemperature({ target: 'bed', temperature: bedTemperature })}
                            />
                        }
                    />
                </View>
            </View>
        </>
    );
};

export default UserPrinterControlTemperature;