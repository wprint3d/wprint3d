#!/usr/bin/env python3
"""Run the real Cura-in-WPrint acceptance flow.

This intentionally does not start a substitute gateway or inject browser-side API
responses. It expects a disposable WPrint stack with the staged Cura plugin
source mounted into the backend and a digest-pinned gateway image available to
the Docker daemon. The destructive opt-in prevents accidental use against a
normal development or production instance.
"""

from __future__ import annotations

import json
import os
import re
import shlex
import subprocess
import tempfile
from pathlib import Path
from typing import TYPE_CHECKING, Any

if TYPE_CHECKING:
    from playwright.sync_api import Page

expect: Any = None
sync_playwright: Any = None


BASE_URL = os.environ.get("WPRINT3D_E2E_BASE_URL", "https://127.0.0.1")
COMPOSE = shlex.split(os.environ.get("WPRINT3D_E2E_COMPOSE", "docker compose -f docker-compose-development.yml"))
PLUGIN_ID = "cura-web-ui"
PLUGIN_LABEL = os.environ.get("WPRINT3D_E2E_PLUGIN_LABEL", "Cura Web UI")
PLUGIN_PATH = os.environ.get("WPRINT3D_E2E_PLUGIN_PATH", "/var/www/plugins-dev/cura-web-ui")
EMAIL = os.environ.get("WPRINT3D_E2E_EMAIL", "admin@admin.com")
PASSWORD = os.environ.get("WPRINT3D_E2E_PASSWORD", "admin")
SECOND_EMAIL = os.environ.get("WPRINT3D_E2E_SECOND_EMAIL")
SECOND_PASSWORD = os.environ.get("WPRINT3D_E2E_SECOND_PASSWORD")
EXPECTED_IMAGE = os.environ.get("CURA_GATEWAY_IMAGE")

SAMPLE_STL = """solid integration_cube
  facet normal 0 0 1
    outer loop
      vertex 0 0 0
      vertex 20 0 0
      vertex 0 20 0
    endloop
  endfacet
  facet normal 0 0 1
    outer loop
      vertex 20 0 0
      vertex 20 20 0
      vertex 0 20 0
    endloop
  endfacet
endsolid integration_cube
"""


def run_backend(*args: str, check: bool = True) -> subprocess.CompletedProcess[str]:
    return subprocess.run(
        [*COMPOSE, "exec", "-T", "backend", *args],
        check=check,
        text=True,
        capture_output=True,
    )


def fail(message: str) -> None:
    raise RuntimeError(message)


def require_release_fixture() -> None:
    if os.environ.get("WPRINT3D_E2E_ALLOW_DESTRUCTIVE") != "1":
        fail("Set WPRINT3D_E2E_ALLOW_DESTRUCTIVE=1 for a disposable acceptance stack.")
    if not SECOND_EMAIL or not SECOND_PASSWORD:
        fail("Second-user credentials are required for owner-isolation coverage.")
    if not EXPECTED_IMAGE or "@sha256:" not in EXPECTED_IMAGE:
        fail("CURA_GATEWAY_IMAGE must be an immutable @sha256 reference.")


def install_unpacked_plugin(page: Page) -> None:
    result = page.evaluate(
        """async (unpackedPath) => {
          const tokenCookie = document.cookie.split('; ').find((entry) => entry.startsWith('XSRF-TOKEN='));
          const csrf = tokenCookie ? decodeURIComponent(tokenCookie.split('=').slice(1).join('=')) : '';
          const response = await fetch('/backend/api/plugins/install', {
            method: 'POST',
            credentials: 'include',
            headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': csrf },
            body: JSON.stringify({ unpackedPath }),
          });
          return { status: response.status, body: await response.text() };
        }""",
        PLUGIN_PATH,
    )
    if result["status"] != 200 or PLUGIN_ID not in result["body"]:
        fail(f"Unpacked Cura plugin installation failed: {result}")


