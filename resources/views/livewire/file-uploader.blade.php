<div>
    <form wire:submit="save">
        <label id="uploadBtn" for="gcode" class="btn btn-primary w-100 mt-1 mt-md-0" wire:loading.class="disabled" wire:offline.class="disabled">
            <input type="file" wire:model.live="gcode" id="gcode" wire:loading.attr="disabled" wire:offline.attr="disabled">

            <div wire:loading>
                <span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>
                <span class="visually-hidden"> Loading... </span>

                Uploading
            </div>

            <div wire:loading.remove>
                <div class="d-inline d-sm-none"> @svg('cloud-arrow-up') </div>
                Upload G-code
            </div>
        </label>

        {{-- @error('gcode') <span class="error text-danger">{{ $message }}</span> @enderror --}}
    </form>
</div>

@push('scripts')
<script>

window.addEventListener('fileUploadFinished', () => {
    toastify.success('Upload completed!');
});

</script>
@endpush