import test from "node:test";
import assert from "node:assert/strict";

import {
    getPrinterConnectionDiagnosticOutput,
    hasUnresponsiveConnectionDiagnostic,
} from "../utils/printerConnectionDiagnostic.js";

test("unresponsive diagnostic helper requires unresponsive status and dmesg output", () => {
    assert.equal(
        hasUnresponsiveConnectionDiagnostic({
            connectionStatus: "unresponsive",
            connectionDiagnostic: "[73476.224266] usb 3-2: device descriptor read/64, error -71",
        }),
        true
    );

    assert.equal(
        hasUnresponsiveConnectionDiagnostic({
            connectionStatus: "offline",
            connectionDiagnostic: "[73476.224266] usb 3-2: device descriptor read/64, error -71",
        }),
        false
    );

    assert.equal(
        hasUnresponsiveConnectionDiagnostic({
            connectionStatus: "unresponsive",
            connectionDiagnostic: "   ",
        }),
        false
    );
});

test("diagnostic output is trimmed before display", () => {
    assert.equal(
        getPrinterConnectionDiagnosticOutput({
            connectionStatus: "unresponsive",
            connectionDiagnostic: "\n[73492.234561] usb usb2-port4: attempt power cycle\n",
        }),
        "[73492.234561] usb usb2-port4: attempt power cycle"
    );
});
