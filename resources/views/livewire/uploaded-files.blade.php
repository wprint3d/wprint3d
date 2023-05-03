<div>
    <livewire:file-controls />

    <div class="row mb-3 mt-2">
        <div class="col-12">
            <div class="input-group mb-1">
                <input
                    type="text"
                    class="form-control"
                    placeholder="Current directory"
                    aria-label="Current directory"
                    aria-describedby="filesUpBtn"
                    readonly
                    value="{{ $subPath ? $subPath : '/' }}"
                >
                <button wire:click="goUp"   class="btn btn-primary" type="button" id="filesUpBtn" @if (!$subPath) disabled @endif> @svg('chevron-up') </button>
                <button wire:click="goHome" class="btn btn-primary" type="button" @if (!$subPath) disabled @endif> @svg('home') </button>
            </div>
        </div>

        <div class="col-12 col-sm-6 col-md-7 dropdown">
            <a class="btn btn-primary dropdown-toggle w-100" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                @svg('align-center') Sort ({{ Str::lower( SortingMode::fromValue($sortingMode)->description ?? 'Unknown' ) }})
            </a>

            <ul class="dropdown-menu">
                <li><a wire:click="sortBy('NAME_ASCENDING')"  class="dropdown-item" href="#"> By name (ascending)  </a></li>
                <li><a wire:click="sortBy('NAME_DESCENDING')" class="dropdown-item" href="#"> By name (descending) </a></li>
                <li><a wire:click="sortBy('DATE_ASCENDING')"  class="dropdown-item" href="#"> By date (ascending)  </a></li>
                <li><a wire:click="sortBy('DATE_DESCENDING')" class="dropdown-item" href="#"> By date (descending) </a></li>
            </ul>
        </div>

        <div class="col-12 col-sm-6 col-md-5 mt-1 mt-sm-0">
            <button onclick="showCreateFolderModal()" class="btn btn-primary w-100 text-truncate">
                @svg('folder-open') Create folder
            </button>
        </div>
    </div>

    <div class="list-group">
        @if ($files)
            @foreach ($files as $index => $file)
                <button
                    type="button"
                    class="list-group-item list-group-item-action {{ $selected == $file['name'] ? 'active' : '' }}"
                    aria-current="true"
                    wire:click="select('{{ $index }}')"
                >
                    <span class="{{ $file['directory'] ? 'fw-semibold' : '' }}">
                        @if ($file['directory']) @svg('folder') @endif
                        {{ $file['name'] }}
                    </span>
                </button>
            @endforeach
        @else
            <button
                type="button"
                class="list-group-item list-group-item-action"
                aria-current="true"
                disabled
            > No files uploaded, try uploading something! </button>
        @endif
    </div>

    <livewire:create-folder-modal />
</div>

@push('scripts')
<script>

document.addEventListener('DOMContentLoaded', () => {

    window.showCreateFolderModal = () => { createFolderModal.show(); };

});

</script>
@endpush