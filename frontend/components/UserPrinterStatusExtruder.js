import { Icon, Text } from "react-native-paper";

import { View } from "react-native";

import TextBold from "./TextBold";
import { useLocalization } from "../includes/LocalizationProvider";

export default function UserPrinterStatusExtruder({ extruder, index }) {
    const { t } = useLocalization();
    if (typeof extruder === 'undefined') { return <></>; }

    return (
        <View style={{ paddingTop: 10 }}>
            <Text style={{ width: '100%', textAlign: 'center' }}>
                <Icon source='printer-3d-nozzle' /> <TextBold>{t("printer.status.extruderLabel", { index })} </TextBold>
            </Text>
            <Text style={{ width: '100%', textAlign: 'center' }}>
                {extruder.temperature}°C {extruder.target > 0 && t("printer.status.targeting", { temperature: extruder.target })}
            </Text>
        </View>
    );
}
