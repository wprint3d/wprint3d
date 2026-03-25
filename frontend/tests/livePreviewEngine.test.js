import test from "node:test";
import assert from "node:assert/strict";

import { createLivePreviewEngine } from "../utils/livePreviewEngine.js";

test("live preview engine tracks absolute and relative movement modes across layers", () => {
    const engine = createLivePreviewEngine({
        maxWarmLayers: 8,
        maxColdSegments: 32,
        simplifyTolerance: 0.01
    });

    engine.bootstrap([
        "G90",
        "M82",
        "G0 X0 Y0 Z0",
        "G1 X10 Y0 E1 F600",
        "G91",
        "M83",
        "G1 X5 Y0 E0.5",
        "G90",
        "M82",
        "G0 Z0.2",
        "G1 X20 Y0 E2"
    ].join("\n"), {
        live: false
    });

    const snapshot = engine.getSnapshot();

    assert.equal(snapshot.nozzlePosition.x, 20);
    assert.equal(snapshot.nozzlePosition.y, 0);
    assert.equal(snapshot.nozzlePosition.z, 0.2);

    const segments = snapshot.warmLayers
        .concat(snapshot.hotSegments)
        .flatMap(layer => layer.segments);

    assert.equal(segments.length, 4);
    assert.equal(segments[0].kind, "extrusion");
    assert.equal(segments[1].kind, "extrusion");
    assert.equal(segments[2].kind, "travel");
    assert.equal(segments[3].kind, "extrusion");
    assert.equal(segments[3].layer, 1);
});

test("live preview engine simulates nozzle motion from feedrate over time", () => {
    const engine = createLivePreviewEngine({
        maxWarmLayers: 8,
        maxColdSegments: 32,
        simplifyTolerance: 0.01
    });

    engine.bootstrap("G90\nM82\nG0 X0 Y0 Z0", {
        live: true
    });

    engine.ingest("G1 X60 Y0 E1 F600");

    engine.tick(0);
    assert.equal(engine.getSnapshot().animatedNozzlePosition.x, 0);

    engine.tick(3000);
    assert.equal(Math.round(engine.getSnapshot().animatedNozzlePosition.x), 30);

    engine.tick(6000);
    assert.equal(engine.getSnapshot().animatedNozzlePosition.x, 60);
});

test("live preview engine demotes older layers into simplified cold extrusion-only chunks", () => {
    const engine = createLivePreviewEngine({
        maxWarmLayers: 1,
        maxColdSegments: 2,
        simplifyTolerance: 0.01
    });

    engine.bootstrap([
        "G90",
        "M82",
        "G0 X0 Y0 Z0",
        "G1 X5 Y0 E1",
        "G0 Z0.2",
        "G0 X5 Y5",
        "G1 X10 Y5 E2",
        "G0 Z0.4",
        "G0 X10 Y10",
        "G1 X15 Y10 E3"
    ].join("\n"), {
        live: false
    });

    const snapshot = engine.getSnapshot();

    assert.equal(snapshot.warmLayers.length, 1);
    assert.equal(snapshot.coldLayers.length, 1);
    assert.equal(snapshot.coldLayers[0].segments.every(segment => segment.kind === "extrusion"), true);
    assert.equal(snapshot.coldLayers[0].segments.length <= 2, true);
});
