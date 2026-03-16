import { useWindowDimensions } from "react-native";

import UserPane                      from "./UserPane";
import UserPaneLoadingIndicator      from "./UserPaneLoadingIndicator";
import UserPrinterPicker             from "./UserPrinterPicker";
import UserPrinterCameras            from "./UserPrinterCameras";
import UserPrinterTemperaturePresets from "./UserPrinterTemperaturePresets";
import UserPrinterFileControls       from "./UserPrinterFileControls";
import UserPrinterFileProgress       from "./UserPrinterFileProgress";
import UserPrinterStatus             from "./UserPrinterStatus";

import { useEffect } from "react";
import { useMutation, useQueryClient } from "@tanstack/react-query";
import { useConnectionStatus } from "../hooks/useConnectionStatus";
import { useLastTerminalMessage } from "../hooks/useLastTerminalMessage";
import { useLocalization } from "../includes/LocalizationProvider";
import { getLeftPaneWidth } from "../utils/userLayout";

export default function UserLeftPane({ isLoadingPrinter = true, printerId = null, printStatus }) {
    const { t } = useLocalization();
    const windowWidth = useWindowDimensions().width;

    const { connectionStatus, isRunningMapper } = useConnectionStatus({ printerId });

    const lastTerminalMessage = useLastTerminalMessage({ printerId });

    const width = getLeftPaneWidth(windowWidth);

    useEffect(() => {
        console.debug('windowWidth:', windowWidth);
    }, [ windowWidth ]);

    return (
        <UserPane style={{ width: width, maxWidth: width, overflow: 'auto' }}>
            {
                isLoadingPrinter
                    ? <UserPaneLoadingIndicator message={t("printer.picker.loadingDetails")} />
                    : <>
                        <UserPrinterPicker key={-1} printerId={printerId} />
                        {printerId && (
                            <>
                                <UserPrinterStatus              connectionStatus={connectionStatus} isRunningMapper={isRunningMapper} />
                                <UserPrinterTemperaturePresets  />
                                <UserPrinterCameras             />
                                <UserPrinterFileProgress        lastTerminalMessage={lastTerminalMessage} />
                            </>
                        )}
                        <UserPrinterFileControls        printerId={printerId} connectionStatus={connectionStatus} printStatus={printStatus} />
                    </>
            }
        </UserPane>
    );
}
