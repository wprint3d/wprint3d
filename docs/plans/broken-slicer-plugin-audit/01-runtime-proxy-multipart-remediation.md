# Plan 01: Runtime Proxy Multipart Remediation

**Repository:** `wprint3d-core`.

**Objective:** Forward browser multipart uploads to a managed plugin runtime with correct EOF behavior, bounded memory, preserved form semantics, and explicit failure diagnostics.

## 1. Reproduce and lock the defect

### Files

- Modify `tests/Feature/Plugins/PluginManagementApiTest.php` or create `tests/Feature/Plugins/PluginRuntimeMultipartProxyTest.php` if separation improves readability.
- Modify `app/Http/Controllers/PluginRuntimeProxyController.php` only after the regression shape is defined.

### Required fixture

Create an enabled SDK revision 5 managed bridge plugin with:

- resolved base URL `http://cura-gateway:9311`;
- runtime HTTP proxy path `/api/v1`;
- method `POST`;
- `maxUploadMb` greater than the fixture;
- an encrypted runtime bearer token where token assertions are useful.

Send a real Laravel `UploadedFile` with known byte content, client filename, and MIME type to `/api/plugins/{id}/runtime/api/v1/uploads`. Include at least one scalar form field. Fake the upstream response, but inspect the actual outbound PSR-7 request body and headers.

### Assertions

- The request completes instead of hanging.
- Outbound `Content-Type` is `multipart/form-data` with a non-empty boundary.
- That boundary is present in the serialized body.
- File bytes, form field value, field names, client filename, and declared MIME type are present.
- Authorization and WPrint identity headers are injected as before.
- Browser-supplied authorization/identity headers are not forwarded.
- Upstream `201` and safe response headers are preserved.

Do not encode the test around the original browser boundary. Boundary replacement is expected.

## 2. Introduce an explicit outbound-body representation

### Recommended structure

Create a small internal value object if it keeps the controller readable:

```text
RuntimeProxyRequestBody
- body: StreamInterface|string|null
- contentType: string|null
- contentLength: int|null
```

It may remain a private controller method if the implementation is still easy to test. Avoid a generic public HTTP client abstraction in this repair.

### Selection rules

1. `GET` and `HEAD`: no body.
2. Non-multipart request: retain the existing bounded raw-body path.
3. Multipart with parsed files or fields: construct a `MultipartStream`.
4. Multipart with no parsed parts but a raw stream: use the bounded raw stream as a compatibility fallback.
5. Multipart with neither parsed parts nor raw bytes: forward an empty multipart body that terminates correctly, allowing the upstream to return a normal validation error.

## 3. Reconstruct multipart without loading complete files

### File parts

For each Symfony/Laravel uploaded file:

- use the logical form field name;
- open the temporary file path as a read-only PSR-7 stream;
- preserve the client filename as multipart metadata only;
- preserve the client MIME type, falling back to `application/octet-stream`;
- reject unreadable/non-file temporary paths with a controlled `422` or `502` response;
- never use the client filename as a local filesystem target.

Use `GuzzleHttp\Psr7\MultipartStream` and `GuzzleHttp\Psr7\Utils::tryFopen()` or equivalent PSR-7 primitives already installed in the repository.

### Form fields

Flatten parsed multipart form data deterministically:

- scalar `name=value` remains `name`;
- arrays use PHP/browser bracket notation such as `options[0]` and `metadata[label]`;
- repeated uploaded files preserve their indexed field names;
- booleans/numbers are stringified as HTML form data;
- `null` is omitted unless existing request semantics require an empty string.

Keep the helper recursive and test nested fields. Do not call `$request->all()` because it merges files and fields into a structure that is harder to serialize safely; use the request parameter bag and file bag separately.

### Headers

When reconstructing the body:

- replace the incoming `Content-Type` boundary with `multipart/form-data; boundary=<generated>`;
- set outbound `Content-Length` only if the reconstructed stream reports a reliable size;
- continue stripping inbound `Content-Length`, `Transfer-Encoding`, connection headers, cookies, and browser authorization;
- retain safe `Accept` behavior.

## 4. Fix EOF semantics in the raw-stream fallback

The `PumpStream` callback must return `false` or `null` when `fread()` reaches EOF. It must never return `""` at EOF.

Track bytes read and reject the first byte beyond the effective limit. Pass a known `size` option to `PumpStream` only when a trustworthy content length is available and is already within the limit.

Add a focused unit assertion or feature fixture that consumes the stream to EOF. A test that only checks early `Content-Length` rejection does not cover this defect.

## 5. Enforce size limits after reconstruction

The effective limit is the minimum of:

- host `plugins.runtime.max_upload_payload_bytes`;
- signed `runtime.httpProxy.maxUploadMb`;
- any lower route-specific limit introduced later.

Enforcement sequence:

1. Reject an oversized inbound `Content-Length` before opening files.
2. Sum/measure the reconstructed multipart stream, including multipart framing where a size is available.
3. Retain the bounded-stream counter for unknown-size raw requests.
4. Return `413` consistently for all limit violations.

Tests must include:

- declared limit exceeded via `Content-Length`;
- uploaded temporary file whose body exceeds the declared limit;
- multiple files whose aggregate exceeds the limit;
- request under the limit;
- no `Content-Length` fallback.

## 6. Map failures deliberately

Add controlled handling for:

- payload too large → `413`;
- unreadable temporary upload → `422` or stable proxy error;
- upstream connection/timeout → `502`/`504` with a non-secret message;
- upstream FastAPI validation → preserve upstream `422` and safe response body;
- client disconnect → abort response work where supported without changing an already accepted slicing job.

Do not return Laravel debug pages, internal Docker URLs, bearer tokens, temporary paths, or stack traces.

Add request duration, plugin ID, method, normalized path, response status, and request byte count to a structured log only if an existing plugin log mechanism supports it cleanly. Never log file bytes or authorization headers.

## 7. Verification commands

From `wprint3d-core`:

```bash
php artisan test tests/Feature/Plugins/PluginManagementApiTest.php
php artisan test tests/Unit/Plugins/PluginRuntimeHttpClientTest.php
./vendor/bin/pint --test app/Http/Controllers/PluginRuntimeProxyController.php tests/Feature/Plugins/PluginManagementApiTest.php
```

If the host has no PHP CLI, run the same commands through the active backend container with `docker compose ... exec -T backend`.

### Gate 01

- Real uploaded-file fixture completes in finite time.
- Multipart semantics and limit tests pass.
- Existing JSON proxy, streaming response, WebSocket rejection, artifact import, and runtime authentication tests remain green.
- No nginx timeout adjustment is used to hide an application deadlock.

## 8. Rollback

The repair is isolated to request-body construction. If it causes a regression:

- disable the built-in Cura plugin or the runtime proxy rollout flag;
- keep JSON proxying disabled only if necessary rather than exposing the sidecar directly;
- do not raise nginx timeouts as a rollback substitute;
- retain uploaded sidecar data and already imported WPrint G-code.
