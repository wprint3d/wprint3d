import { useEffect, useRef, useState } from "react";

import { useEcho } from "./useEcho";

export function useTerminalMessages({ printerId }) {
    const [ messages, setMessages ] = useState([]);
    const messageIdRef = useRef(0);

    const echo = useEcho();

    useEffect(() => {
        if (!echo) {
            console.warn('UserPrinterTerminal: private: listen: echo is not ready');

            return;
        }

        if (!printerId) {
            console.warn('UserPrinterTerminal: private: listen: printerId is not ready');

            return;
        }

        messageIdRef.current = 0;
        setMessages([]);

        const terminalChannelName = `console.${printerId}`;

        console.debug('UserPrinterTerminal: private: listen:', terminalChannelName);

        const channel   = echo.private(terminalChannelName),
              eventName = 'PrinterTerminalUpdated';

        channel.listen(eventName, event => {
            console.debug(`UserPrinterTerminal: private: listen: event: ${terminalChannelName}: `, event);

            messageIdRef.current += 1;

            setMessages(previousMessages => [
                ...previousMessages.slice(-199),
                {
                    id: messageIdRef.current,
                    event: event
                }
            ]);
        });

        return () => {
            console.debug(`UserPrinterTerminal: private: listen: cleanup: ${terminalChannelName}`);

            if (channel === null) { return; }

            channel.stopListening(eventName);
            messageIdRef.current = 0;
            setMessages([]);
        };
    }, [ echo, printerId ]);

    return messages;
}
