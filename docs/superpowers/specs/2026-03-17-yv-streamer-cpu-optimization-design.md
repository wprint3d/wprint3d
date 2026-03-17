# yv-streamer-software CPU Optimization Design

## Problem

The yv-streamer-software MJPEG fallback sidecar consumes 100% CPU on some
machines.  Root cause analysis identified four compounding issues:

1. **No idle detection** — the capture thread runs at full framerate even when
   zero MJPEG subscribers are connected.
2. **Floating-point YUV→RGB conversion** — `yuyv_to_rgb()` performs four `f32`
   multiplications per pixel per frame (eight per YUYV macro-pixel).
3. **No frame skipping** — every V4L2 frame is JPEG-encoded even when the
   previous encoded frame has not been consumed by any subscriber.
4. **Fixed JPEG quality** — encoding always uses quality 80 regardless of CPU
   headroom.

## Solution Overview

Four orthogonal changes, primarily in `manager.rs` with supporting changes in
`app.rs`, `main.rs`, and `lib.rs`:

| Change | Mechanism | Expected Impact |
|--------|-----------|-----------------|
| Subscriber-aware idle shutdown | `receiver_count()` + 30 s grace period | 0 % CPU when nobody watches |
| Integer YUV→RGB | Fixed-point arithmetic (×2¹⁶ coefficients, `i32` ops) | 2–4× faster conversion |
| Frame skipping | `AtomicBool` consumed flag; skip encode if previous frame unread | Self-regulating under load |
| Adaptive JPEG quality | Per-frame timing vs. frame budget; quality 30–80 | Graceful degradation on slow CPUs |

## Design Details

### 1. Subscriber-Aware Idle Shutdown

The capture loop checks `self.latest_frame.receiver_count()` before each
iteration.  When the count drops to zero a monotonic timestamp is recorded.
If 30 continuous seconds elapse with zero receivers the capture thread breaks
out of both the inner frame loop and the outer `capture_once` retry loop and
exits.

**`receiver_count()` safety:** The initial `watch::Receiver` created by
`watch::channel()` is immediately dropped in `spawn_live` (the `let (tx, _)`
pattern).  No internal code retains a `Receiver` — only active MJPEG stream
handlers hold receivers via `subscribe()`.  Therefore `receiver_count() == 0`
reliably indicates zero active subscribers.

**Cleanup channel:** `CameraManager` construction changes from the derived
`Default` to an explicit `new()` method that creates a
`std::sync::mpsc::channel::<String>`.  The sending half is stored in
`CameraManager` (behind the existing `Arc`) and cloned into each
`CameraWorker`.  The receiving half is returned from `CameraManager::new()`
so that `main.rs` can spawn a tokio task to drain it.  `std::sync::mpsc` is
chosen because the capture thread is a `std::thread`, not a tokio task — it
needs a blocking send.  The tokio drain task wraps the blocking
`recv()` in `tokio::task::spawn_blocking` or a loop with `try_recv()` +
`tokio::time::sleep`.

On exit, the capture thread sends its `camera_id` through the cleanup sender.
The drain task calls `workers.write().remove(camera_id)`.

If a subscriber arrives during the grace window the idle timer resets and
capture continues normally.

```
last subscriber disconnects
  → receiver_count() == 0
  → idle timer starts (30 s)
  → [no new subscriber]
  → capture thread exits
  → camera_id sent to cleanup channel
  → CameraManager drain task removes worker
  → next request must provide full bootstrap headers
```

**Logging:** Idle shutdown emits an `info!` log when the grace period starts
and when the worker is removed.  Grace-period reset (subscriber reconnects)
emits a `debug!` log.

### 2. Integer YUV→RGB Conversion

Replace the per-pixel floating-point YUV→RGB with fixed-point integer
arithmetic.  Coefficients are pre-scaled by 65 536 (2¹⁶):

```
// float:   y + 1.402 * v
// integer: (y_fixed + 91881 * v_int) >> 16
```

Same function signatures (`yuyv_to_rgb`, `yuv_to_rgb`), same pixel-accurate
output (±1 rounding).  No new dependencies.

### 3. Frame Skipping

An `AtomicBool` flag `frame_consumed` is added to `CameraWorker`:

