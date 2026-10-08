/*! custom-catalog 0.1.30 — 설정 */
(function () {
  "use strict";
  function boot() {
    var root = document.getElementById("cct_admin");
    if (!root || root.dataset.ready) return !!root;
    root.dataset.ready = "1";
    start(root);
    return true;
  }
  function start(root) {
    var token = "";
    try { token = localStorage.getItem("auth_token") || localStorage.getItem("token") || ""; } catch (e) {}
    function headers() {
      var h = { Accept: "application/json", "Content-Type": "application/json" };
      if (token) h.Authorization = "Bearer " + String(token).replace(/^"+|"+$/g, "");
      return h;
    }
    function esc(s) {
      return String(s == null ? "" : s).replace(/[&<>"]/g, function (c) {
        if (c === "&") return "&";
        if (c === "<") return "<";
        if (c === ">") return ">";
        return """;
      });
    }
    var state = { equipment_kinds: [], material_kinds: [], spec_fields: [], ai: {} };
    function api(method, path, body) {
      return fetch("/api/modules/custom-catalog/admin/" + path, { method: method, headers: headers(), credentials: "same-origin", body: body ? JSON.stringify(body) : undefined }).then(function (r) { return r.json(); });
    }
    function row(group, item, i) {
      return "<li><b>" + esc(item.label || item.key) + "</b> <span>" + esc(item.key) + "</span> <button type=\"button\" data-del=\"" + group + ":" + i + "\">삭제</button></li>";
    }
    function paint() {
      var ai = state.ai || {};
      root.innerHTML = "<style>.cct-set{max-width:880px;margin:0 auto;padding:24px;color:#0f172a}.cct-set h1{font-size:28px;margin:0 0 6px}.cct-set h2{font-size:18px;margin:22px 0 8px}.cct-card{background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:16px;margin:0 0 12px}.cct-card form,.cct-ai{display:flex;gap:8px;flex-wrap:wrap}.cct-card input,.cct-card select{height:40px;border:1px solid #cbd5e1;border-radius:10px;padding:0 10px}.cct-card button{height:40px;border:0;border-radius:10px;background:#0f766e;color:#fff;font-weight:700;padding:0 14px}.cct-card ul{list-style:none;padding:0;margin:10px 0 0}.cct-card li{display:flex;gap:8px;align-items:center;padding:8px 0;border-top:1px solid #e2e8f0}.cct-card li span{color:#64748b}.cct-note{color:#64748b}</style><div class=\"cct-set\"><h1>3D 카탈로그 설정</h1><p class=\"cct-note\">종류, 재원 항목, AI 서버를 여기서 넣습니다.</p>" +
        card("장비 종류", "equipment_kinds", "laser", "레이저") +
        card("재료 종류", "material_kinds", "resin", "레진") +
        card("재원 항목", "spec_fields", "power", "소비 전력") +
        "<section class=\"cct-card\"><h2>AI</h2><p class=\"cct-note\">제안만 하고 기존 카드는 덮지 않습니다.</p><form id=\"cct-ai\" class=\"cct-ai\"><label>사용 <input name=\"enabled\" type=\"checkbox\"" + (ai.enabled ? " checked" : "") + "></label><select name=\"provider\">" + ["ollama","openai","grok","gemini","claude"].map(function (p) { return "<option" + (ai.provider === p ? " selected" : "") + ">" + p + "</option>"; }).join("") + "</select><input name=\"url\" value=\"" + esc(ai.url || "http://localhost:11434") + "\" placeholder=\"주소\"><input name=\"model\" value=\"" + esc(ai.model || "qwen2.5:7b") + "\" placeholder=\"모델\"><input name=\"api_key\" placeholder=\"키, 비우면 유지\"><button>저장</button></form></section></div>";
      bind();
    }
    function card(title, group, keyPh, labelPh) {
      var items = state[group] || [];
      return "<section class=\"cct-card\"><h2>" + title + "</h2><form data-add=\"" + group + "\"><input name=\"key\" placeholder=\"" + keyPh + "\"><input name=\"label\" placeholder=\"" + labelPh + "\"><button>추가</button></form><ul>" + items.map(function (item, i) { return row(group, item, i); }).join("") + "</ul></section>";
    }
    function bind() {
      [].forEach.call(root.querySelectorAll("[data-add]"), function (f) {
        f.onsubmit = function (e) {
          e.preventDefault();
          var g = f.getAttribute("data-add");
          var key = f.key.value.trim();
          var label = f.label.value.trim();
          if (!key || !label) return;
          state[g].push({ key: key, label: label, target: g === "spec_fields" ? "equipment" : "" });
          api("POST", "kinds", state).then(function (j) { state = Object.assign(state, j.data || {}); paint(); });
        };
      });
      [].forEach.call(root.querySelectorAll("[data-del]"), function (b) {
        b.onclick = function () {
          var p = b.getAttribute("data-del").split(":");
          state[p[0]].splice(Number(p[1]), 1);
          api("POST", "kinds", state).then(function (j) { state = Object.assign(state, j.data || {}); paint(); });
        };
      });
      var ai = document.getElementById("cct-ai");
      if (ai) ai.onsubmit = function (e) {
        e.preventDefault();
        api("POST", "ai", { enabled: ai.enabled.checked, provider: ai.provider.value, url: ai.url.value, model: ai.model.value, api_key: ai.api_key.value }).then(function (j) { state.ai = j.data || state.ai; paint(); });
      };
    }
    paint();
    api("GET", "kinds").then(function (j) { state = Object.assign(state, j.data || {}); return api("GET", "ai"); }).then(function (j) { state.ai = (j && j.data) || {}; paint(); }).catch(function () { paint(); });
  }
  if (!boot()) {
    var n = 0;
    var t = setInterval(function () { if (boot() || ++n > 20) clearInterval(t); }, 300);
  }
})();
