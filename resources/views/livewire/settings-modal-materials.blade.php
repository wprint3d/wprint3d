<div>
    <div class="row text-start">
        @if ($materials->count())
            @foreach ($materials as $material)
                @livewire('material-settings', [ 'material' => $material ], key( $material->_id ))
            @endforeach

            <div class="col-12 mt-2 d-flex justify-content-center">
                <button wire:click="add" class="btn btn-primary" wire:offline.attr="disabled"> @svg('plus') Add more </button>
            </div>
        @else
            <div class="d-flex align-items-center">
                <div class="d-flex flex-column flex-fill align-items-center mt-3">
                    @svg('gear-fill', [ 'class' => 'fs-1' ])

                    <p class="text-center mt-3"> No materials are configured, add one or more materials in order to enable temperature presets. </p>

                    <button wire:click="add" class="btn btn-primary" wire:offline.attr="disabled"> Add your first material </button>
                </div>
            </div>
        @endif
    </div>
</div>