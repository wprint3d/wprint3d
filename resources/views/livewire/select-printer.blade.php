<div>
    <select
        id="printerSelect"
        wire:model.live="printerId"
        wire:change="change"
        class="form-select"
        aria-label="Printer picker"
        wire:offline.attr="disabled"
    >
        @if ($printers->count())
            @if (!$printerId)
                <option selected> Select a printer </option>
            @endif

            @foreach ($printers as $printer)
                <option
                    value="{{ $printer->_id }}"
                    @if ($printer->_id == $printerId)
                        selected
                    @endif
                >
                    {{ $printer->machine['machineType'] ?? 'Unknown printer' }} ({{ $printer->machine['uuid'] }})
                </option>
            @endforeach
        @else
            <option selected> No printer available </option>
        @endif
    </select>
</div>