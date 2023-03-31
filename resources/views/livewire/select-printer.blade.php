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
                    {{ $printer->machine['machineType'] ?? 'Unknown printer' }} ({{ $printer->machine['uuid'] }})
                </option>
            @endforeach
        @else
            <option selected> No printer available. </option>
        @endif
    </select>
</div>

@push('scripts')
<script>

window.addEventListener('DOMContentLoaded', () => {

    Echo.channel('printers-map-updated')
        .listen('PrintersMapUpdated', event => {
            console.debug('PrintersMapUpdated', event);

            toastify.info('Hardware change detected, tap here to reload the page.', null, null, () => {
                window.location.reload();
            });
        });

});

</script>
@endpush