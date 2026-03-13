from pathlib import Path

from playwright.sync_api import sync_playwright


BASE_URL = "https://127.0.0.1"
SCREENSHOT_DIR = Path("/home/facuarmo/wprint3d-core/examples/plugins/host-metrics/docs/assets")


def save(page, name: str) -> None:
    SCREENSHOT_DIR.mkdir(parents=True, exist_ok=True)
    page.screenshot(path=str(SCREENSHOT_DIR / name), full_page=True)


def login(page) -> None:
    page.goto(BASE_URL, wait_until="networkidle")
    page.get_by_role("textbox").nth(0).fill("admin@admin.com")
    page.locator('input[type="password"]').fill("admin")
    page.get_by_role("button", name="Log in").click()
    page.wait_for_load_state("networkidle")
    page.wait_for_timeout(4000)


def open_plugins_tab(page) -> None:
    page.get_by_role("button", name="Settings").click()
    page.wait_for_timeout(1000)
    page.get_by_role("button", name="Plugins").click()
    page.wait_for_timeout(2000)
    page.wait_for_selector("text=Installed plugins")


def verify_plugin_install_modal(page) -> None:
    page.get_by_role("button", name="Add a plugin").click(force=True)
    page.wait_for_selector("text=Install a plugin")
    page.wait_for_timeout(750)
    page.get_by_role("button", name="Install from URL", exact=True).last.wait_for()
    page.get_by_role("button", name="Upload from file", exact=True).last.click(force=True)
    page.wait_for_selector("text=Choose a .w3dp package")
    page.wait_for_selector("text=or drag and drop a .w3dp file here")
    if page.get_by_role("button", name="Install unpacked", exact=True).count():
        page.get_by_role("button", name="Install unpacked", exact=True).last.click(force=True)
        page.wait_for_timeout(1500)
        if page.locator("text=Development mount unavailable").count():
            page.wait_for_selector("text=Development mount unavailable")
        else:
            page.wait_for_selector("text=Source mount:")
    page.get_by_role("button", name="Install from URL", exact=True).last.click(force=True)
    page.wait_for_selector("text=Package URL")
    page.get_by_role("button", name="Close", exact=True).last.click()
    page.wait_for_selector("text=Installed plugins")


def verify_marketplace_entry(page) -> None:
    page.get_by_role("button", name="Marketplace", exact=True).click()
    page.wait_for_selector("text=Official registry and trusted sources")
    page.get_by_label("Registry sources").click()
    page.wait_for_selector("text=Trusted registry sources")
    page.wait_for_selector("text=Add a trusted registry")
    page.wait_for_selector("text=Marketplace source settings")
    page.keyboard.press("Escape")
    page.wait_for_timeout(1500)
    page.keyboard.press("Escape")
    page.wait_for_selector("text=Installed plugins")


def main() -> None:
    with sync_playwright() as playwright:
        browser = playwright.chromium.launch(headless=True)
        page = browser.new_page(ignore_https_errors=True, viewport={"width": 1440, "height": 1800})

        login(page)
        page.wait_for_selector("text=CPU")
        page.wait_for_selector("text=RAM")
        save(page, "01-dashboard-navbar.png")

        open_plugins_tab(page)
        page.wait_for_selector("text=Host Metrics")
        verify_plugin_install_modal(page)
        verify_marketplace_entry(page)
        save(page, "02-plugin-management.png")

        page.get_by_role("button", name="Disable").nth(1).click()
        page.get_by_role("button", name="Disable plugin").click()
        page.wait_for_timeout(2500)
        page.wait_for_selector("text=Disabled")
        page.wait_for_function(
            "() => !Array.from(document.querySelectorAll('*')).some((node) => node.textContent === 'CPU')"
        )
        save(page, "03-plugin-disabled.png")

        page.get_by_role("button", name="Enable").nth(0).click()
        page.get_by_role("button", name="Enable plugin").click()
        page.wait_for_timeout(2500)
        page.wait_for_selector("text=CPU")
        save(page, "04-plugin-enabled-again.png")

        browser.close()


if __name__ == "__main__":
    main()
