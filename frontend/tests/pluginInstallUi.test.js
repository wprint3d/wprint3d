import test from "node:test";
import assert from "node:assert/strict";

import { buildRegistryPluginInstallKey } from "../utils/pluginInstallUi.js";

test("registry plugin install keys include source, plugin id, and version", () => {
    assert.equal(
        buildRegistryPluginInstallKey({
            id: "camera-tools",
            latestVersion: "1.2.3",
            registrySource: { id: "official" },
        }),
        "official:camera-tools:1.2.3"
    );
});

test("registry plugin install keys prefer explicit version over latestVersion", () => {
    assert.equal(
        buildRegistryPluginInstallKey({
            id: "camera-tools",
            version: "2.0.0-beta",
            latestVersion: "1.2.3",
            registrySource: { id: "trusted-lab" },
        }),
        "trusted-lab:camera-tools:2.0.0-beta"
    );
});
