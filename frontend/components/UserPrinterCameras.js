import { useQuery } from "@tanstack/react-query";

import { useEffect, useState } from "react";

import { View } from "react-native";

import { Button, Icon, Text, TouchableRipple, useTheme } from "react-native-paper";
import { Image } from "expo-image";

import API from "../includes/API";
import { useLocalization } from "../includes/LocalizationProvider";
import { getSelectedPrinterCameraListQueryKey } from "../utils/printerCameraLinking";

import UserPrinterCamera from "./UserPrinterCamera";
import SmallButton from "./SmallButton";
import SimpleDialog from "./SimpleDialog";

export default function UserPrinterCameras({ printerId = null }) {
    const { colors } = useTheme();
    const { t } = useLocalization();

    const [ selectedCamera,            setSelectedCamera            ] = useState(0);
    const [ expandedCameraVisible,     setExpandedCameraVisible     ] = useState(false);
    const [ pausedCameraSnapshotURL,  setPausedCameraSnapshotURL  ] = useState(null);
    const [ pausedCameraPreviewWidth, setPausedCameraPreviewWidth ] = useState(0);

    const cameraList = useQuery({
        queryKey: getSelectedPrinterCameraListQueryKey(printerId),
        queryFn:  () => API.get('/user/printer/selected/cameras'),
        enabled:  !!printerId
    });

    useEffect(() => {
        console.debug('UserPrinterCameras: cameraList:',     cameraList);
        console.debug('UserPrinterCameras: selectedCamera:', selectedCamera);
    }, [ cameraList.isFetching ]);

    useEffect(() => {
        console.debug('selectedCamera:', selectedCamera);
    }, [ selectedCamera ]);

    useEffect(() => {
        setSelectedCamera(0);
        setExpandedCameraVisible(false);
        setPausedCameraSnapshotURL(null);
    }, [ printerId ]);

    const linkedCameraCount = cameraList.isSuccess ? (cameraList?.data?.data?.length ?? 0) : 0;

    useEffect(() => {
        if (linkedCameraCount === 0) {
            setSelectedCamera(0);
            setExpandedCameraVisible(false);
            setPausedCameraSnapshotURL(null);

            return;
        }

        if (selectedCamera >= linkedCameraCount) {
            setSelectedCamera(linkedCameraCount - 1);
            setExpandedCameraVisible(false);
            setPausedCameraSnapshotURL(null);
        }
    }, [ linkedCameraCount, selectedCamera ]);

    if (!cameraList.isFetched || !cameraList.isSuccess) { return; }

    const cameras = cameraList.data.data;

    if (cameras.length === 0) { return; }

    const camera = cameras[selectedCamera];
    const cameraStreamsMjpeg = camera?.streamsMjpeg ?? camera?.supportsMjpeg ?? true;
    const cameraName = camera?.label ?? t("camera.unknownCamera");
    const canExpandCamera = typeof camera !== 'undefined' && camera?.connected;
    const openExpandedCamera = () => {
        setPausedCameraSnapshotURL(`${camera.url}?${new URLSearchParams({ action: 'snapshot', t: (new Date()).getTime() })}`);
        setExpandedCameraVisible(true);
    };

    return (
        <View style={{ paddingTop: 10 }}>
            {typeof camera !== 'undefined' && expandedCameraVisible && pausedCameraSnapshotURL && (
                <View
                    onLayout={event => setPausedCameraPreviewWidth(event.nativeEvent.layout.width)}
                    style={{
                        position:        'relative',
                        width:           '100%',
                        height:          pausedCameraPreviewWidth ? pausedCameraPreviewWidth / 2 : 180,
                        overflow:        'hidden',
                        backgroundColor: colors.surfaceVariant
                    }}
                >
                    <Image
                        source={{ uri: pausedCameraSnapshotURL }}
                        style={{
                            width:  '100%',
                            height: '100%'
                        }}
                    />

                    <View
                        pointerEvents="none"
                        style={{
                            position:        'absolute',
                            top:             0,
                            right:           0,
                            bottom:          0,
                            left:            0,
                            alignItems:      'center',
                            justifyContent:  'center',
                            backgroundColor: 'rgba(96, 96, 96, 0.45)'
                        }}
                    >
                        <Icon source="pause" size={48} color={colors.white} />
                    </View>
                </View>
            )}

            {typeof camera !== 'undefined' && !expandedCameraVisible && (
                canExpandCamera ? (
                    <TouchableRipple
                        onPress={openExpandedCamera}
                        accessibilityRole="button"
                        accessibilityLabel={t("camera.openLargeView")}
                        borderless={false}
                    >
                        <View style={{ position: 'relative' }}>
                            <UserPrinterCamera url={camera.url} isConnected={camera.connected} streamsMjpeg={cameraStreamsMjpeg} />

                            <View
                                pointerEvents="none"
                                style={{
                                    position:        'absolute',
                                    right:           8,
                                    bottom:          8,
                                    borderRadius:    999,
                                    paddingVertical: 4,
                                    paddingHorizontal: 8,
                                    backgroundColor: 'rgba(0, 0, 0, 0.55)'
                                }}
                            >
                                <Text style={{ color: colors.white, fontSize: 12 }}>
                                    {t("camera.tapToEnlarge")}
                                </Text>
                            </View>
                        </View>
                    </TouchableRipple>
                ) : (
                    <UserPrinterCamera url={camera.url} isConnected={camera.connected} streamsMjpeg={cameraStreamsMjpeg} />
                )
            )}

            {typeof camera !== 'undefined' &&
                <SimpleDialog
                    visible={expandedCameraVisible}
                    setVisible={setExpandedCameraVisible}
                    title={t("camera.previewingCamera", { name: cameraName })}
                    content={<UserPrinterCamera url={camera.url} isConnected={camera.connected} streamsMjpeg={cameraStreamsMjpeg} />}
                    style={{ maxWidth: 1200, width: '95%' }}
                    actions={
                        <Button mode="text" onPress={() => setExpandedCameraVisible(false)}>
                            {t("camera.close")}
                        </Button>
                    }
                />
            }
            <View style={{
                display:        'flex',
                flexDirection:  'row',
                alignSelf:      'center',
                gap:            4,
                paddingTop:     10 
            }}>
                {cameras.map((camera, index) => (
                    <SmallButton
                        key={index}
                        onPress={() => {
                            setExpandedCameraVisible(false);
                            setPausedCameraSnapshotURL(null);
                            setSelectedCamera(index);
                        }}
                        style={{
                            backgroundColor: (
                                selectedCamera == index
                                    ? colors.primary
                                    : colors.onPrimary
                            ),
                            borderColor: colors.primary
                        }}
                        textStyle={{
                            color: (
                                selectedCamera == index
                                    ? colors.onPrimary
                                    : colors.primary
                            )
                        }}
                    >
                        {index + 1}
                    </SmallButton>
                ))}
            </View>
        </View>
    );
}
