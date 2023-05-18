<div>
    {{-- NOTE: $availablePanes is defined within the component's blueprint (SettingsModal.php). --}}

    <div wire:ignore id="jobRecoveryModal" class="modal fade" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false" aria-modal="true" role="dialog">
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
                            <div id="{{ $uidSideA }}" class="row recovery-preview-side-a">
                                <canvas id="recoveryPreviewMainOption" class="preview-canvas col-12"></canvas>

                                <div class="col-12 mt-2 form-check d-flex justify-content-center">
                                    <input wire:model.defer="targetRecoveryLine" name="targetRecoveryLine" type="radio" class="form-check-input mx-2" value="{{ $recoveryMainMaxLine }}" checked>
                                    Continue from line <span class="mx-1">{{ $recoveryMainMaxLine }}</span>
                                </div>
                            </div>
                        </div>

                        <hr class="d-block d-sm-none mt-2">

                        <div class="col-12 col-sm-6">
                            <div id="{{ $uidSideB }}" class="row recovery-preview-side-b">
                                <canvas id="recoveryPreviewAltOption" class="preview-canvas col-12"></canvas>

                                <div class="col-12 mt-2 form-check d-flex justify-content-center">
                                    <input wire:model.defer="targetRecoveryLine" name="targetRecoveryLine" type="radio" class="form-check-input mx-2" value="{{ $recoveryAltMaxLine }}">
                                    Continue from line <span class="mx-1">{{ $recoveryAltMaxLine }}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer justify-content-between">
                    <div class="col-4 col-sm-3">
                        <div class="progress d-none">
                            <div
                                id="recoveryProgress"
                                class="progress-bar progress-bar-striped progress-bar-animated"
                                role="progressbar"
                                aria-label="Recovery progress"
                                aria-valuenow="0"
                                aria-valuemin="0"
                                aria-valuemax="100"
                            ></div>
                        </div>
                    </div>
                    <div id="recoveryStage" class="col text-center"></div>
                    <div>
                        <button
                            wire:click="skip"
                            id="skipRecoveryBtn"
                            type="button"
                            class="btn btn-secondary"
                            data-bs-dismiss="modal"
                            wire:loading.attr="disabled"
                            wire:target="recover"
                        >
                            <div wire:loading>
                                <span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>
                                <span class="visually-hidden"> Loading... </span>
                            </div>

                            Skip
                        </button>
                        <button
                            wire:click="recover"
                            id="recoverBtn"
                            type="button"
                            class="btn btn-primary"
                            wire:loading.attr="disabled"
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
    </div>

    <div id="jobNoRecoveryModal" class="modal fade" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false" aria-modal="true" role="dialog">
        <div class="modal-dialog modal-md modal-fullscreen-md-down">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"> Print job failed </h5>
                </div>
                <div class="modal-body d-flex d-md-block align-items-center">
                    <div class="row">
                        <div class="col-12">
                            <p class="text-center m-0">
                                Unfortunately, it looks like the queued print job has failed and your current settings prevent the software from taking backups. <br>
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

                        Got it
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
    const JOB_RECOVERY_STAGES  = @json( $jobRecoveryStages );

    let respawnModal       = false,
        handleBufferEvents = true;

    window.addEventListener('DOMContentLoaded', () => {
        let jobRecoveryModal = new bootstrap.Modal(
            document.querySelector('#jobRecoveryModal')
        );

        let jobNoRecoveryModal = new bootstrap.Modal(
            document.querySelector('#jobNoRecoveryModal')
        );

        let recoveryStage    = document.querySelector('#recoveryStage'),
            recoveryProgress = document.querySelector('#recoveryProgress'),
            skipRecoveryBtn  = document.querySelector('#skipRecoveryBtn'),
            recoverBtn       = document.querySelector('#recoverBtn');

        const resetDynamicElements = () => {
            recoveryStage.innerText = '';

            recoveryProgress.style.width = 0 + '%'
            recoveryProgress.setAttribute('aria-valuenow', 0);

            recoveryProgress.parentElement.classList.add('d-none');
        }

        const refreshRecoveryPreview = (canvas, fromIndex, toIndex, mainOption) => {
            console.log('FROM INDEX: ',  currentLine);
            console.log('TO INDEX: ',    toIndex);

            let uid = canvas.parentElement.id;

            let preview = GCodePreview.init({
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

            console.log(preview);

            recoveryStage.innerText = 'Loading previews...';
            recoveryProgress.parentElement.classList.remove('d-none');

            Echo.private(`preview.${getSelectedPrinterId()}`)
                .listen('PreviewLineReady', event => {
                    console.debug('PreviewLineReady: ', event);

                    if (!preview || event.previewUID != uid || !handleBufferEvents) return;

                    recoveryStage.innerText = 'Loading previews... ' + (canvas.parentElement.classList.contains('recovery-preview-side-a') ? ' (main)' : ' (alternative)');

                    recoveryProgress.style.width = event.percentage + '%'
                    recoveryProgress.setAttribute('aria-valuenow', event.percentage);

                    event.command.split(PHP_EOL).forEach(line => {
                        command = parseMovement( line );

                        if (preview && command) {
                            preview.parser.parseGCode(command);
                        }
                    });
                });

            Echo.private(`preview.${getSelectedPrinterId()}`)
                .listen('PreviewBuffered', event => {
                    console.debug('PreviewBuffered: ', event);

                    if (!preview || event.previewUID != uid || !handleBufferEvents) return;

                    preview.render();

                    if (event.previewUID == @json( $uidSideB )) {
                        resetDynamicElements();
                    }
                });
        };

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

                radios = document.querySelectorAll('input[name="targetRecoveryLine"]');

                radios[0].value = mainMaxLine;
                radios[0].parentElement.querySelector('span').innerText = mainMaxLine;

                radios[1].value = altMaxLine;
                radios[1].parentElement.querySelector('span').innerText = altMaxLine;

                if (jobBackupInterval == JOB_BACKUP_INTERVALS.NEVER) {
                    jobNoRecoveryModal.show();
                } else {
                    jobRecoveryModal.show();
                }
            });

        Echo.private(`recovery-stage-changed.${getSelectedPrinterId()}`)
            .listen('RecoveryStageChanged', event => {
                console.debug('RecoveryStageChanged:', event);

                if (event.stage == JOB_RECOVERY_STAGES.COUNT_LINES) {
                    recoveryStage.innerText = 'Counting lines...';
                } else if (event.stage == JOB_RECOVERY_STAGES.PARSE_FILE) {
                    recoveryStage.innerText = 'Parsing file...';
                }
            });

        Echo.private(`recovery-progress.${getSelectedPrinterId()}`)
            .listen('RecoveryProgress', event => {
                console.debug('RecoveryProgress:', event);

                recoveryProgress.style.width = event.percentage + '%'
                recoveryProgress.setAttribute('aria-valuenow', event.percentage);
            });

        Echo.private(`recovery-completed.${getSelectedPrinterId()}`)
            .listen('RecoveryCompleted', event => {
                console.debug('RecoveryCompleted:', event);

                resetDynamicElements();

                respawnModal = false;

                jobRecoveryModal.hide();
            });

        window.addEventListener('recoveryFailed', event => {
            resetDynamicElements();

            respawnModal = true;

            jobRecoveryModal.hide();

            toastify.error(`Unable to recover print job: ${event.detail.toLowerCase()}.`);
        });

        window.addEventListener('recoveryTimedOut',  () => {
            resetDynamicElements();

            respawnModal = true;

            jobRecoveryModal.hide();

            toastify.error('Timed out waiting for the printer to become available, please, try again later.');
        });

        window.addEventListener('recoveryJobFailedNoPosition', event => {
            resetDynamicElements();

            respawnModal = false;

            jobRecoveryModal.hide();

            toastify.error( event.detail );
        });

        document.querySelector('#jobRecoveryModal').addEventListener('shown.bs.modal', () => {
            console.debug('Recovery mode logic triggered.');

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

            Livewire.emit('renderRecoveryGcode');
        });

        document.querySelector('#jobRecoveryModal').addEventListener('hidden.bs.modal', () => {
            handleBufferEvents = true;

            if (respawnModal) {
                jobRecoveryModal.show();
            }
        });

        skipRecoveryBtn.addEventListener('click', () => { respawnModal = false; });

        recoverBtn.addEventListener('click', () => {
            handleBufferEvents = false;

            recoveryStage.innerText = 'Waiting for server...';

            recoveryProgress.parentElement.classList.remove('d-none');
        });
    });

    </script>
    @endpush
@endif
