import test from "node:test";
import assert from "node:assert/strict";

import { getNavBarLeftPadding } from "../utils/navBar.js";

test("navbar keeps a compact inset on small tablets", () => {
    assert.equal(getNavBarLeftPadding(767), 8);
});

test("navbar keeps a comfortable left inset on larger screens", () => {
    assert.equal(getNavBarLeftPadding(768), 16);
    assert.equal(getNavBarLeftPadding(1920), 16);
});
