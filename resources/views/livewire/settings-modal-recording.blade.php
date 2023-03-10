<div>
    <form class="container-lg mx-auto row g-3 text-center">
        <div class="col-12">
            <div class="form-check form-switch d-flex justify-content-center">
                <input wire:model="enabled" class="form-check-input mx-2" type="checkbox" role="switch" @if ($enabled) checked @endif>
                <label class="form-check-label"> Enable recording </label>
            </div>
        </div>
        <div class="col-12">
            <p class="m-0">
                If enabled, the recording feature will automatically create videos out of your ongoing prints and save them in real time.
            </p>
        </div>
        <div class="col-12 col-md-6">
            <label class="form-label"> Resolution </label>
            <select wire:model="resolution" class="form-select">
                @foreach ($resolutions as $value)
                    <option value="{{ $value }}" @if ($value == $resolution) selected @endif>
                        {{ $value }}
                    </option>
                @endforeach
            </select>
        </div>
        <div class="col-12 col-md-6">
            <label class="form-label"> Framerate </label>
            <select wire:model="framerate" class="form-select">
                @foreach ($framerates as $value)
                    <option value="{{ $value }}" @if ($value == $framerate) selected @endif>
                        {{ $value }}
                    </option>
                @endforeach
            </select>
        </div>
    </form>
</div>