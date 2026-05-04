import test from "node:test";
import assert from "node:assert/strict";

import { getPrinterConnectionStatusKey } from "../utils/printerConnectionStatus.js";

test("printer connection status reports unresponsive when backend detects USB errors", () => {
    assert.equal(
        getPrinterConnectionStatusKey({
            connectionStatus: {
                connectionStatus: "unresponsive",
                connectionDiagnostic: "[73476.224266] usb 3-2: device descriptor read/64, error -71",
                lastSeen: 100,
                thresholdSecs: 7,
            },
            nowSecs: 101,
        }),
        "unresponsive"
    );
});

test("printer connection status keeps existing online and offline derivation", () => {
    assert.equal(
        getPrinterConnectionStatusKey({
            connectionStatus: {
                connectionStatus: "online",
                lastSeen: 100,
                thresholdSecs: 7,
            },
            nowSecs: 105,
        }),
        "online"
    );

    assert.equal(
        getPrinterConnectionStatusKey({
            connectionStatus: {
                connectionStatus: "online",
                lastSeen: 100,
                thresholdSecs: 7,
            },
            nowSecs: 120,
        }),
        "offline"
    );
});
