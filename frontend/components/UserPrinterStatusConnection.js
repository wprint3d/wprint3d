import { useEffect, useState } from "react";

import { ActivityIndicator, Icon, Text } from "react-native-paper";

import TextBold from "./TextBold";

export default function UserPrinterStatusConnection({ connectionStatus, isRunningMapper }) {
    const [ currentStatus,          setCurrentStatus         ] = useState('waiting for server...');
    const [ thresholdSecs,          setThresholdSecs         ] = useState(null);
    const [ isWaitingForNewStatus,  setIsWaitingForNewStatus ] = useState(true);

    const handleMapperRunning = () => setCurrentStatus('connecting…');

    useEffect(() => {
        if (!connectionStatus) { return; }

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
            paddingTop: 10
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