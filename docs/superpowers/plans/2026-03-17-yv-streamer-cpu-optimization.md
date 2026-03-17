# yv-streamer-software CPU Optimization Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Reduce CPU usage to near-zero when idle and significantly lower it during active streaming by implementing subscriber-aware idle shutdown, integer YUV→RGB, frame skipping, and adaptive JPEG quality.

**Architecture:** Four orthogonal changes layered into the existing `CameraWorker` capture loop. A cleanup channel enables the capture thread (std::thread) to signal the async CameraManager when it exits. The `subscribe()` return type expands to include a frame-consumed flag for backpressure.

**Tech Stack:** Rust, tokio, axum, v4l, jpeg-encoder, tokio::sync::watch, std::sync::mpsc

---

### Task 1: Integer YUV→RGB Conversion

**Files:**
- Modify: `yv-streamer-software/src/manager.rs:525-551` (replace `yuyv_to_rgb`, `yuv_to_rgb`, `clamp`)
- Test: `yv-streamer-software/src/lib.rs` (new test)

- [ ] **Step 1: Write failing test for integer YUV→RGB accuracy**

Add to `lib.rs` tests:

```rust
#[test]
fn integer_yuv_to_rgb_matches_reference_output() {
    use crate::manager::yuv_to_rgb;

    // Pure white: Y=255, U=0, V=0 (after -128 offset applied by caller)
    assert_eq!(yuv_to_rgb(255, 0, 0), [255, 255, 255]);

    // Pure black: Y=0, U=0, V=0
    assert_eq!(yuv_to_rgb(0, 0, 0), [0, 0, 0]);

    // Mid-gray: Y=128, U=0, V=0
    assert_eq!(yuv_to_rgb(128, 0, 0), [128, 128, 128]);

    // Red-ish: Y=128, U=-50, V=100
    let rgb = yuv_to_rgb(128, -50, 100);
    // Verify each channel is within ±1 of float reference
    let ref_r = (128.0 + 1.402 * 100.0).round().clamp(0.0, 255.0) as u8;
    let ref_g = (128.0 - 0.344136 * -50.0 - 0.714136 * 100.0).round().clamp(0.0, 255.0) as u8;
    let ref_b = (128.0 + 1.772 * -50.0).round().clamp(0.0, 255.0) as u8;
    assert!((rgb[0] as i16 - ref_r as i16).abs() <= 1, "red: {} vs {}", rgb[0], ref_r);
    assert!((rgb[1] as i16 - ref_g as i16).abs() <= 1, "green: {} vs {}", rgb[1], ref_g);
    assert!((rgb[2] as i16 - ref_b as i16).abs() <= 1, "blue: {} vs {}", rgb[2], ref_b);
}
```

Note: `yuv_to_rgb` must be made `pub` for the test to access it. Change its signature from `fn yuv_to_rgb(y: f32, u: f32, v: f32) -> [u8; 3]` to `pub fn yuv_to_rgb(y: i32, u: i32, v: i32) -> [u8; 3]`. The test import needs updating: `use crate::manager::yuv_to_rgb;`.

- [ ] **Step 2: Run test to verify it fails**

Run: `cd yv-streamer-software && cargo test integer_yuv_to_rgb -- --nocapture`
Expected: FAIL — `yuv_to_rgb` not public or wrong signature.

- [ ] **Step 3: Implement integer YUV→RGB**

Replace `manager.rs:525-551` (`yuyv_to_rgb`, `yuv_to_rgb`, `clamp`) with:

```rust
fn yuyv_to_rgb(frame: &[u8]) -> Vec<u8> {
    let mut output = Vec::with_capacity(frame.len() / 2 * 3);

    for chunk in frame.chunks_exact(4) {
        let y0 = chunk[0] as i32;
        let u  = chunk[1] as i32 - 128;
        let y1 = chunk[2] as i32;
        let v  = chunk[3] as i32 - 128;

        output.extend_from_slice(&yuv_to_rgb(y0, u, v));
        output.extend_from_slice(&yuv_to_rgb(y1, u, v));
    }

    output
}

pub fn yuv_to_rgb(y: i32, u: i32, v: i32) -> [u8; 3] {
    // Coefficients scaled by 2^16 (65536):
    //   1.402    * 65536 = 91881
    //   0.344136 * 65536 = 22554
    //   0.714136 * 65536 = 46802
    //   1.772    * 65536 = 116130
    let y_fixed = y << 16;
    let red   = (y_fixed + 91881 * v + 32768) >> 16;
    let green = (y_fixed - 22554 * u - 46802 * v + 32768) >> 16;
    let blue  = (y_fixed + 116130 * u + 32768) >> 16;

    [clamp_u8(red), clamp_u8(green), clamp_u8(blue)]
}

fn clamp_u8(value: i32) -> u8 {
    value.clamp(0, 255) as u8
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd yv-streamer-software && cargo test integer_yuv_to_rgb -- --nocapture`
Expected: PASS

