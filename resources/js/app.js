import * as bootstrap from 'bootstrap'

window.bootstrap = bootstrap;

import axios from 'axios';
window.axios = axios;
window.axios.defaults.baseURL = '/api';

import SwipeListener from 'swipe-listener';
window.SwipeListener = SwipeListener;

import Alpine from 'alpinejs';
window.Alpine = Alpine;

Alpine.start();

import toastify from 'toastify-js';
window.toastify = toastify;
window.toastify.defaults.duration       = 5000;
window.toastify.defaults.stopOnFocus    = true;
window.toastify.defaults.escapeMarkup   = false;
window.toastify.defaults.oldestFirst    = false;

window.toastify.toast = (bgClass, text, duration = null, title = null) => {
    let body = text;

    if (title && title.length > 0) {
        body = (
            '<b>'  + title +  '</b> <br>' +
            '<br>' +
            text
        );
    }

    let toast = new toastify({
        text: body,
        className: bgClass,
        style: { background: 'none' },
        duration: duration ?? window.toastify.defaults.duration
    }).showToast();

    toast.options.onClick = () => { toast.hideToast(); };
};

window.toastify.error = (text, duration = null, title = null) => {
    window.toastify.toast('bg-danger', text, duration, title);
};

window.toastify.info = (text, duration = null, title = null) => {
    window.toastify.toast('bg-info', text, duration, title);
};

window.toastify.success = (text, duration = null, title = null) => {
    window.toastify.toast('bg-success', text, duration, title);
};

/**
 * Echo exposes an expressive API for subscribing to channels and listening
 * for events that are broadcast by Laravel. Echo and event broadcasting
 * allows your team to easily build robust real-time web applications.
 */

import Echo from 'laravel-echo';

import Pusher from 'pusher-js';
window.Pusher = Pusher;

const ECHO_OPTIONS = {
    broadcaster: 'pusher',
    key:        import.meta.env.VITE_PUSHER_APP_KEY,
    wsHost:     window.location.hostname,
    wsPort:     import.meta.env.VITE_PUSHER_PORT,
    wssPort:    import.meta.env.VITE_PUSHER_PORT,
    forceTLS:   import.meta.env.VITE_PUSHER_SCHEME === 'https',
    cluster:    import.meta.env.VITE_PUSHER_APP_CLUSTER,
    enabledTransports: ['ws', 'wss'],
};

console.debug(ECHO_OPTIONS);

window.Echo = new Echo(ECHO_OPTIONS);

import * as GCodePreview from 'gcode-preview';
window.GCodePreview = GCodePreview;

import UAParser from 'ua-parser-js';
window.UAParser = new UAParser( window.navigator.userAgent );

window.hasTouchScreen = window.matchMedia('(pointer: coarse)').matches;