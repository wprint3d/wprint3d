export const getSelectedPrinterCameraListQueryKey = (printerId) => (
    ['cameraList', printerId ?? null]
);

export const getPrinterCameraLinkingQueryKeys = (printerId) => [
    ['printerDetails', printerId],
    ['cameraList'],
    ['printersList'],
];

export const invalidatePrinterCameraLinkingQueries = (queryClient, printerId) => Promise.all(
    getPrinterCameraLinkingQueryKeys(printerId).map(queryKey => (
        queryClient.invalidateQueries({ queryKey })
    ))
);
