# WPrint 3D Documentation

WPrint 3D is a web-based host for FDM printers with a Laravel backend and a dedicated frontend.

Use the repository root `README.md` for installation, host setup, and runtime bootstrap instructions.

The documentation here covers the current plugin platform, example verification flows, and development-only printer emulation guides.

```{toctree}
:maxdepth: 1
:caption: Plugin Platform

plugins
plugin-development-guide
plugin-sdk-reference
plugin-sdk-changelog
plugin-signing-for-developers
plugin-signature-verification-for-users
plugin-registry-signing-review
```

```{toctree}
:maxdepth: 1
:caption: Example Workflows And E2E

plugin-showcase-e2e
plugin-shape-matrix-e2e
octoprint-porting-e2e
octoprint-navbartemp-public-registry-guide
```

```{toctree}
:maxdepth: 1
:caption: Development Utilities

fake-serial-printer
fake-serial-marlin-coverage
```
