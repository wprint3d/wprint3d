import test from "node:test";
import assert from "node:assert/strict";

import {
    getRealtimeConnectionOptions,
    getRealtimePort,
    shouldReconcileRealtimeQuery,
    shouldRetryRealtimeConfig,
} from "../utils/realtime.js";

test("realtime uses the page HTTPS port instead of a dedicated websocket port", () => {
    const location = {
        hostname: "printer.local",
        port: "",
        protocol: "https:",
    };

    assert.equal(getRealtimePort(location), 443);
    assert.deepEqual(
        getRealtimeConnectionOptions({ appKey: "app-key", location }),
        {
            authEndpoint: "/backend/broadcasting/auth",
            broadcaster: "pusher",
            key: "app-key",
            wsHost: "printer.local",
            wsPort: 443,
            wssPort: 443,
            forceTLS: true,
            cluster: "mt1",
            enabledTransports: ["ws", "wss"],
        }
    );
});

test("realtime preserves a custom HTTPS port from the current page", () => {
    assert.equal(getRealtimePort({ port: "4433", protocol: "https:" }), 4433);
});

test("realtime config retries transient failures but not authentication failures", () => {
    assert.equal(shouldRetryRealtimeConfig(0, { response: { status: 503 } }), true);
    assert.equal(shouldRetryRealtimeConfig(8, { response: { status: 503 } }), false);
    assert.equal(shouldRetryRealtimeConfig(0, { response: { status: 401 } }), false);
});

test("realtime reconnection reconciles stateful active queries", () => {
    assert.equal(shouldReconcileRealtimeQuery({ queryKey: ["connectionStatus", "printer-1"] }), true);
    assert.equal(shouldReconcileRealtimeQuery({ queryKey: ["user", "printer", "printer-1", "recordings"] }), true);
    assert.equal(shouldReconcileRealtimeQuery({ queryKey: ["licenses"] }), false);
});
