export const BACKEND_STARTING_HTTP_STATUSES = [502, 503, 504];

export const getHttpErrorStatus = error => (
    error?.response?.status ?? error?.status ?? null
);

export const isBackendStartingError = error => (
    BACKEND_STARTING_HTTP_STATUSES.includes(getHttpErrorStatus(error))
);

export const getBackendAvailabilityFailure = query => (
    query?.failureReason ?? query?.error ?? null
);
