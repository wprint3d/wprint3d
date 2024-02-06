<div>
    {{-- NOTE: $availablePanes is defined within the component's blueprint (PrinterManagerModal.php). --}}

    <div id="printerManagementModal" class="modal modal-xl fade bg-black bg-opacity-50" tabindex="-1">
        <div class="modal-dialog modal-fullscreen-xl-down">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"> Manage printer </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-12 col-lg-2">
                            <ul class="nav nav-pills nav-fill flex-row flex-lg-column">
                                @foreach ($availablePanes as $paneName)
                                    <li class="nav-item">
                                        <a
                                            class="nav-link @if ($loop->first) active @endif"
                                            aria-current="page"
                                            href="#"
                                            id="printer-{{ $paneName }}-tab"
                                            data-bs-toggle="tab"
                                            data-bs-target="#printer-{{ $paneName }}-tab-pane"
                                            type="button"
                                            role="tab"
                                            aria-controls="printer-{{ $paneName }}-tab-pane"
                                            aria-selected="{{ $loop->first ? 'true' : 'false' }}"
                                        >
                                            {{ Str::ucfirst($paneName) }}
                                        </a>
                                    </li>
                                @endforeach
                            </ul>
                        </div>

                        <div class="col-12 col-lg-10">
                            <div class="tab-content pt-3 pt-lg-0 px-1 text-start">
                                @if (!$writeable)
                                <div class="alert alert-info text-center" role="alert">
                                    The access level assigned to your account doesn't let you change settings. <br>
                                    <br>
                                    If you think this is an error, please contact the administrator of this instance.
                                </div>
                                @endif

                                @foreach ($availablePanes as $paneName)
                                    <div
                                        class="tab-pane fade @if ($loop->first) active show @endif"
                                        id="printer-{{ $paneName  }}-tab-pane"
                                        role="tabpanel"
                                        aria-labelledby="printer-{{ $paneName }}-tab"
                                        tabindex="0"
                                    >
                                        @livewire(
                                            'printer-manager-modal-' . $paneName,
                                            [ 'printer' => $printer, 'writeable' => $writeable ],
                                            key( 'printer-manager-modal-' . $paneName . '-' . time() )
                                        )
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    {{-- <button type="button" class="btn btn-primary">Save changes</button> --}}
                </div>
            </div>
        </div>
    </div> 
</div>