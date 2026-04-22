(function () {
  "use strict";

  const config = window.SSFLM_ADMIN_CONFIG || {};

  function init() {
    bindAuthToggle();
    bindVerifyButtons();
  }

  function bindAuthToggle() {
    const toggle = document.querySelector(".ssflm-auth-toggle");
    const modeField = document.querySelector(".ssflm-auth-mode-field");
    const apiPanel = document.querySelector(".ssflm-auth-panel-api");
    const basicPanel = document.querySelector(".ssflm-auth-panel-basic");

    if (!toggle || !modeField || !apiPanel || !basicPanel) {
      return;
    }

    const sync = () => {
      const isBasic = toggle.checked;
      modeField.value = isBasic ? "basic" : "api";
      apiPanel.style.display = isBasic ? "none" : "";
      basicPanel.style.display = isBasic ? "" : "none";
    };

    toggle.addEventListener("change", sync);
    sync();
  }

  function bindVerifyButtons() {
    document.querySelectorAll(".ssflm-verify-listmonk").forEach((button) => {
      button.addEventListener("click", async () => {
        const action = button.getAttribute("data-action");
        const statusTarget = button.parentElement.querySelector(
          ".ssflm-admin-status",
        );
        if (!action || !statusTarget) {
          return;
        }

        button.disabled = true;
        statusTarget.textContent = "Checking...";
        statusTarget.classList.remove("is-error", "is-success");

        const payload = new FormData();
        payload.append("action", action);
        payload.append("nonce", config.nonce || "");

        const usernameField = document.querySelector("#listmonk_username");
        const apiUrlField = document.querySelector("#listmonk_api_url");
        const authModeField = document.querySelector(".ssflm-auth-mode-field");
        const apiKeyField = document.querySelector("#listmonk_api_key");
        const passwordField = document.querySelector("#listmonk_password");

        if (usernameField) {
          payload.append("listmonk_username", usernameField.value);
        }

        if (apiUrlField) {
          payload.append("listmonk_api_url", apiUrlField.value);
        }

        if (authModeField) {
          payload.append("listmonk_auth_mode", authModeField.value);
        }

        if (apiKeyField) {
          payload.append("listmonk_api_key", apiKeyField.value);
        }

        if (passwordField) {
          payload.append("listmonk_password", passwordField.value);
        }

        try {
          const response = await fetch(config.ajaxUrl, {
            method: "POST",
            credentials: "same-origin",
            body: payload,
          });
          const data = await response.json();

          if (!response.ok || !data.success) {
            const message =
              data && data.data && data.data.message
                ? data.data.message
                : "Verification failed.";
            statusTarget.textContent = message;
            statusTarget.classList.add("is-error");
            return;
          }

          statusTarget.textContent =
            data.data && data.data.message ? data.data.message : "Verified.";
          statusTarget.classList.add("is-success");
        } catch (error) {
          statusTarget.textContent = "Verification failed.";
          statusTarget.classList.add("is-error");
        } finally {
          button.disabled = false;
        }
      });
    });
  }

  document.addEventListener("DOMContentLoaded", init);
})();
