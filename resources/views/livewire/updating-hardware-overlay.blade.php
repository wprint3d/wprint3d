<div>
    @if ($show)
    <div
        class="animate__animated animate__fadeIn animated bg-body border border-secondary bottom-0 d-flex end-0 p-2 m-2 position-fixed rounded rounded-3 shadow-sm"
        style="z-index: 999999999999"
    >
        <p class="m-0">
            Processing hardware changes
        </p>

        <div class="d-flex flex-column justify-content-center mx-2">
            <div class="spinner-border spinner-border-sm" role="status">
                <span class="visually-hidden">Loading...</span>
            </div>
        </div>
    </div>
    @endif
</div>

@push('scripts')
<script>

window.addEventListener('DOMContentLoaded', () => {

    Echo.channel('printers-map-updating')
        .listen('PrintersMapInProgress', event => {
            console.debug('PrintersMapInProgress', event);

            Livewire.dispatch('refreshMapperStatus');

            return true;
        });

});

</script>
@endpush