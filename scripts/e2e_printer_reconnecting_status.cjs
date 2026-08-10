const fs = require("node:fs");
const path = require("node:path");
const { chromium } = require("playwright");

const baseUrl = process.env.BASE_URL || "http://127.0.0.1:8081";
const screenshotDir = process.env.SCREENSHOT_DIR || "/tmp/wprint3d-reconnecting-status";
const printerId = "69f69ba48fcf1e3b4803b0a2";
const viewports = [
  [375, 812],
  [812, 375],
  [768, 1024],
  [1024, 768],
  [1440, 900],
  [1920, 1080],
];

const response = (body, status = 200) => ({
  status,
  contentType: "application/json",
  body: JSON.stringify(body),
});

const contrastRatio = (foreground, background) => {
  const rgb = (color) => color.match(/[\d.]+/g).slice(0, 3).map(Number);
  const luminance = (color) => {
    const channels = rgb(color).map((value) => {
      const normalized = value / 255;
      return normalized <= 0.03928
        ? normalized / 12.92
        : ((normalized + 0.055) / 1.055) ** 2.4;
    });

    return 0.2126 * channels[0] + 0.7152 * channels[1] + 0.0722 * channels[2];
  };
  const first = luminance(foreground);
  const second = luminance(background);

  return (Math.max(first, second) + 0.05) / (Math.min(first, second) + 0.05);
};

const installBackendRoutes = async (page) => {
  const printer = {
    _id: printerId,
    connected: true,
    node: "/dev/ttyUSB1",
    connectionStatus: "online",
    connectionDiagnostic: null,
    cameras: [],
    recordableCameras: [],
    settings: {},
    machine: {
      machineType: "Ender-2 Pro",
      uuid: "FAKE-UUID/canonical-suffix",
      connectionType: "serial",
      simulated: false,
      extruderCount: 1,
      firmwareName: "Marlin",
      capabilities: {},
    },
  };
  const selectedStatus = {
    statistics: {
      extruders: [{ temperature: 230, target: 230 }],
      bed: { temperature: 70, target: 70 },
    },
    lastSeen: Math.floor(Date.now() / 1000),
    isPaused: false,
    isReconnecting: true,
    isPrinting: true,
    layer: 3,
    maxLayer: 18,
    thresholdSecs: 7,
    connectionStatus: "online",
    connectionDiagnostic: null,
    currentLine: 526,
    maxLine: 4200,
    absolutePosition: { x: 43.431, y: 50.772, z: 0.4, e: 9.25 },
  };

  await page.route("**/backend/api/**", async (route) => {
    const url = route.request().url();
    const apiPath = url.split("/backend/api", 2)[1].split("?", 1)[0];
    const payloads = {
      "/app/name": "WPrint 3D",
      "/checkLogin": { id: "69f698414a49da2df50d0353" },
      "/ws/config": { appKey: "", port: 6001 },
      "/user": {
        _id: "69f698414a49da2df50d0353",
        name: "Admin",
        email: "admin@admin.com",
        role: 0,
        settings: { recording: { enabled: false } },
      },
      "/user/notifications": [],
      "/user/printer/selected": printerId,
      "/user/printer/selected/status": selectedStatus,
      "/user/printer/selected/print": {
        activeFile: "reconnection-test.gcode",
        hasActiveJob: true,
        lastJobHasFailed: false,
        lastLine: 526,
      },
      "/user/printer/selected/cameras": [],
      "/user/printer/selected/console": "",
      "/user/materials": [],
      "/files": { directories: [], files: [] },
      "/files/sortingModes": {
        NAME_ASCENDING: 0,
        NAME_DESCENDING: 1,
        DATE_ASCENDING: 2,
        DATE_DESCENDING: 3,
      },
      "/config/developerMode": true,
      "/config/terminalMaxLines": 200,
      "/config": [],
      "/plugins/ui": [],
      "/plugins": [],
      "/plugins/preferences": { automaticUpdates: false },
      "/app/update/status": null,
      "/printers": [printer],
      [`/printer/${printerId}`]: printer,
      "/cameras": [],
    };

    if (apiPath.startsWith("/config/") && !(apiPath in payloads)) {
      await route.fulfill(response(false));
      return;
    }

    await route.fulfill(response(payloads[apiPath] ?? null));
  });
};

const assertReconnectingStatus = async (page, viewport, theme) => {
  const label = "Estado de conexión: reconectando…";
  const capsule = page.getByLabel(label).first();
  await capsule.waitFor({ state: "visible", timeout: 15000 });

  const text = capsule.getByText("reconectando…", { exact: true });
  await text.waitFor({ state: "visible" });

  const spinner = capsule.getByRole("progressbar");
  await spinner.waitFor({ state: "visible" });

  const bounds = await capsule.boundingBox();
  if (!bounds || bounds.x < 0 || bounds.y < 0) {
    throw new Error(`${theme} ${viewport.join("x")}: reconnecting status starts outside the viewport`);
  }
  if (bounds.x + bounds.width > viewport[0] || bounds.y + bounds.height > viewport[1]) {
    throw new Error(`${theme} ${viewport.join("x")}: reconnecting status is clipped`);
  }

  const styles = await capsule.evaluate((element) => {
    const capsuleStyle = getComputedStyle(element);
    const textElement = element.querySelector("span");
    const textStyle = getComputedStyle(textElement || element);
    return {
      backgroundColor: capsuleStyle.backgroundColor,
      color: textStyle.color,
      fontWeight: textStyle.fontWeight,
    };
  });
  if (styles.backgroundColor === "rgba(0, 0, 0, 0)") {
    throw new Error(`${theme} ${viewport.join("x")}: reconnecting status has no visual container`);
  }
  if (contrastRatio(styles.color, styles.backgroundColor) < 4.5) {
    throw new Error(`${theme} ${viewport.join("x")}: reconnecting status contrast is below 4.5:1`);
  }

  const overflow = await page.evaluate(() => ({
    clientWidth: document.documentElement.clientWidth,
    scrollWidth: document.documentElement.scrollWidth,
  }));
  if (overflow.scrollWidth > overflow.clientWidth) {
    throw new Error(`${theme} ${viewport.join("x")}: page has horizontal overflow`);
  }
};

const main = async () => {
  fs.mkdirSync(screenshotDir, { recursive: true });
  const browser = await chromium.launch({ headless: true });

  try {
    for (const theme of ["light", "dark"]) {
      for (const viewport of viewports) {
        const context = await browser.newContext({
          viewport: { width: viewport[0], height: viewport[1] },
          locale: "es-AR",
          timezoneId: "America/Argentina/Buenos_Aires",
          colorScheme: theme,
        });
        const page = await context.newPage();
        await installBackendRoutes(page);
        await page.goto(baseUrl, { waitUntil: "domcontentloaded" });
        await assertReconnectingStatus(page, viewport, theme);
        const filename = `${theme}-${viewport.join("x")}.png`;
        await page.screenshot({ path: path.join(screenshotDir, filename), fullPage: true });
        process.stdout.write(`PASS ${theme} ${viewport.join("x")}\n`);
        await context.close();
      }
    }
  } finally {
    await browser.close();
  }
};

main().catch((error) => {
  console.error(error);
  process.exitCode = 1;
});
