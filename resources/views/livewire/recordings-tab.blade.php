<div>
    <div>
        <div id="renderProgress" class="progress m-2 d-none" role="progressbar" aria-label="Animated striped example" aria-valuenow="75" aria-valuemin="0" aria-valuemax="100">
            <div class="progress-bar progress-bar-striped progress-bar-animated"></div>
        </div>

        @if (filled ($recordings))
            @foreach ($recordings as $recording)
                <div class="col">
                    <div class="card m-2 shadow-sm">
                        <div class="row g-0">
                            <div class="col-md-4 position-relative mh-6em">
                                <img
                                    src="{{ $recording['thumb'] }}"
                                    class="w-100 h-100 object-fit-cover"
                                    aria-label="Thumbnail of {{ $recording['name'] }}"
                                    onerror="handleMissingImage(this)"
                                >
                                <div wire:click="play({{ $loop->index }})" role="button" class="position-absolute bottom-0 start-0 w-100 h-100 fs-5">
                                    @svg('play-circle', [ 'class' => 'position-absolute bg-white bottom-0 m-2 opacity-75 rounded rounded-4 border border-2' ])
                                </div>
                                <title>{{ $recording['name'] }}</title>
                            </div>
                            <div class="col-md-8">
                                <div class="card-body">
                                    <h5 class="card-title overflow-scroll text-nowrap no-scrollbar">
                                        {{ $recording['name'] }}
                                    </h5>
                                    <p class="card-text">
                                        {{ $recording['size'] }}
                                    </p>
                                    <p class="card-text d-flex justify-content-between">
                                        <small class="text-muted align-self-center">
                                            Saved {{ $recording['modified'] }}
                                        </small>
                                        <button class="btn btn-danger" wire:click="prepareDelete({{ $loop->index }})">
                                            @svg('trash')
                                        </button>
                                    </p>
                                </div>
                            </div>
                        </div>
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

    <div wire:ignore.self class="modal fade" id="recordingDeleteModal" tabindex="-1" aria-labelledby="recordingDeleteModal" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h1 class="modal-title fs-5" id="recordingDeleteModalLabel"> Delete recording </h1>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    Do you really want to permanently delete <b>{{
                        $selected !== null && isset($recordings[ $selected ])
                            ? $recordings[ $selected ]['name']
                            : '' 
                    }}</b>?

                    @if ($error)
                        <p class="text-error text-danger text-center mt-4"></p>
                    @endif
                </div>

                <div class="modal-footer">
                    <button
                        type="button"
                        class="btn btn-secondary"
                        data-bs-dismiss="modal"
                        wire:loading.attr="disabled"
                        wire:target="delete"
                    >
                        <div wire:loading>
                            <span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>
                            <span class="visually-hidden"> Loading... </span>
                        </div>
                        No
                    </button>
                    <button
                        type="button"
                        class="btn btn-primary"
                        wire:click="delete"
                        wire:loading.attr="delete"
                    >
                        <div wire:loading>
                            <span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>
                            <span class="visually-hidden"> Loading... </span>
                        </div>
                        Yes
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>

window.addEventListener('DOMContentLoaded', () => {

    let recordingDeleteModal = new bootstrap.Modal(
        document.querySelector('#recordingDeleteModal')
    );

    window.addEventListener('showRecordingDeleteModal', event => {
        recordingDeleteModal.show();
    });

    window.addEventListener('recordingDeleted', event => {
        recordingDeleteModal.hide();
    });

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
