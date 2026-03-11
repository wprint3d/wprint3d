import { StyleSheet, View } from "react-native";

import { useTheme } from "react-native-paper";;

export default function UserPane({ children, style }) {
    const { colors } = useTheme();

    return (
        <View
            style={[
                styles.root,
                {
                    backgroundColor: colors.background,
                    borderColor:     colors.elevation.level4,
                    maxHeight:       '100%',
                    overflow:        'scroll',
                    flexGrow:        1
                },
                style
            ]}
        >
            {children}
        </View>
    );
}

const styles = StyleSheet.create({
    root: {
        borderWidth: 1,
        borderRadius: 8,
        padding: 12,
        paddingHorizontal: 16,
        overflow: 'auto'
    }
});