- [ ] **Step 5: Run full test suite**

Run: `cd yv-streamer-software && cargo test`
Expected: All tests PASS

- [ ] **Step 6: Commit**

```bash
git add yv-streamer-software/src/manager.rs yv-streamer-software/src/lib.rs
git commit -m "perf: replace floating-point YUV→RGB with integer arithmetic"
```

---

### Task 2: CameraConfig — Add `adaptive_quality` with Manual PartialEq

**Files:**
- Modify: `yv-streamer-software/src/manager.rs:27-92` (`CameraConfig` struct + impls)
- Test: `yv-streamer-software/src/lib.rs` (new test)

- [ ] **Step 1: Write failing test for PartialEq excluding adaptive_quality**

Add to `lib.rs` tests:

```rust
#[test]
fn camera_config_equality_ignores_adaptive_quality() {
    let mut a = CameraConfig::test("cam-1");
    let mut b = CameraConfig::test("cam-1");

    // Same config, different adaptive_quality — should be equal
    a.adaptive_quality = false;
    b.adaptive_quality = true;
    assert_eq!(a, b);

    // Different node — should NOT be equal
    b.adaptive_quality = false;
    b.node = "/dev/video1".to_string();
    assert_ne!(a, b);
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd yv-streamer-software && cargo test camera_config_equality -- --nocapture`
Expected: FAIL — `adaptive_quality` field doesn't exist.

- [ ] **Step 3: Implement CameraConfig changes**

In `manager.rs`:

1. Remove `PartialEq, Eq` from the `#[derive]` on `CameraConfig` (line 27).
2. Add `adaptive_quality: bool` field to `CameraConfig`.
3. Implement `PartialEq` and `Eq` manually, excluding `adaptive_quality`.
4. Update `CameraConfig::test()` to include `adaptive_quality: false`.
5. Update `CameraConfig::from_request()` to parse `adaptive_quality`.

The struct becomes:

```rust
#[derive(Clone, Debug, Serialize)]
pub struct CameraConfig {
    pub camera_id: String,
    pub node: String,
    pub width: u32,
    pub height: u32,
    pub framerate: u32,
    pub capture_encoding: String,
    pub adaptive_quality: bool,
}

impl PartialEq for CameraConfig {
    fn eq(&self, other: &Self) -> bool {
        self.camera_id == other.camera_id
            && self.node == other.node
            && self.width == other.width
            && self.height == other.height
            && self.framerate == other.framerate
            && self.capture_encoding == other.capture_encoding
    }
}

impl Eq for CameraConfig {}
```

Update `test()`:

```rust
pub fn test(camera_id: &str) -> Self {
    Self {
        camera_id: camera_id.to_string(),
        node: "/dev/video0".to_string(),
        width: 640,
        height: 480,
        framerate: 30,
        capture_encoding: "YUYV".to_string(),
        adaptive_quality: false,
    }
}
```

Update `from_request()` — after `capture_encoding`, add:

```rust
let adaptive_quality = value_from_request(
    query,
    headers,
    "adaptive_quality",
    "x-adaptive-quality",
)
.or_else(|| existing.map(|config| config.adaptive_quality.to_string()))
.map(|v| v.eq_ignore_ascii_case("true"))
.unwrap_or(false);
```

And include `adaptive_quality` in the `Ok(Self { ... })` return.

- [ ] **Step 4: Run test to verify it passes**

Run: `cd yv-streamer-software && cargo test camera_config_equality -- --nocapture`
Expected: PASS

- [ ] **Step 5: Run full test suite**

Run: `cd yv-streamer-software && cargo test`
Expected: All tests PASS

- [ ] **Step 6: Commit**

