import test from "node:test";
import assert from "node:assert/strict";

import { getNavBarLeftPadding, groupNavbarWidgets } from "../utils/navBar.js";

test("navbar keeps a compact inset on small tablets", () => {
    assert.equal(getNavBarLeftPadding(767), 8);
});

test("navbar keeps a comfortable left inset on larger screens", () => {
    assert.equal(getNavBarLeftPadding(768), 16);
    assert.equal(getNavBarLeftPadding(1920), 16);
});

test("mobile navbar cards are opt-in per plugin extension", () => {
    const inlineWidget = { id: "inline" };
    const cardWidget = { id: "card", mobilePresentation: "card" };
    const gaugeWidget = { id: "gauges", mobilePresentation: "gauges" };

    assert.deepEqual(groupNavbarWidgets([inlineWidget, cardWidget, gaugeWidget], true), {
        inlineWidgets: [inlineWidget, gaugeWidget],
        mobileCardWidgets: [cardWidget],
    });
});

test("desktop keeps every navbar widget inline", () => {
    const cardWidget = { id: "card", mobilePresentation: "card" };

    assert.deepEqual(groupNavbarWidgets([cardWidget], false), {
        inlineWidgets: [cardWidget],
        mobileCardWidgets: [],
    });
});
