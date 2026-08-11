import test from "node:test";
import assert from "node:assert/strict";

import { resolveGcodeArtifactImport } from "../utils/pluginArtifactImport.js";

const imports = [
    {
        id: "gcode-v1-compatibility",
        pathPattern: "^/api/v1/jobs/[A-Za-z0-9_-]+/gcode$",
        contentTypes: ["text/x-gcode"],
    },
    {
        id: "gcode-v2",
        pathPattern: "^/api/v2/slice-jobs/[A-Za-z0-9_-]+/artifacts/gcode$",
        contentTypes: ["text/x-gcode", "text/plain"],
    },
];

test("host selects the v2 G-code importer by default", () => {
    assert.equal(resolveGcodeArtifactImport(imports)?.id, "gcode-v2");
});

test("host selects the importer whose declared pattern matches the artifact", () => {
    assert.equal(
        resolveGcodeArtifactImport(imports, "/api/v1/jobs/job-1/gcode")?.id,
        "gcode-v1-compatibility",
    );
    assert.equal(
        resolveGcodeArtifactImport(imports, "/api/v2/slice-jobs/job-2/artifacts/gcode")?.id,
        "gcode-v2",
    );
});

test("host rejects undeclared and malformed artifact paths", () => {
    assert.equal(resolveGcodeArtifactImport(imports, "/api/v2/packages/private"), null);
    assert.equal(resolveGcodeArtifactImport([{ id: "gcode", pathPattern: "[" }], "/api/v1/jobs/job/gcode"), null);
});
