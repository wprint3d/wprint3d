# FakeSerial Printer Guide

This guide explains how to enable, use, and disable the built-in FakeSerial printer for development and integration testing.

## What It Does

FakeSerial simulates a Marlin-style serial printer inside WPrint 3D.

When enabled, it:

- appears in the normal printer discovery flow
- is mapped by `map:serial-printers` like a real device
- can be selected from the left-side printer picker
- shows a computer icon in the picker so it is easy to distinguish from hardware
- responds to common G-code commands such as `M105`, `M115`, `M109`, `M190`, `G0`, `G1`, and `G28`
- emits Marlin-like `start`, `busy: processing`, `ok`, and error responses
- exposes a live transcript in the developer UI

## Before You Start

You need:

- access to the WPrint 3D UI as an administrator
- developer mode enabled

Developer mode is required because the FakeSerial controls live in the `Development` settings tab.

## Step 1: Enable Developer Mode

1. Open the WPrint 3D settings modal.
2. Go to the `System` tab.
3. Find `Enable developer mode` under `Advanced settings`.
4. Turn it on.
5. Save the change.
6. Reload the page.

After the reload, a `Development` tab will appear in the settings modal.

## Step 2: Open the FakeSerial Panel

1. Open the settings modal again.
2. Go to the `Development` tab.
3. Open the `Fake serial` sub-tab.

You will see:

- a toggle to plug or unplug the fake printer
- the virtual node name, usually `FAKE0`
- the active baud rate selector
- mapper detection status
- a live transcript panel

## Step 3: Plug In the Fake Printer

1. In `Settings -> Development -> Fake serial`, turn the toggle on.
2. Wait a few seconds for the mapper to run.
3. Watch the status chips:
   - `Plugged in` means the virtual device is enabled
   - `Detected by mapper` means it has been discovered and mapped as a printer

When detection completes, the printer will appear in the left-side printer selector with a computer icon.

## Step 4: Select the Fake Printer

1. Open the printer selector on the left side of the main UI.
2. Choose the printer with the computer icon.
3. Wait for it to become the active printer.

Once selected, you can use it like a normal printer for:

- manual terminal commands
- printer status polling
- preheat and temperature workflows
- plugin serial hooks
- print-flow integration testing

## Step 5: Watch the Transcript

The `Fake serial` panel includes a live transcript that refreshes automatically.

It shows:

- `input`: commands sent to the fake printer
- `output`: simulated Marlin replies
- `status`: connection and plug/unplug state changes

Use this panel when you want to confirm:

- which G-code your integration is sending
- whether a command returns `ok`
- whether a slow command emits `busy: processing`
- whether an invalid command returns an error

## Step 6: Send Test Commands

After selecting the FakeSerial printer, open the normal printer terminal and send commands as you would to a physical printer.

Useful examples:

- `M105`
  Expected result: a temperature report starting with `ok T:`
- `M115`
  Expected result: firmware info and capability lines
- `G28`
  Expected result: one or more `busy: processing` lines, then `ok`
- `M109 S210`
  Expected result: busy output during heat-up, then a final temperature report
- `M190 S60`
  Expected result: busy output during bed heat-up, then a final temperature report
- `M114`
  Expected result: current position
- `M9999`
  Expected result: an `Error:Unknown command` response

## Step 7: Change the Fake Baud Rate

The simulator supports two baud rates:

- `115200`
- `250000`

To change it:

1. Go to `Settings -> Development -> Fake serial`.
2. Click the desired baud rate.
3. Wait for the mapper to refresh the printer mapping.

Notes:

- the selected baud becomes the fake printer's active baud
- discovery should succeed only at the configured baud
- integrations that assume the wrong baud should see a timeout or no response behavior similar to a real mismatch

## Step 8: Test Full Discovery Again

If you want to re-test discovery from scratch:

1. Turn FakeSerial off.
2. Wait for the device to be treated as unplugged.
3. Turn FakeSerial on again.
4. Wait for the mapper to detect it again.

This is useful when validating:

- startup discovery
- plugin behavior during first connection
- printer selection logic
- reconnection handling

## Step 9: Disable FakeSerial

When you are done:

1. Go to `Settings -> Development -> Fake serial`.
2. Turn the toggle off.
3. Wait a few seconds for the mapper state to settle.

After that:

- the virtual node is no longer considered plugged in
- new fake serial connections are rejected
- the left-side picker will stop treating it as an active virtual device

## Behavior Notes

Keep these details in mind while testing:

- FakeSerial allows only one active connection at a time.
- Slow commands intentionally emit `busy: processing` before the final reply.
- Temperature waits such as `M109` and `M190` simulate delayed completion.
- Wrong-baud access is treated like a timeout.
- Unknown commands return error output instead of a successful `ok`.
- The transcript in the developer tab is separate from the normal printer terminal and is meant for debugging the fake device itself.

## Troubleshooting

If the FakeSerial printer does not appear:

1. Confirm `Enable developer mode` is still on.
2. Confirm the FakeSerial toggle is on.
3. Check that the status chip moves from `Plugged in` to `Detected by mapper`.
4. Use the transcript panel and press `Refresh`.
5. Re-toggle FakeSerial off and on.
6. Reload the page if you just enabled developer mode.

If commands fail unexpectedly:

1. Check the transcript for `Error:` lines.
2. Confirm the active baud rate is the one you intended to test.
3. Make sure another workflow is not already holding the fake serial connection.

## Related Files

- Implementation plan: [docs/plans/2026-03-13-fake-serial-printer.md](plans/2026-03-13-fake-serial-printer.md)
- Backend manager: [app/Support/FakeSerial/FakeSerialManager.php](../app/Support/FakeSerial/FakeSerialManager.php)
- Backend emulator: [app/Support/FakeSerial/FakeSerialEmulator.php](../app/Support/FakeSerial/FakeSerialEmulator.php)
- Developer UI panel: [frontend/components/NavBarMenuSettingsModalDeveloperFakeSerial.js](../frontend/components/NavBarMenuSettingsModalDeveloperFakeSerial.js)
