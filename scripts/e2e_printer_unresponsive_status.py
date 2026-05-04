from pathlib import Path
import json
import os
from playwright.sync_api import sync_playwright, expect


BASE_URL = os.environ.get("BASE_URL", "http://127.0.0.1:8081")
SCREENSHOT_DIR = Path(
    os.environ.get(
        "SCREENSHOT_DIR",
        str(Path(__file__).resolve().parent.parent / "docs" / "assets" / "printer-unresponsive-status"),
    )
)
PRINTER_ID = "69f69ba48fcf1e3b4803b0a2"
DMESG_OUTPUT = "\n".join([
    "[66585.971172] usb 3-2: new full-speed USB device number 11 using uhci_hcd",
    "[66586.170583] usb 3-2: New USB device found, idVendor=2341, idProduct=0010, bcdDevice= 0.01",
    "[66586.174837] cdc_acm 3-2:1.0: ttyACM0: USB ACM device",
    "[73464.202401] usb 3-2: USB disconnect, device number 11",
    "[73476.224266] usb 3-2: device descriptor read/64, error -71",
    "[73476.454202] usb 3-2: device descriptor read/64, error -71",
    "[73492.234561] usb usb2-port4: attempt power cycle",
])


def response(payload, status=200):
    return {"status": status, "content_type": "application/json", "body": json.dumps(payload)}


def install_backend_routes(page):
    printer = {
        "_id": PRINTER_ID,
        "connected": False,
        "node": "/dev/ttyACM0",
        "connectionStatus": "unresponsive",
        "connectionDiagnostic": DMESG_OUTPUT,
        "cameras": [],
        "recordableCameras": [],
        "settings": {},
        "machine": {
            "machineType": "Arduino Mega 2560",
            "uuid": "957363237323514080E0",
            "connectionType": "serial",
            "simulated": False,
            "extruderCount": 1,
            "firmwareName": "Marlin",
            "capabilities": {},
        },
    }
    selected_status = {
        "statistics": {},
        "lastSeen": 1777785600,
        "isPaused": False,
        "thresholdSecs": 7,
        "connectionStatus": "unresponsive",
        "connectionDiagnostic": DMESG_OUTPUT,
    }

    def handle(route):
        url = route.request.url
        path = url.split("/backend/api", 1)[1].split("?", 1)[0]

        payloads = {
            "/app/name": "WPrint 3D",
            "/checkLogin": {"id": "69f698414a49da2df50d0353"},
            "/ws/config": {"appKey": "", "port": 6001},
            "/user": {
                "_id": "69f698414a49da2df50d0353",
                "name": "Admin",
                "email": "admin@admin.com",
                "role": 0,
                "settings": {"recording": {"enabled": False}},
            },
            "/user/notifications": [],
            "/user/printer/selected": PRINTER_ID,
            "/user/printer/selected/status": selected_status,
            "/user/printer/selected/print": {"activeFile": None, "hasActiveJob": False, "lastJobHasFailed": False, "lastLine": 0},
            "/user/printer/selected/cameras": [],
            "/user/printer/selected/console": "",
            "/user/materials": [],
            "/files": {"directories": [], "files": []},
            "/files/sortingModes": {"NAME_ASCENDING": 0, "NAME_DESCENDING": 1, "DATE_ASCENDING": 2, "DATE_DESCENDING": 3},
            "/config/developerMode": True,
            "/config/terminalMaxLines": 200,
            "/config": [],
            "/plugins/ui": [],
            "/plugins": [],
            "/plugins/preferences": {"automaticUpdates": False},
            "/app/update/status": None,
            "/printers": [printer],
            f"/printer/{PRINTER_ID}": printer,
            "/cameras": [],
        }

        if path.startswith("/config/") and path not in payloads:
            route.fulfill(**response(False))
            return

        route.fulfill(**response(payloads.get(path)))

    page.route("**/backend/api/**", handle)


def save(page, name: str) -> None:
    SCREENSHOT_DIR.mkdir(parents=True, exist_ok=True)
    page.screenshot(path=str(SCREENSHOT_DIR / name), full_page=True)


def main() -> None:
    with sync_playwright() as playwright:
        browser = playwright.chromium.launch(headless=True)
        page = browser.new_page(viewport={"width": 1440, "height": 1100})
        install_backend_routes(page)

        page.goto(BASE_URL, wait_until="domcontentloaded")
        page.wait_for_timeout(8000)
        expect(page.get_by_text("unresponsive").first).to_be_visible()
        save(page, "01-real-app-unresponsive-status.png")

        page.get_by_role("button", name="View printer connection diagnostic").first.click()
        expect(page.get_by_text("device descriptor read/64, error -71")).to_be_visible()
        save(page, "02-real-app-main-diagnostic-modal.png")
        page.get_by_role("button", name="Got it").click()

        page.mouse.move(1, 1)
        page.get_by_role("button", name="Settings").click()
        page.wait_for_timeout(1000)
        expect(page.get_by_text("Arduino Mega 2560")).to_be_visible()
        save(page, "03-real-app-settings-unresponsive-badge.png")

        page.get_by_role("button", name="View printer connection diagnostic").last.click()
        expect(page.get_by_text("attempt power cycle")).to_be_visible()
        save(page, "04-real-app-settings-diagnostic-modal.png")
        page.get_by_role("button", name="Got it").click()

        page.get_by_role("button", name="Edit").click()
        expect(page.get_by_text("Printer not responding")).to_be_visible()
        expect(page.get_by_text("device descriptor read/64, error -71")).to_be_visible()
        save(page, "05-real-app-edit-details-diagnostic-box.png")

        browser.close()


if __name__ == "__main__":
    main()
