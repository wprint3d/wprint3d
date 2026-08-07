# Cura Gateway Durability and Background Worker Plan

**Goal:** Replace the development-only in-memory/base64/FastAPI-BackgroundTasks job path with a durable, restart-safe, owner-isolated slicing service that can run as one managed Docker sidecar.

**Repository:** `../cura-web-ui`.

**Prerequisites:** The contracts in `01-architecture-and-contracts.md` are accepted. Core runtime proxy work may proceed in parallel, but embedded end-to-end work waits for both.

**Preserve:** Public CLI/process separation from CuraEngine, real layer preview parsing, standalone local-token mode, OpenAPI-first contracts, and the existing development-only `basic-fdm` adapter flag.

**Remove from production path:** Base64 mesh transport, FastAPI `BackgroundTasks`, in-memory job ownership, one giant JSON artifact store, and runtime CuraEngine downloads.

## Task 1: Update API contracts before implementation

**Files:**

- Modify `openapi/slicer.v1.yaml`.
- Modify `asyncapi/slicer-events.v1.yaml`.
- Modify `packages/engine-api/src/types.ts` or the actual type/schema modules discovered in `packages/engine-api/src`.
- Modify `packages/engine-api/src/http.ts`.
- Modify `packages/engine-api/src/http.test.ts`.
- Modify `scripts/generate-contracts.mjs` only if generation lacks the required schemas.
- Regenerate `packages/engine-api/src/generated/openapi.ts` and `services/slicer-gateway/app/generated/openapi_contract.py`.

### Step 1.1: Add upload schemas and endpoint

Add:

```text
POST /api/v1/uploads
GET /api/v1/uploads/{uploadId}
DELETE /api/v1/uploads/{uploadId}
```

`POST` consumes multipart field `file` and returns `UploadArtifact`:

```text
id
fileName
mimeType
sizeBytes
sha256
createdAt
expiresAt
```

Do not return a filesystem path. The delete endpoint succeeds only for an unclaimed upload owned by the current user; otherwise return `409` or `404` without revealing another user's resource.

### Step 1.2: Version the job request

Support `apiVersion: 1.1`. Add `uploadId` to each `SliceModelInput`. Define exactly one source rule:

- release/embedded mode: `uploadId` required and `dataBase64` rejected;
- explicit legacy development mode: exactly one of `uploadId` or `dataBase64` accepted;
- both present: reject `422`.

Do not silently accept a missing source because a filename exists.

### Step 1.3: Expand job state

Add statuses/stages defined in the architecture contract and these fields:

```text
progress: integer 0..100
stage: queued|preparing|processing|post-processing|completed|failed|cancelling|cancelled|retry-queued
attempt: integer >= 0
maxAttempts: integer >= 1
engine: { id, version, resourceRevision }
requestFingerprint: sha256
```

Keep owner ID internal. Public job listing is already owner-scoped and does not need to echo it.

Add `Idempotency-Key` request-header semantics to `POST /jobs`.

### Step 1.4: Add polling event semantics

The job GET response must contain enough state for embedded polling without WebSocket event history. AsyncAPI continues to define:

- `job.queued`;
- `job.progress` with stage/progress;
- `job.retry_queued`;
- `job.completed`;
- `job.failed`;
- `job.cancelling`;
- `job.cancelled`.

Events include monotonically increasing `sequence` per job and `occurredAt`. WebSocket reconnect may accept `afterSequence` later; phase one must at least send a current-state event immediately after subscribe so a reconnect does not wait forever.

### Step 1.5: Extend the TypeScript client

Add:

```text
createUpload(file: Blob, fileName: string, mimeType?: string)
getUpload(uploadId)
deleteUpload(uploadId)
createJob(input, { idempotencyKey? })
pollJobEvents(jobId, options)
```

`createUpload` uses `FormData` and must not manually set the multipart boundary header.

`events()` chooses:

- WebSocket when configured;
- polling when configured;
- a clear configuration error otherwise.

Write fake-timer tests proving polling cadence, terminal stop, backoff, abort-signal cleanup, and no duplicate loop after consumer cancellation.

### Step 1.6: Lint and regenerate

```bash
rtk proxy pnpm lint:contracts
rtk proxy pnpm generate:contracts
rtk proxy pnpm --filter @cura-web-ui/engine-api test
rtk proxy pnpm --filter @cura-web-ui/engine-api typecheck
```

Expected: generated files have no manual edits and tests pass.

## Task 2: Add gateway storage settings and filesystem layout

**Files:**

