<div {{-- wire:poll.5s='refreshFeed' --}}>
    {{-- <img src="{{ $snapshot }}" /> --}}

    @if ($camera->connected)
        <img
            data-src="{{ $url }}"
            style="transform: scale(1, 1) rotate(0deg); width: 100%; height: 100%; opacity: 1;"
        />
    @else
        <div class="offline-camera-placeholder d-flex align-items-center border">
            <div class="d-flex flex-column flex-fill align-items-center mt-3">
                @svg('plug', [ 'class' => 'fs-1 text-black-50' ])

                <p class="text-center mt-3">
                    This camera is not connected.
                </p>

                <p>
                    Here's what you can try:
                </p>

                <ul>
                    <li> Make sure that the camera is plugged in. </li>
                    <li> Reset the USB controller. </li>
                    <li> Re-seat the camera into the port. </li>
                    <li> Restart the host. </li>
                    <li> Remove it from the list of assigned cameras. </li>
                </ul>
            </div>
        </div>
    @endif
</div>
