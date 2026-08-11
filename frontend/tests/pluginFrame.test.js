import test from "node:test";
import assert from "node:assert/strict";

import {
    PLUGIN_HOST_ACTION_REQUEST,
    PLUGIN_HOST_ACTION_RESULT,
    PLUGIN_HOST_ACTION_PROGRESS,
    PLUGIN_HOST_CONTEXT_READY,
    PLUGIN_FULLSCREEN_REQUEST,
    buildPluginHostActionProgress,
    buildPluginHostActionResult,
    buildPluginThemeTokens,
    getPluginFrameIdentity,
    readPluginHostActionRequest,
    readPluginFullscreenRequest,
} from "../utils/pluginFrame.js";

test("host context readiness uses a stable versioned bridge message", () => {
    assert.equal(PLUGIN_HOST_CONTEXT_READY, "wprint3d.plugin-host-context-ready");
});

test("plugin theme bridge exposes the resolved Material theme and semantic token pairs", () => {
    const tokens = buildPluginThemeTokens({
        dark: true,
        colors: {
            primary: "rgb(203, 203, 203)",
            onPrimary: "rgb(34, 34, 34)",
            primaryContainer: "rgb(46, 46, 46)",
            onPrimaryContainer: "rgb(255, 255, 255)",
            background: "rgb(0, 0, 0)",
            onBackground: "rgb(231, 225, 229)",
            surface: "rgb(29, 27, 30)",
            onSurface: "rgb(231, 225, 229)",
            elevation: { level1: "rgb(18, 16, 19)" },
        },
    });

    assert.equal(tokens.colorScheme, "dark");
    assert.equal(tokens.primaryContainer, "rgb(46, 46, 46)");
    assert.equal(tokens.onPrimaryContainer, "rgb(255, 255, 255)");
    assert.equal(tokens.background, "rgb(0, 0, 0)");
    assert.deepEqual(tokens.elevation, { level1: "rgb(18, 16, 19)" });
});

test("theme-only changes keep the iframe navigation identity stable", () => {
    const light = "https://wprint.test/plugin/index.html?pluginId=cura-web-ui&theme=light#workspace";
    const dark = "https://wprint.test/plugin/index.html?pluginId=cura-web-ui&theme=dark#workspace";

    assert.equal(getPluginFrameIdentity(light), getPluginFrameIdentity(dark));
});

test("reactive printer and locale changes keep the iframe navigation identity stable", () => {
    const first = "https://wprint.test/plugin/index.html?pluginId=cura-web-ui&currentPrinterId=printer-a&locale=en&theme=light#workspace";
    const second = "https://wprint.test/plugin/index.html?pluginId=cura-web-ui&currentPrinterId=printer-b&locale=es-AR&theme=dark#workspace";

    assert.equal(getPluginFrameIdentity(first), getPluginFrameIdentity(second));
});

test("fullscreen bridge accepts only explicit generic plugin requests", () => {
    assert.equal(readPluginFullscreenRequest({ type: PLUGIN_FULLSCREEN_REQUEST, fullscreen: true }), true);
    assert.equal(readPluginFullscreenRequest({ type: PLUGIN_FULLSCREEN_REQUEST, fullscreen: false }), false);
    assert.equal(readPluginFullscreenRequest({ type: PLUGIN_FULLSCREEN_REQUEST, fullscreen: "yes" }), null);
    assert.equal(readPluginFullscreenRequest({ type: "untrusted", fullscreen: true }), null);
});

test("host action bridge accepts bounded programmable requests and rejects malformed messages", () => {
    assert.deepEqual(readPluginHostActionRequest({
        type: PLUGIN_HOST_ACTION_REQUEST,
        requestId: "request-1",
        action: "print-artifact",
        payload: { jobId: "job-1" },
    }), {
        requestId: "request-1",
        action: "print-artifact",
        payload: { jobId: "job-1" },
    });

    assert.equal(readPluginHostActionRequest({
        type: PLUGIN_HOST_ACTION_REQUEST,
        requestId: "request-1",
        action: "Print Artifact",
    }), null);
    assert.equal(readPluginHostActionRequest({
        type: PLUGIN_HOST_ACTION_REQUEST,
        requestId: "request-1",
        action: "print-artifact",
        payload: "job-1",
    }), null);
    assert.equal(readPluginHostActionRequest({ type: "untrusted" }), null);
});

test("host action bridge emits explicit success and error envelopes", () => {
    assert.deepEqual(buildPluginHostActionResult("request-1", { path: "cura/job.gcode" }), {
        type: PLUGIN_HOST_ACTION_RESULT,
        requestId: "request-1",
        ok: true,
        result: { path: "cura/job.gcode" },
    });
    assert.deepEqual(buildPluginHostActionResult("request-2", undefined, "Printer is offline"), {
        type: PLUGIN_HOST_ACTION_RESULT,
        requestId: "request-2",
        ok: false,
        error: "Printer is offline",
    });
    assert.deepEqual(buildPluginHostActionResult("request-3", { path: "cura/job.gcode" }, "Printer is busy"), {
        type: PLUGIN_HOST_ACTION_RESULT,
        requestId: "request-3",
        ok: false,
        error: "Printer is busy",
        result: { path: "cura/job.gcode" },
    });
});

test("host action bridge emits bounded correlated progress envelopes", () => {
    assert.deepEqual(buildPluginHostActionProgress("request-1", {
        stage: "starting-print",
        percent: 120,
        message: "Starting print",
        transferId: "transfer-1",
    }), {
        type: PLUGIN_HOST_ACTION_PROGRESS,
        requestId: "request-1",
        progress: {
            stage: "starting-print",
            percent: 100,
            message: "Starting print",
            transferId: "transfer-1",
        },
    });
});
