import { memo, useEffect, useRef, useState } from "react";

import { Platform, View } from "react-native";

import { Image } from "expo-image";

import UserPrinterCameraError from "./UserPrinterCameraError";
import UserPrinterCameraInformation from "./UserPrinterCameraInformation";
import UserPaneLoadingIndicator from "./UserPaneLoadingIndicator";

const UserPrinterCamera = ({ url, isConnected }) => {
    const image = useRef(null);

    const [ width,      setWidth     ] = useState(0);
    const [ activeURL,  setActiveURL ] = useState(url);
    const [ error,      setError     ] = useState(null);
    const [ isLoaded,   setIsLoaded  ] = useState(false);

    useEffect(() => {
        if (
            image.current && Platform.OS === 'web'
            &&
            image.current.nativeViewRef.current
        ) {
            image.current.nativeViewRef.current.src = url;
        }

        setError(null);
        setActiveURL(url);
    }, [ url ] );

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
                    'Re-seat the camera into the port.',
                    'Restart the host.',
                    'Remove it from the list of assigned cameras.',
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
                        'Reset the USB controller.',
                        'Re-seat the camera into the port.',
                        'Restart the host.',
                        'Remove it from the list of assigned cameras.'
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
                    source={{ uri: `${activeURL}?${new URLSearchParams({ action: 'stream' })}` }}
                    onError={error  => {
                        console.error(error);

                        setError(error.error);

                        setIsLoaded(true);
                    }}
                    onLoad={event   => {
                        console.log('event:', event);

                        setIsLoaded(true);
                    }}
                    style={{
                        height:     (isLoaded ? width / 2 : 0),
                        transform:  'scale(1, 1) rotate(0deg)'
                    }}
                />
            </View>
        </>
    );
}

export default memo(UserPrinterCamera);