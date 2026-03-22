import test from "node:test";
import assert from "node:assert/strict";

import { shouldPollCameraStream } from "../utils/cameraStream.js";

test("camera previews never poll the stream endpoint anymore", () => {
    assert.equal(shouldPollCameraStream(true), false);
    assert.equal(shouldPollCameraStream(false), false);
    assert.equal(shouldPollCameraStream(undefined), false);
});
