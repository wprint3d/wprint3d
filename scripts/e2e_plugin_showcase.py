from pathlib import Path
from playwright.sync_api import sync_playwright


BASE_URL = "https://127.0.0.1"
SCREENSHOT_DIR = Path("/home/facuarmo/wprint3d-core/docs/assets/plugins")


def save(page, name: str) -> None:
    SCREENSHOT_DIR.mkdir(parents=True, exist_ok=True)
    page.screenshot(path=str(SCREENSHOT_DIR / name), full_page=True)


def main() -> None:
    with sync_playwright() as playwright:
        browser = playwright.chromium.launch(headless=True)
        page = browser.new_page(ignore_https_errors=True, viewport={"width": 1440, "height": 1800})

        page.goto(BASE_URL, wait_until="networkidle")
        page.wait_for_timeout(5000)

        page.get_by_role("textbox").nth(0).fill("admin@admin.com")
        page.locator('input[type="password"]').fill("admin")
        save(page, "01-login.png")

        page.get_by_role("button", name="Log in").click()
        page.wait_for_timeout(4000)
        page.wait_for_load_state("networkidle")
        save(page, "02-dashboard.png")

        page.get_by_role("button", name="Settings").click()
        page.wait_for_timeout(1000)
        page.get_by_role("button", name="Plugins").click()
        page.wait_for_timeout(2000)
        page.wait_for_selector("text=Installed plugins")
        save(page, "03-plugins-tab.png")

        page.get_by_role("button", name="Ping plugin").click()
        page.wait_for_selector("text=pong from Hello World")
        save(page, "04-plugin-action-toast.png")

        page.get_by_role("button", name="Disable").click()
        page.wait_for_selector("text=Disabled")
        save(page, "05-plugin-disabled.png")

        page.get_by_role("button", name="Enable").click()
        page.wait_for_selector("text=Enabled")
        save(page, "06-plugin-enabled-again.png")

        browser.close()


if __name__ == "__main__":
    main()
