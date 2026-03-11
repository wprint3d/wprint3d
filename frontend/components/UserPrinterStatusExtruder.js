import { Icon, Text } from "react-native-paper";

import { View } from "react-native";

import TextBold from "./TextBold";

export default function UserPrinterStatusExtruder({ extruder, index }) {
    if (typeof extruder === 'undefined') { return <></>; }

    return (
        <View style={{ paddingTop: 10 }}>
            <Text style={{ width: '100%', textAlign: 'center' }}>
                <Icon source='printer-3d-nozzle' /> <TextBold>Extruder #{index}: </TextBold>
            </Text>
            <Text style={{ width: '100%', textAlign: 'center' }}>
                {extruder.temperature}°C {extruder.target > 0 && `(targetting ${extruder.target}°C)`}
            </Text>
        </View>
    );
}