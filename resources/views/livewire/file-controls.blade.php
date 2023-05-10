<div>
    <livewire:print-progress />

    <div class="d-flex justify-content-between p-2">
        <div class="btn-group" role="group" aria-label="Button group with nested dropdown">
            <button
                type="button"
                wire:click="pause"
                class="
                    btn btn-primary rounded-start
                    @if (!$activeFile || !$printer->isRunning())
                        d-none
                    @endif
                "
            >
                @svg('play-fill')
            </button>
            <button
                type="button"
                wire:click="resume"
                class="
                    btn btn-primary rounded-start
                    @if ($activeFile && !$printer->isRunning())
                        d-inline-block
                    @else
                        d-none
                    @endif
                "
            >
                <div class="animate__animated animate__slow animate__infinite animate__flash">
                    @svg('play-fill')
                </div>
            </button>
            <button
                type="button"
                class="
                    btn btn-primary rounded-start
                    @if ($activeFile)
                        d-none
                    @endif
                "
                onclick="openModal('start')"
                @if (!$printer || !$selected || $activeFile)
                    disabled
                @endif
            >
                @svg('play-fill')
            </button>
            <button
                type="button"
                class="btn btn-primary"
                onclick="openModal('stop')"
                @if (!$printer || !$activeFile)
                    disabled
                @endif
            >
                @svg('stop-fill')
            </button>

            <div class="btn-group" role="group">
                <button type="button" class="btn btn-primary dropdown-toggle {{ $selected ? '' : 'disabled' }}" data-bs-toggle="dropdown" aria-expanded="false">
                    Options
                </button>
                <ul class="dropdown-menu">
                    <li>
                        <a class="dropdown-item" href="#" onclick="openModal('delete')"> @svg('trash-fill') Remove </a>
                    </li>
                    <li>
                        <a class="dropdown-item" href="#" onclick="openModal('rename')"> @svg('pencil-square') Rename </a>
                    </li>
                </ul>
            </div>
        </div>

        <livewire:file-uploader />
    </div>

    <div
        class="modal fade"
        id="fileControlsStartModal"
        tabindex="-1"
        aria-labelledby="fileControlsStartModal"
        aria-hidden="true"
        wire:ignore.self
    >
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h1 class="modal-title fs-5" id="fileControlsStartModalLabel"> Print file </h1>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    Do you really want to start printing <b>{{ basename($selected) }}</b>?

                    @if ($error)
                        <p class="text-danger text-center mt-4"> {{ $error }} </p>
                    @endif
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"> No </button>
                    <button type="button" class="btn btn-primary" wire:click="start"> Yes </button>
                </div>
            </div>
        </div>
    </div>

    <div
        class="modal fade"
        id="fileControlsStopModal"
        tabindex="-1"
        aria-labelledby="fileControlsStopModal"
        aria-hidden="true"
        wire:ignore.self
    >
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h1 class="modal-title fs-5" id="fileControlsStopModalLabel"> Cancel print </h1>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    Do you really want to stop printing <b>{{ basename($printer->activeFile ?? '') }}</b>?

                    @if ($error)
                        <p class="text-danger text-center mt-4"> {{ $error }} </p>
                    @endif
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"> No </button>
                    <button type="button" class="btn btn-primary" wire:click="stop"> Yes </button>
                </div>
            </div>
        </div>
    </div>

    <div
        class="modal fade"
        id="fileControlsDeleteModal"
        tabindex="-1"
        aria-labelledby="fileControlsDeleteModal"
        aria-hidden="true"
        wire:ignore.self
    >
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h1 class="modal-title fs-5" id="fileControlsDeleteModalLabel"> Delete file </h1>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    Do you really want to permanently delete <b>{{ basename($selected) }}</b>?

                    @if ($error)
                        <p class="text-danger text-center mt-4"> {{ $error }} </p>
                    @endif
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"> No </button>
                    <button type="button" class="btn btn-primary" wire:click="delete"> Yes </button>
                </div>
            </div>
        </div>
    </div>

    <div
        class="modal fade"
        id="fileControlsRenameModal"
        tabindex="-1"
        aria-labelledby="fileControlsRenameModal"
        aria-hidden="true"
        wire:ignore.self
    >
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h1 class="modal-title fs-5" id="fileControlsRenameModalLabel"> Rename file </h1>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="newFilename" class="col-form-label">Rename <b>{{ basename($selected) }}</b> to:</label>
                        <input type="text" class="form-control" id="newFilename" value="{{ $selected }}" wire:model.defer="newFilename">

                        @if ($error)
                            <p class="text-danger text-center mt-4"> {{ $error }} </p>
                        @endif
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"> Cancel </button>
                    <button type="button" class="btn btn-primary" wire:click="rename"> Save </button>
                </div>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>

    window.actionToModalKey = action => action.charAt(0).toUpperCase() + action.slice(1);

    window.openModal = modalType => {
        console.log('openModal', modalType);

        let action = actionToModalKey(modalType);

        console.log(action);

        let modalName = action + 'Modal';

        window[modalName] = new bootstrap.Modal(
                document.querySelector('#fileControls' + action + 'Modal')
        );

        window[modalName].show();
    };

    window.addEventListener('changeSaved', event => {
        console.log(event.detail);

        window[ actionToModalKey(event.detail.action) + 'Modal' ].hide();
    });

</script>
@endpush