def login(page: Page, email: str = EMAIL, password: str = PASSWORD) -> None:
    page.goto(BASE_URL, wait_until="networkidle")
    page.locator("input").nth(0).fill(email)
    page.locator('input[type="password"]').fill(password)
    page.get_by_role("button", name="Log in").click()
    page.wait_for_load_state("networkidle")
    expect(page.get_by_role("button", name="Settings")).to_be_visible(timeout=20_000)


def cura_frame(page: Page):
    frame = page.frame(url=re.compile(r"hostMode=embedded"))
    if frame is None:
        fail("The WPrint page did not expose an embedded Cura frame.")
    return frame


def open_cura(page: Page):
    expect(page.get_by_role("tab", name=PLUGIN_LABEL, exact=True)).to_be_visible(timeout=30_000)
    page.get_by_role("tab", name=PLUGIN_LABEL, exact=True).click()
    frame = cura_frame(page)
    expect(frame.get_by_test_id("cura-viewport")).to_be_visible(timeout=30_000)
    if page.locator(".cura-header").count() != 0:
        fail("WPrint rendered a duplicate standalone Cura header outside the iframe")
    if page.locator('input[aria-label="API token"], input[aria-label="Gateway URL"]').count() != 0:
        fail("WPrint exposed duplicate gateway controls outside the embedded frame")
    return frame


def assert_host_safety(frame) -> None:
    expect(frame.locator('input[aria-label="API token"]')).to_have_count(0)
    expect(frame.locator('input[aria-label="Gateway URL"]')).to_have_count(0)
    expect(frame.get_by_text("Authorization", exact=False)).to_have_count(0)


def list_files(page: Page) -> str:
    response = page.request.get(f"{BASE_URL}/backend/api/files")
    if not response.ok:
        fail(f"WPrint file-list request failed with HTTP {response.status}")
    return response.text()


def assert_second_user_cannot_read_first_job(browser: Any, first_job_id: str) -> None:
    context = browser.new_context(ignore_https_errors=True)
    page = context.new_page()
    login(page, SECOND_EMAIL or "", SECOND_PASSWORD or "")
    response = page.request.get(f"{BASE_URL}/backend/api/plugins/{PLUGIN_ID}/runtime/api/v1/jobs")
    if response.status not in (200, 403, 404):
        fail(f"Second-user job listing returned unexpected HTTP {response.status}")
    if first_job_id and first_job_id in response.text():
        fail("Second user can see the first user's Cura job")
    context.close()


def latest_job_id(page: Page) -> str:
    response = page.request.get(f"{BASE_URL}/backend/api/plugins/{PLUGIN_ID}/runtime/api/v1/jobs")
    if not response.ok:
        fail(f"Unable to inspect the completed Cura job list: HTTP {response.status}")
    payload = response.json()
    jobs = payload.get("jobs", payload) if isinstance(payload, dict) else payload
    completed = [job for job in jobs if isinstance(job, dict) and job.get("status") == "completed"]
    if not completed:
        fail("Cura completed a visible UI job but the runtime job list had no completed job")
    job = completed[-1]
    job_id = job.get("id") if isinstance(job, dict) else None
    if not isinstance(job_id, str) or not job_id:
        fail("Runtime job list did not contain a usable job id")
    return job_id


