(function () {
  "use strict";

  const config = window.SSFLM_CONFIG || {};
  const selectors = {
    popupContainer: ".ssflm-popup-container",
    popupBackdrop: ".ssflm-popup-backdrop",
    popupClose: ".ssflm-popup-close",
    form: ".ssflm-form",
    panel: ".ssflm-panel",
    message: ".ssflm-message",
    thankYou: ".ssflm-thank-you",
  };

  const state = {
    popupShown: false,
    exitIntentBound: false,
    widgetIds: new Map(),
    pendingSubmissions: new WeakSet(),
    popupTriggers: new WeakMap(),
  };

  function init() {
    bindPopupTriggers();
    renderTurnstileWidgets();
    bindFormSubmit();
  }

  function bindPopupTriggers() {
    const popupContainer = document.querySelector(selectors.popupContainer);
    if (!popupContainer) {
      return;
    }

    setTimeout(() => {
      openPopup(popupContainer, "timer");
    }, 5000);

    if (!state.exitIntentBound) {
      document.addEventListener("mouseout", (event) => {
        if (event.relatedTarget || event.toElement) {
          return;
        }

        if (event.clientY <= 10) {
          openPopup(popupContainer, "exit_intent");
        }
      });
      state.exitIntentBound = true;
    }

    popupContainer.addEventListener("click", (event) => {
      if (
        event.target.matches(selectors.popupBackdrop) ||
        event.target.matches(selectors.popupClose)
      ) {
        closePopup(popupContainer, "dismiss");
      }
    });
  }

  function openPopup(container, trigger = "manual") {
    if (state.popupShown || !container) {
      return;
    }
    container.classList.add("is-active");
    state.popupShown = true;
    state.popupTriggers.set(container, trigger);
    trackEvent("popup_open", {
      form_type: "popup",
      trigger,
    });
  }

  function closePopup(container, reason = "close") {
    if (!container) {
      return;
    }
    container.classList.remove("is-active");
    trackEvent("popup_close", {
      form_type: "popup",
      reason,
      trigger: state.popupTriggers.get(container) || "manual",
    });
  }

  function renderTurnstileWidgets() {
    const forms = document.querySelectorAll(selectors.form);
    if (!forms.length || !config.siteKey) {
      return;
    }

    const attemptRender = () => {
      if (!window.turnstile || typeof window.turnstile.render !== "function") {
        setTimeout(attemptRender, 250);
        return;
      }

      forms.forEach((form) => {
        const holder = form.querySelector(".ssflm-turnstile");
        if (!holder || state.widgetIds.has(form)) {
          return;
        }

        const widgetId = window.turnstile.render(holder, {
          sitekey: config.siteKey,
          theme: "auto",
        });

        state.widgetIds.set(form, widgetId);
      });
    };

    attemptRender();
  }

  function bindFormSubmit() {
    document.querySelectorAll(selectors.form).forEach((form) => {
      form.addEventListener("submit", async (event) => {
        event.preventDefault();

        if (state.pendingSubmissions.has(form)) {
          return;
        }

        state.pendingSubmissions.add(form);
        setLoadingState(form, true);
        setMessage(form, "", "");

        try {
          const widgetId = state.widgetIds.get(form);
          let token = "";

          if (
            widgetId !== undefined &&
            window.turnstile &&
            typeof window.turnstile.getResponse === "function"
          ) {
            token = window.turnstile.getResponse(widgetId);
          }

          if (!token) {
            setMessage(
              form,
              "Please complete the Turnstile verification.",
              "error",
            );
            setLoadingState(form, false);
            state.pendingSubmissions.delete(form);
            return;
          }

          const payload = new FormData();
          payload.append("action", "ssflm_subscribe");
          const nonceField = form.querySelector('[name="nonce"]');
          const listField = form.querySelector('[name="list_id"]');
          payload.append("nonce", nonceField ? nonceField.value || "" : "");
          payload.append("list_id", listField ? listField.value || "0" : "0");
          payload.append(
            "email",
            form.querySelector('[name="email"]').value || "",
          );
          payload.append(
            "name",
            form.querySelector('[name="name"]').value || "",
          );
          payload.append(
            "type",
            form.getAttribute("data-form-type") || "inline",
          );
          payload.append("turnstile_token", token);

          const attributesField = form.querySelector('[name="attributes"]');
          const attributesPayload = buildAttributesPayload(
            form,
            attributesField,
          );
          if (attributesField) {
            payload.append("attributes", attributesPayload);
          }

          trackEvent("submit_attempt", {
            form_type: form.getAttribute("data-form-type") || "inline",
          });

          const response = await fetch(config.ajaxUrl, {
            method: "POST",
            credentials: "same-origin",
            body: payload,
          });

          const data = await response.json();

          if (!response.ok || !data.success) {
            const errorMsg =
              data && data.data && data.data.message
                ? data.data.message
                : "Subscription failed. Please try again.";
            setMessage(form, errorMsg, "error");
            if (
              widgetId !== undefined &&
              window.turnstile &&
              typeof window.turnstile.reset === "function"
            ) {
              window.turnstile.reset(widgetId);
            }
            trackEvent("submit_error", {
              form_type: form.getAttribute("data-form-type") || "inline",
              reason: "request_failed",
            });
            return;
          }

          runSuccessTransition(form);
          trackEvent("submit_success", {
            form_type: form.getAttribute("data-form-type") || "inline",
          });
        } catch (error) {
          setMessage(form, "Connection to listmonk failed.", "error");
          trackEvent("submit_error", {
            form_type: form.getAttribute("data-form-type") || "inline",
            reason: "network_error",
          });
        } finally {
          setLoadingState(form, false);
          state.pendingSubmissions.delete(form);
        }
      });
    });
  }

  function setLoadingState(form, isLoading) {
    const button = form.querySelector('button[type="submit"]');
    if (button) {
      if (!button.dataset.defaultText) {
        button.dataset.defaultText = button.textContent || "Join Newsletter";
      }
      button.disabled = isLoading;
      button.classList.toggle("is-loading", isLoading);
      button.textContent = isLoading
        ? "Subscribing..."
        : button.dataset.defaultText;
    }
  }

  function setMessage(form, message, type) {
    const messageNode = form.querySelector(selectors.message);
    if (!messageNode) {
      return;
    }

    messageNode.textContent = message;
    messageNode.classList.remove("is-error", "is-success");
    if (type === "error") {
      messageNode.classList.add("is-error");
    }
    if (type === "success") {
      messageNode.classList.add("is-success");
    }
  }

  function runSuccessTransition(form) {
    const panel = form.closest(selectors.panel);
    if (!panel) {
      setMessage(form, "Thank you for subscribing!", "success");
      return;
    }

    const thankYou = panel.querySelector(selectors.thankYou);
    if (!thankYou) {
      setMessage(form, "Thank you for subscribing!", "success");
      return;
    }

    panel.classList.add("is-success");
    form.classList.add("is-hidden");
    thankYou.classList.add("is-visible");

    setTimeout(() => {
      const popupContainer = panel.closest(selectors.popupContainer);
      if (popupContainer) {
        closePopup(popupContainer, "success");
      }
    }, 3500);
  }

  function buildAttributesPayload(form, attributesField) {
    const baseAttributes = parseAttributes(
      attributesField ? attributesField.value : "{}",
    );

    if (isTrackUrlEnabled(form)) {
      const sourceUrl = getCurrentPageUrl();
      if (sourceUrl) {
        baseAttributes.source_url = sourceUrl;
      }
    }

    try {
      return JSON.stringify(baseAttributes);
    } catch (error) {
      return "{}";
    }
  }

  function parseAttributes(rawValue) {
    if (!rawValue || typeof rawValue !== "string") {
      return {};
    }

    try {
      const parsed = JSON.parse(rawValue);
      if (!parsed || typeof parsed !== "object" || Array.isArray(parsed)) {
        return {};
      }
      return parsed;
    } catch (error) {
      return {};
    }
  }

  function isTrackUrlEnabled(form) {
    if (!form) {
      return false;
    }
    return form.getAttribute("data-track-url") === "1";
  }

  function getCurrentPageUrl() {
    try {
      const current = new URL(window.location.href);
      if (current.protocol !== "http:" && current.protocol !== "https:") {
        return "";
      }
      current.hash = "";
      return current.toString();
    } catch (error) {
      return "";
    }
  }

  function trackEvent(action, details = {}) {
    const payload = {
      category: "ShieldedSignups",
      action,
      details,
      page_url: getCurrentPageUrl(),
      timestamp: Date.now(),
    };

    // Native hook for custom analytics integrations.
    try {
      document.dispatchEvent(
        new CustomEvent("ssflm:tracking", { detail: payload }),
      );
    } catch (error) {
      // Ignore analytics dispatch failures.
    }

    // Matomo support via the global _paq queue.
    if (Array.isArray(window._paq)) {
      window._paq.push([
        "trackEvent",
        payload.category,
        payload.action,
        JSON.stringify(payload.details || {}),
      ]);
    }

    // Google Analytics (gtag.js) compatibility.
    if (typeof window.gtag === "function") {
      window.gtag("event", payload.action, {
        event_category: payload.category,
        event_label: JSON.stringify(payload.details || {}),
      });
    }

    // Generic GTM/dataLayer integration.
    if (Array.isArray(window.dataLayer)) {
      window.dataLayer.push({
        event: "ssflm_" + payload.action,
        ssflm: payload,
      });
    }

    // Plausible compatibility if loaded.
    if (typeof window.plausible === "function") {
      window.plausible("SSFLM " + payload.action, {
        props: payload.details || {},
      });
    }
  }

  document.addEventListener("DOMContentLoaded", init);
})();