```bash
git add yv-streamer-software/src/manager.rs yv-streamer-software/src/lib.rs
git commit -m "feat: add adaptive_quality to CameraConfig with manual PartialEq"
```

---

### Task 3: CameraStateSnapshot + CameraWorker Core Changes

**Files:**
- Modify: `yv-streamer-software/src/manager.rs:94-401` (`CameraStateSnapshot`, `CameraWorker` struct and methods)

This task adds the new fields and the `frame_consumed` flag. No new tests yet — existing tests must still pass.

- [ ] **Step 1: Add fields to CameraStateSnapshot**

In `manager.rs`, add to `CameraStateSnapshot` (after line 105):

```rust
pub adaptive_quality: bool,
pub current_jpeg_quality: u8,
```

- [ ] **Step 2: Add new fields to CameraWorker**

Modify the `CameraWorker` struct (line 266-272) to add:

```rust
pub struct CameraWorker {
    config: CameraConfig,
    latest_frame: watch::Sender<Bytes>,
    stop: Arc<AtomicBool>,
    frame_count: AtomicU64,
    frame_consumed: Arc<AtomicBool>,
    adaptive_quality: Arc<AtomicBool>,
    current_quality: Arc<std::sync::atomic::AtomicU8>,
    cleanup_sender: Option<std::sync::mpsc::Sender<String>>,
    state: Arc<RwLock<CameraWorkerState>>,
}
```

Add import for `AtomicU8` to the `use` block at top: update `atomic::{AtomicBool, AtomicU64, Ordering}` to `atomic::{AtomicBool, AtomicU8, AtomicU64, Ordering}`.

- [ ] **Step 3: Update spawn_live to initialize new fields**

In `spawn_live` (line 275-314), update the `Arc::new(Self { ... })` block:

```rust
let worker = Arc::new(Self {
    config,
    latest_frame,
    stop: Arc::new(AtomicBool::new(false)),
    frame_count: AtomicU64::new(0),
    frame_consumed: Arc::new(AtomicBool::new(true)),
    adaptive_quality: Arc::new(AtomicBool::new(false)),
    current_quality: Arc::new(AtomicU8::new(80)),
    cleanup_sender: None,
    state: Arc::new(RwLock::new(CameraWorkerState {
        status: "starting".to_string(),
        last_error: None,
        last_frame_at_ms: None,
    })),
});
```

Note: `adaptive_quality` and `cleanup_sender` will be set properly in Task 5 when `CameraManager` is updated. For now, use defaults so existing tests keep passing.

- [ ] **Step 4: Update from_static_frame similarly**

`from_static_frame` is only used by test helpers (`register_static_frame`). These workers never enter the capture loop, so `cleanup_sender: None` is intentional — they have no capture thread to signal idle shutdown.

```rust
fn from_static_frame(config: CameraConfig, frame: Vec<u8>) -> Self {
    let (latest_frame, _) = watch::channel(Bytes::from(frame));
    Self {
        config,
        latest_frame,
        stop: Arc::new(AtomicBool::new(false)),
        frame_count: AtomicU64::new(1),
        frame_consumed: Arc::new(AtomicBool::new(true)),
        adaptive_quality: Arc::new(AtomicBool::new(false)),
        current_quality: Arc::new(AtomicU8::new(80)),
        cleanup_sender: None,
        state: Arc::new(RwLock::new(CameraWorkerState {
            status: "ready".to_string(),
            last_error: None,
            last_frame_at_ms: Some(now_millis()),
        })),
    }
}
```

- [ ] **Step 5: Update snapshot() to include new fields**

In `snapshot()` (line 343-358), add to the returned struct:

```rust
adaptive_quality: self.adaptive_quality.load(Ordering::Relaxed),
current_jpeg_quality: self.current_quality.load(Ordering::Relaxed),
```

- [ ] **Step 6: Update publish_frame() to set frame_consumed = false**

In `publish_frame` (line 379-401), add after `let _ = self.latest_frame.send(frame);`:

```rust
self.frame_consumed.store(false, Ordering::Relaxed);
```

- [ ] **Step 7: Run full test suite**

Run: `cd yv-streamer-software && cargo test`
Expected: All tests PASS. Note: `subscribe()` signature is unchanged at this point so `app.rs` still compiles.

- [ ] **Step 8: Commit**

```bash
git add yv-streamer-software/src/manager.rs
git commit -m "feat: add frame_consumed, adaptive_quality, and cleanup_sender to CameraWorker"
```

