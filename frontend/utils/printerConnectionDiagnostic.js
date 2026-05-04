export function getPrinterConnectionDiagnosticOutput(printerLike) {
    const diagnostic = printerLike?.connectionDiagnostic;

    if (typeof diagnostic !== "string") {
        return "";
    }

    return diagnostic.trim();
}

export function hasUnresponsiveConnectionDiagnostic(printerLike) {
    return (
        printerLike?.connectionStatus === "unresponsive"
        &&
        getPrinterConnectionDiagnosticOutput(printerLike) !== ""
    );
}
