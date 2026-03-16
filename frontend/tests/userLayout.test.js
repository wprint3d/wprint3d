import test from "node:test";
import assert from "node:assert/strict";

import { getLeftPaneWidth } from "../utils/userLayout.js";

test("user layout keeps the left pane full width on small tablets", () => {
    assert.equal(getLeftPaneWidth(768), "100%");
});

test("user layout keeps the left pane roomy on laptops", () => {
    assert.equal(getLeftPaneWidth(1024), "45%");
    assert.equal(getLeftPaneWidth(1440), "40%");
});

test("user layout gives the left pane extra room on large displays", () => {
    assert.equal(getLeftPaneWidth(1600), "35%");
    assert.equal(getLeftPaneWidth(1920), "35%");
    assert.equal(getLeftPaneWidth(2560), "35%");
});
