import test from "node:test";
import assert from "node:assert/strict";

import {
    CONNECTION_STATUS_REFETCH_INTERVAL_MS,
    getConnectionStatusRefetchInterval,
    getPrinterConnectionStatusKey,
} from "../utils/printerConnectionStatus.js";

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

test("printer connection status reports an active print reconnection explicitly", () => {
    assert.equal(
        getPrinterConnectionStatusKey({
            connectionStatus: {
                isReconnecting: true,
                connectionStatus: "unresponsive",
                lastSeen: 100,
                thresholdSecs: 7,
            },
            isRunningMapper: true,
            nowSecs: 120,
        }),
        "reconnecting"
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

test("printer connection status is reconciled while unresponsive", () => {
    assert.equal(
        getConnectionStatusRefetchInterval({
            connectionStatus: { connectionStatus: "unresponsive" },
            realtimeConnectionState: "connected",
        }),
        CONNECTION_STATUS_REFETCH_INTERVAL_MS
    );
});

test("printer connection status polls while reconnecting", () => {
    assert.equal(
        getConnectionStatusRefetchInterval({
            connectionStatus: { isReconnecting: true, connectionStatus: "online" },
            realtimeConnectionState: "connected",
        }),
        CONNECTION_STATUS_REFETCH_INTERVAL_MS
    );
});

test("printer connection status falls back to polling without realtime updates", () => {
    assert.equal(
        getConnectionStatusRefetchInterval({
            connectionStatus: { connectionStatus: "online" },
            realtimeConnectionState: "disconnected",
        }),
        CONNECTION_STATUS_REFETCH_INTERVAL_MS
    );

    assert.equal(
        getConnectionStatusRefetchInterval({
            connectionStatus: { connectionStatus: "online" },
            realtimeConnectionState: "connected",
        }),
        false
    );
});
