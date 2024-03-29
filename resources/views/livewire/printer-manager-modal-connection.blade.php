<div>
    <form class="row g-3">
        <div class="col-12 col-md-6">
          <label for="printerNode" class="form-label"> Node </label>
          <input wire:model.live="node" type="text" class="form-control" id="printerNode" value="{{ $node }}" disabled>
        </div>
        <div class="col-12 col-md-6">
          <label for="baudRate" class="form-label"> Baudrate </label>
          <select
            wire:model.live="baudRate"
            id="baudRate"
            class="form-select"
            wire:offline.attr="disabled"
            @if (!$writeable) disabled @endif
          >
            @foreach (config('app.common_baud_rates') as $baudRateOption)
                <option @if ($baudRate == $baudRateOption) selected @endif>
                    {{ $baudRateOption }}
                </option>
            @endforeach
          </select>
        </div>
    </form>
</div>