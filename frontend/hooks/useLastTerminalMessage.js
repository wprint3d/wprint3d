import { useEffect, useState } from "react";

import { useEcho } from "./useEcho";

export function useLastTerminalMessage({ printerId }) {
    const [ lastMessage, setLastMessage ] = useState(null);

    const echo = useEcho();

    useEffect(() => {
        if (!echo) {
            console.warn('UserLayout: private: listen: echo is not ready');

            return;
        }

        if (!printerId) {
            console.warn('UserLayout: private: listen: printerId is not ready');

            return;
        }

        const terminalChannelName = `console.${printerId}`;

        console.debug('UserLayout: private: listen: ', terminalChannelName);

        const channel   = echo.private(terminalChannelName),
              eventName = 'PrinterTerminalUpdated';
    
        channel.listen(eventName, event => {
            console.debug(`UserLayout: private: listen: event: ${terminalChannelName}: `, event);

            setLastMessage(event);
        });

        return () => {
            console.debug(`UserLayout: private: listen: cleanup: ${terminalChannelName}`);

            if (channel === null) { return; }

            channel.stopListening(eventName);
        };
    }, [ echo, printerId ]);

    return lastMessage;
}