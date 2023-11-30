<div class="text-center">
    <div class="bg-body-overlay-lighter border p-3 mb-2 rounded rounded-2 text-center">
        <h6 class="border-bottom fw-normal pb-1 mb-3 text-dark border-dark">
            Movement
        </h6>

        <div class="row">
            <div class="col-12 col-lg-6 d-flex flex-column align-items-center mb-2 mb-md-0">
                <div class="row">
                    <div class="col"></div>
                    <div class="col">
                        <div class="fs-5 fw-light mb-1"> Z </div>
                    </div>
                    <div class="col"></div>
                </div>
                <div class="row mb-2">
                    <div class="col"></div>
                    <div class="col">
                        <button wire:click="up" class="btn border" wire:loading.attr="disabled">
                            <div wire:loading wire:target="up">
                                <span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>
                                <span class="visually-hidden"> Loading... </span>
                            </div>

                            <div wire:loading.remove wire:target="up">
                                @svg('caret-up-fill')
                            </div>
                        </button>
                    </div>
                    <div class="col"></div>
                </div>
                <div class="row mb-2">
                    <div class="col position-relative p-0">
                        <div class="control-hint-snap-left position-absolute align-items-center d-flex h-100 start-0 fs-5 fw-light mb-1">
                            X
                        </div>
                        <button wire:click="left" class="btn border" wire:loading.attr="disabled">
                            <div wire:loading wire:target="left">
                                <span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>
                                <span class="visually-hidden"> Loading... </span>
                            </div>

                            <div wire:loading.remove wire:target="left">
                                @svg('caret-left-fill')
                            </div>
                        </button>
                    </div>
                    <div class="col">
                        <button wire:click="home" class="btn border" wire:loading.attr="disabled">
                            <div wire:loading wire:target="home">
                                <span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>
                                <span class="visually-hidden"> Loading... </span>
                            </div>

                            <div wire:loading.remove wire:target="home">
                                @svg('house-door-fill')
                            </div>
                        </button>
                    </div>
                    <div class="col p-0">
                        <button wire:click="right" class="btn border" wire:loading.attr="disabled">
                            <div wire:loading wire:target="right">
                                <span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>
                                <span class="visually-hidden"> Loading... </span>
                            </div>

                            <div wire:loading.remove wire:target="right">
                                @svg('caret-right-fill')
                            </div>
                        </button>
                    </div>
                </div>
                <div class="row mb-3">
                    <div class="col"></div>
                    <div class="col">
                        <button wire:click="down" class="btn border" wire:loading.attr="disabled">
                            <div wire:loading wire:target="down">
                                <span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>
                                <span class="visually-hidden"> Loading... </span>
                            </div>

                            <div wire:loading.remove wire:target="down">
                                @svg('caret-down-fill')
                            </div>
                        </button>
                    </div>
                    <div class="col"></div>
                </div>
            </div>

            <div class="col-12 col-lg-6 align-self-center mb-2 mb-md-0">
                <div class="row">
                    <div class="col">
                        <button wire:click="yForward" class="btn border" wire:loading.attr="disabled">
                            <div wire:loading wire:target="yForward">
                                <span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>
                                <span class="visually-hidden"> Loading... </span>
                            </div>

                            <div wire:loading.remove wire:target="yForward">
                                @svg('caret-up-fill')
                            </div>
                        </button>
                    </div>
                </div>
                <div class="row">
                    <div class="col">
                        <h5 class="fs-5 fw-light m-2"> Y </h5>
                    </div>
                </div>
                <div class="row">
                    <div class="col">
                        <button wire:click="yBackward" class="btn border" wire:loading.attr="disabled">
                            <div wire:loading wire:target="yBackward">
                                <span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>
                                <span class="visually-hidden"> Loading... </span>
                            </div>

                            <div wire:loading.remove wire:target="yBackward">
                                @svg('caret-down-fill')
                            </div>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <div class="row mt-3">
            <div class="col" x-data="{ feedrate: {{ Configuration::get('controlFeedrateDefault', env('PRINTER_CONTROL_FEEDRATE_DEFAULT')); }} }">
                <label class="form-label"> Feedrate </label>
                <input
                    wire:model.lazy="feedrate"
                    wire:loading.attr="disabled"
                    x-model="feedrate"
                    type="range"
                    class="form-range"
                    min="{{ Configuration::get('controlFeedrateMin', env('PRINTER_CONTROL_FEEDRATE_MIN')) }}"
                    max="{{ Configuration::get('controlFeedrateMax', env('PRINTER_CONTROL_FEEDRATE_MAX')) }}"
                >
                <span x-text="feedrate"></span>
            </div>

            <div class="col" x-data="{ distance: {{ Configuration::get('controlDistanceDefault', env('PRINTER_CONTROL_DISTANCE_DEFAULT')) }} }">
                <label class="form-label"> Distance (mm) </label>
                <input
                    wire:model.lazy="distance"
                    wire:loading.attr="disabled"
                    x-model="distance"
                    type="range"
                    class="form-range"
                    min="{{ Configuration::get('controlDistanceMin', env('PRINTER_CONTROL_DISTANCE_MIN')) }}"
                    max="{{ Configuration::get('controlDistanceMax', env('PRINTER_CONTROL_DISTANCE_MAX')) }}"
                >
                <span x-text="distance"></span>
            </div>
        </div>
    </div>

    <div class="bg-body-overlay-lighter border p-3 mb-2 rounded rounded-2 text-center">
        <h6 class="border-bottom fw-normal pb-1 mb-3 text-dark border-dark">
            Extrusion
        </h6>

        <label class="form-label"> Distance (mm) </label>

        <div
            x-data="{ distance: 0 }"
            class="btn-toolbar justify-content-center mb-3 d-flex flex-nowrap"
            role="toolbar"
            aria-label="Toolbar with button groups"
        >
            <div class="btn-group mx-1" role="group" aria-label="First group">
                <button wire:click="extrudeBack" type="button" class="btn btn-outline-secondary" wire:loading.attr="disabled">
                    <div wire:loading wire:target="extrudeBack">
                        <span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>
                        <span class="visually-hidden"> Loading... </span>
                    </div>

                    <div wire:loading.remove wire:target="extrudeBack">
                        <span>-</span><span x-text="distance"></span>
                    </div>
                </button>
            </div>

            <div class="input-group">
                <input
                    id="extrusionLength"
                    wire:model.lazy="extrusionLength"
                    x-model="distance"
                    type="number"
                    class="form-control col"
                    placeholder="Type a number"
                    aria-label="Manual extrusion settings"
                    min="0"
                >
            </div>

            <div class="btn-group mx-1" role="group" aria-label="First group">
                <button wire:click="extrudeForward" type="button" class="btn btn-outline-secondary" wire:loading.attr="disabled">
                    <div wire:loading wire:target="extrudeForward">
                        <span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>
                        <span class="visually-hidden"> Loading... </span>
                    </div>

                    <div wire:loading.remove wire:target="extrudeForward">
                        <span>+</span><span x-text="distance"></span>
                    </div>
                </button>
            </div>
        </div>

        @if ($extruderCount > 1)
            <div class="row justify-content-center">
                <div class="col-11 col-sm-8 col-lg-6">
                    <select wire:model="targetMovementExtruder" class="form-select" aria-label="Select a target extruder" wire:loading.attr="disabled">
                        @for ($index = 0; $index < $extruderCount; $index++)
                            <option
                                value="{{ $index }}"
                                @if ($index == $targetMovementExtruder)
                                    selected
                                @endif
                            >
                                Extruder #{{ $index + 1 }}
                            </option>
                        @endfor
                    </select>
                </div>
            </div>
        @endif
    </div>

    <div class="bg-body-overlay-lighter border p-3 rounded rounded-2 text-center">
        <h6 class="border-bottom fw-normal pb-1 mb-3 text-dark border-dark">
            Temperature
        </h6>

        <div class="row">
            <label class="form-label col"> Hotend (°C) </label>
            <label class="form-label col"> Bed (°C) </label>
        </div>

        <div class="row">
            <div class="col">
                <div class="btn-toolbar justify-content-center">
                    <div class="input-group col">
                        <input
                            id="hotendTemperature"
                            wire:model.lazy="hotendTemperature"
                            type="number"
                            class="form-control"
                            min="0"
                            wire:target="setHotendTemperature"
                            wire:loading.attr="disabled"
                        >
                    </div>
                    <div class="btn-group mx-1" role="group" aria-label="Hotend temperature">
                        <button wire:click="setHotendTemperature" type="button" class="btn btn-outline-secondary" wire:loading.attr="disabled">
                            <div wire:loading wire:target="setHotendTemperature">
                                <span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>
                                <span class="visually-hidden"> Loading... </span>
                            </div>

                            <div wire:loading.remove wire:target="setHotendTemperature">
                                @svg('check-lg')
                            </div>
                        </button>
                    </div>
                </div>
            </div>

            <div class="col">
                <div class="btn-toolbar justify-content-center">
                    <div class="input-group col">
                        <input
                            id="bedTemperature"
                            wire:model.lazy="bedTemperature"
                            type="number"
                            class="form-control"
                            min="0"
                            wire:target="setBedTemperature"
                            wire:loading.attr="disabled"
                        >
                    </div>
                    <div class="btn-group mx-1" role="group" aria-label="Hotend temperature">
                        <button wire:click="setBedTemperature" type="button" class="btn btn-outline-secondary" wire:loading.attr="disabled">
                            <div wire:loading wire:target="setBedTemperature">
                                <span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>
                                <span class="visually-hidden"> Loading... </span>
                            </div>

                            <div wire:loading.remove wire:target="setBedTemperature">
                                @svg('check-lg')
                            </div>
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

    window.addEventListener('configApplyError', event => {
        console.debug(event);

        toastify.error(event.detail);
    });

    const NUMERIC_INPUTS = [ 'extrusionLength', 'hotendTemperature', 'bedTemperature' ];

    NUMERIC_INPUTS.forEach(id => {
        input = document.querySelector('#extrusionLength');
        input.addEventListener('change', () => {
            if (input.value < 1) {
                input.value = 0;
            }

            input.dispatchEvent( new Event('input') );
        });
    });

});

</script>
@endpush