def main() -> None:
    require_release_fixture()
    global expect, sync_playwright
    try:
        from playwright.sync_api import expect as playwright_expect, sync_playwright as playwright_sync
    except ModuleNotFoundError as error:
        fail(f"Playwright is required; run this harness in the pinned browser environment: {error}")
    expect = playwright_expect
    sync_playwright = playwright_sync
    manifest = json.loads(run_backend("cat", f"{PLUGIN_PATH}/plugin.json").stdout)
    image = manifest["images"][0]["image"]
    if "@sha256:" not in image or image.endswith("@sha256:" + "0" * 64):
        fail("Mounted Cura plugin is not a staged digest-pinned release manifest")
    if image != EXPECTED_IMAGE:
        fail(f"Plugin image {image!r} does not match CURA_GATEWAY_IMAGE {EXPECTED_IMAGE!r}")
    image_check = subprocess.run(["docker", "image", "inspect", EXPECTED_IMAGE], capture_output=True, text=True)
    if image_check.returncode != 0:
        fail(f"Expected gateway image is not available to Docker: {EXPECTED_IMAGE}")

    run_backend("php", "artisan", "plugin:disable", PLUGIN_ID, check=False)
    run_backend("php", "artisan", "plugin:remove", PLUGIN_ID, check=False)

    with tempfile.NamedTemporaryFile("w", suffix=".stl", delete=False) as sample:
        sample.write(SAMPLE_STL)
        sample_path = sample.name

    try:
        with sync_playwright() as playwright:
            browser = playwright.chromium.launch(headless=True)
            context = browser.new_context(ignore_https_errors=True, viewport={"width": 1440, "height": 1050})
            page = context.new_page()
            login(page)
            install_unpacked_plugin(page)
            page.reload(wait_until="networkidle")
            frame = open_cura(page)
            assert_host_safety(frame)
            frame.get_by_test_id("model-file-input").set_input_files(sample_path)
            expect(frame.get_by_test_id("slice-button")).to_be_enabled(timeout=30_000)
            frame.get_by_test_id("slice-button").click()
            expect(frame.get_by_text(re.compile(r"slicing|completed", re.I))).to_be_visible(timeout=180_000)
            first_job = latest_job_id(page)
            page.reload(wait_until="networkidle")
            frame = open_cura(page)
            expect(frame.get_by_test_id("cura-viewport")).to_be_visible()
            frame.get_by_role("button", name="Preview", exact=True).click()
            expect(frame.get_by_test_id("preview-panel")).to_be_visible(timeout=30_000)
            frame.get_by_role("tab", name="Monitor", exact=True).click()
            expect(frame.get_by_role("button", name="Save to WPrint 3D", exact=True)).to_be_enabled(timeout=30_000)
            frame.get_by_role("button", name="Save to WPrint 3D", exact=True).click()
            expect(page.get_by_role("status")).to_contain_text(re.compile(r"saved|imported|WPrint", re.I), timeout=30_000)
            if "integration_cube.gcode" not in list_files(page):
                fail("Saved Cura G-code did not appear in the WPrint file list")
            assert_second_user_cannot_read_first_job(browser, first_job)

            frame = open_cura(page)
            frame.get_by_test_id("model-file-input").set_input_files({
                "name": "integration_cancel.stl",
                "mimeType": "model/stl",
                "buffer": SAMPLE_STL.encode("utf-8"),
            })
            expect(frame.get_by_test_id("slice-button")).to_be_enabled(timeout=30_000)
            frame.get_by_test_id("slice-button").click()
            cancel = frame.get_by_role("button", name="Cancel", exact=True)
            expect(cancel).to_be_visible(timeout=30_000)
            cancel.click()
            expect(frame.get_by_text(re.compile(r"cancelled|canceled", re.I))).to_be_visible(timeout=60_000)

            run_backend("php", "artisan", "plugin:disable", PLUGIN_ID)
            page.reload(wait_until="networkidle")
            expect(page.get_by_role("tab", name=PLUGIN_LABEL, exact=True)).to_have_count(0, timeout=30_000)
            run_backend("php", "artisan", "plugin:enable", PLUGIN_ID)
            page.reload(wait_until="networkidle")
            frame = open_cura(page)
            if first_job not in (page.request.get(f"{BASE_URL}/backend/api/plugins/{PLUGIN_ID}/runtime/api/v1/jobs").text()):
                fail("Completed Cura job did not survive plugin disable/re-enable")
            context.close()
            browser.close()
    finally:
        Path(sample_path).unlink(missing_ok=True)
        run_backend("php", "artisan", "plugin:disable", PLUGIN_ID, check=False)
        run_backend("php", "artisan", "plugin:remove", PLUGIN_ID, check=False)

    print("CURA_WPRINT3D_E2E=PASS")


if __name__ == "__main__":
    try:
        main()
    except RuntimeError as error:
        raise SystemExit(str(error)) from error
