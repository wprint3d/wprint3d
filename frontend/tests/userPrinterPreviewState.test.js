import test from "node:test";
import assert from "node:assert/strict";

import { isPreviewLoading } from "../utils/userPrinterPreviewState.js";

test("preview loading stays hidden until bootstrap prerequisites exist", () => {
    assert.equal(isPreviewLoading({
        isDownloading: true,
        printerId: "printer-1",
        connectionStatus: null,
        showExtrusion: null,
        showTravelMoves: null,
    }), false);
});

test("preview loading is visible while an actual bootstrap request is active", () => {
    assert.equal(isPreviewLoading({
        isDownloading: true,
        printerId: "printer-1",
        connectionStatus: { isPrinting: true },
        showExtrusion: true,
        showTravelMoves: true,
    }), true);
});
