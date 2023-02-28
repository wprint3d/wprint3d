<div>
    <div class="row text-start">
        @foreach ($printers as $printer)
        <div class="card col-12 col-md-6 col-lg-4 mt-2">
            <img src="images/printer.jpg" class="card-img-top mt-3" alt="{{ $printer->machine['machineType']  }}">
            <div class="card-body">
                <h5 class="card-title"> {{ $printer->machine['machineType'] }} </h5>
                <p class="card-text">
                    <ul>
                    @foreach ($printer->machine as $key => $value)
                        @if (!is_array($value))
                            <li>
                                {{
                                    Str::of($key)
                                       ->snake()
                                       ->replace('_', ' ')
                                       ->ucfirst()
                                       ->replace('url', 'URL')
                                       ->replace('Uuid', 'UUID')
                                }}: {{ $value }}
                            </li>
                        @endif
                    @endforeach
                    </ul>
                </p>
                <button data-printer-id="{{ $printer->_id }}" class="btn btn-primary center printer-manage"> Manage </button>
            </div>
        </div>
        @endforeach
    </div>

    <livewire:printer-manager-modal />
</div>

@push('scripts')
<script>

window.addEventListener('DOMContentLoaded', () => {
    const printerManagementModal = new bootstrap.Modal(
        document.querySelector('#printerManagementModal')
    );

    const resetButtons = () => {
        document.querySelectorAll('.printer-manage.btn').forEach(btn => {
            btn.removeAttribute('disabled')
        });
    };

    document.querySelectorAll('.printer-manage.btn').forEach(button => {
        button.addEventListener('click', event => {
            console.log(event);

            document.querySelectorAll('.printer-manage.btn').forEach(btn => {
                btn.setAttribute('disabled', true)
            });

            Livewire.emit('loadPrinterManagement', event.target.dataset.printerId);
        });
    });

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