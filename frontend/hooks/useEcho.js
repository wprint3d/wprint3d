import Pusher from 'pusher-js';
import Echo from 'laravel-echo';

import { useQuery } from '@tanstack/react-query';

import { useEffect, useState } from 'react';

import API from '../includes/API';

export function useEcho() {
    const [ echo, setEcho ] = useState(null);

    const websocketConfig = useQuery({
        queryKey: ['websocketConfig'],
        queryFn:  () => API.get('/ws/config')
    });

    useEffect(() => {
        console.debug('websocketConfig:', websocketConfig);

        if (!websocketConfig.isSuccess) { return; }

        const appKey = websocketConfig.data?.data?.appKey;

        if (typeof appKey !== 'string' || appKey.trim() === '') {
            console.warn('useEcho: websocket config is missing a valid appKey', {
                status:      websocketConfig.data?.status,
                contentType: websocketConfig.data?.headers?.['content-type'] ?? null,
            });

            setEcho(null);

            return;
        }

        const nextEcho = new Echo({
            authEndpoint:   '/backend/broadcasting/auth',
            broadcaster:    'pusher',
            Pusher,
            key:            appKey,
            wssPort:        websocketConfig.data?.data?.port ?? 6001,
            wsHost:         window.location.hostname,
            forceTLS:       false,
            cluster:        'mt1',
            enabledTransports: ['ws', 'wss']
        });

        setEcho(nextEcho);

        return () => {
            nextEcho.disconnect();
            setEcho(currentEcho => currentEcho === nextEcho ? null : currentEcho);
        };
    }, [ websocketConfig.isSuccess, websocketConfig.data ]);

    return echo;
}
