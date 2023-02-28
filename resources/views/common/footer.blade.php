</main>

<footer></footer>

@livewireScripts

<script>
    window.getSelectedPrinterId = () => document.querySelector('#printerSelect').value;

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
</script>

@stack('scripts')

</body>
</html>
