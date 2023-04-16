<div>
    <canvas id="gcodePreviewCanvas" class="preview-canvas"></canvas>

    <div id="selectedLayerContainer" class="d-none border p-3 mt-1 mb-2 rounded rounded-2 text-center bg-white">
        <div class="row">
            <div class="col" x-data="{ layer: 0 }">
                <label class="form-label"> Shown layer </label>

                <input
                    x-model="layer"
                    id="selectedLayer"
                    type="range"
                    class="form-range"
                    min="1"
                    step="1"
                >
                <span x-text="layer"></span>
            </div>
        </div>

        <div class="row mt-2">
            <div class="col">
                <button id="resumeLiveFeedBtn" type="button" class="btn btn-primary btn-sm d-none" onclick="resumeLiveFeed()">
                    @svg('play') Go back to live feed
                </button>
            </div>
        </div>
    </div>

    <ul class="list-group">
        <li class="list-group-item">
          <input wire:model="showExtrusion" class="form-check-input me-1" type="checkbox">
          <label class="form-check-label"> Show extrusion  </label>
        </li>
        <li class="list-group-item">
          <input wire:model="showTravel" class="form-check-input me-1" type="checkbox">
          <label class="form-check-label"> Show travel </label>
        </li>
    </ul>
</div>

@push('scripts')
<script>

let preview = null;

let gcode           = [];
let currentLine     = 0;
let realCurrentLine = 0;

let showExtrusion = true;
let showTravel    = true;

let autoUpdatePreview = true;

let selectedLayer           = document.querySelector('#selectedLayer');
let selectedLayerContainer  = document.querySelector('#selectedLayerContainer');
let resumeLiveFeedBtn       = document.querySelector('#resumeLiveFeedBtn');

let layerMap = [];

const parseMovement = line => {
    let parsed = line.trim().replace('> ', '');

    if (parsed[0] == 'G') return parsed;

    return null;
};

const refreshPreview = () => {
    console.log('GCRDY: ', gcode);
    console.log('CRLN: ',  currentLine);

    let canvas = document.querySelector('#gcodePreviewCanvas');

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

    if (gcode.length > 0) {
        if (currentLine > 0) {
            preview.processGCode( gcode.slice(0, currentLine).join('\n') );
            preview.render();
        }

        console.log(gcode.length, selectedLayer.max);

        if (
            gcode.length != selectedLayer.max
            &&
            selectedLayerContainer.classList.contains('d-none')
        ) {
            selectedLayerContainer.classList.remove('d-none');
        }
    } else if (!selectedLayerContainer.classList.contains('d-none')) {
        selectedLayerContainer.classList.add('d-none');
    }
};

const mapLayers = () => {
    layerMap = [];

    gcode.forEach((line, index) => {
        if (line.startsWith('G0') || line.startsWith('G1')) {
            let parts = line.split(' ');

            parts.forEach(part => {
                if (part.startsWith('Z')) {
                    part = part.replace('Z', '');

                    layerMap.push( index );
                }
            });
        }
    });

    console.log('layerMap:', layerMap);

    selectedLayer.max   = layerMap.length;
    selectedLayer.value = 1;
    selectedLayer.dispatchEvent( new Event('input') );

    resumeLiveFeedBtn.classList.add ('d-none');
}

window.addEventListener('shown.bs.tab', event => {
    let canvas = document.querySelector('#gcodePreviewCanvas');

    if (canvas) {
        let newCanvas    = document.createElement('canvas');
            newCanvas.id = 'gcodePreviewCanvas';
            newCanvas.classList = [ 'preview-canvas' ];

        canvas.replaceWith( newCanvas );
    }

    refreshPreview(canvas);
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

window.addEventListener('DOMContentLoaded', () => {
    gcode           = @this.gcode;
    currentLine     = @this.currentLine;
    realCurrentLine = currentLine;

    showExtrusion = @this.showExtrusion;
    showTravel    = @this.showTravel;

    mapLayers();

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

    window.addEventListener('gcodePreviewFailedTooLarge', event => {
        console.debug('gcodePreviewFailedTooLarge:', event);

        toastify.info('The G-code preview won\'t be available: the selected file is too large.');
    });

    window.addEventListener('gcodeChanged', event => {
        console.debug('gcodeChanged:', event);

        gcode           = event.detail.gcode;
        currentLine     = event.detail.currentLine;
        realCurrentLine = currentLine;

        resetRender();

        mapLayers();
    });

    selectedLayer.addEventListener('change', event => {
        console.debug('Auto updates disabled due to manual layer selection.');

        resumeLiveFeedBtn.classList.remove('d-none');

        resetRender();

        autoUpdatePreview = false;

        currentLine = layerMap[ event.target.value - 1 ];

        refreshPreview();
    });

    window.resumeLiveFeed = () => {
        console.debug('Auto updates have been re-enabled.');

        resumeLiveFeedBtn.classList.add ('d-none');

        autoUpdatePreview = true;

        currentLine = realCurrentLine;

        resetRender();
        refreshPreview();

        selectedLayer.value = getNearestLayer( realCurrentLine );
        selectedLayer.dispatchEvent( new Event('input') );
    };

    Echo.private(`console.${getSelectedPrinterId()}`)
        .listen('PrinterTerminalUpdated', event => {
            console.debug(event);

            if (event.line) {
                currentLine     = event.line;
                realCurrentLine = currentLine;

                let command = gcode[ event.line - 1 ];

                if (!command) return;

                command = parseMovement( command );

                if (autoUpdatePreview) {
                    if (preview && command) {
                        preview.processGCode(command);
                        preview.render();
                    }

                    if (layerMap.indexOf( realCurrentLine ) > -1) {
                        selectedLayer.value = layerMap.indexOf( realCurrentLine );
                        selectedLayer.dispatchEvent( new Event('input') );
                    }
                }
            }
        });
});

</script>
@endpush