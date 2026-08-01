import Pusher from 'pusher-js';
import Echo from 'laravel-echo';

import { useQuery, useQueryClient } from '@tanstack/react-query';

import { createContext, useContext, useEffect, useRef, useState } from 'react';

import API from '../includes/API';
import {
    getRealtimeConnectionOptions,
    getRealtimeRetryDelay,
    shouldReconcileRealtimeQuery,
    shouldRetryRealtimeConfig,
} from '../utils/realtime';

const EchoContext = createContext({
    echo: null,
    connectionState: 'disconnected',
});

export function EchoProvider({ children }) {
    const [ echo,            setEcho            ] = useState(null);
    const [ connectionState, setConnectionState ] = useState('disconnected');

    const hasConnected = useRef(false);
    const queryClient = useQueryClient();

    const websocketConfig = useQuery({
        queryKey: ['websocketConfig'],
        queryFn:  () => API.get('/ws/config'),
        retry: shouldRetryRealtimeConfig,
        retryDelay: getRealtimeRetryDelay,
        refetchOnReconnect: 'always',
    });
    const appKey = websocketConfig.data?.data?.appKey;

    useEffect(() => {
        console.debug('websocketConfig:', websocketConfig);

        if (!websocketConfig.isSuccess) { return; }

        if (typeof appKey !== 'string' || appKey.trim() === '') {
            console.warn('useEcho: websocket config is missing a valid appKey', {
                status:      websocketConfig.data?.status,
                contentType: websocketConfig.data?.headers?.['content-type'] ?? null,
            });

            setEcho(null);

            return;
        }

        if (typeof window === 'undefined' || !window.location?.hostname) {
            console.warn('useEcho: browser location is not available');

            setEcho(null);

            return;
        }

        const nextEcho = new Echo({
            ...getRealtimeConnectionOptions({ appKey, location: window.location }),
            Pusher,
        });

        const connection = nextEcho.connector.pusher.connection;

        const reconcile = () => queryClient.invalidateQueries({
            predicate: shouldReconcileRealtimeQuery,
            refetchType: 'active',
        });

        const handleConnected = () => {
            setConnectionState('connected');

            if (hasConnected.current) {
                console.info('useEcho: connection restored; reconciling realtime state');
                void reconcile();
            }

            hasConnected.current = true;
        };

        const handleStateChange = ({ current }) => {
            console.debug('useEcho: connection state changed:', current);
            setConnectionState(current);
        };

        const handleError = error => {
            console.warn('useEcho: connection error:', error);
        };

        connection.bind('connected', handleConnected);
        connection.bind('state_change', handleStateChange);
        connection.bind('error', handleError);

        if (connection.state === 'connected') {
            handleConnected();
        }

        setEcho(nextEcho);
        setConnectionState(connection.state);

        return () => {
            connection.unbind('connected', handleConnected);
            connection.unbind('state_change', handleStateChange);
            connection.unbind('error', handleError);

            nextEcho.disconnect();
            hasConnected.current = false;
            setEcho(currentEcho => currentEcho === nextEcho ? null : currentEcho);
            setConnectionState('disconnected');
        };
    }, [ appKey, queryClient, websocketConfig.isSuccess ]);

    return (
        <EchoContext.Provider value={{ echo, connectionState }}>
            {children}
        </EchoContext.Provider>
    );
}

export function useEcho() {
    return useContext(EchoContext).echo;
}

export function useEchoConnectionState() {
    return useContext(EchoContext).connectionState;
}
