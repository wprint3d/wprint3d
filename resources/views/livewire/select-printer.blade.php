

<div>
    <select id="printerSelect" wire:model="printerId" wire:change="change" class="form-select" aria-label="Default select example">
        @if ($printers->count())
            @foreach ($printers as $printer)
                <option
                    value="{{ $printer->_id }}"
                    @if ($printer->_id == $printerId)
                        selected
                    @endif
                >
                    {{ $printer->node }}
                </option>
            @endforeach
        @else
            <option selected> No printer available. </option>
        @endif
    </select>
</div>
