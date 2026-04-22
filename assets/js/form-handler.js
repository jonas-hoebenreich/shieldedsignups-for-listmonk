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
      openPopup(popupContainer);
    }, 5000);

    if (!state.exitIntentBound) {
      document.addEventListener("mouseout", (event) => {
        if (event.relatedTarget || event.toElement) {
          return;
        }

        if (event.clientY <= 10) {
          openPopup(popupContainer);
        }
      });
      state.exitIntentBound = true;
    }

    popupContainer.addEventListener("click", (event) => {
      if (
        event.target.matches(selectors.popupBackdrop) ||
        event.target.matches(selectors.popupClose)
      ) {
        closePopup(popupContainer);
      }
    });
  }

  function openPopup(container) {
    if (state.popupShown || !container) {
      return;
    }
    container.classList.add("is-active");
    state.popupShown = true;
  }

  function closePopup(container) {
    if (!container) {
      return;
    }
    container.classList.remove("is-active");
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
          payload.append("nonce", config.nonce || "");
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
          if (attributesField) {
            payload.append("attributes", attributesField.value || "{}");
          }

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
            return;
          }

          runSuccessTransition(form);
        } catch (error) {
          setMessage(form, "Connection to listmonk failed.", "error");
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
        closePopup(popupContainer);
      }
    }, 3500);
  }

  document.addEventListener("DOMContentLoaded", init);
})();
