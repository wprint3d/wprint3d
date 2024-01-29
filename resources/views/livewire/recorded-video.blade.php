<div class="row g-0" >
    <div class="col-md-4 position-relative mh-6em">

        <img
            src="{{ $recording['thumb'] }}"
            class="w-100 h-100 object-fit-cover"
            aria-label="Thumbnail of {{ $recording['name'] }}"
            onerror="handleMissingImage(this)"
        >

        <div wire:target="play" wire:loading.remove wire:click="play" role="button" class="position-absolute bottom-0 start-0 w-100 h-100 fs-5">
            @svg('play-circle', [ 'class' => 'position-absolute bg-white bottom-0 m-2 opacity-75 rounded rounded-4 border border-2' ])
        </div>

        <div wire:target="play" wire:loading.flex class="h-100 justify-content-center position-absolute start-0 top-0 w-100">
            <div class="bg-white h-100 opacity-25 position-absolute start-0 top-0 w-100"></div>

            <div class="d-flex align-items-center">
                <div class="spinner-border" role="status">
                    <span class="visually-hidden">Loading...</span>
                </div>
            </div>
        </div>

        <title>{{ $recording['name'] }}</title>

    </div>

    <div class="col-md-8">
        <div class="card-body d-flex flex-column h-100 justify-content-center">
            <h5 class="card-title overflow-scroll text-nowrap no-scrollbar">
                {{ $recording['name'] }}
            </h5>
            <p class="card-text">
                {{ $recording['size'] }}
            </p>
            <p class="card-text d-flex justify-content-between">
                <small class="text-muted align-self-center">
                    Saved {{ $recording['modified'] }}
                </small>
                <button
                    class="btn btn-danger"
                    wire:click="prepareDeleteRecording"
                    wire:loading.attr="disabled"
                    wire:target="prepareDeleteRecording"
                    wire:offline.attr="disabled"
                    @if (
                        (isset( $recording['deletable'] ) && !$recording['deletable'])
                        ||
                        !$writeable
                    )
                        disabled
                    @endif
                >
                    <div wire:target="prepareDeleteRecording" wire:loading>
                        <span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>
                        <span class="visually-hidden"> Loading... </span>
                    </div>

                    <div wire:target="prepareDeleteRecording" wire:loading.remove>
                        @svg('trash')
                    </div>
                </button>
            </p>
        </div>
    </div>
</div>