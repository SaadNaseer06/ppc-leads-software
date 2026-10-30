/*!
 * PPC Leads live chat widget
 * <script src="https://YOUR-APP/ppc-chat.js" data-site="WEBSITE_KEY" async></script>
 *
 * Optional attributes:
 *   data-endpoint="https://..."  override the app URL (default: script origin)
 *   data-title="Chat with us"    panel title
 *   data-color="#0f766e"         accent colour
 *   data-greeting="Hi! …"        first system line when the panel opens
 *   data-agent="Support"         fallback agent label when name is missing
 *   data-prompts="A|B|C"         quick-reply chips (pipe-separated); empty string disables
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
  var title = script.getAttribute("data-title") || "Live support";
  var accent = script.getAttribute("data-color") || "#0f766e";
  var greeting = script.getAttribute("data-greeting") || "Welcome — send a message and a specialist will reply shortly.";
  var agentFallback = script.getAttribute("data-agent") || "Support specialist";
  var promptsAttr = script.getAttribute("data-prompts");
  var quickPrompts =
    promptsAttr === ""
      ? []
      : (promptsAttr || "I'd like a quote|I have a question|Can someone help me?|Tell me about your services")
          .split("|")
          .map(function (s) {
            return s.trim();
          })
          .filter(Boolean)
          .slice(0, 6);
  var STORAGE_KEY = "ppc_chat_token_" + siteKey;
  // Poll fast only when realtime (Pusher) isn't delivering; otherwise a slow safety net
  var POLL_FAST_MS = 4000;
  var POLL_SLOW_MS = 30000;
  var CONTACT_SKIP_KEY = "ppc_chat_contact_skip_" + siteKey;

  var state = {
    open: false,
    visitorToken: null,
    conversationId: null,
    status: "open",
    messages: [],
    pusher: null,
    channel: null,
    pollTimer: null,
    lastPoll: 0,
    realtime: false,
    sending: false,
    session: null,
    unread: 0,
    agentName: null,
    agentOnline: false,
    visitorName: null,
    visitorEmail: null,
    contactDone: false,
  };

  function api(path, opts) {
    opts = opts || {};
    // A GET without Content-Type is a "simple" cross-origin request: no CORS preflight per poll
    var headers = { Accept: "application/json" };
    if (opts.body) headers["Content-Type"] = "application/json";
    return fetch(base + path, {
      method: opts.method || "GET",
      headers: headers,
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

  function hexToRgb(hex) {
    var h = (hex || "").replace("#", "");
    if (h.length === 3) h = h[0] + h[0] + h[1] + h[1] + h[2] + h[2];
    var n = parseInt(h, 16);
    if (isNaN(n)) return { r: 15, g: 118, b: 110 };
    return { r: (n >> 16) & 255, g: (n >> 8) & 255, b: n & 255 };
  }

  function css() {
    if (document.getElementById("ppc-chat-style")) return;
    var rgb = hexToRgb(accent);
    var style = document.createElement("style");
    style.id = "ppc-chat-style";
    style.textContent =
      "@keyframes ppc-fade-up{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:none}}" +
      "@keyframes ppc-pop{0%{transform:scale(.92)}60%{transform:scale(1.04)}100%{transform:scale(1)}}" +
      "@keyframes ppc-pulse{0%,100%{box-shadow:0 10px 28px rgba(15,23,42,.22),0 0 0 0 rgba(" +
      rgb.r +
      "," +
      rgb.g +
      "," +
      rgb.b +
      ",.45)}50%{box-shadow:0 10px 28px rgba(15,23,42,.22),0 0 0 10px rgba(" +
      rgb.r +
      "," +
      rgb.g +
      "," +
      rgb.b +
      ",0)}}" +
      "@keyframes ppc-badge-pop{0%{transform:scale(0)}70%{transform:scale(1.15)}100%{transform:scale(1)}}" +
      "@keyframes ppc-dot{0%,80%,100%{opacity:.25;transform:translateY(0)}40%{opacity:1;transform:translateY(-2px)}}" +
      "#ppc-chat-root{all:initial;font-family:ui-sans-serif,system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;position:fixed;z-index:2147483000;right:20px;bottom:20px;color:#0f172a}" +
      "#ppc-chat-root *{box-sizing:border-box}" +
      "#ppc-chat-bubble{position:relative;width:60px;height:60px;border-radius:50%;border:0;cursor:pointer;background:linear-gradient(145deg," +
      accent +
      ",#0b5f58);color:#fff;box-shadow:0 12px 32px rgba(15,23,42,.28);display:flex;align-items:center;justify-content:center;transition:transform .2s ease}" +
      "#ppc-chat-bubble:hover{transform:translateY(-2px)}" +
      "#ppc-chat-bubble.has-unread{animation:ppc-pulse 1.8s ease-in-out infinite}" +
      "#ppc-chat-bubble svg{width:26px;height:26px;fill:currentColor}" +
      "#ppc-chat-badge{position:absolute;top:-2px;right:-2px;min-width:20px;height:20px;padding:0 6px;border-radius:999px;background:#ef4444;color:#fff;font-size:11px;font-weight:700;line-height:20px;text-align:center;border:2px solid #fff;display:none;animation:ppc-badge-pop .35s ease}" +
      "#ppc-chat-badge.show{display:block}" +
      "#ppc-chat-panel{position:absolute;right:0;bottom:76px;width:min(380px,calc(100vw - 28px));height:min(560px,calc(100vh - 110px));background:#fff;border-radius:18px;box-shadow:0 24px 60px rgba(15,23,42,.28);display:none;flex-direction:column;overflow:hidden;border:1px solid #e2e8f0;animation:ppc-pop .28s ease}" +
      "#ppc-chat-panel.open{display:flex}" +
      "#ppc-chat-header{background:linear-gradient(135deg," +
      accent +
      " 0%,#0b5f58 100%);color:#fff;padding:14px 16px;display:flex;align-items:center;gap:12px}" +
      "#ppc-chat-header .ppc-agent-avatar{width:40px;height:40px;border-radius:50%;background:rgba(255,255,255,.2);display:flex;align-items:center;justify-content:center;font-size:14px;font-weight:700;flex-shrink:0;border:1px solid rgba(255,255,255,.25)}" +
      "#ppc-chat-header .ppc-head-text{min-width:0;flex:1}" +
      "#ppc-chat-header strong{display:block;font-size:15px;font-weight:650;letter-spacing:.01em}" +
      "#ppc-chat-header .ppc-status{display:flex;align-items:center;gap:6px;margin-top:3px;font-size:12px;opacity:.92}" +
      "#ppc-chat-header .ppc-status-dot{width:8px;height:8px;border-radius:50%;background:#86efac;box-shadow:0 0 0 3px rgba(134,239,172,.25)}" +
      "#ppc-chat-header .ppc-status-dot.away{background:#fcd34d;box-shadow:0 0 0 3px rgba(252,211,77,.25)}" +
      "#ppc-chat-header button{background:rgba(255,255,255,.12);border:0;color:#fff;cursor:pointer;width:32px;height:32px;border-radius:8px;font-size:18px;line-height:1;flex-shrink:0}" +
      "#ppc-chat-header button:hover{background:rgba(255,255,255,.2)}" +
      "#ppc-chat-msgs{flex:1;overflow-x:hidden;overflow-y:auto;padding:16px 14px;background:linear-gradient(180deg,#f8fafc 0%,#f1f5f9 100%);display:flex;flex-direction:column;gap:12px}" +
      ".ppc-row{display:flex;flex-direction:column;gap:4px;max-width:min(88%,320px);min-width:0;animation:ppc-fade-up .28s ease}" +
      ".ppc-row.visitor{align-self:flex-end;align-items:flex-end}" +
      ".ppc-row.agent{align-self:flex-start;align-items:flex-start}" +
      ".ppc-row.system{align-self:center;max-width:95%}" +
      ".ppc-row.agent-new .ppc-bubble{box-shadow:0 0 0 2px rgba(" +
      rgb.r +
      "," +
      rgb.g +
      "," +
      rgb.b +
      ",.28)}" +
      ".ppc-label{font-size:11px;font-weight:600;color:#64748b;padding:0 4px}" +
      ".ppc-bubble{padding:10px 13px;border-radius:16px;font-size:14px;line-height:1.45;white-space:pre-wrap;overflow-wrap:anywhere;word-break:break-word;max-width:100%;overflow:hidden}" +
      ".ppc-row.visitor .ppc-bubble{background:" +
      accent +
      ";color:#fff;border-bottom-right-radius:5px}" +
      ".ppc-row.agent .ppc-bubble{background:#fff;border:1px solid #e2e8f0;color:#0f172a;border-bottom-left-radius:5px;box-shadow:0 1px 2px rgba(15,23,42,.04)}" +
      ".ppc-row.system .ppc-bubble{background:transparent;border:0;color:#64748b;font-size:12.5px;text-align:center;padding:4px 8px}" +
      ".ppc-prompts{display:flex;flex-wrap:wrap;gap:8px;justify-content:center;padding:4px 2px 2px;animation:ppc-fade-up .35s ease}" +
      ".ppc-prompt{border:1px solid #e2e8f0;background:#fff;color:#334155;border-radius:999px;padding:8px 14px;font-size:12.5px;font-weight:550;line-height:1.3;cursor:pointer;box-shadow:0 1px 2px rgba(15,23,42,.04);transition:border-color .15s,color .15s,background .15s}" +
      ".ppc-prompt:hover{border-color:" +
      accent +
      ";color:" +
      accent +
      ";background:#fff}" +
      ".ppc-prompt:disabled{opacity:.55;cursor:default}" +
      ".ppc-time{font-size:10.5px;color:#94a3b8;padding:0 4px}" +
      "#ppc-chat-typing{display:none;align-items:center;gap:8px;padding:0 14px 10px;background:transparent;font-size:12px;color:#64748b}" +
      "#ppc-chat-typing.show{display:flex}" +
      "#ppc-chat-typing .ppc-dots{display:inline-flex;gap:3px;padding:8px 10px;background:#fff;border:1px solid #e2e8f0;border-radius:14px;border-bottom-left-radius:5px}" +
      "#ppc-chat-typing .ppc-dots i{width:5px;height:5px;border-radius:50%;background:#94a3b8;display:block;animation:ppc-dot 1.1s infinite}" +
      "#ppc-chat-typing .ppc-dots i:nth-child(2){animation-delay:.15s}" +
      "#ppc-chat-typing .ppc-dots i:nth-child(3){animation-delay:.3s}" +
      "#ppc-chat-form{display:flex;gap:8px;padding:12px;border-top:1px solid #e2e8f0;background:#fff}" +
      "#ppc-chat-form input,#ppc-chat-prechat input{flex:1;border:1px solid #e2e8f0;border-radius:12px;padding:11px 12px;font-size:14px;outline:none;background:#f8fafc}" +
      "#ppc-chat-form input:focus,#ppc-chat-prechat input:focus{border-color:" +
      accent +
      ";background:#fff;box-shadow:0 0 0 3px rgba(" +
      rgb.r +
      "," +
      rgb.g +
      "," +
      rgb.b +
      ",.15)}" +
      "#ppc-chat-form button,#ppc-chat-prechat button{border:0;border-radius:12px;background:" +
      accent +
      ";color:#fff;padding:0 16px;font-size:14px;font-weight:650;cursor:pointer}" +
      "#ppc-chat-form button:disabled{opacity:.55;cursor:default}" +
      "#ppc-chat-prechat{display:none;flex-direction:column;gap:10px;padding:18px 16px;border-top:1px solid #e2e8f0;background:#fff}" +
      "#ppc-chat-prechat.show{display:flex}" +
      "#ppc-chat-prechat h3{margin:0;font-size:15px;font-weight:650;color:#0f172a}" +
      "#ppc-chat-prechat p{margin:0;font-size:13px;line-height:1.45;color:#64748b}" +
      "#ppc-chat-prechat .ppc-prechat-actions{display:flex;gap:8px;margin-top:4px}" +
      "#ppc-chat-prechat .ppc-prechat-actions button{flex:1;height:42px}" +
      "#ppc-chat-prechat .ppc-skip{background:#fff;color:#475569;border:1px solid #e2e8f0}" +
      "#ppc-chat-error{display:none;margin:0 12px 10px;padding:9px 12px;border-radius:10px;background:#fef2f2;border:1px solid #fecaca;color:#b91c1c;font-size:12.5px;line-height:1.4}" +
      "#ppc-chat-error.show{display:block}" +
      "#ppc-chat-ended p{margin:0 0 10px}" +
      "#ppc-chat-ended button{border:0;border-radius:10px;background:" +
      accent +
      ";color:#fff;padding:9px 16px;font-size:13px;font-weight:650;cursor:pointer}" +
      "#ppc-chat-ended{padding:14px;text-align:center;font-size:13px;color:#64748b;border-top:1px solid #e2e8f0;background:#fff}" +
      "#ppc-chat-toast{position:absolute;right:0;bottom:76px;width:min(300px,calc(100vw - 28px));background:#fff;border:1px solid #e2e8f0;border-radius:14px;box-shadow:0 16px 40px rgba(15,23,42,.22);padding:12px 14px;display:none;gap:10px;align-items:flex-start;cursor:pointer;animation:ppc-fade-up .3s ease}" +
      "#ppc-chat-toast.show{display:flex}" +
      "#ppc-chat-toast strong{display:block;font-size:13px;color:#0f172a}" +
      "#ppc-chat-toast span{display:block;font-size:12.5px;color:#64748b;margin-top:2px;line-height:1.35;max-height:2.7em;overflow:hidden}";
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

  function initials(name) {
    var parts = String(name || agentFallback)
      .trim()
      .split(/\s+/)
      .filter(Boolean);
    if (!parts.length) return "S";
    return ((parts[0][0] || "") + (parts[1] ? parts[1][0] : "")).toUpperCase();
  }

  function formatTime(iso) {
    if (!iso) return "";
    try {
      var d = new Date(iso);
      return d.toLocaleTimeString([], { hour: "numeric", minute: "2-digit" });
    } catch (e) {
      return "";
    }
  }

  function lastAgent() {
    for (var i = state.messages.length - 1; i >= 0; i--) {
      if (state.messages[i].sender_type === "agent") return state.messages[i];
    }
    return null;
  }

  function updateHeader() {
    var avatar = document.getElementById("ppc-chat-avatar");
    var status = document.getElementById("ppc-chat-status");
    var statusDot = document.getElementById("ppc-chat-status-dot");
    var agent = lastAgent();
    if (agent && agent.sender_name) state.agentName = agent.sender_name;
    if (agent) state.agentOnline = true;

    var name = state.agentName || title;
    if (avatar) avatar.textContent = initials(state.agentName || title);

    if (status && statusDot) {
      if (state.status !== "open") {
        status.textContent = "Chat ended";
        statusDot.className = "ppc-status-dot away";
      } else if (state.agentOnline) {
        status.textContent = (state.agentName || agentFallback) + " · Online";
        statusDot.className = "ppc-status-dot";
      } else {
        status.textContent = "We typically reply in a few minutes";
        statusDot.className = "ppc-status-dot away";
      }
    }
  }

  function updateBadge() {
    var badge = document.getElementById("ppc-chat-badge");
    var bubble = document.getElementById("ppc-chat-bubble");
    if (!badge || !bubble) return;
    if (state.unread > 0 && !state.open) {
      badge.textContent = state.unread > 9 ? "9+" : String(state.unread);
      badge.classList.add("show");
      bubble.classList.add("has-unread");
    } else {
      badge.classList.remove("show");
      bubble.classList.remove("has-unread");
    }
  }

  function showAgentToast(message) {
    if (state.open) return;
    var toast = document.getElementById("ppc-chat-toast");
    if (!toast) return;
    var who = message.sender_name || state.agentName || agentFallback;
    toast.querySelector("strong").textContent = who;
    toast.querySelector("span").textContent = message.body;
    toast.classList.add("show");
    clearTimeout(showAgentToast._t);
    showAgentToast._t = setTimeout(function () {
      toast.classList.remove("show");
    }, 5500);
  }

  function flashTyping(brief) {
    var typing = document.getElementById("ppc-chat-typing");
    if (!typing) return;
    typing.classList.add("show");
    clearTimeout(flashTyping._t);
    flashTyping._t = setTimeout(function () {
      typing.classList.remove("show");
    }, brief ? 600 : 1400);
  }

  function hasVisitorMessage() {
    return state.messages.some(function (m) {
      return m.sender_type === "visitor";
    });
  }

  function renderQuickPrompts(box) {
    if (!quickPrompts.length || state.status !== "open" || hasVisitorMessage()) return;
    var chips = quickPrompts.map(function (text) {
      return el("button", {
        type: "button",
        class: "ppc-prompt",
        text: text,
        onClick: function () {
          if (state.sending || state.status !== "open") return;
          markContactDone(true);
          sendMessage(text);
        },
      });
    });
    box.appendChild(el("div", { class: "ppc-prompts", role: "group", "aria-label": "Suggested messages" }, chips));
  }

  function renderMessages(opts) {
    opts = opts || {};
    var box = document.getElementById("ppc-chat-msgs");
    if (!box) return;
    var highlightId = opts.highlightId;
    box.innerHTML = "";
    if (!state.messages.length) {
      box.appendChild(
        el("div", { class: "ppc-row system" }, [el("div", { class: "ppc-bubble", text: greeting })])
      );
    }
    state.messages.forEach(function (m) {
      var type = m.sender_type || "system";
      if (type === "system") {
        box.appendChild(el("div", { class: "ppc-row system" }, [el("div", { class: "ppc-bubble", text: m.body })]));
        return;
      }
      var rowClass = "ppc-row " + type + (highlightId && m.id === highlightId ? " agent-new" : "");
      var kids = [];
      if (type === "agent") {
        kids.push(el("div", { class: "ppc-label", text: m.sender_name || state.agentName || agentFallback }));
      }
      kids.push(el("div", { class: "ppc-bubble", text: m.body }));
      var time = formatTime(m.sent_at);
      if (time) kids.push(el("div", { class: "ppc-time", text: time }));
      box.appendChild(el("div", { class: rowClass }, kids));
    });
    renderQuickPrompts(box);
    box.scrollTop = box.scrollHeight;
    updateHeader();
  }

  function contactSkipped() {
    try {
      return localStorage.getItem(CONTACT_SKIP_KEY) === "1";
    } catch (e) {
      return false;
    }
  }

  function markContactDone(skip) {
    state.contactDone = true;
    if (skip) {
      try {
        localStorage.setItem(CONTACT_SKIP_KEY, "1");
      } catch (e) {}
    }
    syncContactStep();
  }

  /** Show name/email only for a brand-new chat; hide once chatting or details exist. */
  function needsContactStep() {
    if (state.contactDone || contactSkipped()) return false;
    if (state.visitorName || state.visitorEmail) return false;
    if (state.messages.length > 0) return false;
    if (state.status !== "open") return false;
    return true;
  }

  function syncContactStep() {
    var pre = document.getElementById("ppc-chat-prechat");
    var form = document.getElementById("ppc-chat-form");
    var ended = document.getElementById("ppc-chat-ended");
    var showPre = needsContactStep();
    if (pre) {
      if (showPre) pre.classList.add("show");
      else pre.classList.remove("show");
    }
    var chatOpen = state.status === "open";
    if (form) form.style.display = chatOpen && !showPre ? "flex" : "none";
    if (ended) ended.style.display = chatOpen ? "none" : "block";
    updateHeader();
  }

  function setComposerVisible() {
    syncContactStep();
  }

  function upsertMessage(m, opts) {
    opts = opts || {};
    if (!m || !m.id) return;
    for (var i = 0; i < state.messages.length; i++) {
      if (state.messages[i].id === m.id) return;
    }
    state.messages.push(m);
    state.messages.sort(function (a, b) {
      return a.id - b.id;
    });

    var isAgent = m.sender_type === "agent";
    if (isAgent) {
      state.agentName = m.sender_name || state.agentName;
      state.agentOnline = true;
      if (!state.open) {
        state.unread += 1;
        updateBadge();
        showAgentToast(m);
      } else {
        flashTyping(true);
      }
    }

    renderMessages({ highlightId: isAgent ? m.id : null });
    setComposerVisible();
  }

  function pollOnce() {
    if (!state.visitorToken) return;
    state.lastPoll = Date.now();
    var since = state.messages.length ? state.messages[state.messages.length - 1].id : 0;
    api("/api/chat/messages?visitor_token=" + encodeURIComponent(state.visitorToken) + "&since_id=" + since)
      .then(function (data) {
        state.status = data.status || state.status;
        (data.messages || []).forEach(function (m) {
          upsertMessage(m);
        });
        setComposerVisible();
      })
      .catch(function () {});
  }

  function startPolling() {
    if (state.pollTimer) return;
    state.lastPoll = Date.now();
    state.pollTimer = setInterval(function () {
      if (state.status !== "open" || document.visibilityState === "hidden") return;
      var every = state.realtime ? POLL_SLOW_MS : POLL_FAST_MS;
      if (Date.now() - state.lastPoll >= every - 250) pollOnce();
    }, POLL_FAST_MS);
    document.addEventListener("visibilitychange", function () {
      if (document.visibilityState === "visible" && state.status === "open") pollOnce();
    });
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
          auth: { params: { visitor_token: state.visitorToken } },
        });
        state.pusher = client;
        var channel = client.subscribe("private-chat." + state.conversationId);
        state.channel = channel;
        channel.bind("ChatMessageSent", function (payload) {
          if (payload && payload.message) {
            if (payload.message.sender_type === "agent" && state.open) flashTyping(false);
            if (payload.status) state.status = payload.status;
            upsertMessage(payload.message);
          }
        });
        var subscribed = false;
        var syncRealtime = function () {
          state.realtime = subscribed && client.connection.state === "connected";
        };
        channel.bind("pusher:subscription_succeeded", function () {
          subscribed = true;
          syncRealtime();
        });
        channel.bind("pusher:subscription_error", function () {
          subscribed = false;
          syncRealtime();
        });
        client.connection.bind("state_change", syncRealtime);
        startPolling();
      } catch (e) {
        startPolling();
      }
    });
  }

  // Every caller waits for the same request, so a visitor typing fast never sends without a token
  function ensureSession() {
    if (state.session) return state.session;
    state.session = api("/api/chat/session", {
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
      state.visitorName = data.visitor_name || null;
      state.visitorEmail = data.visitor_email || null;
      if (state.visitorName || state.visitorEmail || state.messages.length > 0) {
        state.contactDone = true;
      }
      if (lastAgent()) {
        state.agentName = lastAgent().sender_name || state.agentName;
        state.agentOnline = true;
      }
      saveToken(data.visitor_token);
      renderMessages();
      setComposerVisible();
      subscribeRealtime(data.pusher);
      return data;
    }, function (e) {
      state.session = null; // let the next attempt retry
      throw e;
    });
    return state.session;
  }

  /** After a chat ends, the visitor can start over without reloading the page */
  function startNewChat() {
    var name = state.visitorName;
    var email = state.visitorEmail;
    try {
      localStorage.removeItem(STORAGE_KEY);
    } catch (e) {}
    if (state.pusher) {
      try {
        state.pusher.disconnect();
      } catch (e) {}
    }
    state.pusher = null;
    state.channel = null;
    state.realtime = false;
    state.session = null;
    state.visitorToken = null;
    state.conversationId = null;
    state.messages = [];
    state.status = "open";
    state.agentName = null;
    state.agentOnline = false;
    renderMessages();
    setComposerVisible();
    var session = ensureSession();
    if (name || email) {
      // Same visitor: carry their details over before the first message creates the lead,
      // and make every caller (sendMessage) wait for that too
      state.session = session.then(function (data) {
        return api("/api/chat/visitor", {
          method: "POST",
          body: { visitor_token: state.visitorToken, name: name, email: email },
        })
          .then(function () {
            state.visitorName = name;
            state.visitorEmail = email;
          })
          .catch(function () {})
          .then(function () {
            return data;
          });
      });
    }
    state.session
      .then(function () {
        var input = document.getElementById("ppc-chat-input");
        if (input) input.focus();
      })
      .catch(function (e) {
        showError(e.message || "Chat is unavailable right now. Please try again shortly.");
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
        showError(e.message || "Your message could not be sent. Please try again.");
        var input = document.getElementById("ppc-chat-input");
        if (input && !input.value) input.value = body;
      })
      .finally(function () {
        state.sending = false;
        if (btn) btn.disabled = false;
      });
  }

  function openPanel() {
    var panel = document.getElementById("ppc-chat-panel");
    var toast = document.getElementById("ppc-chat-toast");
    state.open = true;
    state.unread = 0;
    updateBadge();
    if (toast) toast.classList.remove("show");
    if (panel) panel.classList.add("open");
    ensureSession()
      .then(function () {
        syncContactStep();
        if (!needsContactStep()) {
          var input = document.getElementById("ppc-chat-input");
          if (input) setTimeout(function () {
            input.focus();
          }, 120);
        } else {
          var nameInput = document.getElementById("ppc-chat-name");
          if (nameInput) setTimeout(function () {
            nameInput.focus();
          }, 120);
        }
      })
      .catch(function (e) {
        showError(e.message || "Chat is unavailable right now. Please try again shortly.");
      });
  }

  function showError(message) {
    var box = document.getElementById("ppc-chat-error");
    if (!box) return;
    box.textContent = message;
    box.classList.add("show");
    clearTimeout(showError._t);
    showError._t = setTimeout(function () {
      box.classList.remove("show");
    }, 6000);
  }

  function closePanel() {
    var panel = document.getElementById("ppc-chat-panel");
    state.open = false;
    if (panel) panel.classList.remove("open");
  }

  function buildUI() {
    css();
    var root = el("div", { id: "ppc-chat-root" });

    var toast = el(
      "div",
      {
        id: "ppc-chat-toast",
        onClick: function () {
          openPanel();
        },
      },
      [
        el("div", { class: "ppc-agent-avatar", html: "", style: "display:none" }),
        el("div", { style: "min-width:0;flex:1" }, [el("strong", { text: agentFallback }), el("span", { text: "" })]),
      ]
    );

    var panel = el("div", { id: "ppc-chat-panel" }, [
      el("div", { id: "ppc-chat-header" }, [
        el("div", { id: "ppc-chat-avatar", class: "ppc-agent-avatar", text: initials(title) }),
        el("div", { class: "ppc-head-text" }, [
          el("strong", { text: title }),
          el("div", { class: "ppc-status" }, [
            el("span", { id: "ppc-chat-status-dot", class: "ppc-status-dot away" }),
            el("span", { id: "ppc-chat-status", text: "We typically reply in a few minutes" }),
          ]),
        ]),
        el("button", {
          type: "button",
          "aria-label": "Close",
          text: "×",
          onClick: closePanel,
        }),
      ]),
      el("div", { id: "ppc-chat-msgs" }),
      el("div", { id: "ppc-chat-error", role: "alert" }),
      el("div", { id: "ppc-chat-typing" }, [
        el("div", { class: "ppc-dots", html: "<i></i><i></i><i></i>" }),
        el("span", { text: "Specialist is responding…" }),
      ]),
      el(
        "form",
        {
          id: "ppc-chat-prechat",
          // Not a lead form for ppc-leads.js: the chat creates its own lead
          "data-ppc-ignore": "",
          onSubmit: function (e) {
            e.preventDefault();
            var name = document.getElementById("ppc-chat-name").value.trim();
            var email = document.getElementById("ppc-chat-email").value.trim();
            ensureSession()
              .then(function () {
                if (!name && !email) {
                  markContactDone(true);
                  return null;
                }
                return api("/api/chat/visitor", {
                  method: "POST",
                  body: { visitor_token: state.visitorToken, name: name || null, email: email || null },
                }).then(function () {
                  state.visitorName = name || state.visitorName;
                  state.visitorEmail = email || state.visitorEmail;
                  markContactDone(false);
                });
              })
              .then(function () {
                var input = document.getElementById("ppc-chat-input");
                if (input) input.focus();
              })
              .catch(function (err) {
                showError(err.message || "Could not save your details. Please try again.");
              });
          },
        },
        [
          el("h3", { text: "Before we begin" }),
          el("p", { text: "Share your name and email so our team can follow up. You can also skip and chat right away." }),
          el("input", { id: "ppc-chat-name", type: "text", placeholder: "Your name", autocomplete: "name" }),
          el("input", { id: "ppc-chat-email", type: "email", placeholder: "Email", autocomplete: "email" }),
          el("div", { class: "ppc-prechat-actions" }, [
            el("button", {
              type: "button",
              class: "ppc-skip",
              text: "Skip for now",
              onClick: function () {
                markContactDone(true);
                var input = document.getElementById("ppc-chat-input");
                if (input) input.focus();
              },
            }),
            el("button", { type: "submit", text: "Continue" }),
          ]),
        ]
      ),
      el(
        "form",
        {
          id: "ppc-chat-form",
          "data-ppc-ignore": "",
          onSubmit: function (e) {
            e.preventDefault();
            var input = document.getElementById("ppc-chat-input");
            var body = (input.value || "").trim();
            if (!body) return;
            input.value = "";
            markContactDone(true);
            sendMessage(body);
          },
        },
        [
          el("input", {
            id: "ppc-chat-input",
            type: "text",
            placeholder: "Write your message…",
            autocomplete: "off",
            maxlength: "4000",
          }),
          el("button", { type: "submit", text: "Send" }),
        ]
      ),
      el("div", { id: "ppc-chat-ended", style: "display:none" }, [
        el("p", { text: "This conversation has ended. Thank you for chatting with us." }),
        el("button", { type: "button", text: "Start a new chat", onClick: startNewChat }),
      ]),
    ]);

    var bubble = el("button", {
      id: "ppc-chat-bubble",
      type: "button",
      "aria-label": "Open live chat",
      html:
        '<span id="ppc-chat-badge"></span><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm0 14H5.2L4 17.2V4h16v12z"/></svg>',
      onClick: function () {
        if (state.open) closePanel();
        else openPanel();
      },
    });

    root.appendChild(toast);
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
    open: openPanel,
    close: closePanel,
  };
})();
