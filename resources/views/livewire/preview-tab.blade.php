<div>
    <div wire:ignore>
        <canvas id="gcodePreviewCanvas" class="preview-canvas"></canvas>

        <div id="previewNoFileLoadedAlert" class="alert alert-info text-center mt-2 d-none" role="alert">
            No file has been loaded, start a print in order to preview it.
        </div>

        <div wire:ignore id="previewLoader" class="pt-2 px-3">
            <div class="progress">
                <div
                    class="progress-bar progress-bar-striped progress-bar-animated"
                    role="progressbar"
                    aria-label="Preview load progress"
                    aria-valuenow="0"
                    aria-valuemin="0"
                    aria-valuemax="100"
                    style="width: 0%"
                ></div>
            </div>

            <p class="w-100 text-center mt-2"></p>
        </div>
    </div>

    <div id="selectedLayerContainer" class="d-none border p-3 mt-1 mb-2 rounded rounded-2 text-center bg-light">
        <div wire:ignore class="row">
            <div class="col" x-data="{ layer: 0 }">
                <label class="form-label"> Shown layer </label>

                <input
                    x-model="layer"
                    id="selectedLayer"
                    type="range"
                    class="form-range"
                    min="1"
                    step="1"
                    wire:loading.attr="disabled"
                >
                <span x-text="layer"></span>
            </div>
        </div>

        <div wire:ignore class="row mt-2">
            <div class="col">
                <button
                    id="resumeLiveFeedBtn"
                    type="button"
                    class="btn btn-primary btn-sm d-none"
                    onclick="resumeLiveFeed()"
                    wire:loading.attr="disabled"
                >
                    @svg('play-fill') Go back to live feed
                </button>
            </div>
        </div>
    </div>

    <ul class="list-group">
        <li class="list-group-item">
            <input id="showExtrusionCheck" wire:model.live="showExtrusion" class="form-check-input me-1" type="checkbox">
            <label class="form-check-label"> Show extrusion  </label>
        </li>
        <li class="list-group-item">
            <input id="showTravelCheck" wire:model.live="showTravel" class="form-check-input me-1" type="checkbox">
            <label class="form-check-label"> Show travel </label>
        </li>
    </ul>
</div>

@push('scripts')
<script>

let preview = null;

let bufferedLines = [];

let currentLine  = 0,
    selectedLine = null,
    uid          = null;

let showExtrusion = true;
let showTravel    = true;

let autoUpdatePreview = true;

let isInteracting = false,
    isSelected    = false;

let selectedLayer            = document.querySelector('#selectedLayer'),
    selectedLayerContainer   = document.querySelector('#selectedLayerContainer'),
    resumeLiveFeedBtn        = document.querySelector('#resumeLiveFeedBtn'),
    previewLoader            = document.querySelector('#previewLoader'),
    progressBar              = previewLoader.querySelector('.progress-bar'),
    progressLabel            = previewLoader.querySelector('p'),
    previewNoFileLoadedAlert = document.querySelector('#previewNoFileLoadedAlert');

let layerMap = [];

let isBuffering = true;

const WHEEL_RESET_TIME_MS = 2500;

const isTabActive = () => (
    !!document.querySelector('#preview-tab.active')
);

const parseMovement = line => {
    let parsed = line.trim().replace('> ', '');

    if (parsed[0] == 'G') return parsed;

    return null;
};

