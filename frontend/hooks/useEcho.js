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

    console.debug('websocketConfig:', websocketConfig);

    useEffect(() => {
        if (!websocketConfig.isSuccess) { return; }

        setEcho(
            new Echo({
                authEndpoint:   '/backend/broadcasting/auth',
                broadcaster:    'pusher',
                Pusher,
                key:            websocketConfig.data.data.appKey,
                wssPort:        websocketConfig.data.data.port ?? 6001,
                wsHost:         window.location.hostname,
                forceTLS:       false,
                cluster:        'mt1',
                enabledTransports: ['ws', 'wss']
            })
        );
    }, [ websocketConfig.isFetched ]);

    return echo;
}