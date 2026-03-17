# yv-streamer-software CPU Optimization Design

## Problem

The yv-streamer-software MJPEG fallback sidecar consumes 100% CPU on some
machines.  Root cause analysis identified four compounding issues:

1. **No idle detection** — the capture thread runs at full framerate even when
   zero MJPEG subscribers are connected.
2. **Floating-point YUV→RGB conversion** — `yuyv_to_rgb()` performs six `f32`
   multiplications per pixel per frame.
3. **No frame skipping** — every V4L2 frame is JPEG-encoded even when the
   previous encoded frame has not been consumed by any subscriber.
4. **Fixed JPEG quality** — encoding always uses quality 80 regardless of CPU
   headroom.

## Solution Overview

Four orthogonal changes, all confined to `manager.rs` (with minor test
updates in `lib.rs`):

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

On exit, the thread sends its `camera_id` through a cleanup channel
(`tokio::sync::mpsc` or `std::sync::mpsc`) that the `CameraManager` monitors.
The manager removes the worker from the `workers` HashMap, so subsequent
requests without bootstrap headers receive a 404.

If a subscriber arrives during the grace window the idle timer resets and
capture continues normally.

```
last subscriber disconnects
  → receiver_count() == 0
  → idle timer starts (30 s)
  → [no new subscriber]
  → capture thread exits
  → camera_id sent to cleanup channel
  → CameraManager removes worker
  → next request must provide full bootstrap headers
```

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

- `publish_frame()` sets it to `true`.
- The MJPEG stream handler sets it to `false` after reading a frame.
- Before encoding, the capture loop checks the flag.  If `true` (previous
  frame not yet consumed), the V4L2 frame is still drained via
  `stream.next()` to keep kernel buffers flowing, but encoding is skipped.

Under sustained load this naturally halves (or further reduces) the encoding
rate.

### 4. Adaptive JPEG Quality (Toggleable)

A new bootstrap parameter controls the feature:

| Source | Key | Default |
|--------|-----|---------|
| Header | `X-Adaptive-Quality` | `false` |
| Query  | `adaptive_quality`   | `false` |

When enabled:

1. Encoding duration is measured with `std::time::Instant`.
2. Frame budget = `1000 ms / framerate`.
3. Adjustment rules (applied after 3 consecutive frames in the same
   direction to avoid oscillation):
   - Encoding > 70 % of budget → quality −5 (floor 30).
   - Encoding < 40 % of budget → quality +5 (ceiling 80).
   - Otherwise → hold.
4. When disabled, quality is fixed at 80.

### 5. API & State Changes

**`CameraConfig`** gains:

```rust
pub adaptive_quality: bool,
```

This field participates in the `PartialEq` check, so toggling it triggers a
worker restart.

**`CameraStateSnapshot`** gains:

```rust
pub adaptive_quality: bool,
pub current_jpeg_quality: u8,
```

The `/state` endpoint now reports both the toggle and the live quality level.

### 6. Files Changed

| File | Scope of change |
|------|-----------------|
| `src/manager.rs` | All four optimizations; new fields on `CameraConfig`, `CameraStateSnapshot`, `CameraWorker` |
| `src/lib.rs` | Update `CameraConfig::test()` and existing tests for new field |
| `src/main.rs` | Spawn a background task to drain the cleanup channel |

`src/app.rs` and `src/startup.rs` are untouched.

## Out of Scope

- Hardware-accelerated encoding (e.g. VA-API) — future enhancement.
- Multi-format output (WebRTC, HLS) — unrelated.
- Frontend UI for the adaptive-quality toggle — handled by WPrint core, not
  this sidecar.

## Testing

- Existing unit tests updated for the new `adaptive_quality` field.
- New unit test: verify `CameraConfig` equality detects `adaptive_quality`
  changes.
- E2E Docker test: start the sidecar, request a stream, disconnect, verify
  the worker is cleaned up after 30 s.
- Manual CPU profiling before/after on a 1080p YUYV source.
