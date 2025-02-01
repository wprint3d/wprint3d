import Slider from "@react-native-community/slider";
import { useMutation, useQuery } from "@tanstack/react-query";
import { useEffect, useState } from "react";
import { View } from "react-native";
import { Button, Divider, Icon, Text, useTheme } from "react-native-paper";
import API from "../includes/API";
import UserPaneLoadingIndicator from "./UserPaneLoadingIndicator";
import UserPrinterControlMovementButton from "./UserPrinterControlMovementButton";
import { useSnackbar } from "react-native-paper-snackbar-stack";

const UserPrinterControlMovement = ({ styles, isSmallTablet, isSmallLaptop }) => {
    const { colors } = useTheme();

    const { enqueueSnackbar } = useSnackbar();

    const [ yLabelLayout,     setYLabelLayout     ] = useState({ width: 0, height: 0, x: 0, y: 0 });
    const [ xyControlsLayout, setXYControlsLayout ] = useState({ width: 0, height: 0, x: 0, y: 0 });

    const [ feedrate,       setFeedrate      ] = useState(100);
    const [ distance,       setDistance      ] = useState(5);
    const [ lastDirection,  setLastDirection ] = useState(null);

    const afterMutation = () => {
        console.debug('sendMovementMutation: afterMutation');

        setLastDirection(null);
    };

    const sendMovementMutation = useMutation({
        mutationFn: async (params) => API.post('/user/printer/selected/control/movement', params),
        onSuccess: () => {
            console.debug('sendMovementMutation: onSuccess');

            afterMutation();
        },
        onError: (error) => {
            console.error('sendMovementMutation: onError:', error);

            afterMutation();

            enqueueSnackbar({
                message: 'Failed to send command: ' + (error.response?.data?.message ?? error.message).toLowerCase(),
                variant: 'error',
                action:  { label: 'Got it' }
            });
        }
    });

    const handleMovement = ({ direction, distance, feedrate }) => {
        console.debug('handleMovement:', direction, distance, feedrate);

        setLastDirection(direction);

        sendMovementMutation.mutate({
            direction: direction,
            distance: distance,
            feedrate: feedrate
        });
    };

    const XY_PANE_WIDTH = (
        isSmallTablet
            ? '100%'
            : 200
    );

    let MOVEMENT_PANE_WIDTH = (
        isSmallLaptop // small laptop
            ? '100%'
            : 'auto'
    );

    return (
        <>
            <Text style={{ color: colors.primary, textAlign: 'center', paddingVertical: 4 }} variant="titleSmall">
                Movement
            </Text>
            <Divider />

            <View style={{
                width: MOVEMENT_PANE_WIDTH,
                flexDirection: 'row', flexWrap: 'wrap', justifyContent: 'center', gap: 20
            }}>
                <View
                    style={{
                        flexDirection: 'row', alignItems: 'center', justifyContent: 'center',
                        width: XY_PANE_WIDTH
                    }}
                    onLayout={({ nativeEvent }) => setXYControlsLayout(nativeEvent.layout)}
                >
                    <View style={{ flexDirection: 'row', alignItems: 'center', left: -20 }}>
                        <Text style={{ color: colors.primary, textAlign: 'center', marginTop: 36, position: 'absolute' }}>
                            X
                        </Text>
                    </View>

                    <View style={{ width: 200, paddingVertical: 8, gap: 8 }}>
                        <View
                            style={{ flexDirection: 'row', alignItems: 'center', justifyContent: 'center', paddingRight: 8 }}
                            onLayout={({ nativeEvent }) => setYLabelLayout(nativeEvent.layout)}
                        >
                            <Text style={{ color: colors.primary, textAlign: 'center', paddingVertical: 8, marginLeft: 10 }}>
                                Y
                            </Text>
                        </View>

                        <View style={{ flexDirection: 'column', justifyContent: 'space-between', alignItems: 'center', marginLeft: 8 }}>
                            <UserPrinterControlMovementButton
                                styles={styles}
                                targetDirection="Y_FORWARDS"
                                lastDirection={lastDirection}
                                handleMovement={handleMovement}
                                distance={distance}
                                feedrate={feedrate}
                                icon={'arrow-up-bold'}
                            />
                        </View>

                        <View style={{ flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', gap: 8, paddingLeft: 6 }}>
                            <UserPrinterControlMovementButton
                                styles={styles}
                                targetDirection="LEFT"
                                lastDirection={lastDirection}
                                handleMovement={handleMovement}
                                distance={distance}
                                feedrate={feedrate}
                                icon={'arrow-left-bold'}
                            />

                            <UserPrinterControlMovementButton
                                styles={styles}
                                targetDirection="HOME"
                                lastDirection={lastDirection}
                                handleMovement={handleMovement}
                                distance={distance}
                                feedrate={feedrate}
                                icon={'home'}
                            />

                            <UserPrinterControlMovementButton
                                styles={styles}
                                targetDirection="RIGHT"
                                lastDirection={lastDirection}
                                handleMovement={handleMovement}
                                distance={distance}
                                feedrate={feedrate}
                                icon={'arrow-right-bold'}
                            />
                        </View>

                        <View style={{ flexDirection: 'column', justifyContent: 'space-between', alignItems: 'center', marginLeft: 8 }}>
                            <UserPrinterControlMovementButton
                                styles={styles}
                                targetDirection="Y_BACKWARDS"
                                lastDirection={lastDirection}
                                handleMovement={handleMovement}
                                distance={distance}
                                feedrate={feedrate}
                                icon={'arrow-down-bold'}
                            />
                        </View>
                    </View>
                </View>

                <View
                    style={[
                        {
                            flexDirection: 'row', alignItems: 'center', paddingLeft: 16,
                        }, (!isSmallTablet && {
                            height: xyControlsLayout.height - yLabelLayout.height + (yLabelLayout.y / 2) - 6,
                            alignSelf: 'flex-end'
                        })
                    ]}
                >
                    <Text style={{ color: colors.primary, textAlign: 'center', left: -4, position: 'absolute' }}>
                        Z
                    </Text>

                    <View style={{ flexDirection: 'column', justifyContent: 'center', alignItems: 'center', gap: 8 }}>
                        <UserPrinterControlMovementButton
                            styles={styles}
                            targetDirection="UP"
                            lastDirection={lastDirection}
                            handleMovement={handleMovement}
                            distance={distance}
                            feedrate={feedrate}
                            icon={'arrow-up-bold'}
                        />

                        <UserPrinterControlMovementButton
                            styles={styles}
                            targetDirection="DOWN"
                            lastDirection={lastDirection}
                            handleMovement={handleMovement}
                            distance={distance}
                            feedrate={feedrate}
                            icon={'arrow-down-bold'}
                        />
                    </View>
                </View>

                <View style={{
                    width: '100%',
                    maxWidth: 200,
                    flexDirection: 'column', alignItems: 'center', paddingLeft: 16,
                    alignSelf: 'flex-end',
                    height: xyControlsLayout.height - yLabelLayout.height + (yLabelLayout.y / 2) - 6
                }}>
                    <Text style={{ color: colors.primary, textAlign: 'center', paddingVertical: 4 }}>
                        Feedrate
                    </Text>
                    <Slider
                        style={{ width: '100%' }}
                        value={feedrate}
                        onValueChange={setFeedrate}
                        step={1}
                        minimumValue={1}
                        maximumValue={5000}
                        minimumTrackTintColor={colors.primary}
                        maximumTrackTintColor={colors.primary}
                        thumbTintColor={colors.primary}
                    />
                    <Text style={{ color: colors.primary, textAlign: 'center', paddingVertical: 4 }}>
                        {feedrate} units/s
                    </Text>

                    <Text style={{ color: colors.primary, textAlign: 'center', paddingVertical: 4, marginTop: 16 }}>
                        Distance
                    </Text>
                    <Slider
                        style={{ width: '100%' }}
                        value={distance}
                        onValueChange={setDistance}
                        step={.01}
                        minimumValue={.01}
                        maximumValue={100}
                        minimumTrackTintColor={colors.primary}
                        maximumTrackTintColor={colors.primary}
                        thumbTintColor={colors.primary}
                    />
                    <Text style={{ color: colors.primary, textAlign: 'center', paddingVertical: 4 }}>
                        {distance} mm
                    </Text>
                </View>
            </View>
        </>
    );
};

export default UserPrinterControlMovement;