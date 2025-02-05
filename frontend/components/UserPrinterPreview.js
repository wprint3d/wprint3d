import { useEffect, useRef, useState } from "react";

import { ActivityIndicator, Appbar, Banner, Checkbox, Divider, FAB, Icon, Text, TextInput, Tooltip, useTheme } from "react-native-paper";

import { useEcho } from "../hooks/useEcho";
import { Image, ScrollView, StyleSheet, useWindowDimensions, View } from "react-native";
import { useMutation, useQuery } from "@tanstack/react-query";

import { useSafeAreaInsets } from 'react-native-safe-area-context';

import UserPaneLoadingIndicator from './UserPaneLoadingIndicator';

import API from "../includes/API";
import AppbarActionWithTooltip from "./AppbarActionWithTooltip";

import { useCache } from "../hooks/useCache";

import uuid from 'react-native-uuid';
import { useSnackbar } from "react-native-paper-snackbar-stack";

import Canvas from 'react-native-canvas';

import Slider from '@react-native-community/slider'

// import { GCodeViewer } from "react-gcode-viewer";

import GCodePreview from "./modules/GCodePreview";
import SliderTag from "./modules/SliderTag";

export default function UserPrinterPreview({ connectionStatus, lastUpdate, isSmallTablet = false }) {
    const cache = useCache();

    const windowSize = useWindowDimensions();

    const { bottom } = useSafeAreaInsets();

    const [ isPrinting,     setIsPrinting    ] = useState(false);
    const [ isDownloading,  setIsDownloading ] = useState(true);

    const [ currentLayer,  setCurrentLayer ]  = useState(null);
    const [ maxLayer,      setMaxLayer     ]  = useState(0);
    const [ buffer,        setBuffer       ]  = useState([]);
    const [ selectedLayer, setSelectedLayer ] = useState(null);
    const [ sliderValue,   setSliderValue   ] = useState(0);
    const [ sliderLayout,  setSliderLayout  ] = useState(null);

    const [ sliderTagVisible,  setSliderTagVisible  ] = useState(false);
    const [ sliderTagPosition, setSliderTagPosition ] = useState(null);
    const [ sliderTagValue,    setSliderTagValue    ] = useState(sliderValue);
    const [ sliderShouldTrack, setSliderShouldTrack ] = useState(false);

    const [ showExtrusion,   _setShowExtrusion   ] = useState(null);
    const [ showTravelMoves, _setShowTravelMoves ] = useState(null);

    const setShowExtrusion = async newValue => {
        await cache.set('showExtrusion', newValue);

        return _setShowExtrusion(newValue);
    };

    const setShowTravelMoves = async newValue => {
        await cache.set('showTravelMoves', newValue);

        return _setShowTravelMoves(newValue);
    };

    const BOTTOM_APPBAR_HEIGHT_BASE = 48;
    const BOTTOM_APPBAR_HEIGHT = (
        BOTTOM_APPBAR_HEIGHT_BASE + (
            isSmallTablet
                ? 8
                : 0
        )
    );

    const { colors } = useTheme();

    const loaderMessage = null;

    const baseGcodeQuery = useQuery({
        queryKey: ['baseGcode'],
        queryFn:  () => API.get(
            '/user/printer/selected/print/gcode/lines/stream?' + (selectedLayer !== null ? new URLSearchParams({ targetLayer: selectedLayer }) : '')
        )
    });

    const gcodePreview = useRef(null);

    const parseMovement = line => {
        let parsed = line.trim().replace('> ', '');
    
        if (parsed[0] == 'G') return parsed;
    
        return null;
    };

    const handleFetchComplete = () => {
        console.debug('UserPrinterPreview: handleFetchComplete:', baseGcodeQuery);

        setIsDownloading(false);

        if (buffer.length) {
            console.debug('UserPrinterPreview: private: listen: buffer:', buffer);

            gcodePreview.current.processGCode(buffer.join('\n'));

            setBuffer([]);
        }

        if (gcodePreview.current) {
            gcodePreview.current.clear();
        }
    };

    const downloadGCode = () => {
        console.debug('UserPrinterPreview: downloadGCode:');

        if (gcodePreview.current) {
            gcodePreview.current.clear();
        }

        setBuffer([]);
        setIsDownloading(true);

        baseGcodeQuery.refetch().finally(handleFetchComplete);
    };

    useEffect(() => {
        console.debug('UserPrinterPreview: showExtrusion:',     showExtrusion);
    }, [ showExtrusion ]);

    useEffect(() => {
        console.debug('UserPrinterPreview: showTravelMoves:',   showTravelMoves);
    }, [ showTravelMoves ]);

    useEffect(() => {
        console.debug('UserPrinterPreview: windowSize:', windowSize);

        if (!gcodePreview.current) return;

        gcodePreview.current.resize();
    }, [ windowSize ]);

    useEffect(() => {
        [ 'showExtrusion', 'showTravelMoves' ].forEach(key => {
            (
                async () => {
                    const state = await cache.get(key, true);

                    switch (key) {
                        case 'showExtrusion':   _setShowExtrusion(state);   break;
                        case 'showTravelMoves': _setShowTravelMoves(state); break;
                    }
                }
            )();
        });
    }, []);

    useEffect(() => {
        console.debug('UserPrinterPreview: baseGcodeQuery:', baseGcodeQuery);

        if (!baseGcodeQuery.isSuccess || !gcodePreview || isDownloading) return;

        const gcode = baseGcodeQuery.data.data;

        console.debug('UserPrinterPreview: gcode:', gcode);

        if (!gcode) return;

        gcodePreview.current.processGCode(gcode);
    }, [ baseGcodeQuery.data, gcodePreview, isDownloading ]);

    useEffect(() => {
        if (!gcodePreview.current) return;

        gcodePreview.current.clear();

        downloadGCode();
    }, [ showExtrusion, showTravelMoves ]);

    useEffect(() => {
        if (!connectionStatus) {
            console.warn('UserPrinterPreview: connectionStatus:', connectionStatus);

            return;
        }

        console.debug('UserPrinterPreview: connectionStatus:', connectionStatus);

        const nextIsPrinting = connectionStatus?.isPrinting ?? false;

        if (nextIsPrinting === isPrinting || nextIsPrinting === null) return;

        setIsPrinting(nextIsPrinting);

        downloadGCode();
    }, [ connectionStatus ]);

    useEffect(() => {
        if (!connectionStatus) {
            console.warn('UserPrinterPreview: connectionStatus:', connectionStatus);

            return;
        }

        const nextCurrentLayer = connectionStatus?.layer,
                nextMaxLayer   = connectionStatus?.maxLayer;

        console.debug('UserPrinterPreview: nextCurrentLayer:', currentLayer, 'nextMaxLayer:', maxLayer);

        if (
            nextCurrentLayer === currentLayer || nextCurrentLayer === null
            ||
            nextMaxLayer === maxLayer || nextMaxLayer === null
        ) return;

        setCurrentLayer(nextCurrentLayer);
        setMaxLayer(nextMaxLayer);
    }, [ connectionStatus ]);

    useEffect(() => {
        console.debug('UserPrinterPreview: sliderValue:', sliderValue);

        if (sliderValue === currentLayer) return;

        setSelectedLayer(sliderValue);
    }, [ sliderValue ]);

    useEffect(() => {
        console.debug('UserPrinterPreview: currentLayer:', currentLayer);

        if (currentLayer === null) return;

        setSliderValue(currentLayer);
        setSliderTagValue(currentLayer);
    }, [ currentLayer ]);

    useEffect(() => {
        console.debug('UserPrinterPreview: selectedLayer:', selectedLayer);

        downloadGCode();
    }, [ selectedLayer ]);

    useEffect(() => {
        console.debug('UserPrinterPreview: gcodePreview:',  gcodePreview);
        console.debug('UserPrinterPreview: isDownloading:', isDownloading);
        console.debug('UserPrinterPreview: lastUpdate:',    lastUpdate);

        if (!gcodePreview.current || isDownloading) return;

        let command = lastUpdate?.command;

        if (!command) return;

        command.split('\n').forEach(line => {
            command = parseMovement( line );

            console.debug('UserPrinterPreview: private: listen: command:', command);

            if (!command) return;

            if (isDownloading || baseGcodeQuery.isFetching || !baseGcodeQuery.isFetched || !baseGcodeQuery.isSuccess) {
                const nextBuffer = buffer;

                nextBuffer.push(command);

                setBuffer(nextBuffer);
            } else if (selectedLayer === null) {
                gcodePreview.current.processGCode(command);
            }
        });
    }, [ gcodePreview, isDownloading, lastUpdate ]);

    return (
        <View style={{
            display:         'flex',
            flexDirection:   'column',
            flexGrow:        1,
            backgroundColor: colors.elevation.level1
        }}>
            <View style={{
                position: 'relative',
                padding: 8,
                flexGrow: 1,
                flexShrink: 0,
                flexBasis: 'auto',
                backgroundColor: colors.elevation.level1
            }}>
                <View style={{
                    position: 'absolute',
                    padding: 4,
                    left: 0,
                    top: 0,
                    right: 0,
                    bottom: 0
                }}>
                    <Banner
                        visible={
                            (!baseGcodeQuery.isSuccess && baseGcodeQuery.isFetched && !baseGcodeQuery.isLoading)
                            ||
                            baseGcodeQuery.isFetching
                        }
                        style={{
                            backgroundColor: colors.onPrimary,
                            color:           colors.primary,
                            paddingBottom:   8,
                            margin:          0
                        }}
                    >
                        <Text style={{ flexDirection: 'column', alignItems: 'center', width: '100%', display: 'flex' }}>
                            {
                                baseGcodeQuery.isFetching
                                    ? 'Fetching G-code preview...'
                                    : (
                                        baseGcodeQuery.isFetched && !baseGcodeQuery.isSuccess && baseGcodeQuery?.error?.response?.status === 422
                                            ? 'Idling printers cannot display G-code previews.'
                                            : (
                                                baseGcodeQuery.isFetched && !baseGcodeQuery.isSuccess
                                                    ? (baseGcodeQuery.error?.message ?? 'An error occurred while fetching the G-code preview.')
                                                    : 'Updating UI...'
                                            )
                                    )
                            }
                        </Text>
                    </Banner>
                    {
                        loaderMessage !== null
                            ? <UserPaneLoadingIndicator
                                message={loaderMessage + '...'}
                                style={{
                                    height:         '100%',
                                    alignSelf:      'center',
                                    justifyContent: 'center'
                                }}
                            />
                            : <GCodePreview
                                ref={gcodePreview}
                                backgroundColor={colors.background}
                                buildVolume={{ x: 220, y: 220, z: 250 }}
                                renderExtrusion={showExtrusion ?? true}
                                renderTravel={showTravelMoves  ?? true}
                                key={colors.background}
                            />
                    }
                </View>
            </View>

            <Appbar
                style={[ styles.bottom, {
                    height: BOTTOM_APPBAR_HEIGHT,
                    backgroundColor: colors.elevation.level1,
                    marginVertical: 4
                }]}
                safeAreaInsets={{ bottom }}
            >
                <View style={{ flexDirection: 'row', flexGrow: 1 }}>
                    <AppbarActionWithTooltip
                        title="Show extrusion"
                        icon="printer-3d-nozzle"
                        onPress={() => setShowExtrusion(!showExtrusion)}
                        disabled={!showExtrusion}
                        loading={showExtrusion === null}
                    />

                    <AppbarActionWithTooltip
                        title="Show travel moves"
                        icon="axis-arrow"
                        onPress={() => setShowTravelMoves(!showTravelMoves)}
                        disabled={!showTravelMoves}
                        loading={showTravelMoves === null}
                    />

                    <Slider
                        onLayout={evt => {
                            console.log('UserPrinterPreview: slider: onLayout:', evt);

                            setSliderLayout(evt.nativeEvent.layout);
                        }}
                        onPointerEnter={evt => {
                            if (sliderShouldTrack) return;

                            console.log('UserPrinterPreview: slider: onPointerEnter:', evt);

                            setSliderTagVisible(true);
                            setSliderTagPosition({
                                x: sliderLayout.left + (sliderLayout.width  / 2),
                                y: sliderLayout.top  + (sliderLayout.height / 2)
                            });
                        }}
                        onPointerLeave={evt => {
                            console.log('UserPrinterPreview: slider: onPointerLeave:', evt);

                            setSliderTagVisible(false);
                        }}
                        onPointerMove={evt => {
                            if (!maxLayer || !sliderShouldTrack) return;

                            console.log('UserPrinterPreview: slider: onPointerMove:', evt);

                            setSliderTagPosition({
                                x: evt.nativeEvent.clientX,
                                y: evt.nativeEvent.clientY
                            });
                        }}
                        thumbTintColor={colors.primary}
                        maximumTrackTintColor={colors.elevation.level5}
                        minimumTrackTintColor={colors.primary}
                        style={{ flexGrow: 1, marginHorizontal: 12 }}
                        value={sliderValue ?? 0}
                        onValueChange={value => setSliderTagValue(value)}
                        onSlidingStart={value => {
                            setSliderTagVisible(true);
                            setSliderShouldTrack(true);

                            console.log('UserPrinterPreview: onSlidingStart: value:', value);
                        }}
                        onSlidingComplete={value => {
                            setSliderTagVisible(false);
                            setSliderShouldTrack(false);

                            setSelectedLayer(value);

                            console.log('UserPrinterPreview: onSlidingComplete: value:', value);
                        }}
                        maximumValue={maxLayer}
                        minimumValue={0}
                        step={1}
                        disabled={baseGcodeQuery.isFetching || !maxLayer}
                    />

                    <SliderTag
                        title={sliderTagValue}
                        visible={sliderTagVisible}
                        positionX={sliderTagPosition?.x}
                        positionY={sliderTagPosition?.y}
                    />

                    <AppbarActionWithTooltip
                        title="Live preview"
                        icon={selectedLayer === null ? 'pause': 'play'}
                        onPress={() => setSelectedLayer(selectedLayer === null ? currentLayer : null)}
                        disabled={!isPrinting}
                        loading={baseGcodeQuery.isFetching}
                    />
                </View>
            </Appbar>
        </View>
    );
}

const styles = StyleSheet.create({
    terminalCommandKind: {
        paddingLeft: 4,
        marginRight: 4
    },
    canvas: {
        top: 0,
        left: 0,
        width: '100%',
        height: '100%'
    }
});