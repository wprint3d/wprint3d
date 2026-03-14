# Fake Serial Printer Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Add a developer-toggleable FakeSerial printer that participates in discovery, connection, command execution, and UI selection like a real serial printer while exposing a real-time debug log.

**Architecture:** Introduce a cache-backed fake-serial service that owns virtual-node discovery, connection/session locking, stateful Marlin-like command emulation, and developer log state. Integrate that service into the existing `Serial` class and `map:serial-printers` command so the rest of the backend continues talking to a serial printer abstraction. Add a developer-only API and UI surface for toggling the feature, selecting the active fake baud rate, and watching the G-code transcript.

**Tech Stack:** Laravel 11, MongoDB models, cache-backed runtime state, Expo / React Native frontend, React Query, React Native Paper.

---

### Task 1: Protocol Tests

**Files:**
- Create: `tests/Unit/Support/FakeSerial/FakeSerialEmulatorTest.php`

**Step 1: Write the failing test**

Cover:
- `M105` returns a Marlin-like temperature line with `ok`.
- `M115` returns firmware info and capabilities.
- Slow commands such as `M109`, `M190`, and `G28` emit `busy` lines before a final `ok`.
- Invalid commands emit an error-style line.
- Motion commands update tracked position.

**Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Support/FakeSerial/FakeSerialEmulatorTest.php`
Expected: FAIL because the emulator does not exist yet.

**Step 3: Write minimal implementation**

Create a pure PHP emulator that accepts a persisted state array plus a command string and returns:
- updated state
- transcript lines with delays
- a final combined response string

**Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Support/FakeSerial/FakeSerialEmulatorTest.php`
Expected: PASS

### Task 2: Virtual Node Discovery Tests

**Files:**
- Create: `tests/Unit/Support/FakeSerial/FakeSerialManagerTest.php`

**Step 1: Write the failing test**

Cover:
- fake node is hidden when the feature is disabled
- fake node is exposed when enabled
- only the configured baud succeeds
- the service rejects a second simultaneous connection

**Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Support/FakeSerial/FakeSerialManagerTest.php`
Expected: FAIL because the manager does not exist yet.

**Step 3: Write minimal implementation**

Create the cache-backed manager that:
- exposes the virtual node list
- persists emulator state
- manages single-session locking
- stores a rolling developer transcript

**Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Support/FakeSerial/FakeSerialManagerTest.php`
Expected: PASS

### Task 3: Backend Integration

**Files:**
- Modify: `app/Libraries/Serial.php`
- Modify: `app/Console/Commands/MapSerialPrinters.php`
- Modify: `app/Console/Services/Concurrent/PollSerialConnections.php`
- Modify: `app/Http/Controllers/PrinterController.php`
- Modify: `app/Http/Controllers/ConfigurationController.php`
- Modify: `app/Http/Controllers/UserController.php`
- Modify: `app/Providers/AppServiceProvider.php`
- Modify: `routes/api.php`
- Modify: `config/system.php`
- Modify: `app/Models/Printer.php`
- Create: `app/Http/Controllers/DeveloperFakeSerialController.php`
- Create: `app/Support/FakeSerial/FakeSerialEmulator.php`
- Create: `app/Support/FakeSerial/FakeSerialManager.php`

**Step 1: Write the failing test**

Extend the manager and emulator tests with expectations that reflect the integration contract:
- fake node exists through `Serial::nodeExists`
- mapper candidate list includes the fake node when enabled
- fake printer metadata flags it as simulated

**Step 2: Run tests to verify they fail**

Run:
- `vendor/bin/phpunit tests/Unit/Support/FakeSerial/FakeSerialEmulatorTest.php`
- `vendor/bin/phpunit tests/Unit/Support/FakeSerial/FakeSerialManagerTest.php`

Expected: FAIL on missing integration behavior.

**Step 3: Write minimal implementation**

Implement:
- `Serial` fake-node detection and fake-query execution path
- line-by-line terminal updates for fake responses
- mapper discovery through real plus virtual nodes
- hidden config keys for fake enablement and fake baud rate
- developer endpoints for read/update state and log retrieval
- simulated printer metadata for frontend consumption

**Step 4: Run tests to verify they pass**

Run:
- `vendor/bin/phpunit tests/Unit/Support/FakeSerial/FakeSerialEmulatorTest.php`
- `vendor/bin/phpunit tests/Unit/Support/FakeSerial/FakeSerialManagerTest.php`

Expected: PASS

### Task 4: Frontend Developer Tooling

**Files:**
- Modify: `frontend/components/NavBarMenuSettingsModalDeveloper.js`
- Modify: `frontend/components/UserPrinterPicker.js`
- Create: `frontend/components/NavBarMenuSettingsModalDeveloperFakeSerial.js`

**Step 1: Write the failing test**

No frontend test harness exists. Treat runtime validation as the first red/green cycle:
- the developer tab should show a fake-serial panel only when developer mode is enabled
- the panel should toggle enablement, change the baud rate, and show the rolling transcript
- the printer selector should render a computer icon for simulated printers

**Step 2: Run runtime validation to verify current behavior is missing**

Run from `frontend/`: `pnpm exec expo export -p web`
Expected: current UI has no fake-serial developer panel and no simulated-printer icon.

**Step 3: Write minimal implementation**

Implement:
- a developer-only FakeSerial panel
- React Query reads/writes against the new developer endpoints
- a selector UI that can render option icons for simulated printers

**Step 4: Run runtime validation to verify it works**

Run from `frontend/`: `pnpm exec expo export -p web`
Expected: build succeeds and the updated selector/panel compile.

### Task 5: Verification

**Files:**
- None

**Step 1: Run focused backend verification**

Run:
- `vendor/bin/phpunit tests/Unit/Support/FakeSerial/FakeSerialEmulatorTest.php`
- `vendor/bin/phpunit tests/Unit/Support/FakeSerial/FakeSerialManagerTest.php`

**Step 2: Run focused frontend verification**

Run from `frontend/`: `pnpm exec expo export -p web`

**Step 3: Document environment gaps**

If verification fails because of local runtime issues such as logging permissions, missing `Memcached`, missing frontend dependencies, or absent services, record the exact blocker in the final summary.
