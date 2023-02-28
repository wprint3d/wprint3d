<div>
    <form wire:submit.prevent="save" {{ $uploaded ? 'wire:poll.' . config('file-uploader.success-retention-secs') . 's' : '' }}>
        <label
            for="gcode"
            class="
                btn
                {{ $uploaded ? 'btn-success' : 'btn-primary' }}
            "
            wire:loading.class="disabled"
        >
            <input type="file" wire:model="gcode" id="gcode" wire:loading.attr="disabled">

            <div wire:loading>
                <span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>
                <span class="visually-hidden"> Loading... </span>
            </div>

            {{  $uploaded ? 'Uploaded!' : 'Upload G-code' }}
        </label>

        {{-- @error('gcode') <span class="error text-danger">{{ $message }}</span> @enderror --}}
    </form>
</div>
