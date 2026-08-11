const isGcodeImport = (artifactImport) => {
    if (!artifactImport || typeof artifactImport !== "object") { return false; }

    const id = typeof artifactImport.id === "string" ? artifactImport.id.toLowerCase() : "";
    const contentTypes = Array.isArray(artifactImport.contentTypes)
        ? artifactImport.contentTypes.map((value) => String(value).toLowerCase())
        : [];

    return id.includes("gcode") || contentTypes.includes("text/x-gcode");
};

const pathMatches = (artifactImport, artifactPath) => {
    if (!artifactPath) { return true; }
    if (typeof artifactImport?.pathPattern !== "string" || artifactImport.pathPattern === "") { return false; }

    try {
        return new RegExp(artifactImport.pathPattern).test(artifactPath);
    } catch (_error) {
        return false;
    }
};

const preferenceScore = (artifactImport) => {
    const id = String(artifactImport?.id || "").toLowerCase();
    const pattern = String(artifactImport?.pathPattern || "").toLowerCase();

    if (id === "gcode-v2") { return 40; }
    if (pattern.includes("/api/v2/")) { return 30; }
    if (id === "gcode") { return 20; }
    if (id.includes("gcode")) { return 10; }
    return 0;
};

export const resolveGcodeArtifactImport = (artifactImports, artifactPath = "") => {
    if (!Array.isArray(artifactImports)) { return null; }

    return artifactImports
        .filter((artifactImport) => isGcodeImport(artifactImport) && pathMatches(artifactImport, artifactPath))
        .sort((left, right) => preferenceScore(right) - preferenceScore(left))[0] || null;
};
