<div>
    <div class="terminal overflow-auto bg-body-overlay rounded rounded-2 border border-1 p-2 mb-2 font-monospace">
        @foreach ($terminal as $line)
            <span>
                <span
                    class="
                        terminal-command-kind
                        @if (Str::contains($line, ' > '))
                            bg-secondary
                        @else
                            @if (Str::contains($line, 'ok') || Str::contains($line, 'T:'))
                                bg-success
                            @else
                                @if (Str::contains($line, 'busy'))
                                    bg-warning
                                @else
                                    bg-danger
                                @endif
                            @endif
                        @endif
                    "
                ></span>
                {{ $line }} <br>
            </span>
        @endforeach
    </div>

    <form wire:submit>
        <div class="input-group mb-2">
            <input
                wire:model="command"
                wire:keydown.enter="queueCommand"
                wire:target="queueCommand"
                wire:loading.attr="disabled"
                type="text"
                class="
                    form-control
                    @if (!$writeable)       d-none      @endif
                    @if ($sentEmptyCommand) is-invalid  @endif
                "
                placeholder="Enter a custom command"
                @if (!$printer || $printer->activeFile || !$writeable)
                    disabled
                @endif
            >

            <button
                wire:click="queueCommand"
                wire:target="queueCommand"
                wire:loading.attr="disabled"
                class="btn btn-primary"
                type="button"
                @if (!$printer || $printer->activeFile || !$writeable)
                    disabled
                @endif
            >
                <div wire:target="queueCommand" wire:loading>
                    <span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>
                    <span class="visually-hidden"> Loading... </span>
                </div>

                <span wire:target="queueCommand" wire:loading.remove> Send </span>
            </button>
        </div>
    </form>

    <ul class="list-group">
        <li class="list-group-item">
          <input wire:model.live="autoScroll" class="form-check-input me-1" type="checkbox">
          <label class="form-check-label"> Auto-scroll to bottom </label>
        </li>
        <li class="list-group-item">
          <input wire:model.live="showSensors" class="form-check-input me-1" type="checkbox">
          <label class="form-check-label"> Show sensors updates </label>
        </li>
        <li class="list-group-item">
          <input wire:model.live="showInputCommands" class="form-check-input me-1" type="checkbox">
          <label class="form-check-label"> Show input commands </label>
        </li>
    </ul>
</div>

@push('scripts')
<script>

const TERMINAL_MAX_LINES = @json(
    Configuration::get('terminalMaxLines', env('TERMINAL_MAX_LINES')),
    JSON_NUMERIC_CHECK
);

let autoScroll = @json( $autoScroll );

let terminal = document.querySelector('.terminal');

let lastRunningStatus = true;

const setAutoScrollInterval = () => {
    setInterval(() => {
        if (autoScroll) { terminal.scrollTop = terminal.scrollHeight; }
    }, 250);
};

window.addEventListener('toggleAutoScroll', event => {
    autoScroll = event.detail.enabled;
});

window.addEventListener('DOMContentLoaded', () => {
    const terminal = document.querySelector('.terminal');

    Echo.private(`console.${getSelectedPrinterId()}`)
        .listen('PrinterTerminalUpdated', event => {
            console.debug('Terminal:', event);

            if (lastRunningStatus != event.running) {
                Livewire.dispatch('refreshActiveFile');

                if (!event.running) {
                    toastify.info('The printer is waiting for your interaction.', 30000);
                }
            }

            lastRunningStatus = event.running;

            event.command.split(PHP_EOL).forEach(line => {
                if (line.trim().length > 0) {
                    let targetClass = 'bg-danger';

                    if (line.indexOf(' > ') > -1) {
                        targetClass = 'bg-secondary';
                    } else if (line.indexOf('ok') > -1 || line.indexOf('T:') > -1) {
                        targetClass = 'bg-success';
                    } else if (line.indexOf('busy') > -1) {
                        targetClass = 'bg-warning';
                    }

                    terminal.insertAdjacentHTML('beforeend', 
                        `<span>
                            <span class="terminal-command-kind ${targetClass}"></span>
                            ${event.dateString}: ${line.trim().replaceAll('\n', '<br>')} <br>
                         </span>`
                    );

                    if (document.querySelectorAll('.terminal span').length > TERMINAL_MAX_LINES) {
                        document.querySelector('.terminal span').remove();
                    }
                }
            });

            return true;
        });
});

setAutoScrollInterval();

</script>
@endpush