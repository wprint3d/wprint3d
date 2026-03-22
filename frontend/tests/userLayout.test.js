import test from "node:test";
import assert from "node:assert/strict";

import * as userLayout from "../utils/userLayout.js";

test("user layout keeps the left pane full width on small tablets", () => {
    assert.equal(userLayout.getLeftPaneWidth(768), "100%");
});

test("user layout keeps the left pane roomy on laptops", () => {
    assert.equal(userLayout.getLeftPaneWidth(1024), "45%");
    assert.equal(userLayout.getLeftPaneWidth(1440), "40%");
});

test("user layout gives the left pane extra room on large displays", () => {
    assert.equal(userLayout.getLeftPaneWidth(1600), "35%");
    assert.equal(userLayout.getLeftPaneWidth(1920), "35%");
    assert.equal(userLayout.getLeftPaneWidth(2560), "35%");
});

test("user layout stacks the mobile shell vertically on small tablets", () => {
    assert.equal(typeof userLayout.getUserLayoutRootStyle, "function");

    const style = userLayout.getUserLayoutRootStyle({
        windowWidth: 412,
        isSmallTablet: true
    });

    assert.equal(style.flexDirection, "column");
    assert.equal(style.alignItems, "stretch");
    assert.equal(style.padding, 0);
});

test("user layout keeps the desktop panes side by side on larger screens", () => {
    assert.equal(typeof userLayout.getUserLayoutRootStyle, "function");

    const style = userLayout.getUserLayoutRootStyle({
        windowWidth: 1024,
        isSmallTablet: false
    });

    assert.equal(style.flexDirection, "row");
    assert.equal(style.padding, 8);
});
