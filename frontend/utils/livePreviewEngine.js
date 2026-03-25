const DEFAULT_OPTIONS = {
    maxWarmLayers: 8,
    maxColdSegments: 512,
    simplifyTolerance: 0.05,
    driftTolerance: 2,
};

const ARC_SEGMENT_LENGTH = 2;
const MIN_SEGMENT_DURATION_MS = 16;

function createPosition(seed = {}) {
    return {
        x: seed.x ?? 0,
        y: seed.y ?? 0,
        z: seed.z ?? 0,
        e: seed.e ?? 0,
    };
}

function clonePosition(position) {
    return createPosition(position);
}

function positionsEqual(a, b) {
    return a.x === b.x && a.y === b.y && a.z === b.z && a.e === b.e;
}

function distance3d(a, b) {
    const dx = (b.x ?? 0) - (a.x ?? 0);
    const dy = (b.y ?? 0) - (a.y ?? 0);
    const dz = (b.z ?? 0) - (a.z ?? 0);

    return Math.sqrt((dx * dx) + (dy * dy) + (dz * dz));
}

function lerpPosition(a, b, progress) {
    return {
        x: a.x + ((b.x - a.x) * progress),
        y: a.y + ((b.y - a.y) * progress),
        z: a.z + ((b.z - a.z) * progress),
        e: a.e + ((b.e - a.e) * progress),
    };
}

function parseValue(rawValue) {
    const parsed = Number.parseFloat(rawValue);
    return Number.isFinite(parsed) ? parsed : null;
}

function parseLine(rawLine) {
    const line = rawLine
        .replace(/^>\s*/, "")
        .replace(/;.*$/, "")
        .trim();

    if (!line.length) {
        return null;
    }

    const [head, ...parts] = line.split(/\s+/);
    const command = head.toUpperCase();
    const params = {};

    for (const part of parts) {
        if (!part.length) {
            continue;
        }

        const key = part[0].toUpperCase();
        const value = parseValue(part.slice(1));

        if (value === null) {
            continue;
        }

        params[key] = value;
    }

    return { command, params, raw: line };
}

function buildNextPosition(state, params) {
    const next = clonePosition(state.position);

    for (const axis of ["X", "Y", "Z"]) {
        if (!(axis in params)) {
            continue;
        }

        const key = axis.toLowerCase();
        next[key] = state.motionMode === "relative"
            ? next[key] + params[axis]
            : params[axis];
    }

    if ("E" in params) {
        next.e = state.extrusionMode === "relative"
            ? next.e + params.E
            : params.E;
    }

    return next;
}

function toSegment(lineNumber, layer, tool, feedrate, from, to) {
    const extrusionDelta = (to.e ?? 0) - (from.e ?? 0);
    const kind = extrusionDelta > 0 ? "extrusion" : "travel";
    const distance = distance3d(from, to);
    const effectiveFeedrate = feedrate > 0 ? feedrate : 1200;
    const durationMs = distance === 0
        ? 0
        : Math.max((distance / (effectiveFeedrate / 60)) * 1000, MIN_SEGMENT_DURATION_MS);

    return {
        line: lineNumber ?? null,
        layer,
        tool,
        kind,
        feedrate: effectiveFeedrate,
        durationMs,
        extrusionDelta: extrusionDelta > 0 ? extrusionDelta : 0,
        from: clonePosition(from),
        to: clonePosition(to),
    };
}

function computeArcCenter(start, end, params, clockwise) {
    if (params.I !== undefined || params.J !== undefined) {
        return {
            x: start.x + (params.I ?? 0),
            y: start.y + (params.J ?? 0),
        };
    }

    if (params.R === undefined) {
        return null;
    }

    const dx = end.x - start.x;
    const dy = end.y - start.y;
    const chordLength = Math.sqrt((dx * dx) + (dy * dy));
    const radius = Math.abs(params.R);

    if (chordLength === 0 || chordLength > (radius * 2)) {
        return null;
    }

    const midpoint = {
        x: (start.x + end.x) / 2,
        y: (start.y + end.y) / 2,
    };
    const height = Math.sqrt(Math.max((radius * radius) - ((chordLength / 2) ** 2), 0));
    const normal = {
        x: -dy / chordLength,
        y: dx / chordLength,
    };
    const direction = ((clockwise && params.R > 0) || (!clockwise && params.R < 0)) ? -1 : 1;

    return {
        x: midpoint.x + (normal.x * height * direction),
        y: midpoint.y + (normal.y * height * direction),
    };
}

