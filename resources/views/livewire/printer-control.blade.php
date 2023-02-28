<div class="text-center">
    <div class="bg-body-overlay-lighter border p-3 mb-2 rounded rounded-2 text-center">
        <h6 class="border-bottom fw-normal pb-1 mb-3 text-dark border-dark">
            Movement
        </h6>

        <div class="row">
            <div class="col col-md-12 col-lg-6 d-flex flex-column align-items-center">
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
                        <button wire:click="up"     class="btn border"> @svg('arrow-up') </button>
                    </div>
                    <div class="col"></div>
                </div>
                <div class="row mb-2">
                    <div class="col position-relative p-0">
                        <div class="control-hint-snap-left position-absolute align-items-center d-flex h-100 start-0 fs-5 fw-light mb-1">
                            X
                        </div>
                        <button wire:click="left"   class="btn border"> @svg('arrow-left') </button>
                    </div>
                    <div class="col">
                        <button wire:click="home"   class="btn border"> @svg('home') </button>
                    </div>
                    <div class="col p-0">
                        <button wire:click="right"  class="btn border"> @svg('arrow-right') </button>
                    </div>
                </div>
                <div class="row mb-3">
                    <div class="col"></div>
                    <div class="col">
                        <button wire:click="down"   class="btn border"> @svg('arrow-down') </button>
                    </div>
                    <div class="col"></div>
                </div>
            </div>

            <div class="col col-md-12 col-lg-6 align-self-center">
                <div class="row">
                    <div class="col">
                        <button wire:click="yForward" class="btn border"> @svg('arrow-up') </button>
                    </div>
                </div>
                <div class="row">
                    <div class="col">
                        <h5 class="fs-5 fw-light m-2"> Y </h5>
                    </div>
                </div>
                <div class="row">
                    <div class="col">
                        <button wire:click="yBackward" class="btn border"> @svg('arrow-down') </button>
                    </div>
                </div>
            </div>
        </div>

        <div class="row mt-3">
            <div class="col" x-data="{ feedrate: {{ env('PRINTER_CONTROL_FEEDRATE_DEFAULT') }} }">
                <label class="form-label"> Feedrate </label>
                <input
                    wire:model.lazy="feedrate"
                    x-model="feedrate"
                    type="range"
                    class="form-range"
                    min="{{ env('PRINTER_CONTROL_FEEDRATE_MIN') }}"
                    max="{{ env('PRINTER_CONTROL_FEEDRATE_MAX') }}"
                >
                <span x-text="feedrate"></span>
            </div>

            <div class="col" x-data="{ distance: {{ env('PRINTER_CONTROL_DISTANCE_DEFAULT') }} }">
                <label class="form-label"> Distance (mm) </label>
                <input
                    wire:model.lazy="distance"
                    x-model="distance"
                    type="range"
                    class="form-range"
                    min="{{ env('PRINTER_CONTROL_DISTANCE_MIN') }}"
                    max="{{ env('PRINTER_CONTROL_DISTANCE_MAX') }}"
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
            class="btn-toolbar justify-content-center mb-3"
            role="toolbar"
            aria-label="Toolbar with button groups"
        >
            <div class="btn-group mx-1" role="group" aria-label="First group">
                <button wire:click="extrudeBack" type="button" class="btn btn-outline-secondary">
                    <span>-</span><span x-text="distance"></span>
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
                <button wire:click="extrudeForward" type="button" class="btn btn-outline-secondary">
                    <span>+</span><span x-text="distance"></span>
                </button>
            </div>
        </div>
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
                        >
                    </div>
                    <div class="btn-group mx-1" role="group" aria-label="Hotend temperature">
                        <button wire:click="setHotendTemperature" type="button" class="btn btn-outline-secondary">
                            @svg('ok')
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
                        >
                    </div>
                    <div class="btn-group mx-1" role="group" aria-label="Hotend temperature">
                        <button wire:click="setBedTemperature" type="button" class="btn btn-outline-secondary">
                            @svg('ok')
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

    const hotendTemperature = document.querySelector('#hotendTemperature');
    const bedTemperature    = document.querySelector('#bedTemperature');

    window.addEventListener('configApplyError', event => {
        console.debug(event);

        toastify.error(event.detail);
    });

    window.addEventListener('targetTemperatureChanged', event => {
        console.debug(event);

        if (
            typeof(event.detail.hotend) != 'undefined'
            &&
            event.detail.hotend != hotendTemperature.value
        ) {
            hotendTemperature.value  = event.detail.hotend;
        }

        if (
            typeof(event.detail.bed) != 'undefined'
            &&
            event.detail.bed    != bedTemperature.value
        ) {
            bedTemperature.value     = event.detail.bed;
        }
    });

    window.addEventListener('targetTemperatureReset', () => {
        hotendTemperature.value = 0;
        bedTemperature.value    = 0;
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