---

### Task 4: Update subscribe() Signature + app.rs

**Files:**
- Modify: `yv-streamer-software/src/manager.rs:331-333` (`subscribe()` method)
- Modify: `yv-streamer-software/src/app.rs:15,64-74,125-152`

Both files change together in this task so the project compiles at every step.

- [ ] **Step 1: Update subscribe() in manager.rs to return frame_consumed handle**

Change `subscribe` (line 331-333 of manager.rs):

```rust
pub fn subscribe(&self) -> (watch::Receiver<Bytes>, Arc<AtomicBool>) {
    (self.latest_frame.subscribe(), self.frame_consumed.clone())
}
```

- [ ] **Step 2: Update app.rs imports**

In `app.rs`, add `Arc` and `AtomicBool` imports. Change the `use crate::manager` line and add:

```rust
use std::sync::atomic::{AtomicBool, Ordering};
use std::sync::Arc;
```

- [ ] **Step 2: Update internal_stream to destructure new subscribe() return**

Change `internal_stream` (line 64-74):

```rust
async fn internal_stream(
    State(manager): State<CameraManager>,
    Path(camera_id): Path<String>,
    Query(query): Query<HashMap<String, String>>,
    headers: HeaderMap,
) -> Response {
    match manager.ensure_or_get_existing(&camera_id, &query, &headers) {
        Ok(worker) => {
            let (receiver, frame_consumed) = worker.subscribe();
            mjpeg_stream_response(receiver, frame_consumed)
        }
        Err(error) => error_response(error),
    }
}
```

- [ ] **Step 3: Update mjpeg_stream_response to accept and use frame_consumed**

Change `mjpeg_stream_response` (line 125-152):

```rust
fn mjpeg_stream_response(
    mut receiver: tokio::sync::watch::Receiver<Bytes>,
    frame_consumed: Arc<AtomicBool>,
) -> Response {
    let body_stream = stream! {
        let initial = receiver.borrow().clone();
        if !initial.is_empty() {
            frame_consumed.store(true, Ordering::Relaxed);
            yield Ok::<Bytes, Infallible>(build_mjpeg_chunk(&initial));
        }

        while receiver.changed().await.is_ok() {
            let frame = receiver.borrow().clone();

            if frame.is_empty() {
                continue;
            }

            frame_consumed.store(true, Ordering::Relaxed);
            yield Ok::<Bytes, Infallible>(build_mjpeg_chunk(&frame));
        }
    };

    let mut response = Response::new(Body::from_stream(body_stream));
    response.headers_mut().insert(
        "content-type",
        HeaderValue::from_static("multipart/x-mixed-replace; boundary=frame"),
    );
    response
        .headers_mut()
        .insert("x-accel-buffering", HeaderValue::from_static("no"));
    response
}
```

- [ ] **Step 4: Run full test suite**

Run: `cd yv-streamer-software && cargo test`
Expected: All tests PASS

- [ ] **Step 5: Commit**

```bash
git add yv-streamer-software/src/app.rs
git commit -m "feat: propagate frame_consumed flag through MJPEG stream handler"
```

---

### Task 5: CameraManager — Cleanup Channel + new() Constructor

**Files:**
- Modify: `yv-streamer-software/src/manager.rs:108-234` (`CameraManager` struct + impl)
- Modify: `yv-streamer-software/src/main.rs` (use `new()`, spawn drain task)
- Test: `yv-streamer-software/src/lib.rs` (update test constructors)

- [ ] **Step 1: Restructure CameraManager**

Replace the `CameraManager` struct and add `new()` (line 108-111):

```rust
#[derive(Clone)]
pub struct CameraManager {
    workers: Arc<RwLock<HashMap<String, Arc<CameraWorker>>>>,
    cleanup_sender: std::sync::mpsc::Sender<String>,
}

impl CameraManager {
    pub fn new() -> (Self, std::sync::mpsc::Receiver<String>) {
        let (sender, receiver) = std::sync::mpsc::channel();
        let manager = Self {
            workers: Arc::new(RwLock::new(HashMap::new())),
            cleanup_sender: sender,
        };
        (manager, receiver)
    }

    #[cfg(test)]
    pub fn test_new() -> Self {
        let (sender, _receiver) = std::sync::mpsc::channel();
        Self {
            workers: Arc::new(RwLock::new(HashMap::new())),
            cleanup_sender: sender,
        }
    }
```