function approximateArcSegments(state, parsedLine, lineNumber) {
    const clockwise = parsedLine.command === "G2";
    const nextPosition = buildNextPosition(state, parsedLine.params);
    const center = computeArcCenter(state.position, nextPosition, parsedLine.params, clockwise);

    if (!center) {
        return [toSegment(lineNumber, state.currentLayer, state.tool, state.feedrate, state.position, nextPosition)];
    }

    const startAngle = Math.atan2(state.position.y - center.y, state.position.x - center.x);
    const endAngle = Math.atan2(nextPosition.y - center.y, nextPosition.x - center.x);
    const radius = Math.sqrt(((state.position.x - center.x) ** 2) + ((state.position.y - center.y) ** 2));
    let delta = clockwise ? startAngle - endAngle : endAngle - startAngle;

    if (delta < 0) {
        delta += Math.PI * 2;
    }

    const arcLength = radius * delta;
    const segmentCount = Math.max(2, Math.ceil(arcLength / ARC_SEGMENT_LENGTH));
    const extrusionDelta = (nextPosition.e ?? 0) - (state.position.e ?? 0);
    const segments = [];
    let previous = clonePosition(state.position);

    for (let index = 1; index <= segmentCount; index += 1) {
        const progress = index / segmentCount;
        const angle = clockwise
            ? startAngle - (delta * progress)
            : startAngle + (delta * progress);

        const next = {
            x: center.x + (Math.cos(angle) * radius),
            y: center.y + (Math.sin(angle) * radius),
            z: state.position.z + ((nextPosition.z - state.position.z) * progress),
            e: state.position.e + (extrusionDelta * progress),
        };

        segments.push(toSegment(
            lineNumber,
            state.currentLayer,
            state.tool,
            state.feedrate,
            previous,
            next
        ));

        previous = next;
    }

    return segments;
}

function ensureLayerChunk(chunks, layer) {
    let existing = chunks.find(chunk => chunk.layer === layer);

    if (!existing) {
        existing = {
            layer,
            segments: [],
            simplified: false,
        };
        chunks.push(existing);
        chunks.sort((left, right) => left.layer - right.layer);
    }

    return existing;
}

function areCollinear(a, b, tolerance) {
    const vectorA = {
        x: a.to.x - a.from.x,
        y: a.to.y - a.from.y,
        z: a.to.z - a.from.z,
    };
    const vectorB = {
        x: b.to.x - b.from.x,
        y: b.to.y - b.from.y,
        z: b.to.z - b.from.z,
    };

    const cross = {
        x: (vectorA.y * vectorB.z) - (vectorA.z * vectorB.y),
        y: (vectorA.z * vectorB.x) - (vectorA.x * vectorB.z),
        z: (vectorA.x * vectorB.y) - (vectorA.y * vectorB.x),
    };

    const crossMagnitude = Math.sqrt((cross.x * cross.x) + (cross.y * cross.y) + (cross.z * cross.z));
    return crossMagnitude <= tolerance;
}

function mergeCollinearSegments(segments, tolerance) {
    if (segments.length <= 1) {
        return segments.map(segment => ({ ...segment }));
    }

    const merged = [];

    for (const segment of segments) {
        const previous = merged[merged.length - 1];

        if (
            previous
            && previous.kind === "extrusion"
            && segment.kind === "extrusion"
            && previous.tool === segment.tool
            && previous.to.x === segment.from.x
            && previous.to.y === segment.from.y
            && previous.to.z === segment.from.z
            && areCollinear(previous, segment, tolerance)
        ) {
            previous.to = clonePosition(segment.to);
            previous.extrusionDelta += segment.extrusionDelta;
            previous.durationMs += segment.durationMs;
            continue;
        }

        merged.push({
            ...segment,
            from: clonePosition(segment.from),
            to: clonePosition(segment.to),
        });
    }

    return merged;
}

function perpendicularDistance(point, start, end) {
    const lineDistance = distance3d(start, end);

    if (lineDistance === 0) {
        return distance3d(point, start);
    }

    const numerator = Math.abs(
        ((end.y - start.y) * point.x)
        - ((end.x - start.x) * point.y)
        + (end.x * start.y)
        - (end.y * start.x)
    );

    return numerator / lineDistance;
}

