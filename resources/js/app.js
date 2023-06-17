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

window.toastify.toast = (bgClass, text, duration = null, title = null, onClick = () => {}) => {
    let body = text;

    if (title && title.length > 0) {
        body = (
            '<b>'  + title +  '</b> <br>' +
            '<br>' +
            text
        );
    }

    let toast = new toastify({
        text:       body,
        className:  bgClass,
        style:      { background: 'none' },
        duration:   duration ?? window.toastify.defaults.duration
    }).showToast();

    toast.options.onClick = () => {
        toast.hideToast();

        onClick();
    };
};

window.toastify.error = (text, duration = null, title = null, onClick = () => {}) => {
    window.toastify.toast('bg-danger', text, duration, title, onClick);
};

window.toastify.info = (text, duration = null, title = null, onClick = () => {}) => {
    window.toastify.toast('bg-info', text, duration, title, onClick);
};

window.toastify.success = (text, duration = null, title = null, onClick = () => {}) => {
    window.toastify.toast('bg-success', text, duration, title, onClick);
};

/**
 * Echo exposes an expressive API for subscribing to channels and listening
 * for events that are broadcast by Laravel. Echo and event broadcasting
 * allows your team to easily build robust real-time web applications.
 */

import Echo from 'laravel-echo';
window.Echo = Echo;

import Pusher from 'pusher-js';
window.Pusher = Pusher;

import * as GCodePreview from 'gcode-preview';
window.GCodePreview = GCodePreview;

import UAParser from 'ua-parser-js';
window.UAParser = new UAParser( window.navigator.userAgent );

window.hasTouchScreen = window.matchMedia('(pointer: coarse)').matches;

window.vibrate = input => {
    if (HAPTICS_ENABLED) {
        if (
            typeof(window.navigator)         !== 'undefined'
            &&
            typeof(window.navigator.vibrate) !== 'undefined'
        ) {
            return window.navigator.vibrate(input);
        }
    }

    return false;
}