export function isPreviewBootstrapReady({
    printerId,
    connectionStatus,
    showExtrusion,
    showTravelMoves,
}) {
    return Boolean(
        printerId
        && connectionStatus
        && showExtrusion !== null
        && showTravelMoves !== null
    );
}

export function isPreviewLoading({
    isDownloading,
    printerId,
    connectionStatus,
    showExtrusion,
    showTravelMoves,
}) {
    return Boolean(
        isDownloading
        && isPreviewBootstrapReady({
            printerId,
            connectionStatus,
            showExtrusion,
            showTravelMoves,
        })
    );
}
