from pathlib import Path
import os
import re
import subprocess

from playwright.sync_api import sync_playwright, expect


BASE_URL = os.environ.get("BASE_URL", "https://127.0.0.1")
SCREENSHOT_DIR = Path(os.environ.get("SCREENSHOT_DIR", str(Path(__file__).resolve().parent.parent / "docs" / "assets" / "plugins")))
CONTAINER_RUNTIME = os.environ.get("CONTAINER_RUNTIME", "sudo podman")
BACKEND_CONTAINER = os.environ.get("BACKEND_CONTAINER", "wprint3d-core_backend_1")
PLUGIN_ID = os.environ.get("PLUGIN_ID", "acme.hello-world")
PLUGIN_CARD_NAME = os.environ.get("PLUGIN_CARD_NAME", "Hello World")
PLUGIN_RELATIVE_PATH = os.environ.get("PLUGIN_RELATIVE_PATH", "acme-hello-world")


def save(page, name: str) -> None:
    SCREENSHOT_DIR.mkdir(parents=True, exist_ok=True)
    page.screenshot(path=str(SCREENSHOT_DIR / name), full_page=True)


def backend_exec(*args: str, check: bool = True) -> subprocess.CompletedProcess[str]:
    command = [*CONTAINER_RUNTIME.split(), "exec", BACKEND_CONTAINER, *args]

    return subprocess.run(command, check=check, text=True, capture_output=True)


def ensure_clean_plugin_state() -> None:
    backend_exec("php", "artisan", "plugin:disable", PLUGIN_ID, check=False)
    backend_exec("php", "artisan", "plugin:remove", PLUGIN_ID, check=False)


def verify_plugin_installed_in_backend() -> None:
    result = backend_exec("php", "artisan", "plugin:list")

    if PLUGIN_ID not in result.stdout:
        raise AssertionError(f"{PLUGIN_ID} was not found in backend plugin:list output.")


def login(page) -> None:
    page.goto(BASE_URL, wait_until="networkidle")
    page.wait_for_timeout(3000)
    page.locator("input").nth(0).fill("admin@admin.com")
    page.locator('input[type="password"]').fill("admin")
    page.get_by_role("button", name="Log in").click()
    page.wait_for_load_state("networkidle")
    page.wait_for_timeout(4000)


def open_plugins_tab(page) -> None:
    page.get_by_role("button", name="Settings").click()
    page.wait_for_timeout(750)
    page.get_by_role("button", name=re.compile(r"Plugins$")).last.click()
    page.wait_for_timeout(2000)
    page.wait_for_selector("text=Installed plugins")


def install_unpacked_plugin(page) -> None:
    page.get_by_role("button", name="Add a plugin").click()
    page.wait_for_timeout(1000)
    page.locator("text=Install unpacked").click()
    page.wait_for_timeout(2000)

    expect(page.get_by_text("Source mounts")).to_be_visible(timeout=10000)
    expect(page.get_by_role("button", name="/var/www/plugins", exact=True)).to_be_visible(timeout=10000)
    expect(page.get_by_role("button", name="/var/www/plugins-dev", exact=True)).to_be_visible(timeout=10000)
    expect(page.get_by_text(f"{PLUGIN_ID} • 0.1.0")).to_be_visible(timeout=10000)
    expect(page.get_by_text(f"Mounted path: {PLUGIN_RELATIVE_PATH}")).to_be_visible(timeout=10000)
    save(page, "08-unpacked-plugin-list.png")

    result = page.evaluate(
        """async (payload) => {
            const tokenCookie = document.cookie
                .split('; ')
                .find((entry) => entry.startsWith('XSRF-TOKEN='));
            const csrfToken = tokenCookie ? decodeURIComponent(tokenCookie.split('=').slice(1).join('=')) : '';
            const response = await fetch('/backend/api/plugins/install', {
                method: 'POST',
                credentials: 'include',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                    'X-XSRF-TOKEN': csrfToken,
                },
                body: JSON.stringify({ unpackedPath: payload }),
            });

            return {
                status: response.status,
                body: await response.text(),
            };
        }""",
        f"/var/www/plugins/{PLUGIN_RELATIVE_PATH}",
    )

    if result["status"] != 200 or PLUGIN_ID not in result["body"]:
        raise AssertionError(f"Browser install request failed: {result}")

    save(page, "09-unpacked-plugin-installed.png")


def verify_installed_plugin_card(page) -> None:
    page.reload(wait_until="networkidle")
    page.wait_for_timeout(3000)
    open_plugins_tab(page)
    expect(page.get_by_text(f"{PLUGIN_ID} • 0.1.0")).to_be_visible(timeout=10000)
    save(page, "10-unpacked-plugin-card.png")


def main() -> None:
    ensure_clean_plugin_state()

    with sync_playwright() as playwright:
        browser = playwright.chromium.launch(headless=True)
        page = browser.new_page(ignore_https_errors=True, viewport={"width": 1440, "height": 1800})

        login(page)
        open_plugins_tab(page)
        save(page, "07-before-unpacked-install.png")
        install_unpacked_plugin(page)
        verify_installed_plugin_card(page)

        browser.close()

    verify_plugin_installed_in_backend()
    print("E2E_RESULT=PASS")


if __name__ == "__main__":
    main()
