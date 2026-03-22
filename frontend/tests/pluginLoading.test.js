import test from "node:test";
import assert from "node:assert/strict";

import {
    PLUGIN_LOADING_SETTLE_DELAY_MS,
    getPluginLoadingToastState,
    shouldFinalizePluginLoadingSession,
} from "../utils/pluginLoading.js";

test("plugin loading session does not finalize before the settle window elapses", () => {
    assert.equal(
        shouldFinalizePluginLoadingSession({
            isLoading: false,
            totalPlugins: 3,
            loadedPlugins: 3,
            lastActivityAt: 1000,
            now: 1000 + PLUGIN_LOADING_SETTLE_DELAY_MS - 1,
        }),
        false
    );
});

test("plugin loading session finalizes after the settle window elapses", () => {
    assert.equal(
        shouldFinalizePluginLoadingSession({
            isLoading: false,
            totalPlugins: 3,
            loadedPlugins: 3,
            lastActivityAt: 1000,
            now: 1000 + PLUGIN_LOADING_SETTLE_DELAY_MS,
        }),
        true
    );
});

test("plugin loading toast stays visible while a completed session is still settling", () => {
    assert.deepEqual(
        getPluginLoadingToastState({
            isLoading: false,
            sessionActive: true,
            showCompletedToast: false,
            totalPlugins: 2,
            loadedPlugins: 2,
        }),
        {
            visible: true,
            isComplete: false,
        }
    );
});

test("plugin loading toast hides when there is no active session", () => {
    assert.deepEqual(
        getPluginLoadingToastState({
            isLoading: false,
            sessionActive: false,
            showCompletedToast: false,
            totalPlugins: 2,
            loadedPlugins: 2,
        }),
        {
            visible: false,
            isComplete: false,
        }
    );
});
