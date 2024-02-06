<div class="col-12 col-sm-4 col-lg-3 border-bottom border-xl-bottom-0 py-2 border-xl-end">
    <div class="row">
        <div class="col-12 mt-2">
            <label class="form-label"> Type </label>
            <input wire:model.blur="name" type="text" class="form-control" placeholder="PLA/ABS/PETG/TPU" value="{{ $name }}">
        </div>

        <div class="col-12 mt-2">
            <label class="form-label"> Hotend temperature (°C) </label>
            <input wire:model.blur="hotendTemperature" type="number" class="form-control" placeholder="0" value="{{ $hotendTemperature }}">
        </div>

        <div class="col-12 mt-2">
            <label class="form-label"> Bed temperature (°C) </label>
            <input wire:model.blur="bedTemperature" type="number" class="form-control" placeholder="0" value="{{ $bedTemperature }}">
        </div>

        <div class="col-12 my-1 align-self-center text-center">
            <button wire:click="delete" class="btn btn-danger mt-2 mt-lg-0" wire:offline.attr="disabled">
                @svg('trash') Delete
            </button>
        </div>
    </div>
</div>
