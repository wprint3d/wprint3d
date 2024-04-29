import { Text, View }        from "react-native";
import { ActivityIndicator, useTheme } from "react-native-paper";

export default function UserPaneLoadingIndicator({ message, style }) {
    const { colors } = useTheme();

    return (
        <View
            style={{
                width: '100%',
                paddingVertical: 8,
                ...style
            }}
        >
            <ActivityIndicator animating={true} style={{ paddingBottom: 8 }} />
            <Text style={{ textAlign: 'center', color: colors.onBackground }}>
                {message}...
            </Text>
        </View>
    );
}