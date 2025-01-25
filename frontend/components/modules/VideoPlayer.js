
// TODO: Switch to expo-video once it's fixed
// import { useVideoPlayer, VideoView } from 'expo-video';
import { Video, ResizeMode } from 'expo-av';
import { useEffect, useRef, useState } from 'react';
import { useWindowDimensions, View } from 'react-native';
import { Button } from 'react-native-paper';

const VideoPlayer = ({ source }) => {
    console.debug('VideoPlayer: source:', source);

    const windowHeight = useWindowDimensions().height;

    useEffect(() => {
        console.debug('VideoPlayer: windowHeight:', windowHeight);
    }, [ windowHeight ]);

    const video = useRef(null);

    return (
      <View style={{ height: windowHeight / 1.5 }}>
        <Video
            source={{ uri: source }}
            ref={video}
            useNativeControls
            resizeMode={ResizeMode.CONTAIN}
            isLooping={false}
            style={{ width: '100%', height: '100%' }}
            isMuted={true}
            shouldPlay={true}
            videoStyle={{ width: '100%', height: '100%' }}
        />
      </View>
    );
};

export default VideoPlayer;