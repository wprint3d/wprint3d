from pathlib import Path
import os
import subprocess
import time

from playwright.sync_api import sync_playwright


BASE_URL = os.environ.get("BASE_URL", "https://127.0.0.1")
BACKEND_CONTAINER = os.environ.get("BACKEND_CONTAINER", "wprint3d-core-backend-1")
SCREENSHOT_DIR = Path(os.environ.get("SCREENSHOT_DIR", str(Path(__file__).resolve().parent.parent / "docs" / "assets" / "plugin-shapes")))

PLUGINS = [
    {
        "slug": "host-metrics",
        "plugin_id": "wprint3d.host-metrics",
        "name": "Host Metrics",
        "source_path": "/var/www/examples/plugins/host-metrics",
        "settings_subtitle": "PHP runtime + remote component UI",
        "settings_marker": "cross-platform remote component API",
        "mode": "declarative",
    },
    {
        "slug": "host-metrics-webview-php",
        "plugin_id": "wprint3d.host-metrics-webview-php",
        "name": "Host Metrics WebView",
        "source_path": "/var/www/examples/plugins/host-metrics-webview-php",
        "frame_path": "/plugins/wprint3d.host-metrics-webview-php/assets/ui/settings.html",
        "mode": "webview",
    },
    {
        "slug": "host-metrics-custom-bundle-php",
        "plugin_id": "wprint3d.host-metrics-custom-bundle-php",
        "name": "Host Metrics Bundle",
        "source_path": "/var/www/examples/plugins/host-metrics-custom-bundle-php",
        "frame_path": "/plugins/wprint3d.host-metrics-custom-bundle-php/assets/ui/settings.html",
        "mode": "custom_bundle",
    },
    {
        "slug": "host-metrics-declarative-bridge",
        "plugin_id": "wprint3d.host-metrics-declarative-bridge",
        "name": "Host Metrics Bridge",
        "source_path": "/var/www/examples/plugins/host-metrics-declarative-bridge",
        "settings_subtitle": "Bridge runtime + remote component UI",
        "settings_marker": "same host-rendered remote component API",
        "mode": "declarative",
    },
    {
        "slug": "host-metrics-webview-bridge",
        "plugin_id": "wprint3d.host-metrics-webview-bridge",
        "name": "Host Metrics Bridge WebView",
        "source_path": "/var/www/examples/plugins/host-metrics-webview-bridge",
        "frame_path": "/plugins/wprint3d.host-metrics-webview-bridge/assets/ui/settings.html",
        "mode": "webview",
    },
    {
        "slug": "host-metrics-custom-bundle-bridge",
        "plugin_id": "wprint3d.host-metrics-custom-bundle-bridge",
        "name": "Host Metrics Bridge Bundle",
        "source_path": "/var/www/examples/plugins/host-metrics-custom-bundle-bridge",
        "frame_path": "/plugins/wprint3d.host-metrics-custom-bundle-bridge/assets/ui/settings.html",
        "mode": "custom_bundle",
    },
]


def run(command: list[str], check: bool = True) -> subprocess.CompletedProcess:
    return subprocess.run(command, check=check, text=True, capture_output=True)


def backend_sh(command: str, check: bool = True) -> subprocess.CompletedProcess:
    return run(["docker", "exec", BACKEND_CONTAINER, "sh", "-lc", command], check=check)


def ensure_bridge_service() -> None:
    backend_sh(
        """
        php -r '$body = @file_get_contents("http://127.0.0.1:9310/health"); exit($body === false ? 1 : 0);' \
        || (cd /var/www/examples/plugins/host-metrics-bridge-service && nohup php -S 127.0.0.1:9310 router.php >/tmp/host-metrics-bridge.log 2>&1 &)
        """.strip()
    )
    time.sleep(1)


def cleanup_plugins() -> None:
    for plugin in PLUGINS:
        backend_sh(f"php artisan plugin:disable {plugin['plugin_id']}", check=False)
        backend_sh(f"php artisan plugin:remove {plugin['plugin_id']}", check=False)