function simplifyPolyline(points, tolerance) {
    if (points.length <= 2) {
        return points;
    }

    let maxDistance = 0;
    let index = 0;

    for (let current = 1; current < points.length - 1; current += 1) {
        const distance = perpendicularDistance(points[current], points[0], points[points.length - 1]);

        if (distance > maxDistance) {
            maxDistance = distance;
            index = current;
        }
    }

    if (maxDistance <= tolerance) {
        return [points[0], points[points.length - 1]];
    }

    const left = simplifyPolyline(points.slice(0, index + 1), tolerance);
    const right = simplifyPolyline(points.slice(index), tolerance);

    return left.slice(0, -1).concat(right);
}

function simplifyLayerSegments(layerChunk, tolerance) {
    const extrusionOnly = layerChunk.segments.filter(segment => segment.kind === "extrusion");
    const merged = mergeCollinearSegments(extrusionOnly, tolerance);

    if (merged.length <= 2) {
        return {
            layer: layerChunk.layer,
            simplified: true,
            segments: merged,
        };
    }

    const polyline = [merged[0].from, ...merged.map(segment => segment.to)];
    const simplifiedPoints = simplifyPolyline(polyline, tolerance);
    const simplifiedSegments = [];

    for (let index = 1; index < simplifiedPoints.length; index += 1) {
        simplifiedSegments.push({
            ...merged[Math.min(index - 1, merged.length - 1)],
            from: clonePosition(simplifiedPoints[index - 1]),
            to: clonePosition(simplifiedPoints[index]),
            durationMs: merged[Math.min(index - 1, merged.length - 1)].durationMs,
            extrusionDelta: Math.max(
                merged[Math.min(index - 1, merged.length - 1)].extrusionDelta,
                0.001
            ),
            kind: "extrusion",
        });
    }

    return {
        layer: layerChunk.layer,
        simplified: true,
        segments: simplifiedSegments.length ? simplifiedSegments : merged,
    };
}

function formatNumber(value) {
    return Number.parseFloat(value.toFixed(5));
}

