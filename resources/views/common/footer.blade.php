</main>

<footer></footer>

<livewire:updating-hardware-overlay />
<livewire:spectator-hints-overlay />

@livewireScriptConfig

<script>
    window.livewire_app_url = (new URL(window.location.href)).origin;

    const USER_ID             = @json( Auth::id() );
    const PHP_EOL             = @json( PHP_EOL );
    const TOAST_MESSAGE_TYPES = @json( ToastMessageType::asArray() );
    const THEME_OPTIONS       = @json( ThemeOption::asArray() );

    window.ECHO_OPTIONS = {
        broadcaster: 'pusher',
        key:        @json( env('PUSHER_APP_KEY') ),
        wssPort:    @json( env('EXTERNAL_WEB_SOCKET_PORT') ) ?? 6001,
        wsHost:     window.location.hostname,
        forceTLS:   false,
        cluster:    'mt1',
        enabledTransports: ['ws', 'wss'],
    };

    window.getSelectedPrinterId = () => document.querySelector('#printerSelect').value;

    window.HAPTICS_ENABLED = @json(
        Configuration::get('enableHaptics', env('HAPTICS_ENABLED', false))
    );

    window.hasTouchScreen = window.matchMedia('(pointer: coarse)').matches;

    window.prefersDarkTheme = () => (
        window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches
    );

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

    window.addEventListener('scroll', () => {
        if (
            (
                Math.ceil(window.innerHeight + window.scrollY)
                ==
                document.body.offsetHeight
            )
            ||
            window.scrollY == 0
        ) { vibrate(7.5); }
    });

    let lastTagName     = null;
    let lastMoveTarget  = null;

    window.addEventListener('touchmove', event => {
        lastMoveTarget = event.target;

        console.debug('THIS ELEMENT:', event.target.tagName);

        if ([ 'INPUT' ].includes(event.target.tagName)) {
            vibrate(1.5);
        }
    });

    window.addEventListener('touchend', event => {
        console.debug(
            'THIS TAG:', event.target.tagName,
            'LAST TAG:', lastTagName
        );

        if (lastTagName == 'SELECT') {
            vibrate([ 2, 5, 2 ]);
        } else if (
            event.target.tagName == 'INPUT'
            ||
            (
                [ 'BUTTON', 'A', 'SELECT' ].includes(event.target.tagName)
                &&
                lastMoveTarget != event.target
            )
        ) {
            if (
                event.target.tagName == 'A'
                &&
                event.target.classList.contains('dropdown-item')
            ) { return; }

            if (event.target.tagName == 'SELECT') {
                vibrate([ 2, 5, 2 ]);
            } else {
                vibrate(3);
            }
        }

        lastTagName = event.target.tagName;
    });

    window.addEventListener('show.bs.modal', event => {
        vibrate([ 4, 5, 2 ]);
    });

    window.addEventListener('hide.bs.modal', event => {
        vibrate([ 2, 0, 4 ]);
    });

    // const playSound = () => {
    //     console.log('ps');

    //     let audio = new Audio(@json( asset('resources/audio/job_completed.wav') ));

    //     audio.play();
    // };

    // document.querySelector('#playSoundBtn').addEventListener('click', playSound);

    window.addEventListener('beforeinstallprompt', (event) => {
        // Prevent the mini-infobar from appearing on mobile.
        event.preventDefault();
        
        console.log('👍', 'beforeinstallprompt', event);
        
        // Stash the event so it can be triggered later.
        window.deferredPrompt = event;
        
        // Remove the 'hidden' class from the install button container.
        divInstall.classList.toggle('hidden', false);
    });

    const initializeTooltips = () => {
        tooltipTriggerList = document.querySelectorAll('[data-bs-toggle="tooltip"]');
        tooltipList        = [ ...tooltipTriggerList ].map(tooltipTriggerEl => new bootstrap.Tooltip(tooltipTriggerEl));
    };

    const hideTooltips = () => {
        tooltipList.forEach(tooltip => { tooltip.hide() });
    };

    window.addEventListener('DOMContentLoaded', () => {
        initializeTooltips();

        window.addEventListener('hideTooltips', hideTooltips);

        window.Echo = new Echo(window.ECHO_OPTIONS);

        // Set base URL
        axios.post(
            window.livewire_app_url + '/base',
            { url: window.livewire_app_url }
        ).then(response => {
            console.debug('/base', response);
        });

        window.addEventListener('shown.bs.modal', initializeTooltips);

        Echo.channel('printers-map-updated')
            .listen('PrintersMapUpdated', event => {
                console.debug('PrintersMapUpdated', event);

                toastify.info('Hardware change applied.');

                Livewire.dispatch('hardwareChangeDetected');

                return true;
            });

        Echo.private(`system-message.${USER_ID}`)
            .listen('ToastMessage', event => {
                console.debug('ToastMessage', event);

                switch (event.type) {
                    case TOAST_MESSAGE_TYPES.ERROR:
                        toastify.error( event.message );
                    break;
                    case TOAST_MESSAGE_TYPES.INFO:
                        toastify.info( event.message );
                    break;
                    case TOAST_MESSAGE_TYPES.SUCCESS:
                        toastify.success( event.message );
                    break;
                }

                return true;
            });

        Echo.private(`system-message.${USER_ID}`)
            .listen('SystemMessage', event => {
                console.debug('SystemMessage', event);

                switch (event.name) {
                    case 'folderCreationCompleted':
                        Livewire.dispatch('refreshUploadedFiles');

                        break;
                    case 'recoveryStarted':
                        [ 'skipRecoveryBtn', 'recoverBtn' ].forEach(id => {
                            document.querySelector('#' + id).disabled = true;
                        });

                        break;
                    case 'recoveryAborted':
                        [ 'skipRecoveryBtn', 'recoverBtn' ].forEach(id => {
                            document.querySelector('#' + id).disabled = false;
                        });

                        break;
                    case 'recoveryCompleted':
                        Livewire.dispatch('recoveryCompleted', { newFileName: event.detail });

                        break;
                    case 'recordingDeleted':
                        Livewire.dispatch('refreshRecordings');

                        dispatchEvent( new Event('recordingDeleted') );

                        break;
                    case 'refreshActiveFile':
                        Livewire.dispatch('refreshActiveFile');

                        break;
                    case 'refreshUploadedFiles':
                        Livewire.dispatch('refreshUploadedFiles');

                        break;
                    case 'materialsChanged':
                        Livewire.dispatch('materialsChanged');

                        break;
                    case 'linkedCamerasChanged':
                        Livewire.dispatch('linkedCamerasChanged');

                        break;
                    case 'recorderToggled':
                        Livewire.dispatch('recorderToggled');

                        break;
                }

                return true;
            });
    });

    window.initialize = componentName => {
        console.debug('initialize:', componentName);

        Livewire.dispatchTo( componentName, 'initialize' );

        let event = new CustomEvent('tab-pane-changed');
            event.data = componentName;

        dispatchEvent(event);
    };
</script>

@stack('scripts')

</body>
</html>
