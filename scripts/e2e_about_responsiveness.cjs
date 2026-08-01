const { performance } = require("node:perf_hooks");
const { chromium } = require("playwright");

const baseUrl = process.env.BASE_URL || "https://127.0.0.1";
const variant = process.env.ABOUT_VARIANT || "current";
const testEmail = process.env.E2E_EMAIL || "admin@admin.com";
const testPassword = process.env.E2E_PASSWORD || "admin";

async function main() {
    const browser = await chromium.launch({ headless: true });
    try {
        const context = await browser.newContext({
            ignoreHTTPSErrors: true,
            locale: "en-US",
            viewport: { width: 1440, height: 900 },
        });
        const page = await context.newPage();
        page.setDefaultTimeout(120_000);

        if (process.env.E2E_DEBUG === "true") {
            page.on("console", (message) => console.error(`[browser:${message.type()}] ${message.text()}`));
            page.on("pageerror", (error) => console.error(`[browser:pageerror] ${error.stack || error.message}`));
            page.on("requestfailed", (request) => console.error(`[browser:requestfailed] ${request.url()} ${request.failure()?.errorText || ""}`));
        }

        await context.request.get(`${baseUrl}/backend/sanctum/csrf-cookie`);
        const csrfCookie = (await context.cookies()).find((cookie) => cookie.name === "XSRF-TOKEN");
        const loginResponse = await context.request.post(`${baseUrl}/backend/login`, {
            form: { email: testEmail, password: testPassword },
            headers: {
                Accept: "application/json",
                "X-XSRF-TOKEN": decodeURIComponent(csrfCookie.value),
            },
        });

        if (!loginResponse.ok()) {
            throw new Error(`Login failed with HTTP ${loginResponse.status()}: ${await loginResponse.text()}`);
        }

        await page.goto(baseUrl, { waitUntil: "networkidle" });

        if (process.env.E2E_DEBUG === "true") {
            console.error(await page.locator("body").innerText());
            await page.screenshot({ path: "/artifacts/about-debug.png" });
        }

        await page.getByRole("button", { name: /Settings|Configuración/i }).click();

        await page.evaluate(() => {
        window.__aboutLongTasks = [];
        window.__aboutObserver = new PerformanceObserver((list) => {
            for (const entry of list.getEntries()) {
                window.__aboutLongTasks.push({
                    startTime: entry.startTime,
                    duration: entry.duration,
                });
            }
        });
        window.__aboutObserver.observe({ entryTypes: ["longtask"] });
        });

        const started = performance.now();
        const responsePromise = page.waitForResponse((response) => response.url().endsWith("/api/app/licenses"));
        await page.getByRole("button", { name: /About|Acerca de/i }).click();

        const response = await responsePromise;
        await response.finished();
        const responseFinishedMs = performance.now() - started;

        await page.waitForFunction(
            () => document.body.innerText.includes("nginx (official Docker image)")
        );
        const contentVisibleMs = performance.now() - started;

        const scrollStarted = performance.now();
        if (process.env.E2E_DEBUG === "true") {
            console.error(await page.getByTestId("about-license-list").evaluate((element) => {
                const candidates = [ element, ...element.querySelectorAll("*") ];

                return candidates.map((candidate) => ({
                    tag: candidate.tagName,
                    testId: candidate.getAttribute("data-testid"),
                    clientHeight: candidate.clientHeight,
                    scrollHeight: candidate.scrollHeight,
                    overflowY: getComputedStyle(candidate).overflowY,
                })).filter((candidate) => candidate.scrollHeight > candidate.clientHeight);
            }));
        }
        await page.getByTestId("about-license-list").hover();
        await page.mouse.wheel(0, 500);
        await page.waitForFunction(
            () => document.querySelector('[data-testid="about-license-list"]')?.scrollTop > 0
        );
        const scrollResponseMs = performance.now() - scrollStarted;

        const probeStarted = performance.now();
        await page.evaluate(() => new Promise((resolve) => setTimeout(resolve, 0)));
        const eventLoopProbeMs = performance.now() - probeStarted;

        const metrics = await page.evaluate(() => ({
            longTasks: window.__aboutLongTasks || [],
            domElements: document.getElementsByTagName("*").length,
            bodyTextLength: document.body.innerText.length,
            licenseScrollTop: document.querySelector('[data-testid="about-license-list"]')?.scrollTop || 0,
            licenseScrollHeight: document.querySelector('[data-testid="about-license-list"]')?.scrollHeight || 0,
        }));
        const longTasks = metrics.longTasks;
        delete metrics.longTasks;

        Object.assign(metrics, {
        variant,
        responseFinishedMs: Number(responseFinishedMs.toFixed(1)),
            contentVisibleMs: Number(contentVisibleMs.toFixed(1)),
            scrollResponseMs: Number(scrollResponseMs.toFixed(1)),
        eventLoopProbeMs: Number(eventLoopProbeMs.toFixed(1)),
        longTaskCount: longTasks.length,
        longTaskTotalMs: Number(longTasks.reduce((total, task) => total + task.duration, 0).toFixed(1)),
            longTaskMaxMs: Number(Math.max(0, ...longTasks.map((task) => task.duration)).toFixed(1)),
        });

        if (process.env.E2E_SCREENSHOT === "true") {
            await page.screenshot({ path: "/artifacts/about-flat-list.png" });
        }

        console.log(JSON.stringify(metrics, null, 2));
    } finally {
        await browser.close();
    }
}

main().catch((error) => {
    console.error(error);
    process.exitCode = 1;
});
