import test from "node:test";
import assert from "node:assert/strict";

import {
    DEFAULT_PLUGIN_NAVIGATION_ICON,
    getPluginNavigationIcon,
    getPluginNavigationLabel,
    getPluginNavigationRoute,
    getPluginHostNavigationIndex,
    shouldUseCompactWorkspaceTabs,
} from "../utils/pluginNavigation.js";

test("plugin navigation uses explicit compact metadata when provided", () => {
    const extension = {
        pluginId: "cura-web-ui",
        id: "cura-web-ui-page",
        title: "Cura Slicer",
        navigationLabel: "Slicer",
        icon: "cube-scan",
    };

    assert.equal(getPluginNavigationIcon(extension), "cube-scan");
    assert.equal(getPluginNavigationLabel(extension), "Slicer");
    assert.deepEqual(getPluginNavigationRoute(extension), {
        key: "plugin:cura-web-ui:cura-web-ui-page",
        title: "Slicer",
        accessibilityLabel: "Cura Slicer",
        focusedIcon: "cube-scan",
        unfocusedIcon: "cube-scan",
        extension,
    });
});

test("plugin navigation keeps the puzzle fallback for older manifests", () => {
    assert.equal(getPluginNavigationIcon({}), DEFAULT_PLUGIN_NAVIGATION_ICON);
    assert.equal(getPluginNavigationLabel({ title: "Legacy plugin" }), "Legacy plugin");
});

test("workspace tabs stay icon-only through the tablet and small-laptop boundary", () => {
    assert.equal(shouldUseCompactWorkspaceTabs(768), true);
    assert.equal(shouldUseCompactWorkspaceTabs(1024), true);
    assert.equal(shouldUseCompactWorkspaceTabs(1025), false);
});

test("host navigation hooks resolve the WPrint workspace destinations", () => {
    assert.equal(getPluginHostNavigationIndex("terminal"), 0);
    assert.equal(getPluginHostNavigationIndex("preview"), 1);
    assert.equal(getPluginHostNavigationIndex("control"), 2);
    assert.equal(getPluginHostNavigationIndex("recordings"), 3);
    assert.equal(getPluginHostNavigationIndex("home", { mobile: true }), 0);
    assert.equal(getPluginHostNavigationIndex("files", { mobile: true }), 0);
    assert.equal(getPluginHostNavigationIndex("preview", { mobile: true }), 2);
    assert.equal(getPluginHostNavigationIndex("unknown"), null);
});