Remove the `Default` derive. The `test_new()` method is for tests that don't need the receiver.

- [ ] **Step 2: Update spawn_live to accept cleanup_sender**

Change `CameraWorker::spawn_live` signature to accept the cleanup sender and the adaptive_quality flag:

```rust
fn spawn_live(
    config: CameraConfig,
    cleanup_sender: std::sync::mpsc::Sender<String>,
) -> Result<Arc<Self>, CameraManagerError> {
```

Inside, set the fields:

```rust
let worker = Arc::new(Self {
    adaptive_quality: Arc::new(AtomicBool::new(config.adaptive_quality)),
    cleanup_sender: Some(cleanup_sender),
    // ... rest unchanged
});
```

- [ ] **Step 3: Update ensure_camera to pass cleanup_sender**

In `ensure_camera` (line 158), change:

```rust
let worker = CameraWorker::spawn_live(config, self.cleanup_sender.clone())?;
```

Also, when the config matches but `adaptive_quality` differs, update the runtime flag without restarting. First, add a public setter to `CameraWorker`:

```rust
pub fn set_adaptive_quality(&self, enabled: bool) {
    self.adaptive_quality.store(enabled, Ordering::Relaxed);
}
```

Then update the reuse branch in `ensure_camera`:

```rust
if let Some(worker) = current {
    if worker.config() == &config {
        // Update adaptive_quality without restart (excluded from PartialEq)
        worker.set_adaptive_quality(config.adaptive_quality);
        debug!("Reusing existing worker for camera {}", camera_id);
        return Ok(worker);
    }
    // ... rest of restart logic unchanged
}
```

- [ ] **Step 4: Update main.rs**

Replace `main.rs` with:

```rust
use std::{env, net::SocketAddr};

use tokio::net::TcpListener;
use tracing_subscriber::{layer::SubscriberExt, util::SubscriberInitExt};
use yv_streamer_software::{
    app::build_router,
    manager::CameraManager,
    startup::{self, LOG_LEVEL_ENV},
};

#[tokio::main]
async fn main() {
    let log_filter = startup::resolve_log_filter(
        env::var(LOG_LEVEL_ENV).ok(),
        env::var("RUST_LOG").ok(),
    );

    tracing_subscriber::registry()
        .with(
            tracing_subscriber::EnvFilter::try_new(log_filter.clone())
                .unwrap_or_else(|_| "info".into()),
        )
        .with(tracing_subscriber::fmt::layer())
        .init();

    tracing::info!(
        "{}={}",
        LOG_LEVEL_ENV,
        env::var(LOG_LEVEL_ENV).unwrap_or_else(|_| "<unset>".to_string())
    );

    let host = env::var("YV_STREAMER_SOFTWARE_HOST").unwrap_or_else(|_| "0.0.0.0".to_string());
    let port = env::var("YV_STREAMER_SOFTWARE_PORT")
        .ok()
        .and_then(|value| value.parse::<u16>().ok())
        .unwrap_or(8080);

    let address: SocketAddr = format!("{host}:{port}")
        .parse()
        .expect("failed to parse listen address");
    let listener = TcpListener::bind(address)
        .await
        .expect("failed to bind listener");

    let (manager, cleanup_receiver) = CameraManager::new();

    // Spawn a background task that drains the cleanup channel.
    // When a capture thread exits due to idle timeout it sends its camera_id
    // here so the manager can remove the stale worker entry.
    let drain_manager = manager.clone();
    tokio::task::spawn_blocking(move || {
        while let Ok(camera_id) = cleanup_receiver.recv() {
            tracing::info!("Removing idle worker for camera {}", camera_id);
            drain_manager.remove_worker(&camera_id);
        }
    });

    if startup::should_emit_debug_boot_report(&log_filter) {
        startup::log_debug_boot_report(&manager, &host, port);
    }

    tracing::info!("yv-streamer-software listening on {}", address);

    axum::serve(listener, build_router(manager))
        .await
        .expect("server exited unexpectedly");
}
```

- [ ] **Step 5: Add remove_worker to CameraManager**

Add to the `CameraManager` impl:

