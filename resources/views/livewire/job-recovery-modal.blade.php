<div>
    {{-- NOTE: $availablePanes is defined within the component's blueprint (SettingsModal.php). --}}

    <div id="jobRecoveryModal" class="modal fade" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false" aria-modal="true" role="dialog">
        <div class="modal-dialog modal-xl modal-fullscreen-xl-down">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"> Print job recovery </h5>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-12">
                            <p class="text-center m-0">
                                Unfortunately, it looks like the previously queued print job has failed. <br>
                                <br>
                                Would you like to restore this job from the latest backup?
                            </p>
                        </div>

                        <div class="col-12"> <hr> </div>

                        <div class="col-12">
                            <h6 class="text-center mb-3"> What does your print look like? </h6>
                            <p class="text-center">
                                Select the side that looks closer to the current status of the failed print.
                            </p>
                        </div>

                        <div class="col-12 col-sm-6">
                            <div class="row">
                                <canvas id="recoveryPreviewMainOption" class="preview-canvas col-12"></canvas>

                                <div class="col-12 mt-2 form-check d-flex justify-content-center">
                                    <input wire:model.defer="targetRecoveryLine" name="targetRecoveryLine" type="radio" class="form-check-input mx-2" value="{{ $recoveryMainMaxLine }}">
                                    Continue from line {{ $recoveryMainMaxLine }}
                                </div>
                            </div>
                        </div>

                        <hr class="d-block d-sm-none mt-2">

                        <div class="col-12 col-sm-6">
                            <div class="row">
                                <canvas id="recoveryPreviewAltOption" class="preview-canvas col-12"></canvas>

                                <div class="col-12 mt-2 form-check d-flex justify-content-center">
                                    <input wire:model.defer="targetRecoveryLine" name="targetRecoveryLine" type="radio" class="form-check-input mx-2" value="{{ $recoveryAltMaxLine }}">
                                    Continue from line {{ $recoveryAltMaxLine }}
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button
                        wire:click="skip"
                        id="skipRecoveryBtn"
                        type="button"
                        class="btn btn-secondary"
                        data-bs-dismiss="modal"
                        wire:loading.class="disabled"
                    >
                        <div wire:loading>
                            <span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>
                            <span class="visually-hidden"> Loading... </span>
                        </div>

                        Skip
                    </button>
                    <button
                        wire:click="recover"
                        type="button"
                        class="btn btn-primary"
                        wire:loading.class="disabled"
                    >
                        <div wire:loading>
                            <span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>
                            <span class="visually-hidden"> Loading... </span>
                        </div>

                        Recover
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div id="jobNoRecoveryModal" class="modal fade" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false" aria-modal="true" role="dialog">
        <div class="modal-dialog modal-lg modal-fullscreen-md-down">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"> Print job failed </h5>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-12">
                            <p class="text-center m-0">
                                Unfortunately, it looks like the previously queued print job has failed and your current settings prevent the software from taking backups. <br>
                                <br>
                                Please, consider enabling this feature later on.
                            </p>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button
                        wire:click="skip"
                        type="button"
                        class="btn btn-primary"
                        data-bs-dismiss="modal"
                        wire:loading.class="disabled"
                    >
                        <div wire:loading>
                            <span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>
                            <span class="visually-hidden"> Loading... </span>
                        </div>

                        Abort
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

@if ($printer)
    @push('scripts')
    <script>

    let activeFile        = @json( $printer->activeFile ),
        hasActiveJob      = @json( $printer->hasActiveJob ),
        lastJobHasFailed  = @json( $printer->lastJobHasFailed ),
        lastLine          = @json( $printer->lastLine ),
        mainMaxLine       = @json( $recoveryMainMaxLine ),
        altMaxLine        = @json( $recoveryAltMaxLine ),
        jobBackupInterval = @json( $jobBackupInterval );

    const JOB_BACKUP_INTERVALS = @json( $jobBackupIntervals );

    const refreshRecoveryPreview = (canvas, fromIndex, toIndex) => {
        let preview,
            parsedGcode = gcode.slice(fromIndex, toIndex);

        console.log('GCRDY: ',       parsedGcode);
        console.log('FROM INDEX: ',  currentLine);
        console.log('TO INDEX: ',    toIndex);

        preview = GCodePreview.init({
            canvas: canvas,
            topLayerColor:    '#000000',
            lastSegmentColor: '#898989',
            buildVolume: { x: 150, y: 150, z: 150 },
            initialCameraPosition: [ 0, 400, 450 ],
            lineWidth: 3,
            debug: false
        });

        preview.renderExtrusion = showExtrusion;
        preview.renderTravel    = showTravel;

        preview.render();

        if (parsedGcode.length > 0) {
            preview.processGCode( parsedGcode.join('\n') );
            preview.render();
        }

        console.log(preview);
    };

    let respawnModal = false;
    
    window.addEventListener('DOMContentLoaded', () => {
        console.debug('Recovery mode logic triggered.');

        let jobRecoveryModal = new bootstrap.Modal(
            document.querySelector('#jobRecoveryModal')
        );

        let jobNoRecoveryModal = new bootstrap.Modal(
            document.querySelector('#jobNoRecoveryModal')
        );

        if (
            activeFile
            &&
            (!hasActiveJob || lastJobHasFailed)
        ) {
            if (jobBackupInterval == JOB_BACKUP_INTERVALS.NEVER) {
                jobNoRecoveryModal.show();
            } else {
                jobRecoveryModal.show();
            }
        }

        Echo.private(`failed-job.${getSelectedPrinterId()}`)
            .listen('PrintJobFailed', event => {
                console.debug('PrintJobFailed:', event);

                activeFile        = event.activeFile;
                hasActiveJob      = true;
                lastJobHasFailed  = true;
                lastLine          = event.lastLine;
                mainMaxLine       = 0;
                jobBackupInterval = event.jobBackupInterval;

                if (lastLine ) {
                    mainMaxLine = lastLine - 1;
                }

                altMaxLine = mainMaxLine + 1;

                if (jobBackupInterval == JOB_BACKUP_INTERVALS.NEVER) {
                    jobNoRecoveryModal.show();
                } else {
                    jobRecoveryModal.show();
                }
            });

        window.addEventListener('recoveryCompleted', () => {
            respawnModal = false;

            jobRecoveryModal.hide();
        });

        window.addEventListener('recoveryFailed', event => {
            respawnModal = true;

            jobRecoveryModal.hide();

            toastify.error(`Unable to recover print job: ${event.detail.toLowerCase()}.`);
        });

        window.addEventListener('recoveryTimedOut',  () => {
            respawnModal = true;

            jobRecoveryModal.hide();

            toastify.error('Timed out waiting for the printer to become available, please, try again later.');
        });

        window.addEventListener('recoveryJobFailedNoPosition', () => {
            respawnModal = false;

            jobRecoveryModal.hide();

            toastify.error('Failed to assert absolute position (not enough context in G-code).');
        });

        document.querySelector('#jobRecoveryModal').addEventListener('shown.bs.modal', () => {
            document.querySelector('input[name="targetRecoveryLine"]').checked = true;

            refreshRecoveryPreview(
                document.querySelector('#recoveryPreviewMainOption'), // canvas
                0,                                                    // fromIndex
                mainMaxLine                                           // toIndex
            );

            refreshRecoveryPreview(
                document.querySelector('#recoveryPreviewAltOption'),  // canvas
                0,                                                    // fromIndex
                altMaxLine                                            // toIndex
            );
        });

        document.querySelector('#jobRecoveryModal').addEventListener('hidden.bs.modal', () => {
            if (respawnModal) {
                jobRecoveryModal.show();
            }
        });

        document.querySelector('#skipRecoveryBtn').addEventListener('click', () => {
            respawnModal = false;
        });
    });

    </script>
    @endpush
@endif