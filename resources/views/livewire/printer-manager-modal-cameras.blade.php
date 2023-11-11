<div>
    <div class="row">
        <div class="col-12 col-md-6">
            <div class="card">
                <div class="card-header">
                    Available cameras
                </div>
                <ul class="list-group list-group-flush">
                    @if (count($availableCameras) > 0)
                        @foreach ($availableCameras as $camera)
                            <li class="list-group-item d-flex">
                                <span class="d-flex flex-fill flex-column justify-content-center overflow-scroll text-truncate no-scrollbar">
                                    Local camera at {{ $camera->node }} ({{ $camera->format }})
                                </span>
                                <div class="d-flex justify-content-center">
                                    <button wire:click="add('{{ $camera->_id }}')" class="btn btn-sm border-success text-success mx-2 my-auto">
                                        <div class="m-0"> @svg('plus') </div>
                                    </button>
                                    @if ($camera->connected)
                                        <a href="{{ $camera->url . '?' . http_build_query( data: [ 'action' => 'stream']) }}" target="_blank" class="btn btn-sm d-flex flex-column justify-content-center">
                                            @svg('eye-fill')
                                        </a>
                                    @else
                                        <button
                                            class="btn btn-sm d-flex flex-column justify-content-center"
                                            data-bs-toggle="tooltip" data-bs-title="This camera is not currently connected."
                                        >
                                            @svg('eye-slash-fill')
                                        </button>
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    @else
                        <li class="list-group-item text-center">
                            All cameras were assigned to this printer.
                        </li>
                    @endif
                </ul>
            </div>
        </div>

        <div class="col-12 col-md-6 mt-2 mt-md-0">
            <div class="card">
                <div class="card-header">
                    Assigned cameras
                </div>
                <ul class="list-group list-group-flush">
                    @if (count($assignedCameras) > 0)
                        @foreach ($assignedCameras as $camera)
                            <li class="list-group-item d-flex">
                                <span class="d-flex flex-fill flex-column justify-content-center overflow-scroll text-truncate no-scrollbar">
                                    Local camera at {{ $camera->node }} ({{ $camera->format }})
                                </span>
                                <div class="d-flex justify-content-center">
                                    <button wire:click="remove('{{ $camera->_id }}')" class="btn btn-sm border-danger text-danger mx-2 my-auto">
                                        <div class="m-0"> @svg('dash') </div>
                                    </button>

                                    <button
                                        wire:click="toggleRecordable('{{ $camera->_id }}')"
                                        class="
                                            btn btn-sm mx-2 my-auto
                                            {{ $camera->recordable ? 'border-danger text-danger' : 'border-secondary text-secondary' }}
                                        "
                                        data-bs-toggle="tooltip"
                                        data-bs-title="Whether this camera should be recorded.
                                            @if (!$camera->connected)
                                                This camera isn't connected and it'll be skipped regardless of this setting.
                                            @endif
                                        "
                                    >
                                        <div class="m-0">
                                            @svg('record-circle-fill')
                                        </div>
                                    </button>

                                    @if ($camera->connected)
                                        <a href="{{ $camera->url . '?' . http_build_query( data: [ 'action' => 'stream']) }}" target="_blank" class="btn btn-sm d-flex flex-column justify-content-center">
                                            @svg('eye-fill')
                                        </a>
                                    @else
                                        <button
                                            class="btn btn-sm d-flex flex-column justify-content-center"
                                            data-bs-toggle="tooltip" data-bs-title="This camera is not currently connected."
                                        >
                                            @svg('eye-slash-fill')
                                        </button>
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    @else
                        <li class="list-group-item text-center">
                            No cameras are currently assigned.
                        </li>
                    @endif
                </ul>
            </div>
        </div>
    </div>
</div>