```rust
pub fn remove_worker(&self, camera_id: &str) {
    self.workers
        .write()
        .expect("camera worker write lock poisoned")
        .remove(camera_id);
    debug!("Removed idle worker for camera {}; managed cameras: {:?}", camera_id, self.active_camera_ids());
}
```

- [ ] **Step 6: Update all tests to use test_new()**

In `lib.rs`, replace every `CameraManager::default()` with `CameraManager::test_new()`.

- [ ] **Step 7: Run full test suite**

Run: `cd yv-streamer-software && cargo test`
Expected: All tests PASS

- [ ] **Step 8: Commit**

```bash
git add yv-streamer-software/src/manager.rs yv-streamer-software/src/main.rs yv-streamer-software/src/lib.rs
git commit -m "feat: add cleanup channel and CameraManager::new() constructor"
```

---

### Task 6: Capture Loop — Frame Skipping + Idle Shutdown + Adaptive Quality

**Files:**
- Modify: `yv-streamer-software/src/manager.rs:403-523` (`run_capture_loop`, `capture_once`, `encode_frame_to_jpeg`)

This is the core optimization task. All three remaining optimizations happen in the capture loop.

- [ ] **Step 1: Add Instant import**

Add to the `use std::time` import at top of `manager.rs`:

```rust
use std::time::{Duration, Instant, SystemTime, UNIX_EPOCH};
```

- [ ] **Step 2: Rewrite run_capture_loop with idle shutdown**

Replace `run_capture_loop` (line 403-425):

```rust
fn run_capture_loop(&self) {
    debug!(
        "Camera {} capture loop started for node {}",
        self.config.camera_id,
        self.config.node
    );

    let idle_timeout = Duration::from_secs(30);
    // Track whether we've ever had a subscriber. On startup the capture
    // thread races with the first HTTP request, so receiver_count() == 0
    // is expected. We only enter the idle-shutdown path after at least one
    // subscriber has connected and then disconnected.
    let mut had_subscriber = false;

    while !self.stop.load(Ordering::Relaxed) {
        if self.latest_frame.receiver_count() > 0 {
            had_subscriber = true;
        }

        // Only enter idle shutdown after we've had at least one subscriber
        if had_subscriber && self.latest_frame.receiver_count() == 0 {
            let idle_start = Instant::now();
            info!(
                "Camera {} has no subscribers, starting 30s idle grace period",
                self.config.camera_id
            );

            loop {
                thread::sleep(Duration::from_millis(250));

                if self.stop.load(Ordering::Relaxed) {
                    debug!("Camera {} capture loop stopped (explicit stop during idle)", self.config.camera_id);
                    return;
                }

                if self.latest_frame.receiver_count() > 0 {
                    debug!(
                        "Camera {} subscriber reconnected during grace period",
                        self.config.camera_id
                    );
                    break;
                }

                if idle_start.elapsed() >= idle_timeout {
                    info!(
                        "Camera {} idle timeout reached (30s), shutting down capture thread",
                        self.config.camera_id
                    );
                    if let Some(sender) = &self.cleanup_sender {
                        let _ = sender.send(self.config.camera_id.clone());
                    }
                    return;
                }
            }
        }

        match self.capture_once() {
            Ok(()) => {}
            Err(error) => {
                warn!(
                    "Camera {} capture iteration failed: {}",
                    self.config.camera_id, error
                );
                self.set_error(error);
                thread::sleep(Duration::from_millis(500));
            }
        }
    }

    debug!("Camera {} capture loop stopped", self.config.camera_id);
}
```

- [ ] **Step 3: Rewrite capture_once with frame skipping, idle check, and adaptive quality**

Replace `capture_once` (line 427-493):

