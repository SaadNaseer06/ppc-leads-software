/*!
 * PPC Leads capture script
 * <script src="https://YOUR-APP/ppc-leads.js" data-site="WEBSITE_KEY" async></script>
 *
 * Optional attributes:
 *   data-forms="form.lead-form"  only capture forms matching this selector (default: every form)
 *   data-endpoint="https://..."  override the app URL (default: wherever this script is served from)
 *   data-debug                   log captured submissions to the console
 *
 * Add data-ppc-ignore to any <form> that should never be captured.
 * For custom/AJAX flows call: window.PPCLeads.submit({ name, email, phone, message }, "Form name")
 */
(function () {
  "use strict";
  if (window.PPCLeads && window.PPCLeads.loaded) return;

  var script =
    document.currentScript ||
    (function () {
      var all = document.querySelectorAll("script[data-site]");
      return all[all.length - 1];
    })();
  if (!script) return;

  var siteKey = script.getAttribute("data-site");
  if (!siteKey) {
    console.warn("[PPC Leads] Missing data-site attribute on the script tag");
    return;
  }

  var base = script.getAttribute("data-endpoint") || new URL(script.src, location.href).origin;
  var endpoint = base.replace(/\/$/, "") + "/api/intake/" + encodeURIComponent(siteKey);
  var formSelector = script.getAttribute("data-forms") || "form";
  var debug = script.hasAttribute("data-debug");

  var STORAGE_KEY = "ppcl_attribution";
  var TTL_MS = 90 * 24 * 60 * 60 * 1000;
  var PARAMS = [
    "utm_source", "utm_medium", "utm_campaign", "utm_term", "utm_content",
    "gclid", "gbraid", "wbraid", "msclkid", "dclid", "fbclid", "ttclid", "li_fat_id",
  ];
  var SKIP_FIELD = /pass|card|cvv|cvc|ccnum|iban|ssn|captcha|csrf|nonce|^_?token$/i;

  function log() {
    if (debug && window.console) console.log.apply(console, ["[PPC Leads]"].concat([].slice.call(arguments)));
  }

  function load() {
    try {
      var value = JSON.parse(localStorage.getItem(STORAGE_KEY) || "null");
      if (value && value.ts && Date.now() - value.ts < TTL_MS) return value;
    } catch (e) {}
    return null;
  }

  function save(value) {
    try {
      localStorage.setItem(STORAGE_KEY, JSON.stringify(value));
    } catch (e) {}
  }

  // A visit carrying campaign parameters replaces older attribution (last paid
  // click wins); otherwise the first landing page and referrer are kept.
  var query = new URLSearchParams(location.search);
  var campaign = {};
  var hasCampaign = false;
  PARAMS.forEach(function (p) {
    var v = query.get(p);
    if (v) {
      campaign[p] = v;
      hasCampaign = true;
    }
  });

  var referrer = document.referrer && document.referrer.indexOf(location.origin) !== 0 ? document.referrer : "";
  var stored = load();
  if (!stored || hasCampaign) {
    stored = {
      ts: Date.now(),
      params: campaign,
      landing_page: location.href,
      referrer: referrer || (stored && stored.referrer) || "",
    };
    save(stored);
  }

  function attribution() {
    var a = {
      landing_page: stored.landing_page,
      referrer: stored.referrer,
      first_seen: new Date(stored.ts).toISOString(),
    };
    for (var key in stored.params) a[key] = stored.params[key];
    return a;
  }

  function serialize(form) {
    var data = {};
    var elements = form.elements;
    for (var i = 0; i < elements.length; i++) {
      var el = elements[i];
      var name = el.name || el.id;
      var type = (el.type || "").toLowerCase();
      if (!name || el.disabled) continue;
      if (/^(password|file|submit|button|reset|image)$/.test(type)) continue;
      if ((type === "checkbox" || type === "radio") && !el.checked) continue;
      if (SKIP_FIELD.test(name) || /^cc-/.test(el.autocomplete || "")) continue;

      var value = el.value;
      if (el.tagName === "SELECT" && el.multiple) {
        value = [].filter
          .call(el.options, function (o) { return o.selected; })
          .map(function (o) { return o.value; })
          .join(", ");
      }
      if (value === "" || value === undefined) continue;
      data[name] = data[name] ? data[name] + ", " + value : value;
    }
    return data;
  }

  function isLeadForm(form) {
    if (form.hasAttribute("data-ppc-ignore")) return false;
    if (form.closest && form.closest("#ppc-chat-root")) return false; // our own chat widget
    if (form.matches && !form.matches(formSelector)) return false;
    if (form.querySelector('input[type="password"]')) return false; // login / sign-up
    if (form.getAttribute("role") === "search") return false;
    var named = form.querySelectorAll("input[name], textarea[name], select[name]");
    if (named.length === 1 && /^(s|q|search|query)$/i.test(named[0].name)) return false;
    return true;
  }

  var lastSent = { body: "", at: 0 };

  function send(payload) {
    var body = JSON.stringify(payload);
    var fingerprint = JSON.stringify(payload.fields);
    if (fingerprint === lastSent.body && Date.now() - lastSent.at < 5000) return; // double click
    lastSent = { body: fingerprint, at: Date.now() };
    log("sending", payload);

    // text/plain keeps this a "simple" CORS request: no preflight, survives page unload
    try {
      if (navigator.sendBeacon && navigator.sendBeacon(endpoint, new Blob([body], { type: "text/plain;charset=UTF-8" }))) {
        return;
      }
    } catch (e) {}
    try {
      fetch(endpoint, {
        method: "POST",
        body: body,
        keepalive: true,
        headers: { "Content-Type": "text/plain;charset=UTF-8" },
      });
    } catch (e) {}
  }

  function capture(fields, formName) {
    send({
      fields: fields,
      attribution: attribution(),
      page: { url: location.href, title: document.title },
      form: { name: formName || "" },
    });
  }

  // Capture phase: runs before the site's own handlers, so AJAX forms that
  // call preventDefault() (Contact Form 7, Elementor, ...) are still seen.
  document.addEventListener(
    "submit",
    function (event) {
      var form = event.target;
      if (!form || form.tagName !== "FORM" || !isLeadForm(form)) return;
      var fields = serialize(form);
      if (Object.keys(fields).length === 0) return;
      var formName =
        form.getAttribute("data-form-name") ||
        form.getAttribute("name") ||
        form.getAttribute("aria-label") ||
        form.id ||
        "";
      capture(fields, formName);
    },
    true
  );

  window.PPCLeads = {
    loaded: true,
    submit: function (fields, formName) {
      capture(fields || {}, formName);
    },
    attribution: attribution,
  };
  log("ready", { endpoint: endpoint, attribution: attribution() });
})();
