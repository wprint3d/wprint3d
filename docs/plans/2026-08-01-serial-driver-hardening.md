# Serial Driver and Device Mapper Hardening Plan

**Goal:** Make serial communication bounded, mutually exclusive, recoverable after transport failures, and safe for device discovery without interacting with the physical printer during verification.

**Safety constraint:** Never send any command to a physical serial printer while implementing or validating this plan. Exercise serial behavior only through FakeSerial, test doubles, or isolated pseudo-terminals whose names are outside the mapper's `ttyUSB*` and `ttyACM*` discovery patterns.

## Scope

- Fix absolute timeout enforcement and late-response contamination in `app/Libraries/Serial.php`.
- Fix serial lock acquisition, wait bounds, and TTL sizing.
- Reject oversized and invalid UTF-8 responses before they reach parser and mapper consumers.
- Apply the constructor timeout consistently to FakeSerial and interrupt simulated delays at the deadline.
- Keep a `Serial` instance reusable after a failed transaction by reconnecting before the next query.
- Quarantine and drain a real serial node after a failed transaction so a late reply cannot be attributed to the next command.
- Make `map:serial-printers` clear and refresh its busy marker reliably.
- Save newly discovered printers before writing cache state that requires a model ID.
- Reject corrupted negotiation responses.
- Add focused regression coverage for Serial and mapper behavior.

## Task 1: Serial Deadline and Buffer Tests

**Files:**

- Create `tests/Unit/SerialTest.php`.
- Modify `app/Libraries/Serial.php`.

**Steps:**

1. Add a FakeSerial emulator double that can delay, corrupt, and oversize responses.
2. Verify a constructor timeout is enforced as an absolute monotonic deadline.
3. Verify a timed-out connection reconnects before the next query.
4. Verify invalid UTF-8 and responses above the hard limit are rejected.
5. Implement shared deadline, response validation, and reconnect helpers.
6. Mark failed real-node transactions for bounded recovery and require a quiet, drained input window before reuse.

## Task 2: Serial Lock Regression Tests

**Files:**

- Modify `tests/Unit/SerialTest.php`.
- Modify `app/Libraries/Serial.php`.

**Steps:**

1. Reproduce a contended lock with a deterministic lock double.
2. Require `blockWhileLocking()` to return only after a successful acquisition.
3. Remove the acquire-release-reacquire window.
4. Bound lock waiting independently from lock ownership TTL.
5. Size query lock TTL from the effective query timeout so it cannot expire during a normally bounded query.

## Task 3: Mapper Lifecycle and New-Printer Tests

**Files:**

- Create `tests/Unit/MapSerialPrintersTest.php`.
- Modify `app/Console/Commands/MapSerialPrinters.php`.

**Steps:**

1. Run the mapper against an enabled FakeSerial node in the isolated test database.
2. Verify a previously unseen printer is saved and receives a connection status without a null ID error.
3. Verify the mapper busy marker is removed on both success and failure.
4. Refresh a 30-second busy heartbeat during waits and serial reads so it remains accurate while the mapper is alive but expires promptly after a hard process failure.
5. Reject responses that are missing `ok` or contain invalid UTF-8.
6. Catch transport `Throwable` instances at negotiation boundaries so one malformed device does not terminate the mapper command.
7. Stop retrying the same baud rate when firmware information cannot be read, preventing an unbounded mapper loop.

## Task 4: Verification

Run inside the backend development container:

```bash
php artisan test tests/Unit/SerialTest.php
php artisan test tests/Unit/MapSerialPrintersTest.php
php artisan test tests/Unit/Support/FakeSerial/FakeSerialManagerTest.php tests/Unit/Support/FakeSerial/FakeSerialEmulatorTest.php
php artisan test tests/Unit/PollSerialConnectionsTest.php
./vendor/bin/pint --test app/Libraries/Serial.php app/Console/Commands/MapSerialPrinters.php tests/Unit/SerialTest.php tests/Unit/MapSerialPrintersTest.php
```

Final checks:

- Confirm the physical printer node was never opened by the test process.
- Confirm the production mapper and backend containers did not restart.
- Confirm FakeSerial is disabled and disconnected in the production runtime.
- Review the final diff for unrelated files and preserve all pre-existing workspace changes.
