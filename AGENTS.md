# AGENTS.md

## Purpose

This repository is `wprint3d-core`, the main WPrint 3D monorepo. It combines a Laravel 11 backend, Docker-based service orchestration, USB/device integration, and the Expo-based frontend under `frontend/`.

Use this file as the default operating guide before making changes.

## Repository Map

- `app/`: Laravel application code. Most backend logic, jobs, models, console commands, and services live here.
- `routes/`: HTTP route definitions.
- `config/`: Laravel and system configuration.
- `tests/`: PHPUnit unit and feature tests.
- `internal/`: container entrypoints, startup scripts, cron, supervisor, and runtime helpers.
- `proxy/`: nginx/proxy configuration.
- `docker-compose.yml`: production-like container topology.
- `docker-compose-development.yml`: local development stack.
- `run.sh`: main entrypoint for bootstrapping production or development environments.
- `frontend/`: Expo-based frontend source tracked in the same repository as the backend/runtime.

## Working Rules

- Treat this as a hardware-aware project. Many flows depend on Docker, USB device access, webcams, MongoDB, Redis, and privileged containers.
- Prefer minimal, targeted changes. Do not rewrite broad runtime scripts or Docker topology unless the task requires it.
- Do not launch Docker image builds with `--no-cache` unless doing so is strictly necessary for the active task.
- Write all commit messages in English, regardless of the language used in the initial prompt.
- Write all project documentation in English, including README files, changelogs, release notes, developer guides, and documentation comments, regardless of the language used in the initial prompt. Localized user-interface resources are exempt.
- Preserve existing code style. Backend PHP uses the current Laravel project conventions with semicolons and existing spacing patterns. Frontend code uses the repo's current JS/React Native style rather than introducing a new formatter opinion.
- For every UI/UX change, including standalone pages under `internal/`, first read `DESIGN.md`, then inspect the affected flow and representative adjacent frontend screens end to end, in both themes and at the relevant breakpoints. Treat the current WPrint 3D interface as the visual source of truth: extend it in place, reuse the existing Material 3 theme tokens, React Native Paper components, shared background, typography, spacing, surfaces, density, navigation, hierarchy, and interaction patterns, and validate the result visually against the current frontend. Do not introduce a parallel design system, a visually unrelated variant, or a wholesale new layout unless the user explicitly requests a redesign; responsive adaptations may reflow content but must remain recognizably the same interface.
- Responsive validation is mandatory for every UI/UX change. Exercise the affected flow in a real browser at phone, tablet/layout-boundary, and desktop sizes; the minimum matrix is 375 × 812, 768 px wide, 1024 × 768, and 1440 × 900, plus landscape when the flow is orientation-sensitive. Validate both light and dark themes, capture or inspect the rendered result, and complete the primary interaction at each size. A pass requires no clipped or off-screen controls, collapsed content regions, unintended horizontal overflow, fixed bars covering content, overlapping actions, unreadable text, or inaccessible touch targets. For embedded/plugin interfaces, record and validate both the host viewport and the iframe's actual content viewport because split panes can give a desktop host a phone-width plugin surface. Do not treat `scrollWidth === clientWidth` alone as proof: inspect element bounds and screenshots for content hidden by overflow clipping or negative positioning.
- Do not use absolute filesystem paths in documentation. Prefer repo-relative links such as `docs/plugins.md`, repo-relative commands such as `./plugin.sh pack ...`, and sibling-repo references like `../plugin-registry` only when a path truly leaves this repository.
- Do not edit generated or runtime-managed artifacts unless the task is explicitly about them:
  - `vendor/`
  - `public/vendor/`
  - `storage/`
  - `internal/startup/*.txt`
  - `internal/app_ver`
  - `proxy/internal/`
- Treat `frontend/` as first-party monorepo code. Do not assume there is a separate upstream repository or clone step.
- Ignore unrelated local artifacts unless the task is about them. Current examples may include files like `firebase-debug.log`.

## Common Commands

### Backend

- Install PHP dependencies:
  ```bash
  composer install
  ```
- Run tests:
  ```bash
  php artisan test
  ```
- Run a focused test file:
  ```bash
  php artisan test tests/Feature/ExampleTest.php
  ```
- Check formatting:
  ```bash
  ./vendor/bin/pint --test
  ```
- Apply formatting:
  ```bash
  ./vendor/bin/pint
  ```

### Full Stack / Containers

- Start the development stack:
  ```bash
  ./run.sh -e dev
  ```
- Start the development stack without rebuilding images:
  ```bash
  ./run.sh -e dev --no-build
  ```
- Start the production-like stack locally:
  ```bash
  ./run.sh
  ```
- Work directly with Docker Compose development services:
  ```bash
  docker compose -f docker-compose-development.yml up -d
  ```
- Build all production images for the host architecture:
  ```bash
  docker buildx bake production
  ```
- Build an individual backend target:
  ```bash
  docker build --target backend .
  docker build --target production .
  docker build --target mapper .
  docker build --target streamer .
  docker build --target development .
  ```

### Frontend

Run these from `frontend/`.

- Install frontend dependencies:
  ```bash
  pnpm install --frozen-lockfile
  ```
- Start Expo dev server:
  ```bash
  pnpm exec expo start --clear
  ```
- Build the web bundle:
  ```bash
  pnpm exec expo export -p web
  ```

## Verification Expectations

- For backend-only changes, run at least the most relevant `php artisan test` target.
- For formatting-sensitive backend changes, run `./vendor/bin/pint --test` or `./vendor/bin/pint`.
- For frontend changes, there is no dedicated lint/test script in `frontend/package.json` yet. Use the most relevant runtime validation instead, usually `pnpm exec expo start --clear` or `pnpm exec expo export -p web`.
- If Docker, USB, camera, or privileged-container requirements prevent full verification, say exactly what could not be validated.

## Project-Specific Notes

- `run.sh` is the safest high-level entrypoint. It handles environment selection and compose bootstrap for the monorepo layout, and expects `frontend/` to already exist in the checkout.
- Production and development stacks differ. Check the correct compose file before changing service definitions.
- `Dockerfile` owns the shared PHP builders and the `backend`, `production`, `mapper`, `streamer`, and `development` targets. `backend` is the final compatibility alias for `production`. `frontend/Dockerfile` similarly owns both production and development frontend targets; do not reintroduce separate `Dockerfile.dev` files.
- Production images are immutable with respect to Composer dependencies. `vendor/` is built into the image and a production container must not install dependencies at startup.
- `docker-bake.hcl` is the source of truth for the five production image builds and their public tags.
- The backend startup path in `internal/run.sh` performs important setup such as dependency installation, migrations, queue/bootstrap tasks, Octane/Reverb startup, and hardware mapping. Changes there have wide operational impact.
- `.env` handling is partially automated by the runtime. Do not assume the old manual setup flow still applies without checking the current startup scripts and README.

## When Modifying This File

- Keep instructions concrete and repo-specific.
- Prefer commands that already exist in the repository over aspirational tooling.
- Update this file whenever developer workflows, verification commands, or repository boundaries materially change.