- Modify `services/slicer-gateway/app/settings.py`.
- Create `services/slicer-gateway/app/storage_paths.py`.
- Modify `services/slicer-gateway/tests/test_gateway.py` or split new focused files.

### Step 2.1: Add settings

Add environment-backed settings:

```text
SLICER_GATEWAY_STORAGE_ROOT=/data
SLICER_GATEWAY_DATABASE_PATH=/data/db/slicer.sqlite3
SLICER_GATEWAY_MAX_UPLOAD_BYTES=536870912
SLICER_GATEWAY_MAX_CONCURRENT_JOBS=1
SLICER_GATEWAY_MAX_JOB_ATTEMPTS=2
SLICER_GATEWAY_COMPLETED_RETENTION_HOURS=168
SLICER_GATEWAY_FAILED_RETENTION_HOURS=24
SLICER_GATEWAY_UPLOAD_RETENTION_HOURS=24
SLICER_GATEWAY_METADATA_RETENTION_HOURS=720
SLICER_GATEWAY_MAX_STORAGE_BYTES=4294967296
SLICER_GATEWAY_CLEANUP_INTERVAL_SECONDS=3600
SLICER_GATEWAY_LEGACY_BASE64_UPLOADS=0
SLICER_GATEWAY_RUNTIME_MODE=production|development|test
```

Production mode fails startup when storage root is absent, not writable, symlinked unexpectedly, or on a read-only filesystem. Test mode may use a temporary root supplied by fixtures.

CuraEngine auto-download defaults to false in production and remains opt-in in development.

### Step 2.2: Implement safe path resolution

`StoragePaths` creates and resolves only server-generated IDs beneath known roots. It must:

- validate ID format;
- use `Path.resolve()` and verify containment;
- reject symlink escape;
- create directories with restrictive permissions;
- expose methods such as `upload_source(upload_id)`, `job_root(job_id)`, `job_gcode(job_id)`, and `layer_chunk(job_id, layer_id)`;
- never accept an API-supplied filename as a path component;
- store original display filenames only in metadata.

### Step 2.3: Write tests

Test traversal, symlink escape, invalid IDs, parallel directory creation, permission errors, disk-full errors where practical, and exact expected layout.

## Task 3: Implement SQLite migrations and repositories

**Files:**

- Add dependency `aiosqlite` to `services/slicer-gateway/pyproject.toml`.
- Create `services/slicer-gateway/app/db.py`.
- Create `services/slicer-gateway/app/migrations/0001_jobs.sql` and a migration runner, or use versioned Python migrations if SQL resources complicate packaging.
- Create `services/slicer-gateway/app/repositories/uploads.py`.
- Create `services/slicer-gateway/app/repositories/jobs.py`.
- Create `services/slicer-gateway/app/repositories/artifacts.py`.
- Create `services/slicer-gateway/app/repositories/events.py`.
- Create corresponding tests under `services/slicer-gateway/tests`.

### Step 3.1: Define the database schema

Use at least these tables:

`schema_migrations`

```text
version primary key
applied_at
```

`uploads`

```text
id primary key
owner_id indexed
file_name
mime_type
size_bytes
sha256
storage_key unique
created_at
expires_at indexed
claimed_job_id nullable indexed
```

`jobs`

```text
id primary key
owner_id indexed
status indexed
stage
progress
attempt
max_attempts
idempotency_key nullable
request_fingerprint
request_json
engine_json nullable
artifacts_json nullable
error_json nullable
cancel_requested boolean
created_at
updated_at
started_at nullable
finished_at nullable
```

Unique index: `(owner_id, idempotency_key)` when key is not null.

`job_events`

```text
job_id
sequence
event_type
payload_json
created_at
primary key (job_id, sequence)
```

Use foreign keys where SQLite configuration enforces them. Enable WAL mode, foreign keys, and a bounded busy timeout on connection setup.

### Step 3.2: Define repository transactions

Required atomic methods:

```text
create_upload
claim_uploads_for_job
release_or_delete_upload
create_job_idempotently
claim_next_job
update_progress_if_active
request_cancel
mark_cancelled
mark_completed_with_artifacts
mark_failed
requeue_interrupted_jobs
append_event_with_next_sequence
list_jobs_for_owner
get_job_for_owner
```

`claim_next_job` uses a transaction so two worker tasks cannot run the same job. Even though default concurrency is one, write the repository correctly.

### Step 3.3: Idempotency behavior tests

Test:

