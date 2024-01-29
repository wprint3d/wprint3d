<div>
    <form class="container-lg mx-auto row g-3 text-center">
        <div class="col-12">
            <div class="form-check form-switch d-flex justify-content-center">
                <input
                    wire:model.live="enabled"
                    class="form-check-input mx-2"
                    type="checkbox"
                    role="switch"
                    wire:offline.attr="disabled"
                    @if ($enabled)      checked  @endif
                    @if (!$writeable)   disabled @endif
                >
                <label class="form-check-label"> Enable recording </label>
            </div>
        </div>
        <div class="col-12">
            <p class="m-0">
                If enabled, the recording feature will automatically create videos out of your ongoing prints and save them in real time.
            </p>
        </div>
        <div class="col-12 col-md-4">
            <label class="form-label"> Resolution </label>
            <select
                wire:model.live="resolution"
                class="form-select"
                wire:offline.attr="disabled"
                @if (!$writeable) disabled @endif
            >
                @foreach ($resolutions as $value)
                    <option value="{{ $value }}" @if ($value == $resolution) selected @endif>
                        {{ $value }}
                    </option>
                @endforeach
            </select>
        </div>
        <div class="col-12 col-md-4">
            <label class="form-label"> Framerate </label>
            <select
                wire:model.live="framerate"
                class="form-select"
                wire:offline.attr="disabled"
                @if (!$writeable) disabled @endif
            >
                @foreach ($framerates as $value)
                    <option value="{{ $value }}" @if ($value == $framerate) selected @endif>
                        {{ $value }}
                    </option>
                @endforeach
            </select>
        </div>
        <div class="col-12 col-md-4">
            <label class="form-label"> Capture interval </label>
            <input
                wire:model="captureInterval"
                type="number"
                class="form-control"
                placeholder="0.25"
                min="0.25"
                step="0.25"
                wire:offline.attr="disabled"
                @if (!$writeable) disabled @endif
            >
        </div>
    </form>
</div>