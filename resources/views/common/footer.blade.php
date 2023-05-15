</main>

<footer></footer>

<livewire:updating-hardware-overlay />

@livewireScripts

<script>
    const PHP_EOL = @json(PHP_EOL);

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
            });
    });
</script>

@stack('scripts')

</body>
</html>