const refreshPreview = () => {
    console.log('CRLN: ', currentLine);

    let canvas = document.querySelector('#gcodePreviewCanvas');

    let wheelInteractionHandler = null;

    canvas.addEventListener('wheel', event => {
        console.log('wheel');

        isInteracting = true;

        if (wheelInteractionHandler) {
            clearTimeout( wheelInteractionHandler );

            wheelInteractionHandler = null;
        }

        wheelInteractionHandler = setTimeout(() => {
            if (!isSelected) { isInteracting = false; }
        }, WHEEL_RESET_TIME_MS);
    });

    [ 'touchstart', 'mousedown' ].forEach(eventName => {
        canvas.addEventListener(eventName, event => {
            console.log(eventName);

            isInteracting = true;
            isSelected    = true;
        });
    });

    [ 'touchend', 'mouseup' ].forEach(eventName => {
        canvas.addEventListener(eventName, event => {
            console.log(eventName);

            isInteracting = false;
            isSelected    = false;

            preview.render();
        });
    });

    let previewOptions = {
        canvas: canvas,
        topLayerColor:    '#000000',
        lastSegmentColor: '#898989',
        buildVolume: { x: 150, y: 150, z: 150 },
        initialCameraPosition: [ 0, 400, 450 ],
        lineWidth: 3,
        renderTubes: true,
        debug: true
    };

    let currentTheme = document.querySelector('#currentTheme').innerText.trim();

    if (
        currentTheme == THEME_OPTIONS.DARK
        ||
        (currentTheme.length == 0 && prefersDarkTheme())
    ) {
        previewOptions['backgroundColor'] = 'black';
    }

    preview = GCodePreview.init(previewOptions);

    preview.renderExtrusion = showExtrusion;
    preview.renderTravel    = showTravel;

    preview.render();
};

window.addEventListener('resize', () => {
    if (preview) {
        preview.resize();
    }
});

window.addEventListener('initializePreviewTab', event => {
    console.debug('initializePreviewTab');

    previewNoFileLoadedAlert.classList.add('d-none');

    isBuffering = true;

    let canvas = document.querySelector('#gcodePreviewCanvas');

    if (canvas) {
        let newCanvas    = document.createElement('canvas');
            newCanvas.id = 'gcodePreviewCanvas';
            newCanvas.classList = [ 'preview-canvas' ];

        canvas.replaceWith( newCanvas );
    }

    refreshPreview(canvas);

    reloadPreviewFromServer();
});

const resetRender = () => {
    if (document.querySelector('#preview-tab').classList.contains('active')) {
        let canvas = document.querySelector('#gcodePreviewCanvas');

        refreshPreview(canvas);
    }
};

const getNearestLayer = lineNumber => {
    let layerIndex = 1;

    for (let index = lineNumber - 1; index > 0; index--) {
        layerIndex = layerMap.indexOf( index )

        if (layerIndex > -1) break;
    }

    return layerIndex;
}

const reloadPreviewFromServer = (mapLayers = true) => {
    selectedLayerContainer.classList.add('d-none');
    previewLoader.classList.remove('d-none');

    progressBar.style.width = 0 + '%'
    progressBar.setAttribute('aria-valuenow', 0);

    progressLabel.innerText = (
        mapLayers
            ? 'Mapping layers...'
            : 'Buffering G-code...'
    );

    Livewire.dispatch('reloadPreviewFromServer', {
        uid:          uid,
        selectedLine: selectedLine,
        mapLayers:    mapLayers
    });
}

