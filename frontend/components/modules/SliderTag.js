import { useState } from "react";
import { Pressable, View } from "react-native";
import { Portal, Text, useTheme } from "react-native-paper";

export default function SliderTag({ visible = false, title, positionX, positionY }) {
    const { colors } = useTheme();

    const HEIGHT = 32,
          WIDTH  = 48;

    return (
        <>
            {visible && (
                <Portal>
                    <View style={{
                        alignSelf: 'flex-start',
                        alignItems: 'center',
                        justifyContent: 'center',
                        paddingHorizontal: 16,
                        borderRadius: 4,
                        height: HEIGHT,
                        width: WIDTH,
                        maxHeight: HEIGHT,
                        position: 'absolute',
                        top: positionY - HEIGHT - 12,
                        left: positionX - (WIDTH / 2),
                        zIndex: 999,
                        backgroundColor: colors.primary
                    }} testID="tooltip-test">
                        <Text
                            accessibilityLiveRegion="polite"
                            numberOfLines={1}
                            selectable={false}
                            variant="labelLarge"
                            style={{ color: colors.surface }}
                        > {title} </Text>

                        <View style={{
                            position: 'absolute',
                            top: 30,
                            left: (30 / 1.5),
                            width: 0,
                            height: 0,
                            borderLeftWidth: 8,
                            borderLeftColor: 'transparent',
                            borderRightWidth: 8,
                            borderRightColor: 'transparent',
                            borderBottomWidth: 8,
                            borderBottomColor: 'transparent',
                            borderTopWidth: 8,
                            borderTopColor: colors.primary
                        }} />
                    </View>
                </Portal>
            )}
        </>
    );
}