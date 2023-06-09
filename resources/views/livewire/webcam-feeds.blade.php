<div>
    @if (count($cameras) > 0)
    <div class="tab-content mb-2">
        @foreach ($cameras as $camera)
        <div
            class="position-relative tab-pane fade tab-camera @if ($loop->first) show active @endif"
            id="pills-camera-{{ $loop->index }}"
            role="tabpanel"
            aria-labelledby="pills-camera-{{ $loop->index }}-tab"
            tabindex="0"
        >
            @livewire('webcam-feed', [ 'camera' => $camera ], key( $camera->_id ))

            @livewire('webcam-feed-recording-indicator', [
                'printerId' => $printer->_id,
                'camera'    => $camera
            ], key( 'ri_' . $camera->_id ))
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

window.handleBrokenCamera = element => {
    console.error('Camera load error: ', element.src);

    element.outerHTML = `
        <div class="offline-camera-placeholder d-flex align-items-center border">
            <div class="d-flex flex-column flex-fill align-items-center mt-3">
                @svg('exclamation-circle', [ 'class' => 'fs-1 text-black-50' ])

                <p class="text-center mt-3">
                    This camera is not working.
                </p>

                <p>
                    Here's what you can try:
                </p>

                <ul>
                    <li> Reset the USB controller. </li>
                    <li> Re-seat the camera into the port. </li>
                    <li> Restart the host. </li>
                    <li> Remove it from the list of assigned cameras. </li>
                </ul>
            </div>
        </div>`;
};

</script>
@endpush