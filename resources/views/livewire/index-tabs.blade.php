<div>
    <ul class="nav nav-tabs flex-nowrap overflow-x-auto overflow-y-hidden" role="tablist">
        @foreach ($tabs as $tab)
            @if ($tab != 'control' || $enableControlTab)
                <li wire:click="select({{ $loop->index }})" class="nav-item" role="presentation">
                    <button
                        class="nav-link d-flex align-items-center @if ($activeTab == $tab) active @endif"
                        id="{{ $tab }}-tab"
                        type="button"
                        role="tab"
                        aria-controls="{{ $tab }}-tab-pane"
                        aria-selected="true"
                        onclick="initialize('{{ $tab . '-tab' }}')"
                        wire:loading.attr="disabled"
                        wire:loading.class.remove="active"
                    >
                        <span class="mx-2">
                            {{ Str::title( $tab ) }}
                        </span>

                        <div
                            class="spinner-border spinner-border-sm"
                            role="status"
                            wire:loading
                            wire:target="select({{ $loop->index }})"
                        >
                            <span class="visually-hidden">Loading...</span>
                        </div>
                    </button>
                </li>
            @endif
        @endforeach
    </ul>
    <div class="tab-content m-2">
        <div
            wire:loading.delay.longest
            class="justify-content-center pt-2 w-100"
            wire:loading.class="animate__animated animate__fadeIn"
        >
            <div class="d-flex flex-column align-items-center">
                <div class="spinner-border" role="status">
                    <span class="visually-hidden"> Please wait... </span>
                </div>
                <p class="pt-3 text-center"> This is taking a little longer than expected, please wait for a while... </p>
            </div>
        </div>

        @foreach ($tabs as $tab)
            @if ($tab != 'control' || $enableControlTab)
                <div
                    class="tab-pane fade position-relative @if ($activeTab == $tab) show active @endif"
                    id="{{ $tab }}-tab-pane"
                    role="tabpanel"
                    aria-labelledby="{{ $tab }}-tab"
                    tabindex="0"
                >
                    <div wire:loading class="bg-light h-100 opacity-75 position-absolute start-0 top-0 w-100 z-3"></div>

                    @livewire( $tab . '-tab', [ 'writeable' => $writeable ], key( $tab . '-tab' ) )
                </div>
            @endif
        @endforeach
    </div>
</div>