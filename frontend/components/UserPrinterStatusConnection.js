import { useEffect, useState } from "react";

import { ActivityIndicator, Icon, Text } from "react-native-paper";

import TextBold from "./TextBold";

export default function UserPrinterStatusConnection({ connectionStatus }) {
    const [ currentStatus, setCurrentStatus ] = useState('waiting for server...');

    useEffect(() => {
        if (connectionStatus.isError) {
            setCurrentStatus(connectionStatus.error);

            return;
        }

        if (!connectionStatus.isFetched) { return; }

        setCurrentStatus(
            (Date.now() / 1000) - connectionStatus.data.lastSeen
            >
            connectionStatus.data.thresholdSecs
                ? 'offline'
                : 'online'
        );
    }, [ connectionStatus.data, connectionStatus.isFetching ]);

    return (
        <Text style={{ 
            width:      '100%',
            textAlign:  'center',
            paddingTop: 10
        }}>
            <ActivityIndicator
                animating={connectionStatus.isFetching}
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