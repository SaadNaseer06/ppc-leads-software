/*!
 * PPC Leads live chat widget
 * <script src="https://YOUR-APP/ppc-chat.js" data-site="WEBSITE_KEY" async></script>
 *
 * Optional attributes:
 *   data-endpoint="https://..."  override the app URL (default: script origin)
 *   data-title="Chat with us"    panel title
 *   data-color="#0f766e"         accent colour
 *   data-greeting="Hi! …"        first system line when the panel opens
 */
(function () {
  "use strict";
  if (window.PPCChat && window.PPCChat.loaded) return;

  var script =
    document.currentScript ||
    (function () {
      var all = document.querySelectorAll("script[data-site][src*='ppc-chat']");
      return all[all.length - 1];
    })();
  if (!script) return;

  var siteKey = script.getAttribute("data-site");
  if (!siteKey) {
    console.warn("[PPC Chat] Missing data-site attribute on the script tag");
    return;
  }

  var base = (script.getAttribute("data-endpoint") || new URL(script.src, location.href).origin).replace(/\/$/, "");
  var title = script.getAttribute("data-title") || "Chat with us";
  var accent = script.getAttribute("data-color") || "#0f766e";
  var greeting = script.getAttribute("data-greeting") || "Hi! Send a message and our team will reply shortly.";
  var STORAGE_KEY = "ppc_chat_token_" + siteKey;

  var state = {
    open: false,
    visitorToken: null,
    conversationId: null,
    status: "open",
    messages: [],
    pusher: null,
    channel: null,
    pollTimer: null,
    sending: false,
    started: false,
  };

  function api(path, opts) {
    opts = opts || {};
    return fetch(base + path, {
      method: opts.method || "GET",
      headers: { Accept: "application/json", "Content-Type": "application/json" },
      body: opts.body ? JSON.stringify(opts.body) : undefined,
      credentials: "omit",
    }).then(function (res) {
      return res.json().then(function (data) {
        if (!res.ok) throw new Error((data && data.error) || "Request failed");
        return data;
      });
    });
  }

  function loadToken() {
    try {
      return localStorage.getItem(STORAGE_KEY) || null;
    } catch (e) {
      return null;
    }
  }

  function saveToken(token) {
    try {
      if (token) localStorage.setItem(STORAGE_KEY, token);
    } catch (e) {}
  }

  function css() {
    if (document.getElementById("ppc-chat-style")) return;
    var style = document.createElement("style");
    style.id = "ppc-chat-style";
    style.textContent =
      "#ppc-chat-root{all:initial;font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;position:fixed;z-index:2147483000;right:20px;bottom:20px;color:#0f172a}" +
      "#ppc-chat-root *{box-sizing:border-box}" +
      "#ppc-chat-bubble{width:56px;height:56px;border-radius:50%;border:0;cursor:pointer;background:" +
      accent +
      ";color:#fff;box-shadow:0 8px 24px rgba(15,23,42,.25);display:flex;align-items:center;justify-content:center}" +
      "#ppc-chat-bubble svg{width:26px;height:26px;fill:currentColor}" +
      "#ppc-chat-panel{position:absolute;right:0;bottom:72px;width:min(360px,calc(100vw - 32px));height:min(520px,calc(100vh - 100px));background:#fff;border-radius:16px;box-shadow:0 18px 50px rgba(15,23,42,.28);display:none;flex-direction:column;overflow:hidden;border:1px solid #e2e8f0}" +
      "#ppc-chat-panel.open{display:flex}" +
      "#ppc-chat-header{background:" +
      accent +
      ";color:#fff;padding:14px 16px;display:flex;align-items:center;justify-content:space-between;gap:8px}" +
      "#ppc-chat-header strong{font-size:15px}" +
      "#ppc-chat-header button{background:transparent;border:0;color:#fff;cursor:pointer;font-size:20px;line-height:1;opacity:.85}" +
      "#ppc-chat-msgs{flex:1;overflow-y:auto;padding:14px;background:#f8fafc;display:flex;flex-direction:column;gap:10px}" +
      ".ppc-msg{max-width:85%;padding:8px 12px;border-radius:14px;font-size:14px;line-height:1.4;white-space:pre-wrap;word-break:break-word}" +
      ".ppc-msg.visitor{align-self:flex-end;background:" +
      accent +
      ";color:#fff;border-bottom-right-radius:4px}" +
      ".ppc-msg.agent{align-self:flex-start;background:#fff;border:1px solid #e2e8f0;color:#0f172a;border-bottom-left-radius:4px}" +
      ".ppc-msg.system{align-self:center;background:transparent;color:#94a3b8;font-size:12px;padding:0}" +
      ".ppc-meta{font-size:11px;color:#94a3b8;margin-top:2px}" +
      "#ppc-chat-form{display:flex;gap:8px;padding:10px;border-top:1px solid #e2e8f0;background:#fff}" +
      "#ppc-chat-form input,#ppc-chat-prechat input{flex:1;border:1px solid #e2e8f0;border-radius:10px;padding:10px 12px;font-size:14px;outline:none}" +
      "#ppc-chat-form input:focus,#ppc-chat-prechat input:focus{border-color:" +
      accent +
      "}" +
      "#ppc-chat-form button,#ppc-chat-prechat button{border:0;border-radius:10px;background:" +
      accent +
      ";color:#fff;padding:0 14px;font-size:14px;font-weight:600;cursor:pointer}" +
      "#ppc-chat-form button:disabled{opacity:.6;cursor:default}" +
      "#ppc-chat-prechat{padding:14px;border-top:1px solid #e2e8f0;background:#fff;display:grid;gap:8px}" +
      "#ppc-chat-prechat p{margin:0 0 4px;font-size:13px;color:#64748b}" +
      "#ppc-chat-ended{padding:12px;text-align:center;font-size:13px;color:#64748b;border-top:1px solid #e2e8f0;background:#fff}";
    document.head.appendChild(style);
  }

  function el(tag, attrs, children) {
    var node = document.createElement(tag);
    if (attrs) {
      Object.keys(attrs).forEach(function (k) {
        if (k === "text") node.textContent = attrs[k];
        else if (k === "html") node.innerHTML = attrs[k];
        else if (k === "onClick") node.addEventListener("click", attrs[k]);
        else if (k === "onSubmit") node.addEventListener("submit", attrs[k]);
        else node.setAttribute(k, attrs[k]);
      });
    }
    (children || []).forEach(function (c) {
      if (c) node.appendChild(c);
    });
    return node;
  }

  function renderMessages() {
    var box = document.getElementById("ppc-chat-msgs");
    if (!box) return;
    box.innerHTML = "";
    if (!state.messages.length) {
      box.appendChild(el("div", { class: "ppc-msg system", text: greeting }));
    }
    state.messages.forEach(function (m) {
      var row = el("div", { class: "ppc-msg " + (m.sender_type || "system"), text: m.body });
      box.appendChild(row);
    });
    box.scrollTop = box.scrollHeight;
  }

  function setComposerVisible() {
    var form = document.getElementById("ppc-chat-form");
    var ended = document.getElementById("ppc-chat-ended");
    var pre = document.getElementById("ppc-chat-prechat");
    var open = state.status === "open";
    if (form) form.style.display = open ? "flex" : "none";
    if (ended) ended.style.display = open ? "none" : "block";
    if (pre) pre.style.display = "none";
  }

  function upsertMessage(m) {
    if (!m || !m.id) return;
    for (var i = 0; i < state.messages.length; i++) {
      if (state.messages[i].id === m.id) return;
    }
    state.messages.push(m);
    state.messages.sort(function (a, b) {
      return a.id - b.id;
    });
    renderMessages();
  }

  function startPolling() {
    if (state.pollTimer) return;
    state.pollTimer = setInterval(function () {
      if (!state.visitorToken) return;
      var since = state.messages.length ? state.messages[state.messages.length - 1].id : 0;
      api("/api/chat/messages?visitor_token=" + encodeURIComponent(state.visitorToken) + "&since_id=" + since)
        .then(function (data) {
          state.status = data.status || state.status;
          (data.messages || []).forEach(upsertMessage);
          setComposerVisible();
        })
        .catch(function () {});
    }, 3000);
  }

  function stopPolling() {
    if (state.pollTimer) {
      clearInterval(state.pollTimer);
      state.pollTimer = null;
    }
  }

  function loadPusher(cb) {
    if (window.Pusher) return cb();
    var s = document.createElement("script");
    s.src = "https://js.pusher.com/8.4.0/pusher.min.js";
    s.async = true;
    s.onload = function () {
      cb();
    };
    s.onerror = function () {
      cb(new Error("pusher load failed"));
    };
    document.head.appendChild(s);
  }

  function subscribeRealtime(cfg) {
    if (!cfg || !cfg.key) {
      startPolling();
      return;
    }
    loadPusher(function (err) {
      if (err || !window.Pusher) {
        startPolling();
        return;
      }
      try {
        var client = new window.Pusher(cfg.key, {
          cluster: cfg.cluster || "mt1",
          authEndpoint: cfg.auth_endpoint || base + "/api/chat/auth",
          auth: {
            params: { visitor_token: state.visitorToken },
          },
        });
        state.pusher = client;
        var channel = client.subscribe("private-chat." + state.conversationId);
        state.channel = channel;
        channel.bind("ChatMessageSent", function (payload) {
          if (payload && payload.message) upsertMessage(payload.message);
        });
        channel.bind("pusher:subscription_error", function () {
          startPolling();
        });
        // Soft fallback even when Pusher works (missed events)
        startPolling();
      } catch (e) {
        startPolling();
      }
    });
  }

  function ensureSession() {
    if (state.started) return Promise.resolve();
    state.started = true;
    return api("/api/chat/session", {
      method: "POST",
      body: {
        public_key: siteKey,
        visitor_token: loadToken(),
        page_url: location.href,
      },
    }).then(function (data) {
      state.visitorToken = data.visitor_token;
      state.conversationId = data.conversation_id;
      state.status = data.status || "open";
      state.messages = data.messages || [];
      saveToken(data.visitor_token);
      renderMessages();
      setComposerVisible();
      subscribeRealtime(data.pusher);
      return data;
    });
  }

  function sendMessage(body) {
    if (!body || state.sending || state.status !== "open") return;
    state.sending = true;
    var btn = document.querySelector("#ppc-chat-form button");
    if (btn) btn.disabled = true;
    ensureSession()
      .then(function () {
        return api("/api/chat/messages", {
          method: "POST",
          body: { visitor_token: state.visitorToken, body: body },
        });
      })
      .then(function (data) {
        upsertMessage(data.message);
      })
      .catch(function (e) {
        alert(e.message || "Could not send message");
      })
      .finally(function () {
        state.sending = false;
        if (btn) btn.disabled = false;
      });
  }

  function buildUI() {
    css();
    var root = el("div", { id: "ppc-chat-root" });
    var panel = el("div", { id: "ppc-chat-panel" }, [
      el("div", { id: "ppc-chat-header" }, [
        el("strong", { text: title }),
        el("button", {
          type: "button",
          "aria-label": "Close",
          text: "×",
          onClick: function () {
            state.open = false;
            panel.classList.remove("open");
          },
        }),
      ]),
      el("div", { id: "ppc-chat-msgs" }),
      el("form", {
        id: "ppc-chat-prechat",
        style: "display:none",
        onSubmit: function (e) {
          e.preventDefault();
          var name = document.getElementById("ppc-chat-name").value.trim();
          var email = document.getElementById("ppc-chat-email").value.trim();
          ensureSession().then(function () {
            return api("/api/chat/visitor", {
              method: "POST",
              body: { visitor_token: state.visitorToken, name: name || null, email: email || null },
            });
          }).then(function () {
            document.getElementById("ppc-chat-prechat").style.display = "none";
          });
        },
      }, [
        el("p", { text: "Optional — leave your name so we can follow up:" }),
        el("input", { id: "ppc-chat-name", type: "text", placeholder: "Name", autocomplete: "name" }),
        el("input", { id: "ppc-chat-email", type: "email", placeholder: "Email", autocomplete: "email" }),
        el("button", { type: "submit", text: "Save" }),
      ]),
      el("form", {
        id: "ppc-chat-form",
        onSubmit: function (e) {
          e.preventDefault();
          var input = document.getElementById("ppc-chat-input");
          var body = (input.value || "").trim();
          if (!body) return;
          input.value = "";
          sendMessage(body);
        },
      }, [
        el("input", {
          id: "ppc-chat-input",
          type: "text",
          placeholder: "Type a message…",
          autocomplete: "off",
          maxlength: "4000",
        }),
        el("button", { type: "submit", text: "Send" }),
      ]),
      el("div", { id: "ppc-chat-ended", style: "display:none", text: "This chat has ended." }),
    ]);

    var bubble = el("button", {
      id: "ppc-chat-bubble",
      type: "button",
      "aria-label": "Open live chat",
      html:
        '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm0 14H5.2L4 17.2V4h16v12z"/></svg>',
      onClick: function () {
        state.open = !state.open;
        if (state.open) {
          panel.classList.add("open");
          ensureSession().catch(function (e) {
            alert(e.message || "Chat is unavailable");
          });
          // Offer optional contact once panel opens
          setTimeout(function () {
            var pre = document.getElementById("ppc-chat-prechat");
            if (pre && state.messages.length === 0) pre.style.display = "grid";
          }, 400);
        } else {
          panel.classList.remove("open");
        }
      },
    });

    root.appendChild(panel);
    root.appendChild(bubble);
    document.body.appendChild(root);
    renderMessages();
  }

  function boot() {
    if (!document.body) {
      document.addEventListener("DOMContentLoaded", boot);
      return;
    }
    buildUI();
  }

  boot();

  window.PPCChat = {
    loaded: true,
    open: function () {
      var bubble = document.getElementById("ppc-chat-bubble");
      if (bubble && !state.open) bubble.click();
    },
    close: function () {
      var panel = document.getElementById("ppc-chat-panel");
      state.open = false;
      if (panel) panel.classList.remove("open");
    },
  };
})();
