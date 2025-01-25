import { View } from "react-native";

import { HelperText, Icon, List, Text } from "react-native-paper";

import UserPrinterCameraInformation from "./UserPrinterCameraInformation";

export default function UserPrinterCameraError({ icon, message, error = null, suggestions = [], onLayout, height }) {
    return (
        <UserPrinterCameraInformation onLayout={onLayout} height={height}>
            <View style={{
                display:        'flex',
                flexDirection:  'column',
                flexGrow:       1,
                alignItems:     'center',
                justifyContent: 'center',
                marginVertical: 3,
                maxWidth:       350,
                width:          '100%'
            }}>
                <Icon source={icon} size={48} />

                <Text style={{ marginTop: 10, fontSize: 16 }}>
                    {message}
                </Text>

                <View style={{ maxWidth: '100%', marginTop: 10 }}>
                    {error && <HelperText>{error}</HelperText>}

                    <List.Accordion title="Troubleshooting options" style={{ padding: 0 }} theme={{ colors: { background: 'transparent' } }}>
                        {suggestions.map((suggestion, index) => <List.Item description={`- ${suggestion}`} key={index} />)}
                    </List.Accordion>
                </View>
            </View>
        </UserPrinterCameraInformation>
    );
}