```rust
fn capture_once(&self) -> Result<(), String> {
    self.set_status("opening");

    debug!(
        "Camera {} opening device {} with requested {}x{} {}fps ({})",
        self.config.camera_id,
        self.config.node,
        self.config.width,
        self.config.height,
        self.config.framerate,
        self.config.capture_encoding
    );

    let device = Device::with_path(&self.config.node)
        .map_err(|error| format!("Failed to open {}: {error}", self.config.node))?;

    let mut format = device
        .format()
        .map_err(|error| format!("Failed to read device format: {error}"))?;
    format.width = self.config.width;
    format.height = self.config.height;
    format.fourcc = encoding_to_fourcc(&self.config.capture_encoding)
        .ok_or_else(|| format!("Unsupported capture encoding {}", self.config.capture_encoding))?;
    let applied_format = device
        .set_format(&format)
        .map_err(|error| format!("Failed to set device format: {error}"))?;

    debug!(
        "Camera {} applied V4L2 format {}x{} ({})",
        self.config.camera_id,
        applied_format.width,
        applied_format.height,
        applied_format.fourcc.str().unwrap_or("unknown")
    );

    let params = Parameters::with_fps(self.config.framerate);
    let _ = device.set_params(&params);
    debug!(
        "Camera {} requested stream parameters at {}fps",
        self.config.camera_id,
        self.config.framerate
    );

    let mut stream =
        MmapStream::with_buffers(&device, Type::VideoCapture, 4).map_err(|error| error.to_string())?;

    self.set_status("streaming");
    debug!(
        "Camera {} entered streaming state using mmap buffers",
        self.config.camera_id
    );

    let frame_budget = Duration::from_millis(1000 / self.config.framerate.max(1) as u64);
    let mut quality: u8 = 80;
    let mut consecutive_direction: i8 = 0; // -1 = decrease, +1 = increase
    let mut consecutive_count: u8 = 0;
    let mut skipped_frames: u64 = 0;

    while !self.stop.load(Ordering::Relaxed) {
        // Idle check: if no subscribers, break out to let run_capture_loop handle grace period
        if self.latest_frame.receiver_count() == 0 {
            debug!("Camera {} lost all subscribers during streaming, breaking to idle check", self.config.camera_id);
            break;
        }

        let (frame, _) = stream.next().map_err(|error| error.to_string())?;

        // Frame skipping: if previous frame hasn't been consumed, skip encoding
        if !self.frame_consumed.load(Ordering::Relaxed) {
            skipped_frames += 1;
            if skipped_frames % 300 == 0 {
                debug!(
                    "Camera {} has skipped {} frames (unconsumed)",
                    self.config.camera_id, skipped_frames
                );
            }
            continue;
        }

        let encode_start = Instant::now();
        let active_quality = if self.adaptive_quality.load(Ordering::Relaxed) {
            quality
        } else {
            80
        };

        let jpeg = encode_frame_to_jpeg(
            frame,
            self.config.width,
            self.config.height,
            &self.config.capture_encoding,
            active_quality,
        )
        .map_err(|error| error.to_string())?;

        let encode_duration = encode_start.elapsed();

        // Adaptive quality adjustment
        if self.adaptive_quality.load(Ordering::Relaxed) {
            let ratio = encode_duration.as_secs_f64() / frame_budget.as_secs_f64();
            let direction: i8 = if ratio > 0.70 {
                -1 // need to decrease quality
            } else if ratio < 0.40 {
                1 // can increase quality
            } else {
                0 // hold
            };

            if direction == 0 || direction != consecutive_direction {
                consecutive_direction = direction;
                consecutive_count = if direction == 0 { 0 } else { 1 };
            } else {
                consecutive_count += 1;
            }

            if consecutive_count >= 3 {
                let old_quality = quality;
                if direction < 0 {
                    quality = quality.saturating_sub(5).max(30);
                } else {
                    quality = (quality + 5).min(80);
                }
                if quality != old_quality {
                    debug!(
                        "Camera {} adaptive quality: {} → {} (encode ratio {:.1}%)",
                        self.config.camera_id, old_quality, quality,
                        ratio * 100.0
                    );
                }
                consecutive_count = 0;
            }

            self.current_quality.store(quality, Ordering::Relaxed);
        } else {
            // Reset when adaptive is off
            quality = 80;
            consecutive_count = 0;
            consecutive_direction = 0;
            self.current_quality.store(80, Ordering::Relaxed);
        }

        self.publish_frame(Bytes::from(jpeg));
    }

    Ok(())
}
```

- [ ] **Step 4: Update encode_frame_to_jpeg to accept quality parameter**

Change `encode_frame_to_jpeg` (line 506-523):

```rust
fn encode_frame_to_jpeg(
    frame: &[u8],
    width: u32,
    height: u32,
    capture_encoding: &str,
    quality: u8,
) -> Result<Vec<u8>, Box<dyn std::error::Error + Send + Sync>> {
    match capture_encoding {
        "MJPG" => Ok(frame.to_vec()),
        "YUYV" => {
            let rgb = yuyv_to_rgb(frame);
            let mut output = Vec::new();
            let encoder = Encoder::new(&mut output, quality);
            encoder.encode(&rgb, width as u16, height as u16, ColorType::Rgb)?;
            Ok(output)
        }
        unsupported => Err(format!("Unsupported capture encoding {unsupported}").into()),
    }
}
```

