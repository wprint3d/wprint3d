<div>
    @if ($camera->connected && $isRecording)
        <div class="position-absolute text-danger bottom-0 end-0 fs-5 m-1 animate__animated animate__flash animate__infinite animate__slow">
            @svg('record-fill', [ 'class' => 'opacity-50' ])
        </div>
    @endif
</div>
