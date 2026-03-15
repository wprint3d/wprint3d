# FakeSerial Marlin Coverage

FakeSerial uses [Marlin.php](../app/Enums/Marlin.php) as the command inventory.

Behavior rules:

- Every command listed in `Marlin.php` now routes through an explicit FakeSerial behavior path.
- Stateful commands mutate emulator state so future printer-state inspection can rely on coherent machine data.
- Hardware-limited commands return coherent device-specific failures instead of generic unknowns.
- Commands not present in `Marlin.php` still return `Error:Unknown command`.

Current coverage model:

- Motion and positioning: linear moves, arc-like moves, dwell, units, homing, workspace selection, stored positions, retract / recover, parking
- Probe, leveling, calibration, backlash, and homing-offset families
- SD card lifecycle, selection, logging, progress, sorting, abort, and print-timer families
- Power, steppers, spindle / laser, coolant, blower, fans, lights, LCD, tone, camera, and servo families
- Temperature, PID, waiting, auto-report, material preset, and chamber / cooler temperature families
- Filament, runout, width sensing, filament change, loading / unloading, and extrusion tuning families
- EEPROM-style save / restore / validate / report families
- Security, passcode, macro, prompt-response, network, and maintenance families
- Driver, TMC, stepper-current, microstepping, kinematics, SCARA, Delta, and diagnostic families

Notable coherence guarantees:

- Tool selection respects the fake printer’s single-extruder limit. Example: `T7` returns a tool-unavailable error.
- MMU-only `Tx` commands return an explicit MMU-unavailable error.
- Bare-command smoke testing across the full `Marlin.php` inventory now produces no `Unknown command` or `Unsupported FakeSerial command` responses.

Verification:

- `vendor/bin/phpunit tests/Unit/Support/FakeSerial/FakeSerialEmulatorTest.php`
- `vendor/bin/phpunit tests/Unit/Support/FakeSerial/FakeSerialManagerTest.php`
- Inventory smoke pass over `Marlin.php` confirming `problems=0`
