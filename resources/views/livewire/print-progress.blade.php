<div>
    <div id="printProgressBar" class="progress mt-3 mb-1 mx-2 @if (!$printing) d-none @endif" role="progressbar" aria-label="Animated striped example" aria-valuenow="{{ $progress }}" aria-valuemin="0" aria-valuemax="100">
        <div
            class="progress-bar progress-bar-striped progress-bar-animated"
            style="width: {{ $progress }}%"
        ></div>
    </div>
    <div id="printProgressLastCommand" class="text-center @if (!$lastCommand) d-none @endif"> {{ $lastCommand }} </div>
</div>

@push('scripts')
<script>

window.addEventListener('DOMContentLoaded', () => {
    const progressBar = document.querySelector('#printProgressBar');
    const lastCommand = document.querySelector('#printProgressLastCommand');

    let noMaxLineHits = 0;

    const NO_MAX_LINE_HITS_LIMIT = 3;

    Echo.private(`console.${getSelectedPrinterId()}`)
        .listen('PrinterTerminalUpdated', event => {
            console.debug('PrintProgress:', event);

            if (event.maxLine) {
                let progress = (
                    ((event.line > 0 ? event.line : 1) * 100)
                    /
                    event.maxLine
                );

                if (progressBar.classList.contains('d-none')) {
                    progressBar.classList.remove('d-none');
                }

                progressBar.querySelector('.progress-bar').style.width = progress + '%';

                if (event.meaning) {
                    if (lastCommand.classList.contains('d-none')) {
                        lastCommand.classList.remove('d-none');
                    }

                    lastCommand.innerText = event.meaning;
                }

                noMaxLineHits = 0;
            } else if (noMaxLineHits < NO_MAX_LINE_HITS_LIMIT) {
                noMaxLineHits++;
            } else if (
                !progressBar.classList.contains('d-none')
                ||
                !lastCommand.classList.contains('d-none')
            ) {
                progressBar.classList.add('d-none');
                lastCommand.classList.add('d-none');

                Livewire.emit('refreshActiveFile');
            }
        });

    Echo.private(`finished-job.${getSelectedPrinterId()}`)
        .listen('PrintJobFinished', event => {
            console.debug('PrintJobFinished:', event);

            Livewire.emit('refreshActiveFile');
        });
});

</script>
@endpush