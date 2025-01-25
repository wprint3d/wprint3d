import Slider from '@react-native-community/slider';
import { useMutation } from '@tanstack/react-query';
import React, { useEffect, useState } from 'react';
import { useWindowDimensions, View } from 'react-native';
import { Button, Text, TextInput, ActivityIndicator, Picker, useTheme, Divider, Icon } from 'react-native-paper';
import DropDown from 'react-native-paper-dropdown';
import { useSnackbar } from 'react-native-paper-snackbar-stack';
import API from '../includes/API';

const UserPrinterControlExtrusion = ({ styles, connectionStatus, isSmallLaptop, isSmallTablet }) => {
    const { colors } = useTheme();

    const { enqueueSnackbar } = useSnackbar();

    const [ extrusionDistance,    setExtrusionDistance    ] = useState(5);
    const [ showExtruderDropDown, setShowExtruderDropDown ] = useState(false);
    const [ selectedExtruder,     setSelectedExtruder     ] = useState(null);
    const [ extruderList,         setExtruderList         ] = useState([]);

    const [ lastAction, setLastAction ] = useState(null);

    const sendExtrusionMutation = useMutation({
        mutationFn:  ({ action, distance, extruder }) => (
            API.post(`/user/printer/selected/control/extrusion/${action}`, {
                distance: distance,
                extruder: extruder
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

    const handleExtrusion = ({ action, distance }) => {
        console.debug('UserPrinterControlExtrusion: handleExtrusion:', action, distance);

        setLastAction(action);

        sendExtrusionMutation.mutate({
            action:   action,
            distance: distance,
            extruder: selectedExtruder
        });
    };

    const afterMutation = () => {
        console.debug('UserPrinterControlExtrusion: afterMutation:', lastAction);

        setLastAction(null);
    };

    const retractButton = (
        <Button
            mode="contained" style={styles.controlButton}
            onPress={() => handleExtrusion({ action: 'retract', distance: extrusionDistance })}
            disabled={lastAction === 'retract'}
        >
            <Icon
                color={
                    lastAction === 'retract'
                        ? colors.elevation.level5
                        : colors.onPrimary
                } size={24} source={'arrow-up-bold'}
            />
        </Button>
    );

    const extrudeButton = (
        <Button
            mode="contained" style={styles.controlButton}
            onPress={() => handleExtrusion({ action: 'extrude', distance: extrusionDistance })}
            disabled={lastAction === 'extrude'}
        >
            <Icon
                color={
                    lastAction === 'extrude'
                        ? colors.elevation.level5
                        : colors.onPrimary
                } size={24} source={'arrow-down-bold'}
            />
        </Button>
    );

    useEffect(() => {
        const extruders = connectionStatus?.statistics?.extruders;

        if (!extruders || !extruders.length) {
            setExtruderList([{
                label: 'No extruders available',
                value: null
            }]);

            setSelectedExtruder(null);

            return;
        }

        if (extruders.length === extruderList.length) { return; }

        setExtruderList(
            extruders.map((extruder, index) => ({
                label: `Extruder ${index + 1} (T${index})`,
                value: index
            }))
        );

        if (!selectedExtruder) {
            setSelectedExtruder(0);
        }
    }, [ connectionStatus ]);

    return (
        <>
            <Text style={{ color: colors.primary, textAlign: 'center', paddingVertical: 4, marginTop: 16 }} variant="titleSmall">
                Extrusion
            </Text>
            <Divider />

            <View style={{ flexDirection: 'column', alignItems: 'center', gap: 8, paddingTop: 16 }}>
                <View style={{ flexDirection: 'row', flexWrap: 'wrap', justifyContent: 'center', alignItems: 'center', gap: 8 }}>
                    {!isSmallLaptop &&
                        <View style={{ flexDirection: 'column', justifyContent: 'space-between', alignItems: 'center' }}>
                            {retractButton}
                            <Text style={{ color: colors.primary, textAlign: 'center', paddingTop: 8 }}>
                                Retract
                            </Text>
                        </View>
                    }

                    <View style={{ flexDirection: 'column', justifyContent: 'space-between', alignItems: 'center' }}>
                        <Text style={{ color: colors.primary, textAlign: 'center', paddingVertical: 10 }}>
                            Distance
                        </Text>
                        <Slider
                            style={{ width: 200, maxWidth: '100%' }}
                            value={extrusionDistance}
                            onValueChange={setExtrusionDistance}
                            step={.01}
                            minimumValue={.01}
                            maximumValue={50}
                            minimumTrackTintColor={colors.primary}
                            maximumTrackTintColor={colors.primary}
                            thumbTintColor={colors.primary}
                        />
                        <Text style={{ color: colors.primary, textAlign: 'center', paddingVertical: 4 }}>
                            {extrusionDistance} mm
                        </Text>
                    </View>

                    {!isSmallLaptop &&
                        <View style={{ flexDirection: 'column', justifyContent: 'space-between', alignItems: 'center' }}>
                            {extrudeButton}
                            <Text style={{ color: colors.primary, textAlign: 'center', paddingTop: 8 }}>
                                Extrude
                            </Text>
                        </View>
                    }

                    {isSmallLaptop &&
                        <View style={{ width: '100%', gap: 16, flexDirection: 'row', justifyContent: 'center', alignItems: 'center' }}>
                            <View style={{ flexDirection: 'column', justifyContent: 'space-between', alignItems: 'center' }}>
                                {retractButton}
                                <Text style={{ color: colors.primary, textAlign: 'center', paddingTop: 8 }}>
                                    Retract
                                </Text>
                            </View>

                            <View style={{ flexDirection: 'column', justifyContent: 'space-between', alignItems: 'center' }}>
                                {extrudeButton}
                                <Text style={{ color: colors.primary, textAlign: 'center', paddingTop: 8 }}>
                                    Extrude
                                </Text>
                            </View>
                        </View>
                    }
                </View>

                <View style={{ paddingTop: 4 }}>
                    <DropDown
                        label="Extruder"
                        mode="outlined"
                        visible={showExtruderDropDown}
                        showDropDown={() => setShowExtruderDropDown(true)}
                        onDismiss={() => setShowExtruderDropDown(false)}
                        value={selectedExtruder}
                        list={extruderList}
                        setValue={setSelectedExtruder}
                        inputProps={{
                            right: (
                                <TextInput.Icon
                                    icon={showExtruderDropDown ? 'menu-up' : 'menu-down'}
                                    onPress={() => setShowExtruderDropDown(true)}
                                />
                            )
                        }}
                    />
                </View>
            </View>
        </>
    );
};

export default UserPrinterControlExtrusion;