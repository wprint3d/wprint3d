import { useEffect, useState } from "react";

import { useEcho } from "./useEcho";

export function useConnectionStatus({ printerId }) {
    const [ connectionStatus, setConnectionStatus ] = useState(null),
          [ isRunningMapper,  setIsRunningMapper  ] = useState(null);

    const echo = useEcho();

    useEffect(() => {
        if (!echo) {
            console.warn('UserPrinterStatusConnection: private: listen: echo is not ready');

            return;
        }

        if (!printerId) {
            console.warn('UserPrinterStatusConnection: private: listen: printerId is not ready');

            return;
        }

        const channelName = `connection-status.${printerId}`;

        console.debug('UserPrinterStatusConnection: private: listen: ', channelName);

        const channel = echo.private(channelName),
            statusEventName = 'PrinterConnectionStatusUpdated',
            mapperEventName = 'PrinterMapperIsRunning'; 

        channel.listen(statusEventName, event => {
            console.debug(`UserPrinterStatusConnection: private: listen: event: ${channelName}.${statusEventName}: `, event);

            setConnectionStatus(event);
        });

        channel.listen(mapperEventName, event => {
            console.debug(`UserPrinterStatusConnection: private: listen: event: ${channelName}.${mapperEventName}: `, event);

            setIsRunningMapper(event);
        });

        return () => {
            console.debug(`UserPrinterStatusConnection: private: listen: cleanup: ${channelName}`);

            if (channel === null) { return; }

            channel.stopListening(statusEventName);
            channel.stopListening(mapperEventName);
        }
    }, [ echo, printerId ]);

    return { connectionStatus, isRunningMapper };
}