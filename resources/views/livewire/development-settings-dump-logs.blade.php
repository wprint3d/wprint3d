<div class="col-12 col-xxl-6">
    <div class="row g-0 border rounded overflow-hidden flex-md-row mb-4 shadow-sm h-md-250 position-relative">
        <div class="col p-4 d-flex flex-column position-static">
            <h3 class="mb-0"> Dump recent logs </h3>

            <div class="mb-1 text-body-secondary">
                Core
            </div>

            <p class="card-text mb-auto">
                Dumps all system logs from today and yesterday into a .ZIP file and returns the file to be downloaded by the browser.
            </p>

            <div class="row mx-0 pt-1 justify-content-end">
                <button wire:click="dump" class="btn btn-primary btn-block col-auto" type="button" wire:loading.attr="disabled">
                    <span wire:loading>
                        Preparing…
                    </span>

                    <span wire:loading.remove>
                        Download
                    </span>
                </button>
            </div>
        </div>
    </div>
</div>