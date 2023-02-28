<div>
    <div class="row text-start">
        @foreach ($cameras as $camera)
            @livewire('camera-settings', [ 'camera' => $camera ], key( $camera->_id ))
        @endforeach
    </div>
</div>