- `publish_frame()` sets `frame_consumed` to `false` (a new frame was just
  published — not yet consumed by any subscriber).
- The MJPEG stream handler in `app.rs` (`mjpeg_stream_response`) sets it to
  `true` after reading a frame (marking it as consumed).
- Before encoding, the capture loop checks the flag.  If `false` (previous
  frame not yet consumed), the V4L2 frame is still drained via
  `stream.next()` to keep kernel buffers flowing, but encoding is skipped.

This means `app.rs` **does** change: the `mjpeg_stream_response` function
needs access to the worker's `frame_consumed` flag.  The `subscribe()` method
is extended to return a tuple
`(watch::Receiver<Bytes>, Arc<AtomicBool>)` — the receiver and a handle to
the consumed flag.

**Logging:** Frame skips are counted and reported at `debug!` level every
300 frames (matching the existing frame-count logging cadence).

Under sustained load this naturally halves (or further reduces) the encoding
rate.

### 4. Adaptive JPEG Quality (Toggleable)

A new bootstrap parameter controls the feature:

| Source | Key | Default |
|--------|-----|---------|
| Header | `X-Adaptive-Quality` | `false` |
| Query  | `adaptive_quality`   | `false` |

`adaptive_quality` is stored as an `AtomicBool` on `CameraWorker`, **not** as
part of the `CameraConfig` equality check.  This allows toggling adaptive
quality at runtime without restarting the capture pipeline (re-opening the
V4L2 device, re-creating mmap buffers).  The `CameraConfig` struct still
carries the field for serialization/state reporting, but `PartialEq` is
implemented manually to exclude it.

**Initial quality:** When adaptive mode is enabled, quality starts at 80
(the maximum) and degrades only if encoding time warrants it.

When enabled:

1. Encoding duration is measured with `std::time::Instant`.
2. Frame budget = `1000 ms / framerate`.
3. Adjustment rules (applied after 3 consecutive frames in the same
   direction to avoid oscillation; the consecutive-frame counter resets
   whenever the direction changes or a "hold" result occurs):
   - Encoding > 70 % of budget → quality −5 (floor 30).
   - Encoding < 40 % of budget → quality +5 (ceiling 80).
   - Otherwise → hold (resets counter).
4. When disabled, quality is fixed at 80.

**Logging:** Quality changes emit a `debug!` log with the old and new values.

### 5. API & State Changes

**`CameraConfig`** gains:

```rust
pub adaptive_quality: bool,
```

`PartialEq` is manually implemented to **exclude** `adaptive_quality`, so
toggling it does not restart the worker.

**`CameraStateSnapshot`** gains:

```rust
pub adaptive_quality: bool,
pub current_jpeg_quality: u8,
```

The `/state` endpoint now reports both the toggle and the live quality level.

### 6. Files Changed

| File | Scope of change |
|------|-----------------|
| `src/manager.rs` | All four optimizations; new fields on `CameraConfig`, `CameraStateSnapshot`, `CameraWorker`; cleanup channel sender; manual `PartialEq` for `CameraConfig` |
| `src/app.rs` | `mjpeg_stream_response` sets `frame_consumed` flag after reading each frame |
| `src/main.rs` | `CameraManager::new()` replaces `default()`; spawn drain task for cleanup channel |
| `src/lib.rs` | Update `CameraConfig::test()` and existing tests for new field |

`src/startup.rs` is untouched.

## Out of Scope

- Hardware-accelerated encoding (e.g. VA-API) — future enhancement.
- Multi-format output (WebRTC, HLS) — unrelated.
- Frontend UI for the adaptive-quality toggle — handled by WPrint core, not
  this sidecar.

## Testing

- Existing unit tests updated for the new `adaptive_quality` field.
- New unit test: verify manual `CameraConfig` `PartialEq` excludes
  `adaptive_quality` but includes all other fields.
- New unit test: verify integer `yuv_to_rgb` matches floating-point output
  within ±1 per channel.
- E2E Docker test: start the sidecar, request a stream, disconnect, confirm
  the `/api/v1/cameras` list endpoint returns an empty list after 30 s.
  Note: this test requires a 30+ second wait, so it should be tagged as a
  slow/integration test in CI.
- Manual CPU profiling before/after on a 1080p YUYV source.
