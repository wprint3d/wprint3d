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

export default function UserLeftPane({ isLoadingPrinter = true, printerId = null, maxHeight, printStatus }) {
    const windowWidth = useWindowDimensions().width;

    const { connectionStatus, isRunningMapper } = useConnectionStatus({ printerId });

    const lastTerminalMessage = useLastTerminalMessage({ printerId });

    let width = '30%';

    if (windowWidth <= 768)  {          // small tablet
        width = '100%';
    } else if (windowWidth <= 1024) {   // small laptop
        width = '45%';
    } else if (windowWidth <= 1440) {   // medium laptop
        width = '40%';
    } else if (windowWidth <= 1600) {   // small desktop
        width = '35%';  
    }

    useEffect(() => {
        console.debug('windowWidth:', windowWidth);
    }, [ windowWidth ]);

    return (
        <UserPane style={{
            width:      width,
            maxHeight:  maxHeight,
            height:     maxHeight,
            overflow:   'auto'
        }}>
            {
                isLoadingPrinter
                    ? <UserPaneLoadingIndicator message={"Getting printer information"} />
                    : <>
                        <UserPrinterPicker key={-1} printerId={printerId} />
                        {printerId && (
                            <>
                                <UserPrinterStatus              connectionStatus={connectionStatus} isRunningMapper={isRunningMapper} />
                                <UserPrinterTemperaturePresets  />
                                <UserPrinterCameras             />
                                <UserPrinterFileProgress        lastTerminalMessage={lastTerminalMessage} />
                                <UserPrinterFileControls        printerId={printerId} connectionStatus={connectionStatus} printStatus={printStatus} />
                            </>
                        )}
                    </>
            }
        </UserPane>
    );
}