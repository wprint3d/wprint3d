<div class="col-12 col-sm-6 col-xl-4 border-bottom border-xl-bottom-0 py-2 border-xl-end">
    <div class="row">
        <div class="col-12 col-lg-4 col-xl-12 mt-xl-2 mb-2 mb-lg-0">
            <label class="form-label"> Type </label>
            <input wire:model="name" type="text" class="form-control" placeholder="PLA/ABS/PETG/TPU" value="{{ $name }}">
        </div>

        <div class="col-12 col-lg-3 col-xl-12 mt-xl-2 mb-2 mb-lg-0">
            <label class="form-label"> Hotend temperature (°C) </label>
            <input wire:model="hotendTemperature" type="number" class="form-control" placeholder="0" value="{{ $hotendTemperature }}">
        </div>

        <div class="col-12 col-lg-3 col-xl-12 mt-xl-2">
            <label class="form-label"> Bed temperature (°C) </label>
            <input wire:model="bedTemperature" type="number" class="form-control" placeholder="0" value="{{ $bedTemperature }}">
        </div>

        <div class="col-12 col-lg-2 col-xl-12 mt-xl-2 align-self-center text-center">
            <button wire:click="delete" class="btn btn-danger mt-2 mt-lg-0">
                @svg('trash') Delete
            </button>
        </div>
    </div>
</div>