- same owner/key/fingerprint returns existing job;
- same owner/key/different fingerprint returns conflict;
- different owners may reuse the key;
- concurrent creates produce one job;
- no key creates distinct jobs;
- claimed uploads cannot be claimed by another job/user.

### Step 3.4: Migration tests

Create an empty database, migrate, insert data, reopen, migrate again, and verify idempotence. Add a future-version refusal test so older code does not operate on a newer schema silently.

## Task 4: Implement streaming upload endpoints

**Files:**

- Add `python-multipart` to `services/slicer-gateway/pyproject.toml`.
- Create `services/slicer-gateway/app/routers/uploads.py`.
- Modify `services/slicer-gateway/app/main.py`.
- Modify `services/slicer-gateway/app/dependencies.py`.
- Add tests in `services/slicer-gateway/tests/test_uploads.py`.

### Step 4.1: Write tests first

Cover:

- authenticated upload succeeds and SHA/size match;
- empty file rejected;
- oversized upload rejected while streaming and partial file removed;
- accepted STL MIME/extension combinations;
- unsupported type rejected with stable code;
- original filename sanitized for display but not used as disk path;
- owner isolation on GET/DELETE;
- claimed upload cannot be deleted;
- expired upload cleanup;
- duplicate bytes still receive distinct upload IDs unless a deliberate per-owner dedupe decision is later documented;
- interrupted client upload leaves no database row or file;
- filesystem write failure leaves no row.

### Step 4.2: Stream to a temporary file

Read `UploadFile` in bounded chunks, for example 1 MiB. Simultaneously:

- count bytes;
- calculate SHA-256;
- enforce limit;
- write to a temp path in the upload directory.

After validation, atomically rename to `source.bin` and insert the database row. On database failure, delete the file. On file failure, insert nothing.

### Step 4.3: Validate content conservatively

Do not trust MIME or extension alone. For STL:

- accept valid binary STL length/triangle-count structure;
- accept plausible ASCII STL beginning with `solid` and containing facet structure;
- reject obvious HTML/JSON/executable content.

If adding 3MF upload acceptance, verify ZIP structure and required 3MF entries with zip-bomb limits. Do not claim 3MF slicing support until the adapter converts or supplies it correctly to CuraEngine.

## Task 5: Replace FastAPI BackgroundTasks with a durable JobRunner

**Files:**

- Create `services/slicer-gateway/app/job_runner.py`.
- Modify `services/slicer-gateway/app/main.py` to use a FastAPI lifespan.
- Modify `services/slicer-gateway/app/routers/jobs.py`.
- Modify `services/slicer-gateway/app/dependencies.py`.
- Create `services/slicer-gateway/tests/test_job_runner.py`.
- Modify `services/slicer-gateway/tests/test_gateway.py`.

### Step 5.1: Define lifecycle

On application lifespan startup:

1. validate/create storage paths;
2. open database and run migrations;
3. change jobs left in preparing/processing/post-processing/cancelling to either retry-queued or failed according to attempt count/cancellation flag;
4. append recovery events transactionally;
5. start `max_concurrent_jobs` worker tasks;
6. start cleanup task;
7. report ready only after initialization completes.

On shutdown:

1. stop accepting new claims;
2. mark active jobs for recoverable interruption;
3. send graceful termination to CuraEngine process groups;
4. wait up to shutdown grace;
5. force kill remaining processes;
6. flush logs/events and close DB;
7. cancel cleanup/worker tasks cleanly.

### Step 5.2: Job creation behavior

`POST /jobs`:

- validates owner/profile/uploads before creating anything;
- canonicalizes request JSON and computes fingerprint;
- creates or returns idempotent job;
- claims uploads in the same logical transaction;
- publishes/writes `job.queued`;
- signals the runner's wake event;
- returns `202` without starting work in the request task.

Remove `BackgroundTasks` entirely from this route.

### Step 5.3: Worker loop

Each worker:

1. atomically claims next queued/retry-queued job;
2. records preparing state and increments attempt;
3. invokes the adapter with immutable job/request/upload paths;
4. persists progress/events;
5. persists final artifacts before marking completed;
6. handles cancellation separately from failure;
7. converts known engine errors into stable error codes;
8. catches unexpected exceptions, records bounded diagnostics, and continues to the next job;
9. never holds the SQLite transaction open while CuraEngine runs.

When no job is available, wait on an `asyncio.Event` plus a bounded periodic wakeup. Do not busy-loop.

### Step 5.4: Tests

Use a controllable adapter double. Test FIFO claiming, concurrency limit, exception continuation, restart recovery, max-attempt exhaustion, shutdown interruption, idempotent create, and no duplicate execution across two worker tasks.

