import { Icon, Text } from "react-native-paper";
import TextBold from "./TextBold";
import { View } from "react-native";
import { useLocalization } from "../includes/LocalizationProvider";

export default function UserPrinterStatusBed({ connectionStatus }) {
    const { t } = useLocalization();
    if (!connectionStatus?.statistics) { return <></>; }

    const { bed } = connectionStatus?.statistics;

    if (!bed) { return <></>; }

    return (
        <View style={{ paddingTop: 10, width: '100%' }}>
            <Text style={{ width: '100%', textAlign: 'center' }}>
                <Icon source='printer-3d' /> <TextBold>{t("printer.status.bedLabel")}</TextBold>
            </Text>
            <Text style={{ width: '100%', textAlign: 'center' }}>
                {bed.temperature}°C {bed.target > 0 && t("printer.status.targeting", { temperature: bed.target })}
            </Text>
        </View>
    );
}
