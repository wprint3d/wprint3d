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
                        <li class="list-group-item">
                            Local camera at {{ $camera->node }} ({{ $camera->format }})
                            <a href="{{ $camera->url }}" target="_blank" class="btn btn-sm">
                                @svg('eye-open')
                            </a>
                            <div class="d-flex end-0 h-100 position-absolute top-0">
                                <button wire:click="add('{{ $camera->_id }}')" class="btn btn-sm border-success text-success mx-2 my-auto">
                                    <div class="m-0"> @svg('plus-sign') </div>
                                </button>
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

        <div class="col-12 col-md-6 mt-2 mt-sm-0">
            <div class="card">
                <div class="card-header">
                    Assigned cameras
                </div>
                <ul class="list-group list-group-flush">
                    @if (count($assignedCameras) > 0)
                        @foreach ($assignedCameras as $camera)
                            <li class="list-group-item">
                                Local camera at {{ $camera->node }} ({{ $camera->format }})
                                <a href="{{ $camera->url }}" target="_blank" class="btn btn-sm">
                                    @svg('eye-open')
                                </a>
                                <div class="d-flex end-0 h-100 position-absolute top-0">
                                    <button wire:click="remove('{{ $camera->_id }}')" class="btn btn-sm border-success text-danger mx-2 my-auto">
                                        <div class="m-0"> @svg('minus-sign') </div>
                                    </button>
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