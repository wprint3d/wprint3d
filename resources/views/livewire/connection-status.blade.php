<div class="my-2">
    @if ($printer)
    <div id="connectionStatusContainer">
        <div class="row text-center">
            <div class="col">
                Connection status: <span id="connectionStatusText"> waiting for server... </span>
            </div>
        </div>

        <div class="row text-center">
            <div id="printerStatisticsText" class="col"></div>
        </div>

        {{--
        <div class="row text-center mt-2">
            <div class="col">
                <button class="btn btn-secondary" wire:click='connect'>Connect</button>
            </div>
        </div>
        --}}
    </div>
    @endif
</div>

@push('scripts')
<script>

const PRINTER_LAST_SEEN_ONLINE_THRESHOLD_SECS = @json(
    Configuration::get('lastSeenThresholdSecs', env('PRINTER_LAST_SEEN_ONLINE_THRESHOLD_SECS')),
    JSON_NUMERIC_CHECK
);

window.addEventListener('DOMContentLoaded', () => {
    const connectionStatusContainer = document.querySelector('#connectionStatusContainer');
    const connectionStatusText      = document.querySelector('#connectionStatusText');
    const printerStatisticsText     = document.querySelector('#printerStatisticsText');

    let targetTemperatures = { hotend: 0, bed: 0 };

    Echo.private(`connection-status.${getSelectedPrinterId()}`)
        .listen('PrinterConnectionStatusUpdated', event => {

            console.debug('ConnectionStatus:', event);

            if (connectionStatusContainer.classList.contains('d-none')) {
                connectionStatusContainer.classList.remove('d-none');
            }

            connectionStatusText.innerHTML = (
                !event.lastSeen
                ||
                (
                    (Date.now() / 1000) - event.lastSeen
                    >
                    PRINTER_LAST_SEEN_ONLINE_THRESHOLD_SECS
                )
            ) ? 'offline'
              : 'online';

            let printerStatistics = '';

            if (event.statistics.extruders) {
                event.statistics.extruders.forEach((extruder, index) => {
                    printerStatistics += `Extruder #${index}: ${extruder.temperature}°C`;

                    if (typeof(extruder.target) != 'undefined') {
                        printerStatistics += ` (targetting ${extruder.target}°C)`;

                        if (targetTemperatures.hotend != extruder.target) {
                            targetTemperatures.hotend =  extruder.target;
                        }
                    }

                    printerStatistics += '<br>';
                });
            }

            if (event.statistics.bed) {
                printerStatistics += `Bed: ${ event.statistics.bed.temperature }°C`;

                if (typeof(event.statistics.bed.target) != 'undefined') {
                    printerStatistics += ` (targetting ${ event.statistics.bed.target }°C) <br>`;

                    if (targetTemperatures.bed != event.statistics.bed.target) {
                        targetTemperatures.bed =  event.statistics.bed.target;
                    }
                }
            }

            let targetTemperatureChanged = new CustomEvent('targetTemperatureChanged', { detail: targetTemperatures });

            dispatchEvent( targetTemperatureChanged );

            printerStatisticsText.innerHTML = printerStatistics;

            return true;
        });
});

</script>
@endpush