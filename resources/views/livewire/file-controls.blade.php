<div>
    <livewire:print-progress />

    <div class="d-flex flex-column flex-md-row justify-content-between p-0 p-lg-2 mt-3 mt-lg-1">
        <div class="btn-group" role="group" aria-label="Button group with nested dropdown">
            <button
                type="button"
                wire:click="pause"
                wire:loading.attr="disabled"
                wire:offline.attr="disabled"
                class="
                    btn btn-primary rounded-start
                    @if (!$activeFile || !$printer->isRunning())
                        d-none
                    @endif
                "
            >
                <div wire:loading wire:target="pause">
                    <span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>
                    <span class="visually-hidden"> Loading... </span>
                </div>

                <span wire:loading.remove wire:target="pause">
                    @svg('pause-fill')
                </span>
            </button>
            <button
                type="button"
                wire:click="resume"
                wire:loading.attr="disabled"
                wire:offline.attr="disabled"
                class="
                    btn btn-primary rounded-start
                    @if ($activeFile && !$printer->isRunning())
                        d-inline-block
                    @else
                        d-none
                    @endif
                "
            >
                <div wire:loading wire:target="resume">
                    <span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>
                    <span class="visually-hidden"> Loading... </span>
                </div>

                <div wire:loading.remove wire:target="resume" class="animate__animated animate__slow animate__infinite animate__flash">
                    @svg('play-fill')
                </div>
            </button>
            <button
                type="button"
                wire:loading.attr="disabled"
                wire:offline.attr="disabled"
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
                wire:loading.attr="disabled"
                wire:offline.attr="disabled"
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
                    <button
                        type="button"
                        class="btn btn-secondary"
                        data-bs-dismiss="modal"
                        wire:loading.attr="disabled"
                        wire:target="start"
                    > No </button>

                    <button type="button" class="btn btn-primary" wire:click="start" wire:offline.attr="disabled">
                        <div wire:loading wire:target="start">
                            <span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>
                            <span class="visually-hidden"> Loading... </span>
                        </div>

                        <span wire:loading.remove wire:target="start"> Yes </span>
                    </button>
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
                    <button
                        type="button"
                        class="btn btn-secondary"
                        data-bs-dismiss="modal"
                        wire:loading.attr="disabled"
                        wire:target="stop"
                    > No </button>

                    <button type="button" class="btn btn-primary" wire:click="stop" wire:offline.attr="disabled">
                        <div wire:loading wire:target="stop">
                            <span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>
                            <span class="visually-hidden"> Loading... </span>
                        </div>

                        <span wire:loading.remove wire:target="stop"> Yes </span>
                    </button>
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
                    <button
                        type="button"
                        class="btn btn-secondary"
                        data-bs-dismiss="modal"
                        wire:loading.attr="disabled"
                        wire:target="delete"
                    > No </button>

                    <button type="button" class="btn btn-primary" wire:click="delete" wire:offline.attr="disabled">
                        <div wire:loading wire:target="delete">
                            <span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>
                            <span class="visually-hidden"> Loading... </span>
                        </div>

                        <span wire:loading.remove wire:target="delete"> Yes </span>
                    </button>
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
                        <input
                            type="text"
                            class="form-control"
                            id="newFilename"
                            value="{{ $selected }}"
                            wire:model="newFilename"
                            wire:offline.attr="disabled"
                        >

                        @if ($error)
                            <p class="text-danger text-center mt-4"> {{ $error }} </p>
                        @endif
                    </div>
                </div>
                <div class="modal-footer">
                    <button
                        type="button"
                        class="btn btn-secondary"
                        data-bs-dismiss="modal"
                        wire:loading.attr="disabled"
                        wire:target="rename"
                    > Cancel </button>

                    <button type="button" class="btn btn-primary" wire:click="rename" wire:offline.attr="disabled">
                        <div wire:loading wire:target="rename">
                            <span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>
                            <span class="visually-hidden"> Loading... </span>
                        </div>

                        <span wire:loading.remove wire:target="rename"> Save </span>
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>

    window.actionToModalKey = action => {
        console.debug('actionToModalKey:', action);

        if (typeof(action) == 'undefined') {
            console.warn('actionToModalKey: called without an action.');

            return null;
        }

        return action.charAt(0).toUpperCase() + action.slice(1);
    };

    window.openModal = modalType => {
        console.debug('openModal: modalType:', modalType);

        let action = actionToModalKey(modalType);

        console.debug('openModal: action:', action);

        if (!action) {
            console.warn('openModal: action: no valid action was detected.');

            return;
        }

        let modalName = action + 'Modal';

        window[modalName] = new bootstrap.Modal(
            document.querySelector('#fileControls' + action + 'Modal')
        );

        window[modalName].show();
    };

    window.addEventListener('changeSaved', event => {
        console.log(event.detail);

        let action = actionToModalKey(event.detail.action);

        if (!action) {
            console.warn('openModal: action: no valid action was detected.');

            return;
        }

        window[ action + 'Modal' ].hide();
    });

</script>
@endpush