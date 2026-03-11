import { Icon, Text } from "react-native-paper";
import TextBold from "./TextBold";
import { View } from "react-native";

export default function UserPrinterStatusBed({ connectionStatus }) {
    if (!connectionStatus?.statistics) { return <></>; }

    const { bed } = connectionStatus?.statistics;

    if (!bed) { return <></>; }

    return (
        <View style={{ paddingTop: 10, width: '100%' }}>
            <Text style={{ width: '100%', textAlign: 'center' }}>
                <Icon source='printer-3d' /> <TextBold>Bed:</TextBold>
            </Text>
            <Text style={{ width: '100%', textAlign: 'center' }}>
                { bed.temperature }°C {bed.target > 0 && `(targetting ${bed.target}°C)`}
            </Text>
        </View>
    );
}