## Task 6: Refactor CuraEngine execution for progress and cancellation

**Files:**

- Modify `services/slicer-gateway/app/adapters/base.py`.
- Modify `services/slicer-gateway/app/adapters/curaengine.py`.
- Modify `services/slicer-gateway/app/adapters/basic_fdm.py` only to satisfy the new interface in development.
- Remove production use of `services/slicer-gateway/app/adapters/mock.py`; tests may retain test-only doubles outside production adapter selection.
- Create `services/slicer-gateway/app/cura_progress.py`.
- Add focused adapter/progress tests.

### Step 6.1: Define adapter result and callbacks

The adapter receives:

```text
job_id
validated immutable request
resolved upload paths
job working directory
async progress callback(stage, percentage, detail?)
async cancellation predicate or event
```

It returns structured artifact metadata and estimates, not giant G-code/layer strings.

### Step 6.2: Use async subprocess APIs

Replace blocking `subprocess.Popen(...).communicate()` inside a background callback with `asyncio.create_subprocess_exec`:

- command is an argument array;
- `start_new_session=True` on Linux;
- stdout/stderr consumed concurrently to avoid pipe deadlock;
- logs streamed to bounded file;
- progress parser receives lines;
- absolute timeout enforced with monotonic time;
- process group terminated on cancellation/timeout;
- TERM grace followed by KILL;
- process registry entry removed in `finally`.

Never invoke a shell.

### Step 6.3: Progress mapping

Parse known CuraEngine progress output defensively. When exact engine progress is unavailable, use monotonic stage floors:

- queued: 0;
- preparing/materializing models: 1–10;
- CuraEngine processing: 10–85;
- parsing preview/estimates: 85–95;
- committing artifacts: 95–99;
- completed: 100.

Never decrease progress. Throttle database progress writes, for example to a change of at least 1 percentage point or 500 ms, while preserving stage transitions immediately.

### Step 6.4: Cancellation semantics

`POST /jobs/{id}/cancel`:

- terminal job: return current job idempotently;
- queued job: mark cancelled without starting;
- active job: mark cancelling, set cancellation event, append event, return `202`;
- runner terminates process group and marks cancelled after cleanup;
- race with completion resolves transactionally: whichever terminal state commits first wins, and the API returns that state.

Test cancellation while queued, preparing, engine-running, post-processing, already complete, and during gateway shutdown.

### Step 6.5: Validate supported features before process launch

Keep current explicit errors for CLI-boundary limitations such as unsupported per-object mesh roles. Validate before launching CuraEngine, preserve a structured `unsupported_feature` code, and do not generate fake output.

## Task 7: Move artifacts out of JSON/in-memory storage

**Files:**

- Modify `services/slicer-gateway/app/routers/jobs.py`.
- Modify `services/slicer-gateway/app/preview_parser.py`.
- Modify `services/slicer-gateway/app/post_processing.py`.
- Modify repository/artifact modules from Task 3.
- Add `zstandard` only if compressed JSON chunks are retained.
- Add artifact streaming tests.

### Step 7.1: Persist artifact metadata, not contents

SQLite stores kind, relative storage key, MIME, size, SHA-256, and timestamps. G-code, logs, thumbnails, diagnostics, and layer chunks remain files.

Use atomic write/rename for every final artifact. Mark the job completed only after all required metadata references committed files.

### Step 7.2: Stream responses

Use `FileResponse` or streaming responses for G-code/logs. Add:

- `Content-Length`;
- `ETag` based on SHA-256;
- safe `Content-Disposition` filename;
- `X-Content-Type-Options: nosniff`;
- owner authorization before path resolution.

Support conditional `If-None-Match` where straightforward. Byte range support is optional for phase one unless preview performance requires it.

### Step 7.3: Layer chunks

Persist one index plus one chunk per layer. If using `.json.zst`, gateway decompresses or returns supported content encoding consistently; the TypeScript client must match. Never load all layers to serve one layer.

Add a pathological-large-layer test and enforce per-job preview size limits with a structured warning rather than exhausting memory.

## Task 8: Add WPrint bridge auth and owner isolation

**Files:**

- Modify `services/slicer-gateway/app/settings.py`.
- Modify `services/slicer-gateway/app/auth.py`.
- Modify all routers that expose owner-scoped data.
- Add `services/slicer-gateway/tests/test_auth.py`.

### Step 8.1: Add auth mode

Support:

```text
disabled       test-only
local-token    standalone development/local deployment
wprint-bridge  managed WPrint sidecar
```

