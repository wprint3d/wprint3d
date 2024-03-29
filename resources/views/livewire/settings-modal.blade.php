<div>
    {{-- NOTE: $availablePanes is defined within the component's blueprint (SettingsModal.php). --}}

    <div wire:ignore.self id="settingsModal" class="modal fade" tabindex="-1">
        <div class="modal-dialog modal-fullscreen">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"> Settings </h5>
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
                                            id="{{ $paneName }}-tab"
                                            data-bs-toggle="tab"
                                            data-bs-target="#{{ $paneName }}-tab-pane"
                                            type="button"
                                            role="tab"
                                            aria-controls="{{ $paneName }}-tab-pane"
                                            aria-selected="{{ $loop->first ? 'true' : 'false' }}"
                                            onclick="initialize('{{ 'settings-modal-' . $paneName }}')"
                                        >
                                            {{ Str::ucfirst($paneName) }}
                                        </a>
                                    </li>
                                @endforeach
                            </ul>
                        </div>

                        <div class="col-12 col-lg-10">
                            <div class="tab-content pt-3 pt-lg-0 px-1">
                                @foreach ($availablePanes as $paneName)
                                    <div
                                        class="tab-pane fade @if ($loop->first) active show @endif"
                                        id="{{ $paneName  }}-tab-pane"
                                        role="tabpanel"
                                        aria-labelledby="{{ $paneName }}-tab"
                                        tabindex="0"
                                    >
                                        @if ($isPrinting)
                                        <div class="alert alert-warning text-center" role="alert">
                                            Please note that some of your printers are currently working on a print job. Any changes done to these printers will be applied after their current job is finished.
                                        </div>
                                        @endif

                                        @if (!$writeable)
                                        <div class="alert alert-info text-center" role="alert">
                                            The access level assigned to your account is <span class="fw-bold">{{ UserRole::getDescription( (int) $role) }}</span> which is not allowed to change settings. <br class="d-xl-none">
                                            <br class="d-xl-none">
                                            If you think this is an error, please contact the administrator of this instance.
                                        </div>
                                        @endif

                                        @livewire('settings-modal-' . $paneName, [ 'writeable' => $writeable ], key('settings-modal-' . $paneName))
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
