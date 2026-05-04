import { ScrollView } from "react-native";
import { Button, Icon, Text, useTheme } from "react-native-paper";

import SimpleDialog from "./SimpleDialog";
import { useLocalization } from "../includes/LocalizationProvider";

export default function PrinterConnectionDiagnosticDialog({ visible, setVisible, diagnostic }) {
    const { colors } = useTheme();
    const { t } = useLocalization();

    return (
        <SimpleDialog
            visible={visible}
            setVisible={setVisible}
            title={t("printer.status.unresponsiveDiagnosticTitle")}
            left={<Icon source="help-circle-outline" color={colors.warning ?? colors.error} size={28} />}
            style={{ maxWidth: 640 }}
            content={
                <>
                    <Text style={{ marginBottom: 12 }}>
                        {t("printer.status.unresponsiveDiagnosticHelp")}
                    </Text>
                    <ScrollView
                        style={{
                            maxHeight: 260,
                            borderRadius: 12,
                            backgroundColor: colors.elevation?.level1 ?? colors.surfaceVariant,
                            padding: 12,
                        }}
                    >
                        <Text style={{ fontFamily: 'monospace', whiteSpace: 'pre-wrap' }}>
                            {diagnostic}
                        </Text>
                    </ScrollView>
                </>
            }
            actions={
                <Button mode="contained" onPress={() => setVisible(false)}>
                    {t("notifications.gotIt")}
                </Button>
            }
        />
    );
}
