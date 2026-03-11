import { Text, View }        from "react-native";
import { ActivityIndicator, useTheme } from "react-native-paper";

export default function UserPaneLoadingIndicator({ message, style }) {
    const { colors } = useTheme();

    return (
        <View
            style={{
                width: '100%',
                paddingVertical: 16,
                ...style
            }}
        >
            <ActivityIndicator animating={true} style={{ paddingBottom: 8 }} />
            <Text style={{ textAlign: 'center', color: colors.onBackground, paddingVertical: 8 }}>
                {message}…
            </Text>
        </View>
    );
}