export function createLivePreviewEngine(customOptions = {}) {
    const options = {
        ...DEFAULT_OPTIONS,
        ...customOptions,
    };

    const state = {
        motionMode: "absolute",
        extrusionMode: "absolute",
        feedrate: 1200,
        feedrateScale: 100,
        tool: 0,
        currentLayer: 0,
        position: createPosition(),
        hotLayers: [],
        warmLayers: [],
        coldLayers: [],
        mode: "live",
        selectedLayer: null,
        nozzlePosition: createPosition(),
        animatedNozzlePosition: createPosition(),
        simulationQueue: [],
        simulationCursor: null,
        renderDirty: false,
        hasSimplifiedGeometry: false,
    };

    function resetRuntime() {
        state.motionMode = "absolute";
        state.extrusionMode = "absolute";
        state.feedrate = 1200;
        state.feedrateScale = 100;
        state.tool = 0;
        state.currentLayer = 0;
        state.position = createPosition();
        state.hotLayers = [];
        state.warmLayers = [];
        state.coldLayers = [];
        state.nozzlePosition = createPosition();
        state.animatedNozzlePosition = createPosition();
        state.simulationQueue = [];
        state.simulationCursor = null;
        state.renderDirty = true;
        state.hasSimplifiedGeometry = false;
    }

    function rebalanceLayers() {
        const allExactLayers = state.warmLayers.concat(state.hotLayers).sort((left, right) => left.layer - right.layer);
        const nextHotLayers = allExactLayers.length ? [allExactLayers[allExactLayers.length - 1]] : [];
        const nextWarmLayers = allExactLayers.slice(0, -1);

        while (nextWarmLayers.length > options.maxWarmLayers) {
            const oldest = nextWarmLayers.shift();

            if (!oldest) {
                break;
            }

            state.coldLayers.push(simplifyLayerSegments(oldest, options.simplifyTolerance));
            state.hasSimplifiedGeometry = true;
        }

        let coldSegmentCount = state.coldLayers.reduce((count, layer) => count + layer.segments.length, 0);

        while (coldSegmentCount > options.maxColdSegments && state.coldLayers.length) {
            const removed = state.coldLayers.shift();
            coldSegmentCount -= removed?.segments.length ?? 0;
        }

        state.hotLayers = nextHotLayers;
        state.warmLayers = nextWarmLayers;
    }

    function appendSegment(segment) {
        const target = ensureLayerChunk(state.hotLayers, segment.layer);
        target.segments.push(segment);
        rebalanceLayers();
        state.renderDirty = true;
        state.nozzlePosition = clonePosition(segment.to);

        if (state.mode === "live" && state.selectedLayer === null) {
            state.simulationQueue.push(segment);
        } else {
            state.animatedNozzlePosition = clonePosition(state.nozzlePosition);
        }
    }

    function applyCommand(rawLine, lineNumber) {
        const parsed = parseLine(rawLine);

        if (!parsed) {
            return;
        }

        switch (parsed.command) {
            case "G90":
                state.motionMode = "absolute";
                return;
            case "G91":
                state.motionMode = "relative";
                return;
            case "M82":
                state.extrusionMode = "absolute";
                return;
            case "M83":
                state.extrusionMode = "relative";
                return;
            case "G92":
                for (const axis of ["X", "Y", "Z", "E"]) {
                    if (axis in parsed.params) {
                        state.position[axis.toLowerCase()] = parsed.params[axis];
                    }
                }
                state.nozzlePosition = clonePosition(state.position);
                state.animatedNozzlePosition = clonePosition(state.position);
                return;
            case "M220":
                if ("S" in parsed.params) {
                    state.feedrateScale = parsed.params.S;
                }
                return;
            default:
                if (/^T\d+$/.test(parsed.command)) {
                    state.tool = Number.parseInt(parsed.command.slice(1), 10) || 0;
                    return;
                }
        }

        if (parsed.command !== "G0" && parsed.command !== "G1" && parsed.command !== "G2" && parsed.command !== "G3") {
            return;
        }

        if ("F" in parsed.params) {
            state.feedrate = parsed.params.F;
        }

        const previousPosition = clonePosition(state.position);
        const nextPosition = buildNextPosition(state, parsed.params);

        if (previousPosition.z !== nextPosition.z) {
            state.currentLayer += 1;
        }

        const segments = parsed.command === "G2" || parsed.command === "G3"
            ? approximateArcSegments(state, parsed, lineNumber)
            : [toSegment(
                lineNumber,
                state.currentLayer,
                state.tool,
                state.feedrate * (state.feedrateScale / 100),
                previousPosition,
                nextPosition
            )];

        state.position = nextPosition;

        for (const segment of segments) {
            if (!positionsEqual(segment.from, segment.to)) {
                appendSegment(segment);
            }
        }
    }

    function exportVisibleLayers() {
        const layers = state.coldLayers
            .concat(state.warmLayers)
            .concat(state.hotLayers)
            .sort((left, right) => left.layer - right.layer);

        if (state.selectedLayer === null) {
            return layers;
        }

        return layers.filter(layer => layer.layer <= state.selectedLayer);
    }

    function exportGCode() {
        const visibleLayers = exportVisibleLayers();
        const segments = visibleLayers.flatMap(layer => layer.segments);

        if (!segments.length) {
            return "";
        }

        let currentPosition = createPosition();
        let currentExtrusion = 0;
        const lines = ["G90", "M82", "G92 X0 Y0 Z0 E0"];

        for (const segment of segments) {
            if (!positionsEqual(currentPosition, segment.from)) {
                lines.push(
                    `G0 X${formatNumber(segment.from.x)} Y${formatNumber(segment.from.y)} Z${formatNumber(segment.from.z)}`
                );
            }

            if (segment.kind === "extrusion") {
                currentExtrusion += Math.max(segment.extrusionDelta, 0.001);
                lines.push(
                    `G1 X${formatNumber(segment.to.x)} Y${formatNumber(segment.to.y)} Z${formatNumber(segment.to.z)} E${formatNumber(currentExtrusion)}`
                );
            } else {
                lines.push(
                    `G0 X${formatNumber(segment.to.x)} Y${formatNumber(segment.to.y)} Z${formatNumber(segment.to.z)}`
                );
            }

            currentPosition = clonePosition(segment.to);
        }

        return lines.join("\n");
    }

    function bootstrap(gcode, bootstrapOptions = {}) {
        resetRuntime();
        state.mode = bootstrapOptions.live === false ? "scrubbed" : "live";

        if (typeof gcode === "string" && gcode.length) {
            for (const line of gcode.split(/\r?\n/)) {
                applyCommand(line, null);
            }
        }

        if (bootstrapOptions.authoritativePosition) {
            state.nozzlePosition = clonePosition(bootstrapOptions.authoritativePosition);
            state.animatedNozzlePosition = clonePosition(bootstrapOptions.authoritativePosition);
        } else {
            state.animatedNozzlePosition = clonePosition(state.nozzlePosition);
        }

        state.simulationQueue = [];
        state.simulationCursor = null;
    }

    function ingest(gcode, metadata = {}) {
        if (!gcode) {
            return;
        }

        const lines = Array.isArray(gcode) ? gcode : `${gcode}`.split(/\r?\n/);
        let lineNumber = metadata.line ?? null;

        for (const line of lines) {
            applyCommand(line, lineNumber);

            if (lineNumber !== null) {
                lineNumber += 1;
            }
        }
    }

    function setMode(mode) {
        state.mode = mode;

        if (mode !== "live") {
            state.simulationQueue = [];
            state.simulationCursor = null;
            state.animatedNozzlePosition = clonePosition(state.nozzlePosition);
        }
    }

    function setSelectedLayer(layer) {
        state.selectedLayer = Number.isInteger(layer) ? layer : null;
        state.renderDirty = true;

        if (state.selectedLayer !== null) {
            state.simulationQueue = [];
            state.simulationCursor = null;
            const visibleLayers = exportVisibleLayers();
            const visibleSegments = visibleLayers.flatMap(chunk => chunk.segments);
            const lastVisible = visibleSegments[visibleSegments.length - 1];
            state.animatedNozzlePosition = clonePosition(lastVisible?.to ?? state.nozzlePosition);
        }
    }

    function tick(nowMs) {
        if (state.mode !== "live" || state.selectedLayer !== null) {
            return false;
        }

        if (!state.simulationCursor && state.simulationQueue.length) {
            const nextSegment = state.simulationQueue.shift();

            if (nextSegment) {
                state.simulationCursor = {
                    segment: nextSegment,
                    startedAtMs: nowMs,
                };

                state.animatedNozzlePosition = clonePosition(nextSegment.from);
                return true;
            }
        }

        if (!state.simulationCursor) {
            return false;
        }

        const { segment, startedAtMs } = state.simulationCursor;
        const durationMs = Math.max(segment.durationMs, MIN_SEGMENT_DURATION_MS);
        const progress = Math.min(1, (nowMs - startedAtMs) / durationMs);

        state.animatedNozzlePosition = lerpPosition(segment.from, segment.to, progress);

        if (progress >= 1) {
            state.animatedNozzlePosition = clonePosition(segment.to);
            state.simulationCursor = null;
        }

        return true;
    }

    function reconcile(authoritativePosition) {
        if (!authoritativePosition) {
            return false;
        }

        const drift = distance3d(state.animatedNozzlePosition, authoritativePosition);

        if (drift <= options.driftTolerance) {
            return false;
        }

        state.nozzlePosition = clonePosition(authoritativePosition);
        state.animatedNozzlePosition = clonePosition(authoritativePosition);
        state.simulationCursor = null;
        state.simulationQueue = [];
        return true;
    }

    function consumeRenderGCode() {
        state.renderDirty = false;
        return exportGCode();
    }

    function getSnapshot() {
        return {
            hotSegments: state.hotLayers.map(layer => ({
                layer: layer.layer,
                simplified: layer.simplified,
                segments: layer.segments.map(segment => ({
                    ...segment,
                    from: clonePosition(segment.from),
                    to: clonePosition(segment.to),
                })),
            })),
            warmLayers: state.warmLayers.map(layer => ({
                layer: layer.layer,
                simplified: layer.simplified,
                segments: layer.segments.map(segment => ({
                    ...segment,
                    from: clonePosition(segment.from),
                    to: clonePosition(segment.to),
                })),
            })),
            coldLayers: state.coldLayers.map(layer => ({
                layer: layer.layer,
                simplified: layer.simplified,
                segments: layer.segments.map(segment => ({
                    ...segment,
                    from: clonePosition(segment.from),
                    to: clonePosition(segment.to),
                })),
            })),
            nozzlePosition: clonePosition(state.nozzlePosition),
            animatedNozzlePosition: clonePosition(state.animatedNozzlePosition),
            hasSimplifiedGeometry: state.hasSimplifiedGeometry,
            renderDirty: state.renderDirty,
            selectedLayer: state.selectedLayer,
        };
    }

    return {
        bootstrap,
        consumeRenderGCode,
        exportGCode,
        getAnimatedNozzlePosition: () => clonePosition(state.animatedNozzlePosition),
        hasRenderDirty: () => state.renderDirty,
        hasSimplifiedGeometry: () => state.hasSimplifiedGeometry,
        getSnapshot,
        ingest,
        reconcile,
        reset: resetRuntime,
        setMode,
        setSelectedLayer,
        tick,
    };
}
