<div>
    @if (count($cameras) > 0)
    <div class="tab-content mb-2">
        @foreach ($cameras as $camera)
        <div
            class="tab-pane fade tab-camera @if ($loop->first) show active @endif"
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

@push('scripts')
<script>

const applySrcToActiveCamera = () => {
    let defaultTabPane = document.querySelector('.tab-camera.active');

    if (defaultTabPane) {
        let img = defaultTabPane.querySelector('img');

        if (img) {
            img.src = img.dataset.src;
        }
    }
}

document.addEventListener('shown.bs.tab', event => {
    const previousTab   = event.relatedTarget;
    const activeTab     = event.target;

    const previousTabPane = document.querySelector(previousTab.dataset.bsTarget);
    const activeTabPane   = document.querySelector(activeTab.dataset.bsTarget);

    if (activeTabPane.classList.contains('tab-camera')) {
        let previousImg = previousTabPane.querySelector('img');

        if (previousImg) {
            previousImg.src = '';
        }

        applySrcToActiveCamera();
    }
});

applySrcToActiveCamera();

window.addEventListener('webcamFeedsChanged', applySrcToActiveCamera);

</script>
@endpush