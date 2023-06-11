</main>

<footer></footer>

<livewire:updating-hardware-overlay />

@livewireScripts

<script>
    const USER_ID             = @json( Auth::id() );
    const SESSION_ID          = @json( session()->getId() );
    const PHP_EOL             = @json( PHP_EOL );
    const TOAST_MESSAGE_TYPES = @json( ToastMessageType::asArray() );

    window.getSelectedPrinterId = () => document.querySelector('#printerSelect').value;

    window.HAPTICS_ENABLED = @json(
        Configuration::get('enableHaptics', env('HAPTICS_ENABLED', false))
    );

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

    initializeTooltips();

    window.addEventListener('DOMContentLoaded', () => {
        window.addEventListener('shown.bs.modal', initializeTooltips);

        Echo.channel('printers-map-updated')
            .listen('PrintersMapUpdated', event => {
                console.debug('PrintersMapUpdated', event);

                toastify.info('Hardware change applied.');

                Livewire.emit('hardwareChangeDetected');

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
                        Livewire.emit('refreshUploadedFiles');

                        break;
                    case 'targetTemperatureReset':
                        dispatchEvent( new Event('targetTemperatureReset') );

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
                        Livewire.emit('recoveryCompleted', event.detail);

                        break;
                    case 'recordingDeleted':
                        Livewire.emit('refreshRecordings');

                        dispatchEvent( new Event('recordingDeleted') );

                        break;
                    case 'refreshActiveFile':
                        Livewire.emit('refreshActiveFile');

                        break;
                    case 'refreshUploadedFiles':
                        Livewire.emit('refreshUploadedFiles');

                        break;
                    case 'materialsChanged':
                        Livewire.emit('materialsChanged');

                        break;
                    case 'linkedCamerasChanged':
                        Livewire.emit('linkedCamerasChanged');

                        break;
                    case 'recorderToggled':
                        Livewire.emit('recorderToggled');

                        break;
                }

                return true;
            });
    });
</script>

@stack('scripts')

</body>
</html>
