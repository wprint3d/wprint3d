import { View } from "react-native";

import { Text, useTheme } from "react-native-paper";

const FormattedTextView = ({ text, wrap = true }) => {
    const { colors } = useTheme();

    return (
        <View style={{ flexGrow: 1, flexShrink: 1, maxHeight: '50vh' }}>
            <Text style={{
                fontFamily: 'monospace', overflow: 'scroll',
                backgroundColor: colors.elevation.level1,
                padding: 8, whiteSpace: wrap ? 'pre-line' : 'pre',
            }}>
                {text}
            </Text>
        </View>
    );
}

export default FormattedTextView;