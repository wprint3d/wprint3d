<div>
    <div class="row text-start">
        @if ($printers->count())
            @foreach ($printers as $printer)
                @livewire('settings-modal-printer-card', [ 'printer' => $printer ])
            @endforeach
        @else
            <div class="d-flex align-items-center">
                <div class="d-flex flex-column flex-fill align-items-center mt-3">
                    @svg('usb-plug-fill', [ 'class' => 'fs-1' ])

                    <p class="text-center mt-3"> No printers were detected. </p>

                    <p>
                        If you are absolutely sure that a printer should show up in this section, here's what you can try:
                    </p>

                    <ul>
                        <li> Reset the USB controller. </li>
                        <li> Re-seat the printer's USB plug into the port. </li>
                        <li> Restart the host. </li>
                    </ul>
                </div>
            </div>
        @endif
    </div>

    @livewire('printer-manager-modal', [ 'writeable' => $writeable ])
</div>

@push('scripts')
<script>

window.showPrinterManagerModal = printerId => {
    console.log(event);

    document.querySelectorAll('.printer-manage.btn').forEach(btn => {
        btn.setAttribute('disabled', true)
    });

    Livewire.emit('loadPrinterManagement', printerId);
};

window.addEventListener('DOMContentLoaded', () => {
    const printerManagementModal = new bootstrap.Modal(
        document.querySelector('#printerManagementModal')
    );

    const resetButtons = () => {
        document.querySelectorAll('.printer-manage.btn').forEach(btn => {
            btn.removeAttribute('disabled')
        });
    };

    window.addEventListener('printerLoaded', event => {
        console.debug(event);

        if (event.detail) {
            printerManagementModal.show();
        } else {
            toastify.error('Couldn\'t load printer, please, try again later.');

            resetButtons();
        }
    });

    printerManagementModal._element.addEventListener('hidden.bs.modal', resetButtons);
});

</script>
@endpush