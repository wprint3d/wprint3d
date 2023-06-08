<div>
    <ul class="nav nav-tabs" role="tablist">
        @foreach ($tabs as $tab)
            @if ($tab != 'control' || $enableControlTab)
                <li wire:click="select({{ $loop->index }})" class="nav-item" role="presentation">
                    <button
                        class="nav-link @if ($activeTab == $tab) active @endif"
                        id="{{ $tab }}-tab"
                        data-bs-toggle="tab"
                        data-bs-target="#{{ $tab }}-tab-pane"
                        type="button"
                        role="tab"
                        aria-controls="{{ $tab }}-tab-pane"
                        aria-selected="true"
                        onclick="tabChanged(this)"
                    > {{ Str::title( $tab ) }} </button>
                </li>
            @endif
        @endforeach
    </ul>
    <div class="tab-content m-2">
        @foreach ($tabs as $tab)
            @if ($tab != 'control' || $enableControlTab)
                <div
                    class="tab-pane fade @if ($activeTab == $tab) show active @endif"
                    id="{{ $tab }}-tab-pane"
                    role="tabpanel"
                    aria-labelledby="{{ $tab }}-tab"
                    tabindex="0"
                > @livewire( $tab . '-tab', [], key( $tab . '-tab' ) ) </div>
            @endif
        @endforeach
    </div>
</div>

@push('scripts')
<script>

window.tabChanged = element => {
    dispatchEvent( new Event('tabChanged') );
}

</script>
@endpush