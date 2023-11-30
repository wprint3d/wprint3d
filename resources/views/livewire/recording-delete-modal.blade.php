
<div wire:ignore.self class="modal fade" id="recordingDeleteModal" tabindex="-1" aria-labelledby="recordingDeleteModal" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h1 class="modal-title fs-5" id="recordingDeleteModalLabel"> Delete recording </h1>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                Do you really want to permanently delete <b>{{
                    $selected !== null && !empty( trim($name) )
                        ? $name
                        : '' 
                }}</b>?

                @if ($error)
                    <p class="text-error text-danger text-center mt-4">{{ $error }}</p>
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
                    wire:loading.attr="disabled"
                    wire:target="delete"
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

});

</script>
@endpush
