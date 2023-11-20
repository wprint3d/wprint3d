<div>
    <div class="row text-start">
        @if ($cameras->count())
            @foreach ($cameras as $camera)
                @livewire('camera-settings', [ 'camera' => $camera, 'writeable' => $writeable ], key( $camera->_id ))
            @endforeach
        @else
            <div class="d-flex align-items-center">
                <div class="d-flex flex-column flex-fill align-items-center mt-3">
                    @svg('camera-video-off-fill', [ 'class' => 'fs-1' ])

                    <p class="text-center mt-3"> No cameras were detected. </p>

                    <p>
                        If you believe that a camera should show up in this section, here's what you can try:
                    </p>

                    <ul>
                        <li> Reset the USB controller. </li>
                        <li> Re-seat the camera's USB plug into the port. </li>
                        <li> If it's a CSI camera, ensure that the flex cable works properly. </li>
                        <li> Restart the host. </li>
                    </ul>
                </div>
            </div>
        @endif
    </div>
</div>