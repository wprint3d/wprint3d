import test from "node:test";
import assert from "node:assert/strict";

import {
    getScrollableModalContentStyle,
    getScrollableModalFrameStyle,
} from "../utils/modalLayout.js";

test("scrollable desktop modal uses max height instead of fixed height", () => {
    const style = getScrollableModalFrameStyle({
        backgroundColor: "#fff",
        width: "95%",
        maxWidth: 960,
        maxHeight: "95%",
    });

    assert.equal(style.height, undefined);
    assert.equal(style.maxHeight, "95%");
    assert.equal(style.padding, 0);
    assert.equal(style.overflow, "hidden");
});

test("scrollable fullscreen modal keeps the frame full screen", () => {
    const style = getScrollableModalFrameStyle({
        backgroundColor: "#fff",
        isFullScreen: true,
        width: "60%",
        maxWidth: 500,
        maxHeight: "75%",
    });

    assert.equal(style.height, "100%");
    assert.equal(style.maxHeight, "100%");
    assert.equal(style.width, "100%");
    assert.equal(style.maxWidth, "100%");
});

test("scrollable modal content owns the padding", () => {
    const style = getScrollableModalContentStyle({
        horizontalPadding: 16,
        verticalPadding: 32,
    });

    assert.deepEqual(style, {
        paddingHorizontal: 16,
        paddingVertical: 32,
    });
});
