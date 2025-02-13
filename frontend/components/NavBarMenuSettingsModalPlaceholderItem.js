import { View } from "react-native";
import { Icon, List, Text, useTheme } from "react-native-paper";

const NavBarMenuSettingsModalPlaceholderItem = ({ icon, message, troubleshootingOptions = [], actions = [] }) => {
    const { colors } = useTheme();

    return (
        <View style={{ alignItems: 'center', justifyContent: 'center', flex: 1 }}>
            <Icon source={icon} size={64} />

            <Text variant="bodyLarge" style={{ textAlign: 'center', paddingVertical: 20 }}>
                {message}
            </Text>

            {troubleshootingOptions.length > 0 &&
                <List.Accordion title="Troubleshooting" theme={{ colors: { background: colors.elevation.level0 } }}>
                    {troubleshootingOptions.map((option, index) => <List.Item key={index} title={option} />)}
                </List.Accordion>
            }

            {actions}
        </View>
    );
};

export default NavBarMenuSettingsModalPlaceholderItem;