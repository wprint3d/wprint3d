import { useEffect, useState } from "react";
import { useQuery } from "@tanstack/react-query";

import { useEcho } from "./useEcho";
import API from "../includes/API";

export function useConnectionStatus({ printerId }) {
    const [ connectionStatus, setConnectionStatus ] = useState(null),
          [ isRunningMapper,  setIsRunningMapper  ] = useState(null);

    const echo = useEcho();

    const initialConnectionStatus = useQuery({
        queryKey: ['connectionStatus', printerId],
        queryFn:  () => API.get('/user/printer/selected/status'),
        enabled:  !!printerId,
    });

    useEffect(() => {
        if (!initialConnectionStatus.isSuccess) { return; }

        setConnectionStatus(initialConnectionStatus.data?.data ?? null);
    }, [ initialConnectionStatus.isSuccess, initialConnectionStatus.data ]);

    useEffect(() => {
        if (!echo) {
            console.warn('UserPrinterStatusConnection: private: listen: echo is not ready');

            return;
        }

        if (!printerId) {
            console.warn('UserPrinterStatusConnection: private: listen: printerId is not ready');

            return;
        }

        const mainChannelName   = `connection-status.${printerId}`,
              mapperChannelName = 'printers-map-updated';

        console.debug('UserPrinterStatusConnection: private: listen: ', mainChannelName, mapperChannelName);

        const mainChannel = echo.private(mainChannelName),
              statusEventName         = 'PrinterConnectionStatusUpdated',
              mapperRunningEventName  = 'PrinterMapperIsRunning';

        const mapperChannel = echo.channel(mapperChannelName),
              mapperStoppedEventName = 'PrintersMapUpdated';

        const handleStatusUpdated = event => {
            console.debug(`UserPrinterStatusConnection: private: listen: event: ${mainChannelName}.${statusEventName}: `, event);

            setConnectionStatus(event);
        };

        const handleMapperRunning = event => {
            console.debug(`UserPrinterStatusConnection: private: listen: event: ${mainChannelName}.${mapperRunningEventName}: `, event);

            setIsRunningMapper(event);
        };

        const handleMapperStopped = event => {
            console.debug(`UserPrinterStatusConnection: private: listen: event: ${mapperChannel}.${mapperStoppedEventName}: `, event);

            setIsRunningMapper(null);
        };

        mainChannel.listen(statusEventName, handleStatusUpdated);
        mainChannel.listen(mapperRunningEventName, handleMapperRunning);
        mapperChannel.listen(mapperStoppedEventName, handleMapperStopped);

        return () => {
            console.debug(`UserPrinterStatusConnection: private: listen: cleanup: ${mainChannelName}, ${mapperChannelName}`);

            if (mainChannel === null && mapperChannel === null) { return; }

            mainChannel.stopListening(statusEventName, handleStatusUpdated);
            mainChannel.stopListening(mapperRunningEventName, handleMapperRunning);

            mapperChannel.stopListening(mapperStoppedEventName, handleMapperStopped);
        }
    }, [ echo, printerId ]);

    return { connectionStatus, isRunningMapper };
}
