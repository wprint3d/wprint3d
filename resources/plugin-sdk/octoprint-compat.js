(function attachWPrint3DOctoPrintCompat(global) {
  function parseJson(value, fallback) {
    if (!value) {
      return fallback;
    }

    try {
      return JSON.parse(value);
    } catch (_error) {
      return fallback;
    }
  }

  function getCookie(name) {
    const prefix = `${name}=`;
    const cookie = document.cookie
      .split("; ")
      .find((entry) => entry.startsWith(prefix));

    return cookie ? decodeURIComponent(cookie.slice(prefix.length)) : "";
  }

  function defaultContext() {
    const params = new URLSearchParams(global.location.search);

    return {
      pluginId: params.get("pluginId") || "",
      pluginName: params.get("pluginName") || "",
      extensionId: params.get("extensionId") || "",
      currentPrinterId: params.get("currentPrinterId") || "",
      pluginApiBase: params.get("pluginApiBase") || "",
      pluginSettingsBase: params.get("pluginSettingsBase") || "",
      pluginStateBase: params.get("pluginStateBase") || "",
      theme: parseJson(params.get("theme"), {}),
    };
  }

  async function request(url, options) {
    const response = await fetch(url, {
      credentials: "include",
      headers: {
        Accept: "application/json",
        "Content-Type": "application/json",
        "X-XSRF-TOKEN": getCookie("XSRF-TOKEN"),
        ...(options?.headers || {}),
      },
      ...options,
    });

    const contentType = response.headers.get("content-type") || "";
    const body = contentType.includes("application/json")
      ? await response.json()
      : await response.text();

    if (!response.ok) {
      const message = typeof body === "object" && body !== null
        ? body.message || JSON.stringify(body)
        : body;

      throw new Error(message || `Request failed with status ${response.status}.`);
    }

    return body;
  }

  function createHostBridge(overrides) {
    const context = {
      ...defaultContext(),
      ...(overrides || {}),
    };

    const pluginApiBase = context.pluginApiBase || `/backend/api/plugins/${context.pluginId}`;
    const pluginSettingsBase = context.pluginSettingsBase || `${pluginApiBase}/settings`;
    const pluginStateBase = context.pluginStateBase || `${pluginApiBase}/state`;

    return {
      context,
      getTheme() {
        return context.theme || {};
      },
      async getSettings() {
        return request(pluginSettingsBase, { method: "GET" });
      },
      async saveSettings(settings) {
        return request(pluginSettingsBase, {
          method: "PUT",
          body: JSON.stringify({ settings }),
        });
      },
      async getState() {
        return request(pluginStateBase, { method: "GET" });
      },
      async invokeAction(actionId, payload) {
        return request(`${pluginApiBase}/actions/${actionId}`, {
          method: "POST",
          body: JSON.stringify({
            payload: payload || {},
            printerId: context.currentPrinterId || null,
          }),
        });
      },
      watchState(callback, options) {
        const intervalMs = Math.max(1000, Number(options?.intervalMs || 5000));
        let disposed = false;

        const run = async () => {
          if (disposed) {
            return;
          }

          try {
            callback(await request(pluginStateBase, { method: "GET" }));
          } catch (error) {
            if (typeof options?.onError === "function") {
              options.onError(error);
            }
          }
        };

        if (options?.immediate !== false) {
          void run();
        }

        const intervalId = global.setInterval(run, intervalMs);

        return () => {
          disposed = true;
          global.clearInterval(intervalId);
        };
      },
    };
  }

  global.WPrint3DOctoPrintCompat = {
    createHostBridge,
    fromWindow() {
      return createHostBridge();
    },
  };
})(window);
