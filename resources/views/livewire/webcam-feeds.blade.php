<div>
    @if (count($cameras) > 0)
    <div class="tab-content mb-2">
        @foreach ($cameras as $camera)
        <div
            class="tab-pane fade @if ($loop->first) show active @endif"
            id="pills-camera-{{ $loop->index }}"
            role="tabpanel"
            aria-labelledby="pills-camera-{{ $loop->index }}-tab"
            tabindex="0"
        >
            @livewire('webcam-feed', [ 'camera' => $camera ], key( $camera->_id ))
        </div>
        @endforeach
    </div>
    <ul class="nav nav-pills justify-content-center" id="pills-tab" role="tablist">
        @foreach ($cameras as $camera)
        <li class="nav-item" role="presentation">
            <button
                class="nav-link @if ($loop->first) active @endif"
                id="pills-camera-{{ $loop->index }}-tab"
                data-bs-toggle="pill"
                data-bs-target="#pills-camera-{{ $loop->index }}"
                type="button"
                role="tab"
                aria-controls="pills-camera-{{ $loop->index }}"
                aria-selected="true"
            > {{ $loop->iteration }} </button>
        </li>
        @endforeach
    </ul>
    @endif
</div>