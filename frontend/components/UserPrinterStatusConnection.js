import { useEffect, useState } from "react";
import { View } from "react-native";

import { ActivityIndicator, Icon, IconButton, Text, useTheme } from "react-native-paper";

import TextBold from "./TextBold";
import { useLocalization } from "../includes/LocalizationProvider";
import PrinterConnectionDiagnosticDialog from "./PrinterConnectionDiagnosticDialog";
import {
    PRINTER_CONNECTION_STATUS,
    getPrinterConnectionStatusKey,
} from "../utils/printerConnectionStatus";
import {
    getPrinterConnectionDiagnosticOutput,
    hasUnresponsiveConnectionDiagnostic,
} from "../utils/printerConnectionDiagnostic";

const STATUS_TRANSLATION_KEYS = {
    waitingForServer: "printer.status.waitingForServer",
    connecting:      "printer.status.connecting",
    offline:         "printer.status.offline",
    online:          "printer.status.online",
    reconnecting:    "printer.status.reconnecting",
    unresponsive:    "printer.status.unresponsive",
};

export default function UserPrinterStatusConnection({ connectionStatus, isRunningMapper }) {
    const { t } = useLocalization();
    const { colors } = useTheme();
    const [ currentStatusKey,       setCurrentStatusKey      ] = useState("waitingForServer");
    const [ isWaitingForNewStatus,  setIsWaitingForNewStatus ] = useState(true);
    const [ lastUpdate,             setLastUpdate            ] = useState(Date.now() / 1000);
    const [ showConnectionDiagnosticDialog, setShowConnectionDiagnosticDialog ] = useState(false);

    const MAX_THRESHOLD_SECS = 15;

    const handleMapperRunning = () => setCurrentStatusKey(PRINTER_CONNECTION_STATUS.CONNECTING);

    useEffect(() => {
        const timeout = setInterval(() => {
            if ((Date.now() / 1000) - lastUpdate <= MAX_THRESHOLD_SECS) { return; }
            if (currentStatusKey === PRINTER_CONNECTION_STATUS.UNRESPONSIVE) { return; }
            if (currentStatusKey === PRINTER_CONNECTION_STATUS.RECONNECTING) { return; }

            setIsWaitingForNewStatus(false);
            setCurrentStatusKey(PRINTER_CONNECTION_STATUS.OFFLINE);
        }, 1000);

        return () => { clearInterval(timeout); };
    }, [ currentStatusKey, lastUpdate ]);

    useEffect(() => {
        if (!connectionStatus) { return; }

        setLastUpdate(Date.now() / 1000);

        console.debug('UserPrinterStatusConnection: connectionStatus:', connectionStatus);

        const updateConnectionState = () => {
            if (!connectionStatus) { return; }

            const now = Date.now() / 1000;

            setIsWaitingForNewStatus(
                !connectionStatus.isReconnecting
                &&
                connectionStatus.connectionStatus !== PRINTER_CONNECTION_STATUS.UNRESPONSIVE
                &&
                connectionStatus.lastSeen !== null
                &&
                (
                    now - connectionStatus.lastSeen
                    >
                    connectionStatus.thresholdSecs
                )
                &&
                (
                    now - connectionStatus.lastSeen
                    <
                    connectionStatus.thresholdSecs * 2
                )
            );

            setCurrentStatusKey(getPrinterConnectionStatusKey({
                connectionStatus,
                isRunningMapper,
                nowSecs: now,
            }));
        };

        updateConnectionState();

        const timeout = setInterval(updateConnectionState, 1000);

        if (isRunningMapper && !connectionStatus.isReconnecting) {
            clearInterval(timeout);

            handleMapperRunning();

            return;
        }

        return () => { clearInterval(timeout); };
    }, [ connectionStatus, isRunningMapper ]);

    useEffect(() => {
        if (!isRunningMapper) { return; }
        if (connectionStatus?.isReconnecting) { return; }

        handleMapperRunning();
    }, [ connectionStatus?.isReconnecting, isRunningMapper ]);

    useEffect(() => {
        console.debug('UserPrinterStatusConnection: isWaitingForNewStatus:', isWaitingForNewStatus);
    }, [ isWaitingForNewStatus ]);

    const isUnresponsive = currentStatusKey === PRINTER_CONNECTION_STATUS.UNRESPONSIVE;
    const isReconnecting = currentStatusKey === PRINTER_CONNECTION_STATUS.RECONNECTING;
    const statusText = t(STATUS_TRANSLATION_KEYS[currentStatusKey] ?? STATUS_TRANSLATION_KEYS.offline);
    const statusColor = isReconnecting ? colors.onTertiaryContainer : colors.onSurface;
    const diagnostic = getPrinterConnectionDiagnosticOutput(connectionStatus);
    const hasDiagnostic = hasUnresponsiveConnectionDiagnostic({
        connectionStatus: currentStatusKey,
        connectionDiagnostic: diagnostic,
    });

    return (
        <>
            <PrinterConnectionDiagnosticDialog
                visible={showConnectionDiagnosticDialog}
                setVisible={setShowConnectionDiagnosticDialog}
                diagnostic={diagnostic}
            />
            <View style={{
                width: '100%',
                paddingTop: 15,
                justifyContent: 'center',
                alignItems: 'center',
            }}>
                <View
                    accessibilityLabel={`${t("printer.status.connectionStatus")} ${statusText}`}
                    accessibilityLiveRegion="polite"
                    style={{
                        minHeight: 28,
                        paddingHorizontal: isReconnecting ? 10 : 0,
                        paddingVertical: isReconnecting ? 4 : 0,
                        borderRadius: 999,
                        backgroundColor: isReconnecting ? colors.tertiaryContainer : 'transparent',
                        flexDirection: 'row',
                        justifyContent: 'center',
                        alignItems: 'center',
                        flexWrap: 'wrap',
                    }}
                >
                    <ActivityIndicator
                        animating={isWaitingForNewStatus || isReconnecting}
                        color={isReconnecting ? statusColor : undefined}
                        size={isReconnecting ? 14 : 10}
                        style={{ marginRight: 4 }}
                    />
                    {!isReconnecting && (hasDiagnostic ? (
                        <IconButton
                            icon="help-circle-outline"
                            iconColor={statusColor}
                            size={18}
                            accessibilityLabel={t("printer.status.viewDiagnostic")}
                            onPress={() => setShowConnectionDiagnosticDialog(true)}
                            style={{ width: 24, height: 24, margin: 0 }}
                        />
                    ) : (
                        <Icon
                            source={isUnresponsive ? 'help-circle-outline' : 'connection'}
                            color={statusColor}
                            size={18}
                        />
                    ))}
                    <Text style={{ marginLeft: 4, color: statusColor }}>
                        <TextBold>{t("printer.status.connectionStatus")}</TextBold>{' '}
                        <Text style={{ color: statusColor, fontWeight: isReconnecting ? 'bold' : 'normal' }}>
                            {statusText}
                        </Text>
                    </Text>
                </View>
            </View>
        </>
    );
}
