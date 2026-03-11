import { memo, useEffect, useRef, useState } from "react";

import { Platform, View } from "react-native";
import { Icon, Text, useTheme } from "react-native-paper";

import { Image } from "expo-image";

import UserPrinterCameraError from "./UserPrinterCameraError";
import UserPrinterCameraInformation from "./UserPrinterCameraInformation";
import UserPaneLoadingIndicator from "./UserPaneLoadingIndicator";

const UserPrinterCamera = ({ url, isConnected, supportsMjpeg = true }) => {
    const image = useRef(null);

    const { colors } = useTheme();

    const [ width,      setWidth      ] = useState(0);
    const [ activeURL,  setActiveURL  ] = useState(null);
    const [ error,      setError      ] = useState(null);
    const [ isLoaded,   setIsLoaded   ] = useState(false);
    const [ isUpdating, setIsUpdating ] = useState(true);

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
        console.debug('UserPrinterCamera: isUpdating:', isUpdating);

        if (supportsMjpeg) {
            return; // Exit early if MJPEG is supported
        }

        const interval = setInterval(() => {
            setActiveURL(`${url}?${new URLSearchParams({ action: 'stream', t: (new Date()).getTime() })}`);
        }, 1500);

        return () => {
            clearInterval(interval); // Cleanup the interval when the component unmounts
        };
    }, [url]); // Depend on `url` to update the interval if `url` changes

    useEffect(() => {
        console.debug('UserPrinterCamera: activeURL:', activeURL);
    }, [ activeURL ]);

    const viewHeight = width / 2;

    if (!isConnected) {
        return (
            <UserPrinterCameraError
                icon="power-plug"
                message="This camera is not connected."
                height={viewHeight}
                suggestions={[
                    'Make sure that the camera is plugged in.',
                    'Reset the USB controller.',
                    'Restart the host.'
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
                    message="This camera is not working."
                    height={viewHeight}
                    error={error}
                    suggestions={[
                        'Re-seat the camera into the port.',
                        'Restart the host.'
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
                    <UserPaneLoadingIndicator message="Buffering stream" />
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
                        setIsUpdating(false);
                    }}
                    onLoad={event   => {
                        console.debug('UserPrinterCamera: event:', event);

                        setIsLoaded(true);
                        setIsUpdating(false);
                    }}
                    style={{
                        height:     (isLoaded ? width / 2 : 0),
                        transform:  'scale(1, 1) rotate(0deg)'
                    }}
                />

                {!supportsMjpeg && (
                    <View style={{ width: '100%', flexDirection: 'row', justifyContent: 'center', paddingTop: 10 }}>
                        <Icon source="alert" size={12} />

                        <Text style={{ marginLeft: 2, fontSize: 12, color: colors.onSurfaceVariant }}>
                            Slow mode (MJPEG is not supported)
                        </Text>
                    </View>
                )}
            </View>
        </>
    );
}

export default memo(UserPrinterCamera);