- [ ] **Step 5: Run full test suite**

Run: `cd yv-streamer-software && cargo test`
Expected: All tests PASS

- [ ] **Step 6: Commit**

```bash
git add yv-streamer-software/src/manager.rs
git commit -m "feat: add idle shutdown, frame skipping, and adaptive JPEG quality to capture loop"
```

---

### Task 7: Final Integration Test + Cleanup

**Files:**
- All source files for final validation
- `yv-streamer-software/Dockerfile` for Docker build test

- [ ] **Step 1: Run full test suite one final time**

Run: `cd yv-streamer-software && cargo test`
Expected: All tests PASS

- [ ] **Step 2: Run clippy for lint checks**

Run: `cd yv-streamer-software && cargo clippy -- -D warnings 2>&1 || true`
Fix any warnings.

- [ ] **Step 3: Build the Docker image**

Run: `cd yv-streamer-software && docker build -f Dockerfile --target test .`
Expected: Build succeeds and tests pass inside container.

- [ ] **Step 4: Build the release Docker image**

Run: `cd yv-streamer-software && docker build -t yv-streamer-software:local -f Dockerfile .`
Expected: Build succeeds.

- [ ] **Step 5: Commit any final fixes**

```bash
git add -A yv-streamer-software/
git commit -m "chore: fix lint warnings and finalize CPU optimization implementation"
```

---

### Task 8: E2E Manual Test (Docker)

**Files:** None (manual testing)

This task validates the full optimization stack in a live Docker container with a real or virtual V4L2 device.

- [ ] **Step 1: Start the sidecar container**

```bash
docker network create yv-streamer-test-net 2>/dev/null || true

docker run -d \
  --name yv-streamer-test \
  --network yv-streamer-test-net \
  -p 8080:8080 \
  --privileged \
  -e YV_STREAMER_SOFTWARE_HOST=0.0.0.0 \
  -e YV_STREAMER_SOFTWARE_PORT=8080 \
  -e YV_STREAMER_SOFTWARE_LOG_LEVEL=debug \
  -v /dev:/dev \
  -v /dev/bus/usb:/dev/bus/usb \
  -v /sys/class:/sys/class \
  -v /sys/devices:/sys/devices \
  -v /run/udev:/run/udev:ro \
  yv-streamer-software:local
```

- [ ] **Step 2: Verify idle behavior — no workers exist at startup**

```bash
curl -s http://127.0.0.1:8080/api/v1/cameras | jq .
```

Expected: `[]` (empty list)

- [ ] **Step 3: Start a stream, then disconnect and verify idle cleanup**

Open in browser: `http://127.0.0.1:8080/cam-1?action=stream&node=/dev/video0&resolution=640x480&framerate=15&capture_encoding=YUYV`

Verify camera appears:
```bash
curl -s http://127.0.0.1:8080/api/v1/cameras | jq .
```

Close the browser tab. Wait 35 seconds. Then:
```bash
curl -s http://127.0.0.1:8080/api/v1/cameras | jq .
```

Expected: `[]` (worker removed after 30s idle timeout)

- [ ] **Step 4: Test adaptive quality toggle**

Open: `http://127.0.0.1:8080/cam-1?action=stream&node=/dev/video0&resolution=1920x1080&framerate=30&capture_encoding=YUYV&adaptive_quality=true`

Check state:
```bash
curl -s http://127.0.0.1:8080/cam-1?action=state&node=/dev/video0&resolution=1920x1080&framerate=30&capture_encoding=YUYV | jq '.current_jpeg_quality, .adaptive_quality'
```

Expected: `adaptive_quality: true`, `current_jpeg_quality` may have decreased from 80 if CPU is constrained.

- [ ] **Step 5: Monitor CPU usage**

```bash
docker stats yv-streamer-test --no-stream
```

Compare CPU % with and without a stream subscriber. With no subscribers, CPU should be near 0%.

- [ ] **Step 6: Clean up**

```bash
docker rm -f yv-streamer-test
docker network rm yv-streamer-test-net
```
