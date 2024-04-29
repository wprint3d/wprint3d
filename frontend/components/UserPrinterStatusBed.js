import { Icon, Text } from "react-native-paper";
import TextBold from "./TextBold";
import { View } from "react-native";

export default function UserPrinterStatusBed({ connectionStatus }) {
    if (
        !connectionStatus.isFetched || !connectionStatus.isSuccess
        ||
        typeof connectionStatus.data.data.statistics === 'undefined'
    ) { return <></>; }

    const { bed } = connectionStatus.data.data.statistics;

    if (typeof bed === 'undefined') { return <></>; }

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