window.addEventListener('DOMContentLoaded', () => {
    currentLine = @this.currentLine;
    uid         = @this.uid;

    showExtrusion = @this.showExtrusion;
    showTravel    = @this.showTravel;

    window.matchMedia('(prefers-color-scheme: light)').addListener(() => {
        if (!isTabActive()) { return; }

        Livewire.dispatch('initializePreviewTab');
    });

    window.matchMedia('(prefers-color-scheme: dark)').addListener(() => {
        if (!isTabActive()) { return; }

        Livewire.dispatch('initializePreviewTab');
    });

    window.addEventListener('themeReloaded', () => {
        if (!isTabActive()) { return; }

        Livewire.dispatch('initializePreviewTab');
    });

    window.addEventListener('refreshSettings', event => {
        if (
            event.detail.showExtrusion  != showExtrusion
            ||
            event.detail.showTravel     != showTravel
        ) {
            showExtrusion = event.detail.showExtrusion;
            showTravel    = event.detail.showTravel;

            resetRender();
        }
    });

    selectedLayer.addEventListener('change', event => {
        console.debug('Auto updates disabled due to manual layer selection.');

        resumeLiveFeedBtn.classList.remove('d-none');

        resetRender();

        autoUpdatePreview = false;

        currentLine  = layerMap[ event.target.value - 1 ];
        selectedLine = currentLine;

        refreshPreview();

        reloadPreviewFromServer(
            false // mapLayers
        );
    });

    window.resumeLiveFeed = () => {
        console.debug('Auto updates have been re-enabled.');

        resumeLiveFeedBtn.classList.add('d-none');

        autoUpdatePreview = true;

        selectedLine = null;

        resetRender();
        refreshPreview();

        reloadPreviewFromServer(
            false // mapLayers
        );

        // selectedLayer.value = getNearestLayer( realCurrentLine );
        // selectedLayer.dispatchEvent( new Event('input') );
    };

    Echo.private(`console.${getSelectedPrinterId()}`)
        .listen('PrinterTerminalUpdated', event => {
            console.debug(event);

            if (!preview || !autoUpdatePreview) return true;

            if (event.line) {
                event.command.split(PHP_EOL).forEach(line => {
                    command = parseMovement( line );

                    if (!command) return;

                    if (isBuffering) {
                        bufferedLines.push(command);
                    } else if (preview && command) {
                        if (!isBuffering && bufferedLines.length) {
                            bufferedLines.forEach(line => {
                                preview.parser.parseGCode(line);
                            });
                        }

                        bufferedLines = [];

                        preview.parser.parseGCode(command);

                        if (!isInteracting) {
                            preview.render();
                        }
                    }
                });

                currentLine = event.line;
            }

            return true;
        });

    Echo.private(`preview.${getSelectedPrinterId()}`)
        .listen('PreviewLayerMapReady', event => {
            console.debug('PreviewLayerMapReady: ', event);

            if (!preview || event.previewUID != uid) return true;

            layerMap = event.layerMap;

            progressLabel.innerText = 'Buffering G-code...';

            selectedLayer.max   = layerMap.length;
            selectedLayer.value = 1;
            selectedLayer.dispatchEvent( new Event('input') );

            resetRender();

            return true;
        });

    Echo.private(`preview.${getSelectedPrinterId()}`)
        .listen('PreviewLineReady', event => {
            console.debug('PreviewLineReady: ', event);

            if (!preview || event.previewUID != uid) return true;

            progressBar.style.width = event.percentage + '%'
            progressBar.setAttribute('aria-valuenow', event.percentage);

            event.command.split(PHP_EOL).forEach(line => {
                if (preview) {
                    console.log('pgc:', preview.parser.parseGCode(line));
                }
            });

            currentLine = event.line;

            return true;
        });

    Echo.private(`preview.${getSelectedPrinterId()}`)
        .listen('PreviewBuffered', event => {
            console.debug('PreviewBuffered: ', event);

            if (!preview || event.previewUID != uid) return true;

            previewLoader.classList.add('d-none');

            isBuffering = false;

            preview.render();

            selectedLayer.value = getNearestLayer( currentLine );
            selectedLayer.dispatchEvent( new Event('input') );

            selectedLayerContainer.classList.remove('d-none');

            return true;
        });

    window.addEventListener('previewNoFileLoaded', event => {
        resetRender();

        previewLoader.classList.add('d-none');

        previewNoFileLoadedAlert.classList.remove('d-none');
    });

    window.addEventListener('selectedFileChanged', () => {
        reloadPreviewFromServer();
    });

    [ 'showExtrusionCheck', 'showTravelCheck' ].forEach(id => {
        document.querySelector('#' + id).addEventListener('change', event => {
            resetRender();
            reloadPreviewFromServer(
                false // mapLayers
            );
        });
    });
});

</script>
@endpush