

import { Linking, StyleSheet, View, useWindowDimensions } from "react-native";

import { useQuery } from "@tanstack/react-query";

import UserLeftPane  from "./UserLeftPane";
import UserRightPane from "./UserRightPane";

import API from "../includes/API";
import { useEffect, useState } from "react";
import { useEcho } from "../hooks/useEcho";
import UserPrinterMapProgressSnackbar from "./UserPrinterMapProgressSnackbar";
import SimpleDialog from "./SimpleDialog";
import { Text } from "react-native-paper";
import JobRecoveryModal from "./JobRecoveryModal";
import UserPaneLoadingIndicator from "./UserPaneLoadingIndicator";

export default function UserLayout({ navbarHeight, isSmallTablet, isSmallLaptop }) {
    const dimensions = useWindowDimensions();

    const echo = useEcho();

    const [ enableTerminalPolling,  setEnableTerminalPolling ] = useState(false);
    const [ lastTerminalMessage,    setLastTerminalMessage   ] = useState(null);
    const [ isListening,            setIsListening           ] = useState(false);
    const [ connectionStatus,       setConnectionStatus      ] = useState(null);
    const [ isRunningMapper,        setIsRunningMapper       ] = useState(null);

    const selectedPrinter = useQuery({
        queryKey: ['selectedPrinter'],
        queryFn:  () => API.get('/user/printer/selected')
    });

    const printStatusQuery = useQuery({
        queryKey: ['printStatus'],
        queryFn:  () => API.get('/user/printer/selected/print'),
        enabled:  !!(selectedPrinter.isFetched && selectedPrinter.isSuccess && selectedPrinter.data?.data)
    });

    useEffect(() => {
        if (!echo) {
            console.warn('UserLayout: private: listen: echo is not ready');

            return;
        }

        if (!selectedPrinter.isFetched || !selectedPrinter.isSuccess) {
            console.warn('UserLayout: private: listen: selectedPrinter is not ready');

            return;
        }

        setEnableTerminalPolling(true);
    }, [ echo, selectedPrinter ]);

    useEffect(() => {
        if (!enableTerminalPolling && isListening || !echo) { return; }

        const printerId = selectedPrinter.data?.data;

        if (!printerId) {
            console.warn('UserLayout: private: listen: printerId is not ready');

            return;
        }

        const terminalChannelName = `console.${printerId}`;

        console.debug('UserLayout: private: listen: ', terminalChannelName);

        setIsListening(true);
    
        const channel   = echo.private(terminalChannelName),
              eventName = 'PrinterTerminalUpdated';
    
        channel.listen(eventName, event => {
            console.debug(`UserLayout: private: listen: event: ${terminalChannelName}: `, event);

            setLastTerminalMessage(event);
        });

        return () => {
            console.debug(`UserLayout: private: listen: cleanup: ${terminalChannelName}`);

            if (channel === null) { return; }

            channel.stopListening(eventName);

            // setIsListening(false);
        };
    }, [ enableTerminalPolling, setIsListening ]);

    useEffect(() => {
        if (!echo || !selectedPrinter.isFetched || !selectedPrinter.isSuccess) { return; }

        const printerId = selectedPrinter.data?.data;

        if (!printerId) { return; }

        const channelName = `connection-status.${printerId}`;

        console.debug('UserLayout: private: listen: ', channelName);

        const channel = echo.private(channelName),
              statusEventName = 'PrinterConnectionStatusUpdated',
              mapperEventName = 'PrinterMapperIsRunning'; 

        channel.listen(statusEventName, event => {
            console.debug(`UserLayout: private: listen: event: ${channelName}.${statusEventName}: `, event);

            setConnectionStatus(event);
        });

        channel.listen(mapperEventName, event => {
            console.debug(`UserLayout: private: listen: event: ${channelName}.${mapperEventName}: `, event);

            setIsRunningMapper(event);
        });

        return () => {
            console.debug(`UserLayout: private: listen: cleanup: ${channelName}`);

            if (channel === null) { return; }

            channel.stopListening(statusEventName);
            channel.stopListening(mapperEventName);
        }
    }, [ echo, selectedPrinter.data ]);
        
    useEffect(() => {
        if (!isRunningMapper) { return; }

        const timeout = setTimeout(() => setIsRunningMapper(null), 5000);

        return () => clearTimeout(timeout);
    }, [isRunningMapper]);

    const maxPaneHeight = dimensions.height - navbarHeight - (styles.root.padding * 2);

    const printStatus = printStatusQuery?.data?.data;

    return (
        <View style={[
            styles.root,
            (
                dimensions.width >= 1600
                    ? { paddingHorizontal: (dimensions.width >= 1920 ? 128 : 32) }
                    : {}
            ),
            {
                flexWrap: (
                    dimensions.width <= 425 // mobile large
                        ? 'wrap'
                        : 'nowrap'
                )
            }
        ]}>
            <UserPrinterMapProgressSnackbar isRunningMapper={isRunningMapper} />

            <UserLeftPane
                selectedPrinter={selectedPrinter}
                maxHeight={maxPaneHeight}
                connectionStatus={connectionStatus}
                lastTerminalMessage={lastTerminalMessage}
                isRunningMapper={isRunningMapper}
                printStatus={printStatus}
            />

            <UserRightPane
                selectedPrinter={selectedPrinter}
                maxHeight={maxPaneHeight}
                connectionStatus={connectionStatus}
                lastTerminalMessage={lastTerminalMessage}
                isSmallLaptop={isSmallLaptop}
                isSmallTablet={isSmallTablet}
            />

            <JobRecoveryModal
                selectedPrinter={selectedPrinter}s
                isSmallLaptop={isSmallLaptop}
                isSmallTablet={isSmallTablet}
                printStatus={printStatus}
            />
        </View>
    );
}

const styles = StyleSheet.create({
    root: {
        width: '100%',
        display: 'flex',
        flexDirection: 'row',
        padding: 8,
        gap: 8
    }
});