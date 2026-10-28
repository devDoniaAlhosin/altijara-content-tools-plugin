/**
 * Content Tools hub — tabs + bulk ID meter / validation.
 */
(function () {
  var root = document.getElementById("altijara-ct-dashboard");
  if (!root) return;

  var baseUrl = root.getAttribute("data-base-url") || "";
  var navItems = root.querySelectorAll(".altijara-nav-item[data-tab]");
  var panels = root.querySelectorAll(".altijara-panel[data-tab]");
  var i18n = window.AltijaraCTAdmin || {};

  function activate(tabId, push) {
    navItems.forEach(function (btn) {
      var on = btn.getAttribute("data-tab") === tabId;
      btn.classList.toggle("is-active", on);
      btn.setAttribute("aria-selected", on ? "true" : "false");
    });
    panels.forEach(function (panel) {
      var on = panel.getAttribute("data-tab") === tabId;
      panel.classList.toggle("is-active", on);
      if (on) {
        panel.removeAttribute("hidden");
      } else {
        panel.setAttribute("hidden", "hidden");
      }
    });
    root.setAttribute("data-active-tab", tabId);
    if (push && baseUrl && window.history && window.history.replaceState) {
      window.history.replaceState(null, "", baseUrl + "&tab=" + encodeURIComponent(tabId));
    }
  }

  navItems.forEach(function (btn) {
    btn.addEventListener("click", function () {
      activate(btn.getAttribute("data-tab") || "overview", true);
    });
  });

  function parseIds(raw) {
    var parts = String(raw || "").split(/[\s,;|]+/);
    var seen = {};
    var ids = [];
    for (var i = 0; i < parts.length; i++) {
      var digits = String(parts[i]).replace(/[^0-9]/g, "");
      if (!digits) continue;
      var id = parseInt(digits, 10);
      if (!id || seen[id]) continue;
      seen[id] = true;
      ids.push(id);
    }
    return ids;
  }

  var form = document.getElementById("altijara-ct-bulk-form");
  if (!form) return;

  var textarea = document.getElementById("altijara_ct_bulk_ids");
  var meter = document.getElementById("altijara-ct-bulk-meter");
  var countEl = meter ? meter.querySelector("[data-count]") : null;
  var maxIds = parseInt(form.getAttribute("data-max-ids") || "100", 10);
  var maxChars = parseInt(form.getAttribute("data-max-chars") || "4000", 10);

  function refreshMeter() {
    if (!textarea || !countEl) return;
    var ids = parseIds(textarea.value);
    countEl.textContent = String(ids.length);
    if (meter) {
      meter.classList.toggle("is-over", ids.length > maxIds);
      meter.classList.toggle("is-ready", ids.length > 0 && ids.length <= maxIds);
    }
  }

  textarea.addEventListener("input", refreshMeter);
  refreshMeter();

  form.addEventListener("submit", function (e) {
    var raw = textarea.value || "";
    if (raw.length > maxChars) {
      e.preventDefault();
      window.alert(i18n.bulkEmpty || "Input too long.");
      return;
    }
    var ids = parseIds(raw);
    if (!ids.length) {
      e.preventDefault();
      window.alert(i18n.bulkEmpty || "Enter at least one valid post ID.");
      return;
    }
    if (ids.length > maxIds) {
      if (!window.confirm(i18n.bulkTooMany || "Too many IDs. Continue with the first batch?")) {
        e.preventDefault();
        return;
      }
    } else if (!window.confirm(i18n.bulkConfirm || "Assign language visibility?")) {
      e.preventDefault();
    }
  });
})();
