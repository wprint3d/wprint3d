export function mount(target, context) {
  applyTheme(context.theme || {});

  target.innerHTML = `
    <div class="component-shell">
      <div class="component-header">
        <div>
          <p class="component-kicker">Manifest component</p>
          <h2>${escapeHtml(context.pluginName || "Host Metrics")}</h2>
        </div>
        <span class="component-chip">Loaded from JS</span>
      </div>
      <p class="component-copy">
        The custom bundle loader imported this module through the manifest <code>components</code> field and mounted it into the settings page.
      </p>
      <div class="component-grid">
        <article class="metric-card">
          <div class="metric-top">
            <span class="metric-label">CPU</span>
            <strong class="metric-value" data-metric-value="cpu">--%</strong>
          </div>
          <div class="metric-track"><span data-metric-bar="cpu"></span></div>
        </article>
        <article class="metric-card">
          <div class="metric-top">
            <span class="metric-label">RAM</span>
            <strong class="metric-value" data-metric-value="ram">--%</strong>
          </div>
          <div class="metric-track metric-track--secondary"><span data-metric-bar="ram"></span></div>
        </article>
      </div>
      <div class="component-status">
        <span class="component-status__dot"></span>
        <span data-metric-status>Loading metrics…</span>
      </div>
    </div>
  `;

  injectStyles();

  const update = async () => {
    setStatus(target, "Loading metrics…");

    try {
      const response = await fetch(`${context.pluginApiBase}/actions/${context.actionId}`, {
        method: "POST",
        credentials: "include",
        headers: {
          "Accept": "application/json",
          "Content-Type": "application/json",
          "X-XSRF-TOKEN": getCookie("XSRF-TOKEN"),
        },
        body: JSON.stringify({ payload: {} }),
      });

      const payload = await response.json();

      if (!response.ok) {
        throw new Error(payload.message || "Unable to load metrics.");
      }

      const metrics = payload.data || payload;
      setMetric(target, "cpu", metrics?.cpu?.usedPercentage, "--primary");
      setMetric(target, "ram", metrics?.ram?.usedPercentage, "--secondary");
      setStatus(target, `Updated ${new Date().toLocaleTimeString([], { hour: "2-digit", minute: "2-digit" })}`);
    } catch (error) {
      setStatus(target, error.message || "Unable to load metrics.", true);
    }
  };

  update();
  const intervalId = window.setInterval(update, 10000);

  return () => window.clearInterval(intervalId);
}

function applyTheme(theme) {
  const root = document.documentElement;

  [
    ["--primary", theme.primary],
    ["--secondary", theme.secondary],
    ["--surface", theme.surface],
    ["--surface-strong", theme.elevation?.level2 || theme.surfaceVariant],
    ["--outline", theme.outlineVariant || theme.outline],
    ["--text", theme.onSurface],
    ["--muted", theme.onSurfaceVariant],
    ["--error", theme.error],
  ].forEach(([name, value]) => {
    if (value) {
      root.style.setProperty(name, value);
    }
  });
}

function injectStyles() {
  if (document.getElementById("host-metrics-card-styles")) {
    return;
  }

  const style = document.createElement("style");
  style.id = "host-metrics-card-styles";
  style.textContent = `
    .component-shell {
      display: grid;
      gap: 16px;
    }
    .component-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 16px;
      flex-wrap: wrap;
    }
    .component-kicker {
      margin: 0 0 6px;
      color: var(--muted);
      text-transform: uppercase;
      letter-spacing: 0.12em;
      font-size: 11px;
      font-weight: 700;
    }
    .component-header h2 {
      margin: 0;
      font-size: 26px;
      line-height: 1.08;
      color: var(--text);
    }
    .component-chip {
      display: inline-flex;
      align-items: center;
      border-radius: 999px;
      padding: 8px 12px;
      font-size: 12px;
      font-weight: 700;
      background: color-mix(in srgb, var(--surface-strong) 84%, transparent);
      border: 1px solid color-mix(in srgb, var(--outline) 78%, transparent);
      color: var(--text);
    }
    .component-copy {
      margin: 0;
      color: var(--muted);
      line-height: 1.55;
    }
    .component-copy code {
      color: var(--text);
    }
    .component-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
      gap: 14px;
    }
    .metric-card {
      border-radius: 18px;
      padding: 16px;
      background: color-mix(in srgb, var(--surface-strong) 78%, transparent);
      border: 1px solid color-mix(in srgb, var(--outline) 82%, transparent);
      display: grid;
      gap: 12px;
    }
    .metric-top {
      display: flex;
      align-items: baseline;
      justify-content: space-between;
      gap: 12px;
    }
    .metric-label {
      color: var(--muted);
      text-transform: uppercase;
      letter-spacing: 0.14em;
      font-size: 12px;
      font-weight: 700;
    }
    .metric-value {
      color: var(--text);
      font-size: 28px;
      line-height: 1;
    }
    .metric-track {
      height: 11px;
      border-radius: 999px;
      overflow: hidden;
      background: color-mix(in srgb, var(--text) 12%, transparent);
    }
    .metric-track > span {
      display: block;
      width: 0;
      height: 100%;
      border-radius: inherit;
      background: linear-gradient(90deg, color-mix(in srgb, var(--primary) 72%, white), var(--primary));
      transition: width 180ms ease;
    }
    .metric-track--secondary > span {
      background: linear-gradient(90deg, color-mix(in srgb, var(--secondary) 72%, white), var(--secondary));
    }
    .component-status {
      display: flex;
      align-items: center;
      gap: 10px;
      color: var(--muted);
      font-size: 13px;
    }
    .component-status__dot {
      width: 10px;
      height: 10px;
      border-radius: 999px;
      background: var(--primary);
      box-shadow: 0 0 0 6px color-mix(in srgb, var(--primary) 18%, transparent);
    }
    .component-status[data-error="true"] .component-status__dot {
      background: var(--error);
      box-shadow: 0 0 0 6px color-mix(in srgb, var(--error) 18%, transparent);
    }
  `;

  document.head.appendChild(style);
}

function setMetric(target, metricId, value, colorVariable) {
  const numeric = clamp(value);
  const valueNode = target.querySelector(`[data-metric-value="${metricId}"]`);
  const barNode = target.querySelector(`[data-metric-bar="${metricId}"]`);

  if (valueNode) {
    valueNode.textContent = `${Math.round(numeric)}%`;
  }

  if (barNode) {
    barNode.style.width = `${numeric}%`;
    barNode.style.background = `linear-gradient(90deg, color-mix(in srgb, var(${colorVariable}) 72%, white), var(${colorVariable}))`;
  }
}

function setStatus(target, message, isError = false) {
  const status = target.querySelector(".component-status");
  const label = target.querySelector("[data-metric-status]");

  if (status) {
    status.dataset.error = isError ? "true" : "false";
  }

  if (label) {
    label.textContent = message;
  }
}

function clamp(value) {
  const numeric = Number(value);

  if (!Number.isFinite(numeric)) {
    return 0;
  }

  return Math.max(0, Math.min(100, numeric));
}

function escapeHtml(value) {
  return String(value)
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll("\"", "&quot;")
    .replaceAll("'", "&#39;");
}

function getCookie(name) {
  const prefix = `${name}=`;

  for (const part of document.cookie.split(";")) {
    const trimmed = part.trim();

    if (trimmed.startsWith(prefix)) {
      return decodeURIComponent(trimmed.slice(prefix.length));
    }
  }

  return "";
}
