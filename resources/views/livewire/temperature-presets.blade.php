<div>
    @if ($show && $materials->count())
        <div class="row p-2">
            <div class="col-9 col-md-7 col-lg-8">
                <select wire:model="materialIndex" class="form-select" aria-label="Material selector">
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

            <div class="col-3 col-md-5 col-lg-4">
                <button
                    wire:click="load"
                    wire:loading.attr="disabled"
                    wire:loading.class="btn-success"
                    wire:loading.class.remove="btn-danger"
                    class="btn btn-danger w-100"
                >
                    <span wire:loading.class="d-none">
                        @svg('thermometer')

                        <span class="d-none d-md-inline">
                            Warm up
                        </span>
                    </span>

                    <span wire:loading.inline>
                        <span class="animate__animated animate__slow animate__infinite animate__flash">
                            @svg('thermometer-high')
                        </span>

                        @svg('check-lg')
                    </span>
                </button>
            </div>
        </div>
    @endif
</div>
