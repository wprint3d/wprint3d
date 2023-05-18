<div>
    <div wire:ignore id="createFolderModal" class="modal fade" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false" aria-modal="true" role="dialog">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"> Create folder </h5>
                </div>
                <div class="modal-body">
                    <p class="text-center">
                        Type the name of the folder to create, make sure not to include special symbols. Spaces <span class="fw-semibold">are</span> allowed.
                    </p>

                    <input
                        id="folderNameInput"
                        wire:model.lazy="name"
                        type="text"
                        class="form-control"
                        placeholder="Folder name"
                        wire:loading.attr="disabled"
                        wire:target="createFolder"
                    >
                </div>
                <div class="modal-footer">
                    <div>
                        <button
                            type="button"
                            class="btn btn-secondary"
                            data-bs-dismiss="modal"
                            wire:loading.attr="disabled"
                            wire:target="createFolder"
                        >
                            <div wire:loading>
                                <span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>
                                <span class="visually-hidden"> Loading... </span>
                            </div>

                            Cancel
                        </button>
                        <button
                            wire:click="createFolder"
                            id="createFolderBtn"
                            type="button"
                            class="btn btn-primary"
                            wire:loading.attr="disabled"
                        >
                            <div wire:loading>
                                <span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>
                                <span class="visually-hidden"> Loading... </span>
                            </div>

                            Create
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>

window.addEventListener('DOMContentLoaded', () => {
    let folderNameInput = document.querySelector('#folderNameInput'),
        respawnModal    = false;

    window.createFolderModal = new bootstrap.Modal(
        document.querySelector('#createFolderModal')
    );

    document.querySelector('#createFolderModal').addEventListener('hidden.bs.modal', () => {
        if (respawnModal) { createFolderModal.show(); }
    });

    document.querySelector('#createFolderModal').addEventListener('shown.bs.modal', () => {
        folderNameInput.value = '';

        respawnModal = false;
    });

    window.addEventListener('folderCreationError', event => {
        toastify.error('Folder creation failed: ' + event.detail);

        respawnModal = true;

        createFolderModal.hide();
    });

    window.addEventListener('folderCreationCompleted', event => {
        toastify.success('<b>' + event.detail + '</b> was successfully created!');

        respawnModal = false;

        createFolderModal.hide();
    });
});

</script>
@endpush