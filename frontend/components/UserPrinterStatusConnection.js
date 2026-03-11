import { useEffect, useState } from "react";

import { ActivityIndicator, Icon, Text } from "react-native-paper";

import TextBold from "./TextBold";

export default function UserPrinterStatusConnection({ connectionStatus, isRunningMapper }) {
    const [ currentStatus,          setCurrentStatus         ] = useState('waiting for server…');
    const [ thresholdSecs,          setThresholdSecs         ] = useState(null);
    const [ isWaitingForNewStatus,  setIsWaitingForNewStatus ] = useState(true);
    const [ lastUpdate,             setLastUpdate            ] = useState(Date.now() / 1000);

    const MAX_THRESHOLD_SECS = 15;

    const handleMapperRunning = () => setCurrentStatus('connecting…');

    useEffect(() => {
        const timeout = setInterval(() => {
            if ((Date.now() / 1000) - lastUpdate <= MAX_THRESHOLD_SECS) { return; }

            setIsWaitingForNewStatus(false);
            setCurrentStatus('offline');
        }, 1000);

        return () => { clearTimeout(timeout); };
    }, [ lastUpdate ]);

    useEffect(() => {
        if (!connectionStatus) { return; }

        setLastUpdate(Date.now() / 1000);

        console.debug('UserPrinterStatusConnection: connectionStatus:', connectionStatus);

        setThresholdSecs(connectionStatus.thresholdSecs);

        const timeout = setInterval(() => {
            if (!connectionStatus) { return; }

            const now = Date.now() / 1000;

            setIsWaitingForNewStatus(
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

            if (thresholdSecs === null) { return; }

            const diffSecs = now - connectionStatus.lastSeen;

            // console.debug('UserPrinterStatusConnection: diffSecs:', diffSecs);

            setCurrentStatus(
                connectionStatus.lastSeen === null
                ||
                (
                    diffSecs
                    >
                    connectionStatus.thresholdSecs * 2
                )
                    ? 'offline'
                    : 'online'
            );
        }, 1000);

        if (isRunningMapper) {
            clearTimeout(timeout);

            handleMapperRunning();

            return;
        }

        return () => { clearTimeout(timeout); };
    }, [ connectionStatus ]);

    useEffect(() => {
        if (!isRunningMapper) { return; }

        handleMapperRunning();
    }, [ isRunningMapper ]);

    useEffect(() => {
        console.debug('UserPrinterStatusConnection: isWaitingForNewStatus:', isWaitingForNewStatus);
    }, [ isWaitingForNewStatus ]);

    return (
        <Text style={{ 
            width:      '100%',
            textAlign:  'center',
            paddingTop: 15
        }}>
            <ActivityIndicator
                animating={isWaitingForNewStatus}
                size={10}
                style={{
                    display:      'inline',
                    paddingRight: 4
                }}
            />
            <Icon source='connection' /> <TextBold>Connection status:</TextBold> {currentStatus}
        </Text>
    );
}