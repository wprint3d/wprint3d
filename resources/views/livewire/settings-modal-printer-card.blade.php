<div class="card col-12 col-sm-6 col-lg-4 mt-2">
    <img src="images/printer.jpg" class="card-img-top mt-3" alt="{{ $printer->machine['machineType'] ?? 'Unknown printer'  }}">
    <div class="card-body">
        <div class="d-flex justify-content-center mb-2">
            <span class="badge bg-{{ $printer->connected ? 'success' : 'danger' }}">
                @if ($printer->connected)
                    Connected
                @else
                    Disconnected
                @endif
            </span>
        </div>

        <h5 class="card-title pt-2"> {{ $printer->machine['machineType'] ?? 'Unknown printer' }} </h5>

        <p class="card-text">
            <ul class="px-1 px-md-3">
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

        <button
            onclick="showPrinterManagerModal('{{ $printer->_id }}')"
            class="btn btn-primary center printer-manage"
        > Manage </button>

    </div>
</div>