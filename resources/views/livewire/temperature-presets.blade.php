<div>
    @if ($show && $materials->count())
        <div class="row p-2">
            <div class="col-9 col-md-7">
                <select wire:model.live="materialIndex" class="form-select" aria-label="Material selector">
                    @foreach ($materials as $index => $material)
                        <option
                            value="{{ $index }}"
                            @if ($index == $materialIndex)
                                selected
                            @endif
                        >
                            {{ $material->name ?? 'Unknown' }} (H: {{ $material->temperatures['hotend'] }} °C, B: {{ $material->temperatures['bed'] }} °C)
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="col-3 col-md-5">
                <button
                    wire:click="load"
                    wire:target="load"
                    wire:loading.attr="disabled"
                    wire:loading.class="btn-success"
                    wire:loading.class.remove="btn-danger"
                    class="btn btn-danger w-100"
                >
                    <span wire:target="load" wire:loading.class="d-none">
                        @svg('thermometer')

                        <span class="d-none d-md-inline">
                            Warm up
                        </span>
                    </span>

                    <span wire:target="load" wire:loading.inline>
                        <span class="d-none d-md-inline">
                            @svg('thermometer-high')
                        </span>

                        @svg('check-lg', [ 'class' => 'animate__animated animate__slow animate__infinite animate__flash' ])
                    </span>
                </button>
            </div>
        </div>
    @endif
</div>
