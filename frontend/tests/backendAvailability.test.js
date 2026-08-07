import test from "node:test";
import assert from "node:assert/strict";
import { QueryClient, QueryObserver } from "@tanstack/react-query";

import {
    getBackendAvailabilityFailure,
    getHttpErrorStatus,
    isBackendStartingError,
} from "../utils/backendAvailability.js";

test("backend availability recognizes proxy startup responses", () => {
    assert.equal(isBackendStartingError({ response: { status: 502 } }), true);
    assert.equal(isBackendStartingError({ status: 503 }), true);
    assert.equal(isBackendStartingError({ response: { status: 504 } }), true);
    assert.equal(isBackendStartingError({ response: { status: 500 } }), false);
    assert.equal(getHttpErrorStatus(new Error("Network error")), null);
});

test("backend availability preserves the startup signal while retrying", async () => {
    const queryClient = new QueryClient();
    let attempts = 0;
    let resolveRetry;

    const retryResponse = new Promise(resolve => {
        resolveRetry = resolve;
    });
    const observer = new QueryObserver(queryClient, {
        queryKey: ["backend-availability"],
        queryFn: () => {
            attempts += 1;

            if (attempts === 1) {
                return Promise.reject({ response: { status: 502 } });
            }

            return retryResponse;
        },
        retry: true,
        retryDelay: 0,
    });
    const results = [];
    const unsubscribe = observer.subscribe(result => results.push(result));
    const fetchPromise = observer.refetch();

    await new Promise(resolve => setTimeout(resolve, 0));

    const retryingResult = results.find(result => (
        result.isFetching
        && isBackendStartingError(getBackendAvailabilityFailure(result))
    ));

    assert.ok(retryingResult);
    assert.equal(retryingResult.isError, false);
    assert.equal(retryingResult.failureReason.response.status, 502);

    resolveRetry({ data: "WPrint 3D" });
    await fetchPromise;

    assert.equal(observer.getCurrentResult().isSuccess, true);

    unsubscribe();
    queryClient.clear();
});
