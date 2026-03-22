import { memo, useEffect, useRef, useState } from "react";

import { Platform, View } from "react-native";
import { Icon, Text, useTheme } from "react-native-paper";

import { Image } from "expo-image";

import UserPrinterCameraError from "./UserPrinterCameraError";
import UserPrinterCameraInformation from "./UserPrinterCameraInformation";
import UserPaneLoadingIndicator from "./UserPaneLoadingIndicator";
import { useLocalization } from "../includes/LocalizationProvider";
import { shouldPollCameraStream } from "../utils/cameraStream";

const UserPrinterCamera = ({ url, isConnected, streamsMjpeg = true }) => {
    const image = useRef(null);

    const { colors } = useTheme();
    const { t } = useLocalization();

    const [ width,      setWidth      ] = useState(0);
    const [ activeURL,  setActiveURL  ] = useState(null);
    const [ error,      setError      ] = useState(null);
    const [ isLoaded,   setIsLoaded   ] = useState(false);

    useEffect(() => {
        if (
            image.current && Platform.OS === 'web'
            &&
            image.current.nativeViewRef.current
        ) {
            image.current.nativeViewRef.current.src = url;
        }

        setError(null);
        setActiveURL(`${url}?${new URLSearchParams({ action: 'stream', t: (new Date()).getTime() })}`);
    }, [ url ] );

    useEffect(() => {
        console.debug('UserPrinterCamera: shouldPollCameraStream:', shouldPollCameraStream(streamsMjpeg));
    }, [ streamsMjpeg ]);

    useEffect(() => {
        console.debug('UserPrinterCamera: activeURL:', activeURL);
    }, [ activeURL ]);

    const viewHeight = width / 2;

    if (!isConnected) {
        return (
            <UserPrinterCameraError
                icon="power-plug"
                message={t("camera.notConnected")}
                height={viewHeight}
                suggestions={[
                    t("camera.suggestionPlugIn"),
                    t("camera.suggestionResetUsb"),
                    t("camera.suggestionRestartHost")
                ]}
                onLayout={event => setWidth(event.nativeEvent.layout.width)}
            />
        );
    }

    if (error) {
        return (
            <View style={{ margin: 8 }}>
                <UserPrinterCameraError
                    icon="exclamation"
                    message={t("camera.notWorking")}
                    height={viewHeight}
                    error={error}
                    suggestions={[
                        t("camera.suggestionReseat"),
                        t("camera.suggestionRestartHost")
                    ]}
                    onLayout={event => setWidth(event.nativeEvent.layout.width)}
                />
            </View>
        );
    }

    return (
        <>
            <UserPrinterCameraInformation
                height={viewHeight}
                onLayout={event => setWidth(event.nativeEvent.layout.width)}
                style={{ display: (isLoaded ? 'none' : 'block') }}
            >
                <View style={{
                    display:        'flex',
                    flexDirection:  'column',
                    justifyContent: 'center',
                    height:         '100%'
                }}>
                    <UserPaneLoadingIndicator message={t("camera.bufferingStream")} />
                </View>
            </UserPrinterCameraInformation>

            <View
                onLayout={event => setWidth(event.nativeEvent.layout.width)}
                style={{
                    width: '100%',
                    display: (isLoaded ? 'block' : 'none')
                }}
            >
                <Image
                    ref={image}
                    source={{ uri: activeURL }}
                    onError={error  => {
                        console.error('UserPrinterCamera: error:', error);

                        setError(error.error);

                        setIsLoaded(true);
                    }}
                    onLoad={event   => {
                        console.debug('UserPrinterCamera: event:', event);

                        setIsLoaded(true);
                    }}
                    style={{
                        height:     (isLoaded ? width / 2 : 0),
                        transform:  'scale(1, 1) rotate(0deg)'
                    }}
                />

                {!streamsMjpeg && (
                    <View style={{ width: '100%', flexDirection: 'row', justifyContent: 'center', paddingTop: 10 }}>
                        <Icon source="alert" size={12} />

                        <Text style={{ marginLeft: 2, fontSize: 12, color: colors.onSurfaceVariant }}>
                            {t("camera.slowMode")}
                        </Text>
                    </View>
                )}
            </View>
        </>
    );
}

export default memo(UserPrinterCamera);
