import test from "node:test";
import assert from "node:assert/strict";

import { formatLoadingIndicatorMessage } from "../utils/loadingIndicator.js";

test("loading indicator shows one ellipsis when message already ends with dots", () => {
    assert.equal(
        formatLoadingIndicatorMessage("Cargando lista de impresoras..."),
        "Cargando lista de impresoras…"
    );
});

test("loading indicator adds one ellipsis to plain loading messages", () => {
    assert.equal(
        formatLoadingIndicatorMessage("Loading printers list"),
        "Loading printers list…"
    );
});
