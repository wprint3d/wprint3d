# Cura Web UI in WPrint 3D: cross-application E2E

The release-gate browser flow lives in [`scripts/e2e_cura_wprint3d.py`](../scripts/e2e_cura_wprint3d.py) because WPrint owns authentication, plugin installation, iframe mounting, the runtime proxy, and the file list. It drives the real Cura UI inside the real WPrint iframe and talks to the real managed gateway; it does not stub requests or use a fake adapter.

Run it only against a disposable WPrint stack with the staged Cura plugin mounted into the backend container:

```bash
WPRINT3D_E2E_ALLOW_DESTRUCTIVE=1 \
WPRINT3D_E2E_PLUGIN_PATH=/var/www/plugins-dev/cura-web-ui \
CURA_GATEWAY_IMAGE=ghcr.io/wprint3d/cura-web-ui-gateway:X.Y.Z@sha256:<digest> \
WPRINT3D_E2E_SECOND_EMAIL=second@example.test \
WPRINT3D_E2E_SECOND_PASSWORD='test-password' \
  python3 scripts/e2e_cura_wprint3d.py
```

The mounted `plugin.json` must contain the exact same non-zero digest as `CURA_GATEWAY_IMAGE`; the script rejects development placeholders. The second account is mandatory because the flow verifies owner isolation.

The sequence covers login, unpacked-plugin installation, embedded host safety, real STL upload, real slicing, reload during/after the job, preview, Save-to-WPrint, file-list visibility, cancellation of a second job, disable/re-enable durability, and a second-user job-visibility check. Cleanup disables/removes only `cura-web-ui` in the disposable stack.

The script is an implementation of the F11 flow, but F11 remains unchecked until it has been executed against the published digest and candidate WPrint image. A local signed-package smoke already proves the lower-level install/proxy/slice/import lifecycle; this browser run is the cross-application release evidence.
