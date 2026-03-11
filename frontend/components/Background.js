import React, { memo } from 'react';
import {
  ImageBackground,
  StyleSheet,
  KeyboardAvoidingView,
  View,
} from 'react-native';
import { useTheme } from 'react-native-paper';

// TODO: Fix this layout, at some point. :)
// import Svg from '../assets/background.svg';

const Background = ({ children }) => {
  const { colors } = useTheme();

    // TODO: Fix this layout, at some point. :)
    // <View style={[ StyleSheet.absoluteFill, { width: '100%', height: '100%' } ]}>
    //     <Svg />
    // </View>

  return (
    <ImageBackground
      testID='background'
      source={require('../assets/background.png')}
      resizeMode="repeat"
      style={[
        styles.background,
        { backgroundColor: colors.background }
      ]}
    >
      <KeyboardAvoidingView style={styles.container} behavior="padding">
        {children}
      </KeyboardAvoidingView>
    </ImageBackground>
  );
}

const styles = StyleSheet.create({
  background: {
    flex: 1,
    position: 'absolute',
    top: 0,
    left: 0,
    height: '100%',
    width: '100%'
  },
  container: {
    width: '100%',
    height: '100%'
  },
});

export default memo(Background);