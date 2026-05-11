(function () {
  "use strict";

  function generateToken() {
    return "hs-" + Math.random().toString(36).slice(2) + "-" + Date.now().toString(36);
  }

  function safeJson(value) {
    try {
      return JSON.stringify(value, null, 2);
    } catch (e) {
      return String(value);
    }
  }

  function initEmbed(container) {
    if (!container) return;
    if (container.getAttribute("data-propeller-initialized") === "1") return;
    container.setAttribute("data-propeller-initialized", "1");

    var instanceId = container.getAttribute("data-instance-id") || "";
    var configMap = window.PropellerEmbedInstances || {};
    var CONFIG = configMap[instanceId] || {
      debug: false,
      initialTimeoutMs: 8000,
      handshakeTimeoutMs: 8000,
      expectedMessageType: "propeller-page-state",
      allowedIframeOrigins: [],
      newTabPath: "/axelerator-public",
      handshakeInitType: "propeller-handshake-init",
      handshakeAckType: "propeller-handshake-ack"
    };

    var iframe = container.querySelector(".propeller-app");
    var warning = container.querySelector(".propeller-cookie-warning");
    var warningDetail = container.querySelector(".cookie-warning-detail");
    var retryBtn = container.querySelector(".retry-embed");
    var cookieDomainLabel = container.querySelector(".cookie-domain-label");
    var openNewTab = container.querySelector(".open-new-tab");
    var debugPanel = container.querySelector(".propeller-debug-panel");
    var debugLogEl = container.querySelector(".cookie-debug-log");
    var debugClearBtn = container.querySelector(".propeller-debug-clear");
    var handshakeStateEl = container.querySelector(".propeller-debug-handshake-state");
    var lastStateEl = container.querySelector(".propeller-debug-last-state");
    var lastReasonEl = container.querySelector(".propeller-debug-last-reason");
    var messageCountEl = container.querySelector(".propeller-debug-message-count");
    var sourceMatchCountEl = container.querySelector(".propeller-debug-source-match-count");
    var currentSrcEl = container.querySelector(".propeller-debug-current-src");
    var queryInstanceEl = container.querySelector(".propeller-debug-query-instance");
    var lastOriginEl = container.querySelector(".propeller-debug-last-origin");
    var handshakeTokenEl = container.querySelector(".propeller-debug-token");
    var destroyed = false;

    if (!iframe || !warning) return;

    var state = {
      instanceId: instanceId,
      iframeLoaded: false,
      pageStateReceived: false,
      timeoutId: null,
      environment: "unknown",
      lastMessageOrigin: "",
      lastMessageState: "",
      lastReason: "initialized",
      lastEventSourceMatched: false,
      handshakeConfirmed: false,
      handshakeToken: generateToken(),
      messagesSeen: 0,
      sourceMatchedMessages: 0,
      handshakeAckCount: 0
    };

    function refreshDiagnostics() {
      if (handshakeStateEl) handshakeStateEl.textContent = state.handshakeConfirmed ? "confirmed" : "pending";
      if (lastStateEl) lastStateEl.textContent = state.lastMessageState || "none";
      if (lastReasonEl) lastReasonEl.textContent = state.lastReason || "none";
      if (messageCountEl) messageCountEl.textContent = String(state.messagesSeen);
      if (sourceMatchCountEl) sourceMatchCountEl.textContent = String(state.sourceMatchedMessages);
      if (currentSrcEl) currentSrcEl.textContent = iframe.src || "";
      if (lastOriginEl) lastOriginEl.textContent = state.lastMessageOrigin || "none";
      if (handshakeTokenEl) handshakeTokenEl.textContent = state.handshakeToken || "none";
      if (queryInstanceEl) {
        try {
          queryInstanceEl.textContent = new URL(iframe.src, window.location.href).searchParams.get("propeller_embed_instance") || "missing";
        } catch (e) {
          queryInstanceEl.textContent = "invalid-url";
        }
      }
    }

    function setReason(reason, details) {
      state.lastReason = reason;
      refreshDiagnostics();
      if (CONFIG.debug) {
        if (typeof details !== "undefined") {
          log(reason, details);
        } else {
          log(reason);
        }
      }
    }

    function hardHideDebugUi() {
      if (debugPanel) {
        debugPanel.hidden = true;
        debugPanel.style.display = "none";
        debugPanel.setAttribute("aria-hidden", "true");
      }
      if (debugLogEl) {
        debugLogEl.hidden = true;
        debugLogEl.style.display = "none";
        debugLogEl.textContent = "";
      }
    }

    function showDebugUiIfEnabled() {
      if (!CONFIG.debug) {
        hardHideDebugUi();
        return;
      }
      if (debugPanel) {
        debugPanel.hidden = false;
        debugPanel.style.display = "block";
        debugPanel.removeAttribute("aria-hidden");
      }
      if (debugLogEl) {
        debugLogEl.hidden = false;
        debugLogEl.style.display = "block";
      }
    }

    function log() {
      if (!CONFIG.debug) return;

      var parts = Array.prototype.slice.call(arguments).map(function (v) {
        try {
          return typeof v === "string" ? v : safeJson(v);
        } catch (e) {
          return String(v);
        }
      });

      var line = "[" + new Date().toISOString() + "] [" + instanceId + "] " + parts.join(" ");
      if (window.console && console.log) {
        console.log("[Propeller Embed]", instanceId, ...arguments);
      }

      showDebugUiIfEnabled();

      if (debugLogEl) {
        debugLogEl.textContent += line + "\n";
        debugLogEl.scrollTop = debugLogEl.scrollHeight;
      }
    }

    function getIframeUrl() {
      try {
        return new URL(iframe.src, window.location.href);
      } catch (e) {
        setReason("invalid_iframe_url", e && e.message ? e.message : e);
        return null;
      }
    }

    function getIframeOrigin() {
      var u = getIframeUrl();
      return u ? u.origin : "";
    }

    function getIframeHost() {
      var u = getIframeUrl();
      return u ? u.hostname : "";
    }

    function detectEnvironment() {
      var host = getIframeHost().toLowerCase();
      if (host.indexOf("propeller.insure") !== -1) return "prod";
      return "unknown";
    }

    function updateEnvironmentLabels() {
      state.environment = detectEnvironment();
      var origin = getIframeOrigin();
      var host = getIframeHost();

      if (cookieDomainLabel && host) {
        cookieDomainLabel.textContent = host;
      }

      if (openNewTab && origin) {
        var newTabUrl = new URL(iframe.src, window.location.href);
        openNewTab.href = newTabUrl.toString();
      }

      refreshDiagnostics();
      setReason("environment_detected", { environment: state.environment, origin: origin });
    }

    function showWarning(message) {
      warning.hidden = false;
      if (warningDetail) warningDetail.textContent = message || "";
      setReason("warning_shown", message);
    }

    function hideWarning() {
      warning.hidden = true;
      if (warningDetail) warningDetail.textContent = "";
      setReason("warning_hidden");
    }

    function clearTimeoutSafe() {
      if (state.timeoutId) {
        clearTimeout(state.timeoutId);
        state.timeoutId = null;
      }
    }

    function startTimeout() {
      clearTimeoutSafe();
      state.timeoutId = window.setTimeout(function () {
        if (destroyed) return;

        if (!state.iframeLoaded) {
          showWarning("The embedded app did not load in time.");
          setReason("timeout_iframe_not_loaded");
          return;
        }

        if (!state.handshakeConfirmed) {
          showWarning("The embedded app loaded but did not complete handshake validation.");
          setReason("timeout_handshake_not_confirmed", {
            iframeSrc: iframe.src,
            queryInstance: (function () {
              try {
                return new URL(iframe.src, window.location.href).searchParams.get("propeller_embed_instance") || "missing";
              } catch (e) {
                return "invalid-url";
              }
            })(),
            allowedOrigins: CONFIG.allowedIframeOrigins,
            messagesSeen: state.messagesSeen,
            sourceMatchedMessages: state.sourceMatchedMessages,
            lastMessageOrigin: state.lastMessageOrigin || "none"
          });
          return;
        }

        if (!state.pageStateReceived) {
          showWarning("The embedded app loaded but did not report its state.");
          setReason("timeout_no_page_state");
        }
      }, CONFIG.handshakeTimeoutMs || CONFIG.initialTimeoutMs || 8000);
    }

    function resetState() {
      clearTimeoutSafe();
      state.iframeLoaded = false;
      state.pageStateReceived = false;
      state.lastMessageOrigin = "";
      state.lastMessageState = "";
      state.lastEventSourceMatched = false;
      state.handshakeConfirmed = false;
      state.messagesSeen = 0;
      state.sourceMatchedMessages = 0;
      state.handshakeAckCount = 0;
      state.handshakeToken = generateToken();
      refreshDiagnostics();
      setReason("state_reset");
    }

    function handleIframeLoad() {
      state.iframeLoaded = true;
      refreshDiagnostics();
      setReason("iframe_load_event");
      sendHandshakeInit();
    }

    function isAllowedOrigin(origin) {
      return Array.isArray(CONFIG.allowedIframeOrigins) && CONFIG.allowedIframeOrigins.indexOf(origin) !== -1;
    }

    function isMessageForThisInstance(event) {
      try {
        return !!iframe.contentWindow && event.source === iframe.contentWindow;
      } catch (e) {
        setReason("message_source_check_failed", e && e.message ? e.message : e);
        return false;
      }
    }

    function sendHandshakeInit() {
      var origin = getIframeOrigin();
      if (!origin || !iframe.contentWindow) {
        setReason("handshake_init_skipped_missing_origin_or_window", {
          origin: origin,
          hasContentWindow: !!iframe.contentWindow
        });
        return;
      }

      var payload = {
        type: CONFIG.handshakeInitType || "propeller-handshake-init",
        instanceId: instanceId,
        parentSuppliedInstanceId: instanceId,
        queryInstanceId: (function () {
          try {
            return new URL(iframe.src, window.location.href).searchParams.get("propeller_embed_instance") || instanceId;
          } catch (e) {
            return instanceId;
          }
        })(),
        handshakeToken: state.handshakeToken,
        iframeSrc: iframe.src
      };

      try {
        iframe.contentWindow.postMessage(payload, origin);
        refreshDiagnostics();
        setReason("handshake_init_sent", { payload: payload, targetOrigin: origin });
      } catch (e) {
        setReason("handshake_init_send_failed", e && e.message ? e.message : e);
      }
    }

    function handleMessage(event) {
      if (destroyed) return;

      state.messagesSeen += 1;

      if (!isMessageForThisInstance(event)) {
        refreshDiagnostics();
        return;
      }

      state.sourceMatchedMessages += 1;
      state.lastEventSourceMatched = true;
      state.lastMessageOrigin = event.origin || "";
      refreshDiagnostics();

      if (!isAllowedOrigin(event.origin)) {
        setReason("message_disallowed_origin", event.origin);
        return;
      }

      var data = event.data || {};
      if (!data || typeof data !== "object") {
        setReason("message_invalid_payload", event.data);
        return;
      }

      var receivedInstanceId = data.instanceId || data.parentSuppliedInstanceId || data.queryInstanceId || "";
      if (receivedInstanceId !== instanceId) {
        setReason("message_wrong_instance", {
          received: receivedInstanceId,
          expected: instanceId,
          rawData: data
        });
        return;
      }

      if (data.type === (CONFIG.handshakeAckType || "propeller-handshake-ack")) {
        if (data.handshakeToken !== state.handshakeToken) {
          setReason("handshake_ack_invalid_token", {
            received: data.handshakeToken || "",
            expected: state.handshakeToken
          });
          return;
        }

        state.handshakeConfirmed = true;
        state.handshakeAckCount += 1;
        refreshDiagnostics();
        setReason("handshake_confirmed", data);
        return;
      }

      if (data.type !== CONFIG.expectedMessageType) {
        setReason("message_unexpected_type", data.type);
        return;
      }

      if (!state.handshakeConfirmed) {
        setReason("page_state_before_handshake", data);
        return;
      }

      if (data.handshakeToken && data.handshakeToken !== state.handshakeToken) {
        setReason("page_state_invalid_token", {
          received: data.handshakeToken,
          expected: state.handshakeToken
        });
        return;
      }

      state.pageStateReceived = true;
      state.lastMessageState = data.state || "";
      clearTimeoutSafe();
      refreshDiagnostics();
      setReason("page_state_received", data);

      if (data.state === "success") {
        hideWarning();
        return;
      }

      if (data.state === "error") {
        showWarning("Third-party cookies appear to be blocked.");
        return;
      }

      showWarning("The embedded app reported an unknown state.");
    }

    function retryIframe() {
      resetState();
      hideWarning();

      var url = getIframeUrl();
      if (!url) {
        showWarning("Could not retry because the iframe URL is invalid.");
        return;
      }

      url.searchParams.set("_t", String(Date.now()));
      url.searchParams.set("propeller_embed_instance", instanceId);
      iframe.src = url.toString();
      refreshDiagnostics();
      setReason("iframe_retry", iframe.src);
      startTimeout();
    }

    function clearDebugLog() {
      if (debugLogEl) {
        debugLogEl.textContent = "";
      }
      setReason("debug_log_cleared");
    }

    function destroy() {
      if (destroyed) return;
      destroyed = true;
      clearTimeoutSafe();
      iframe.removeEventListener("load", handleIframeLoad);
      window.removeEventListener("message", handleMessage);
      if (retryBtn) retryBtn.removeEventListener("click", retryIframe);
      if (debugClearBtn) debugClearBtn.removeEventListener("click", clearDebugLog);
      container.removeAttribute("data-propeller-initialized");
      delete container.PropellerEmbedDebug;
    }

    iframe.addEventListener("load", handleIframeLoad);
    window.addEventListener("message", handleMessage);
    if (retryBtn) retryBtn.addEventListener("click", retryIframe);
    if (debugClearBtn) debugClearBtn.addEventListener("click", clearDebugLog);

    hardHideDebugUi();
    updateEnvironmentLabels();
    refreshDiagnostics();
    startTimeout();

    setReason("initialization_complete", {
      instanceId: instanceId,
      handshakeToken: state.handshakeToken,
      debugEnabled: !!CONFIG.debug,
      iframeSrc: iframe.src,
      expectedMessageType: CONFIG.expectedMessageType,
      handshakeInitType: CONFIG.handshakeInitType,
      handshakeAckType: CONFIG.handshakeAckType,
      allowedIframeOrigins: CONFIG.allowedIframeOrigins,
      redundantInstanceTransport: ["query:param:propeller_embed_instance", "postMessage:instanceId", "postMessage:parentSuppliedInstanceId", "postMessage:queryInstanceId"]
    });

    container.PropellerEmbedDebug = {
      retry: retryIframe,
      destroy: destroy,
      state: state,
      config: CONFIG,
      sendHandshakeInit: sendHandshakeInit
    };
  }

  function initAllEmbeds(root) {
    var scope = root || document;
    var embeds = scope.querySelectorAll(".propeller-embed[data-instance-id]");
    for (var i = 0; i < embeds.length; i += 1) {
      initEmbed(embeds[i]);
    }
  }

  window.PropellerEmbed = window.PropellerEmbed || {};
  window.PropellerEmbed.initAll = initAllEmbeds;
  window.PropellerEmbed.initOne = initEmbed;

  document.addEventListener("DOMContentLoaded", function () {
    initAllEmbeds(document);
  });
})();
