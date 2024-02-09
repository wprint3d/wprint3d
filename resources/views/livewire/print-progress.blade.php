<div>
    <div id="printProgressBar" class="progress mt-3 mb-1 mx-2 @if (!$printing) d-none @endif" role="progressbar" aria-label="Animated striped example" aria-valuenow="{{ $progress }}" aria-valuemin="0" aria-valuemax="100">
        <div
            class="progress-bar progress-bar-striped progress-bar-animated"
            style="width: {{ $progress }}%"
        ></div>
    </div>
    <div id="printProgressLastCommand" class="text-center @if (!$lastCommand) d-none @endif">
        <span class="command">{{ $lastCommand }}</span> <br>
        <span class="fw-light">
            <span class="remaining-time">0 seconds</span> remaining
        </span>
    </div>
</div>

@push('scripts')
<script>

window.addEventListener('DOMContentLoaded', () => {
    const progressBar   = document.querySelector('#printProgressBar');
    const lastCommand   = document.querySelector('#printProgressLastCommand').querySelector('.command');
    const remainingTime = document.querySelector('#printProgressLastCommand').querySelector('.remaining-time');

    let noMaxLineHits = 0;

    const NO_MAX_LINE_HITS_LIMIT = 3;

    Echo.private(`console.${getSelectedPrinterId()}`)
        .listen('PrinterTerminalUpdated', event => {
            console.debug('PrintProgress:', event);

            if (event.maxLine) {
                if (lastCommand.parentElement.classList.contains('d-none')) {
                    lastCommand.parentElement.classList.remove('d-none');
                }

                let progress = (
                    ((event.line > 0 ? event.line : 1) * 100)
                    /
                    event.maxLine
                );

                if (event.stopTimestampSecs !== null) {
                    let difference = datetimeDifference(
                        new Date(),
                        new Date(event.stopTimestampSecs * 1000)
                    );

                    if (difference.seconds <= 0) {
                        result = 'a few seconds';
                    } else {
                        let keys            = Object.keys(difference),
                            firstValidIndex = 0,
                            result          = '';

                        for (let index = 0; index < keys.length; index++) {
                            if (difference[ keys[index] ] > 0) {
                                firstValidIndex = index;

                                break;
                            }
                        }

                        for (let index = firstValidIndex; index < keys.length; index++) {
                            if (keys[index] == 'milliseconds') {
                                continue;
                            }

                            let keyLabel = keys[index];

                            if (difference[ keys[index] ] == 1) {
                                keyLabel = keys[index].substr(0, keys[index].length - 1);
                            }

                            result += `${difference[ keys[index] ]} ${keyLabel}`;

                            if (keys.length > 1) {
                                if (keys[index].indexOf('minute') > -1) {
                                    result += ' and ';
                                } else if (index < keys.length - 2) {
                                    result += ', ';
                                }
                            }
                        }
                    }

                    remainingTime.innerText = result;
                }

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

                Livewire.dispatch('refreshActiveFile');

                lastCommand.parentElement.classList.add('d-none');
            }

            return true;
        });

    Echo.private(`finished-job.${getSelectedPrinterId()}`)
        .listen('PrintJobFinished', event => {
            console.debug('PrintJobFinished:', event);

            Livewire.dispatch('refreshActiveFile');

            return true;
        });
});

</script>
@endpush