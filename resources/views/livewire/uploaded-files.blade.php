<div>
    <livewire:file-controls />
    <div class="list-group">
        @foreach (
            array_map(
                function ($item) {
                    return Str::replace('gcode/', '', $item);
                },
                Storage::files('gcode')
            ) as $fileName
        )
            <button
                type="button"
                class="list-group-item list-group-item-action {{ $selected == $fileName ? 'active' : '' }}"
                aria-current="true"
                wire:click="select('{{ $fileName }}')"
            >
                {{ $fileName }}
            </button>
        @endforeach
    </div>
</div>
