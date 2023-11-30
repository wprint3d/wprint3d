<div>
    <div>
        <div id="renderProgress" class="progress m-2 d-none bg-black bg-opacity-25" role="progressbar" aria-label="Animated striped example" aria-valuenow="75" aria-valuemin="0" aria-valuemax="100">
            <div class="progress-bar progress-bar-striped progress-bar-animated overflow-visible"></div>
        </div>

        <div wire:loading.flex class="justify-content-center pt-2">
            <div class="d-flex flex-column align-items-center">
                <div class="spinner-border" role="status">
                    <span class="visually-hidden">Loading...</span>
                </div>
                <p class="pt-3"> Loading recordings... </p>
            </div>
        </div>

        <div wire:loading.remove>
            @if (filled( $recordings ))
                @foreach ($recordings as $recording)
                    <div class="col">
                        <div class="card m-2 shadow-sm">
                            @livewire('recorded-video', [
                                'index'     => $loop->index,
                                'recording' => $recording,
                                'writeable' => $writeable
                            ], key( $recording['name'] ))
                        </div>
                    </div>
                @endforeach
            @else
                <div class="d-flex align-items-center">
                    <div class="d-flex flex-column flex-fill align-items-center mt-3">
                        @svg('camera-video-off-fill', [ 'class' => 'fs-2' ])

                        <p class="text-center mt-3">
                            @if (Auth::user()->settings['recording']['enabled'])
                                Nothing here so far, select a file and get going!
                            @else
                                Recording is disabled. <br>
                                <br>
                                To start recording, enable the feature from <b>Settings</b> > <b>Recording</b>.
                            @endif
                        </p>
                    </div>
                </div>
            @endif
        </div>
    </div>

    @livewire('recording-delete-modal')
</div>

@push('scripts')
<script>

window.addEventListener('DOMContentLoaded', () => {

    window.setRenderProgress = (progress, fileName = null) => {
        let renderProgress = document.querySelector('#renderProgress'),    
            barSection     = renderProgress.querySelector('.progress-bar');

        if (progress) {
            renderProgress.classList.remove('d-none');
        } else {
            renderProgress.classList.add('d-none');

            progress = 0;
        }

        renderProgress.setAttribute('aria-valuenow', progress);

        barSection.style.width = progress + '%';
        barSection.innerText = (
            progress > 0
                ? `Rendering "${fileName}" (${progress}% completed)`
                : ''
        );
    }

    Echo.private(`job-progress.${getSelectedPrinterId()}`)
        .listen('RecordingRenderProgress', event => {
            console.debug('RecordingRenderProgress:', event);

            setRenderProgress(event.progress, event.fileName);

            return true;
        });

    Echo.private(`finished-job.${getSelectedPrinterId()}`)
        .listen('RecordingRenderFinished', event => {
            console.debug('RecordingRenderFinished:', event);

            Livewire.emit('refreshRecordings');

            setRenderProgress(null);

            return true;
        });

});

</script>
@endpush