def package_and_install(plugin: dict) -> None:
    package_path = f"/tmp/{plugin['slug']}.w3dp"
    backend_sh(f"php artisan plugin:pack {plugin['source_path']} --output {package_path}")
    backend_sh(f"php artisan plugin:install {package_path}")
    backend_sh(f"php artisan plugin:enable {plugin['plugin_id']}")


def save(page, filename: str) -> None:
    SCREENSHOT_DIR.mkdir(parents=True, exist_ok=True)
    page.screenshot(path=str(SCREENSHOT_DIR / filename), full_page=True)


def login(page) -> None:
    page.goto(BASE_URL, wait_until="networkidle")
    page.get_by_role("textbox").nth(0).fill("admin@admin.com")
    page.locator('input[type="password"]').fill("admin")
    page.get_by_role("button", name="Log in").click()
    page.wait_for_load_state("networkidle")
    page.wait_for_timeout(2500)


def open_settings(page) -> None:
    page.get_by_role("button", name="Settings").click()
    page.wait_for_timeout(800)


def open_plugins_tab(page) -> None:
    open_settings(page)
    page.get_by_role("button", name="Plugins").click()
    page.wait_for_selector("text=Installed plugins")


def open_plugin_settings_tab(page, plugin_name: str) -> None:
    page.get_by_role("button", name=plugin_name, exact=True).click()
    page.wait_for_timeout(1200)


def wait_for_navbar_metrics(page) -> None:
    page.wait_for_selector("text=CPU")
    page.wait_for_selector("text=RAM")


def wait_for_settings_surface(page, plugin: dict) -> None:
    if plugin["mode"] == "declarative":
        page.wait_for_selector(f"text={plugin['settings_subtitle']}")
        if plugin.get("settings_marker"):
            page.wait_for_selector(f"text={plugin['settings_marker']}")
        page.wait_for_selector("text=CPU")
        page.wait_for_selector("text=RAM")
        return

    deadline = time.time() + 15

    while time.time() < deadline:
        for frame in page.frames:
            if plugin["frame_path"] in frame.url:
                frame.wait_for_selector("text=CPU", timeout=5000)
                frame.wait_for_selector("text=RAM", timeout=5000)
                frame.wait_for_function("() => !document.body.innerText.includes('--%')", timeout=5000)
                frame.wait_for_function("() => !document.body.innerText.includes('CSRF token mismatch.')", timeout=5000)
                if plugin["mode"] == "webview":
                    frame.wait_for_selector("text=WebView surface", timeout=5000)
                else:
                    frame.wait_for_selector("text=Custom bundle surface", timeout=5000)
                    frame.wait_for_selector("text=Loaded from JS", timeout=5000)
                    frame.wait_for_selector("text=Manifest component", timeout=5000)
                return

        page.wait_for_timeout(500)

    raise RuntimeError(f"Timed out waiting for elevated settings surface for {plugin['plugin_id']}")


def close_settings(page) -> None:
    page.keyboard.press("Escape")
    page.wait_for_timeout(500)


def main() -> None:
    ensure_bridge_service()
    cleanup_plugins()

    with sync_playwright() as playwright:
        browser = playwright.chromium.launch(headless=True)
        page = browser.new_page(ignore_https_errors=True, viewport={"width": 1600, "height": 1600})

        login(page)

        for index, plugin in enumerate(PLUGINS, start=1):
            package_and_install(plugin)
            page.goto(BASE_URL, wait_until="networkidle")
            page.wait_for_timeout(2000)
            wait_for_navbar_metrics(page)
            save(page, f"{index:02d}-{plugin['slug']}-navbar.png")

            open_plugins_tab(page)
            page.wait_for_selector(f"text={plugin['name']}")
            open_plugin_settings_tab(page, plugin["name"])
            wait_for_settings_surface(page, plugin)
            save(page, f"{index:02d}-{plugin['slug']}-settings.png")
            close_settings(page)

            backend_sh(f"php artisan plugin:disable {plugin['plugin_id']}", check=False)
            backend_sh(f"php artisan plugin:remove {plugin['plugin_id']}", check=False)

        browser.close()

    cleanup_plugins()


if __name__ == "__main__":
    main()
