<div class="col-12 border-bottom py-2">
    <div class="row">
        <div class="col-12 col-lg-4 mb-2 mb-lg-0">
            <label class="form-label"> Type </label>
            <input wire:model.lazy="name" type="text" class="form-control" placeholder="PLA/ABS/PETG/TPU" value="{{ $name }}">
        </div>

        <div class="col-12 col-lg-3 mb-2 mb-lg-0">
            <label class="form-label"> Hotend temperature (°C) </label>
            <input wire:model.lazy="hotendTemperature" type="number" class="form-control" placeholder="0" value="{{ $hotendTemperature }}">
        </div>

        <div class="col-12 col-lg-3">
            <label class="form-label"> Bed temperature (°C) </label>
            <input wire:model.lazy="bedTemperature" type="number" class="form-control" placeholder="0" value="{{ $bedTemperature }}">
        </div>

        <div class="col-12 col-lg-2 align-self-center text-center">
            <button wire:click="delete" class="btn btn-danger mt-2 mt-lg-0">
                @svg('trash') Delete
            </button>
        </div>
    </div>
</div>
