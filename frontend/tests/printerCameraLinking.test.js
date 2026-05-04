import test from "node:test";
import assert from "node:assert/strict";

import {
    getSelectedPrinterCameraListQueryKey,
    getPrinterCameraLinkingQueryKeys,
    invalidatePrinterCameraLinkingQueries,
} from "../utils/printerCameraLinking.js";

test("selected printer camera list query key is scoped to the active printer", () => {
    assert.deepEqual(
        getSelectedPrinterCameraListQueryKey("printer-1"),
        ["cameraList", "printer-1"]
    );

    assert.deepEqual(
        getSelectedPrinterCameraListQueryKey(null),
        ["cameraList", null]
    );
});

test("printer camera linking invalidates details, main webcam, and printer list queries", () => {
    assert.deepEqual(
        getPrinterCameraLinkingQueryKeys("printer-1"),
        [
            ["printerDetails", "printer-1"],
            ["cameraList"],
            ["printersList"],
        ]
    );
});

test("printer camera linking sends every affected query to react-query", async () => {
    const invalidated = [];
    const queryClient = {
        invalidateQueries: ({ queryKey }) => {
            invalidated.push(queryKey);
            return Promise.resolve();
        },
    };

    await invalidatePrinterCameraLinkingQueries(queryClient, "printer-2");

    assert.deepEqual(invalidated, [
        ["printerDetails", "printer-2"],
        ["cameraList"],
        ["printersList"],
    ]);
});