In `wprint-bridge` mode:

- validate bearer against `WPRINT3D_BRIDGE_TOKEN` using constant-time comparison;
- require valid `X-WPrint-Plugin-Id` matching configured plugin ID;
- require non-empty `X-WPrint-User-Id` for user-scoped endpoints;
- accept optional printer/locale/request ID after conservative validation;
- ignore forwarded headers unless bearer auth succeeded;
- never accept identity from query parameters.

Health endpoint may validate bearer for WPrint lifecycle checks. If Docker-level health must run without a token, provide a separate loopback-safe executable health command rather than a public unauthenticated API.

### Step 8.2: Scope resources

Uploads, jobs, artifacts, events, backups, and user-created profiles must be owner-scoped. Bundled printer/material/quality catalogs may remain shared read-only resources.

Use indistinguishable `404` responses for another user's IDs.

### Step 8.3: Test header spoofing

Test invalid token with trusted-looking headers, valid token with missing user, browser-supplied internal header stripping through the WPrint proxy fixture, cross-owner reads/cancels/artifacts, and local-token backward compatibility.

## Task 9: Cleanup, quota, and database maintenance

**Files:**

- Create `services/slicer-gateway/app/cleanup.py`.
- Add `services/slicer-gateway/tests/test_cleanup.py`.
- Modify health/capabilities endpoints to report non-sensitive storage readiness.

### Step 9.1: Cleanup lock and eligibility

Use one SQLite/application lock so only one cleanup task runs. Determine eligibility from database state and timestamps, not directory mtime alone.

Never delete:

- queued/retry-queued/active job data;
- upload claimed by a nonterminal retained job;
- SQLite database/WAL files;
- paths not represented by a validated storage key.

Delete DB metadata only after deleting associated files, or mark cleanup state so retry is safe.

### Step 9.2: Enforce quota

Calculate managed storage usage without following symlinks. When above budget:

1. delete expired unclaimed uploads;
2. delete expired failed/cancelled artifacts;
3. delete expired completed artifacts oldest first;
4. preserve metadata longer according to policy;
5. if still above budget, reject new uploads with `507 Insufficient Storage` and an actionable message.

### Step 9.3: Health response

Health/capabilities may include:

```text
databaseReady
storageWritable
queueDepth
activeJobs
engineReady
engineVersion
```

Do not include paths, user IDs, filenames, tokens, or raw exception traces.

## Task 10: Remove legacy job storage from production wiring

**Files:**

- Modify `services/slicer-gateway/app/main.py`.
- Modify `services/slicer-gateway/app/storage.py`.
- Modify `docs/architecture.md`.
- Modify `README.md` where it describes removed browser-local or JSON job behavior.
- Update tests.

Keep the current memory/JSON store only for non-job catalogs if still useful. Rename/document it so nobody mistakes it for production job durability. Remove job/G-code/log/layer fields and methods after all consumers use repositories.

Production application wiring must instantiate:

- catalog/resource store;
- SQLite repositories;
- storage paths;
- event bus backed by persisted current state/events;
- job runner;
- adapter;
- cleanup service.

No production route may add `adapter.run_job` to FastAPI `BackgroundTasks`.

## Phase verification

Run from `../cura-web-ui`:

```bash
rtk proxy pnpm lint:contracts
rtk proxy pnpm typecheck
rtk proxy pnpm test
rtk proxy pnpm test:gateway
rtk proxy pnpm build
```

Then perform this manual/API recovery sequence against a temporary storage root and real CuraEngine:

1. upload a real cube STL;
2. create a job with idempotency key;
3. observe queued → preparing → processing;
4. kill the gateway process during processing;
5. restart with the same storage root;
6. verify retry-queued/recovery and exactly one final completed job;
7. verify G-code and layer preview are real;
8. create another job and cancel during CuraEngine execution;
9. verify process group exits and job becomes cancelled;
10. verify another owner cannot list/read/cancel/download either job;
11. run cleanup with synthetic expired data and verify active/completed-retained data survives.

The phase is complete only when:

- no production slicing uses FastAPI `BackgroundTasks`;
- mesh bytes use multipart upload and opaque upload IDs;
- active and queued jobs survive restart according to policy;
- idempotency prevents duplicate submissions;
- cancellation terminates the real process group;
- job/artifact state is owner-isolated;
- G-code and preview artifacts are streamed from files;
- storage quota/cleanup is safe and tested;
- OpenAPI, AsyncAPI, generated types, Python models, and TypeScript client agree.
