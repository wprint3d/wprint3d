<div>
    @if ($writeable)
        <livewire:file-controls />
    @endif

    <div class="row mb-3 mt-3 mt-lg-2">
        <div class="col-12">
            <div class="input-group mb-1 mb-lg-1">
                <input
                    type="text"
                    class="form-control"
                    placeholder="Current directory"
                    aria-label="Current directory"
                    aria-describedby="filesUpBtn"
                    readonly
                    value="{{ $subPath ? $subPath : '/' }}"
                >

                <button wire:click="goUp"   wire:loading.attr="disabled" class="btn btn-primary" type="button" id="filesUpBtn" @if (!$subPath) disabled @endif>
                    <span wire:target="goUp" wire:loading.class="animate__animated animate__flash animate__infinite animate__slow">
                        @svg('caret-up-fill')
                    </span>
                </button>

                <button wire:click="goHome" wire:loading.attr="disabled" class="btn btn-primary" type="button" @if (!$subPath) disabled @endif>
                    <span wire:target="goHome" wire:loading.class="animate__animated animate__flash animate__infinite animate__slow">
                        @svg('house-door-fill')
                    </span> 
                </button>
            </div>
        </div>

        <div class="col-12 dropdown @if ($writeable) col-sm-6 col-md-7 @endif">
            <a
                class="btn btn-primary dropdown-toggle w-100 text-truncate"
                href="#"
                role="button"
                data-bs-toggle="dropdown"
                aria-expanded="false"
                wire:loading.class="disabled"
            >
                <div wire:loading wire:target="sortBy">
                    <span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>
                    <span class="visually-hidden"> Loading... </span>
                </div>

                <span wire:loading.class="d-none"> @svg('filter') </span>
                Sort ({{ Str::lower( SortingMode::fromValue($sortingMode)->description ?? 'Unknown' ) }})
            </a>

            <ul class="dropdown-menu">
                <li><a wire:click="sortBy('NAME_ASCENDING')"  class="dropdown-item" href="#"> By name (ascending)  </a></li>
                <li><a wire:click="sortBy('NAME_DESCENDING')" class="dropdown-item" href="#"> By name (descending) </a></li>
                <li><a wire:click="sortBy('DATE_ASCENDING')"  class="dropdown-item" href="#"> By date (ascending)  </a></li>
                <li><a wire:click="sortBy('DATE_DESCENDING')" class="dropdown-item" href="#"> By date (descending) </a></li>
            </ul>
        </div>

        <div class="col-12 col-sm-6 col-md-5 mt-1 mt-sm-0">
            <button
                onclick="showCreateFolderModal()"
                class="btn btn-primary w-100 text-truncate @if (!$writeable) d-none disabled @endif"
            >
                @svg('folder-plus') Create folder
            </button>
        </div>
    </div>

    <div id="uploadedFilesList" class="list-group border">
        @if ($files)
            @foreach ($files as $index => $file)
                <button
                    type="button"
                    class="list-group-item list-group-item-action overflow-scroll no-scrollbar {{ $selected == $file['name'] ? 'active' : '' }}"
                    aria-current="true"
                    wire:click="select('{{ $index }}')"
                    wire:loading.class="disabled"
                    @if (!$writeable && !$file['directory']) disabled @endif
                >
                    <div wire:loading wire:target="select('{{ $index }}')">
                        <span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>
                        <span class="visually-hidden"> Loading... </span>
                    </div>

                    <span class="{{ $file['directory'] ? 'fw-semibold' : '' }}">
                        @if (isset( $file['active'] ) && $file['active'])
                            @if ($file['directory'])
                                @svg('folder-symlink-fill', [ 'class' => 'text-black-50 animate__animated animate__slow animate__infinite animate__flash' ])
                            @else
                                @svg('play-fill', [ 'class' => 'animate__animated animate__slow animate__infinite animate__flash' ])
                            @endif
                        @elseif ($file['directory'])
                            @svg('folder-fill')
                        @endif
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