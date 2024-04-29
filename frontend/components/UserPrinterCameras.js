import { useQuery } from "@tanstack/react-query";

import { useState } from "react";

import { View } from "react-native";

import { Text, TouchableRipple, useTheme } from "react-native-paper";

import API from "../includes/API";

import UserPrinterCamera from "./UserPrinterCamera";
import SmallButton from "./SmallButton";

export default function UserPrinterCameras() {
    const { colors } = useTheme();

    const [ selectedCamera, setSelectedCamera ] = useState(0);

    console.debug('selectedCamera:', selectedCamera);

    const cameraList = useQuery({
        queryKey: ['cameraList'],
        queryFn:  () => API.get('/user/printer/selected/cameras')
    });

    console.debug('cameraList:', cameraList);

    if (!cameraList.isFetched || !cameraList.isSuccess) { return; }

    const cameras = cameraList.data.data;

    if (cameras.length === 0) { return; }

    const camera = cameras[selectedCamera];
    
    console.debug('camera:', camera);

    return (
        <View style={{ paddingTop: 10 }}>
            {typeof camera !== 'undefined' &&
                <UserPrinterCamera url={camera.url} isConnected={camera.connected} />
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
                        onPress={() => setSelectedCamera(index)}
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