<div class="card col-12 col-md-6 col-lg-3 mt-2 @if (!$enabled) opacity-50 @endif">
    <img src="images/camera.jpg" class="card-img-top mt-3" alt="{{ $camera->label  }}">
    <div class="card-body">
        <h5 class="card-title"> {{ $camera->label }} at node {{ $camera->node }} </h5>

        <div class="form-check form-switch">
            <input wire:model="enabled" class="form-check-input" type="checkbox" role="switch" @if ($enabled) checked
                @endif>
            <label class="form-check-label"> Use this camera </label>
        </div>

        <div class="mt-2">
            <label class="form-label"> Format </label>
            <select wire:model="format" class="form-select" @if (!$enabled) disabled @endif>
                @foreach ($camera->availableFormats as $availableFormat)
                <option @if ($availableFormat == $format) selected @endif>
                    {{ $availableFormat }}
                </option>
                @endforeach
            </select>
        </div>

        <button class="btn btn-primary mt-3 mx-auto d-block" onclick="window.open('{{ $url }}')" @if (!$enabled) disabled @endif>
            @svg('eye-open') Preview
        </button>
    </div>
</div>