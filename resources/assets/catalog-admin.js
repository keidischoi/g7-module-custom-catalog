/*! custom-catalog 0.1.41 — 관리자 */
(function () {
  "use strict";
  function boot() {
    var root = document.getElementById("cct_admin");
    if (!root) {
      root = document.createElement("div");
      root.id = "cct_admin";
      var host = document.querySelector("main") || document.querySelector("[data-slot='content']") || document.body;
      host.appendChild(root);
    }
    if (root.dataset.ready) return true;
    root.dataset.ready = "1";
    start(root);
    return true;
  }
  function start(root) {
    var token = "";
    try { token = localStorage.getItem("auth_token") || localStorage.getItem("token") || ""; } catch (e) {}
    var settings = location.pathname.indexOf("/settings") >= 0;
    function headers(json) {
      var h = { Accept: "application/json" };
      if (json) h["Content-Type"] = "application/json";
      if (token) h.Authorization = "Bearer " + String(token).replace(/^"+|"+$/g, "");
      return h;
    }
    function esc(s) {
      return String(s == null ? "" : s).replace(/[&<>"]/g, function (c) {
        if (c === "&") return "&amp;";
        if (c === "<") return "&lt;";
        if (c === ">") return "&gt;";
        return "&#34;";
      });
    }
    var state = { equipment_kinds: [], material_kinds: [], spec_fields: [], ai: {}, equipment: [], materials: [], note: "" };
    function api(method, path, body, form) {
      return fetch("/api/modules/custom-catalog/admin/" + path, {
        method: method,
        headers: form ? headers(false) : headers(true),
        credentials: "same-origin",
        body: form ? body : (body ? JSON.stringify(body) : undefined)
      }).then(function (r) { return r.json(); });
    }
    function row(group, item, i) {
      return "<li><b>" + esc(item.label || item.key) + "</b> <span>" + esc(item.key) + "</span> <button type=\"button\" data-del=\"" + group + ":" + i + "\">삭제</button></li>";
    }
    function card(title, group, keyPh, labelPh, help) {
      var items = state[group] || [];
      return "<section class=\"cct-card\"><h2>" + title + "</h2><p class=\"cct-note\">" + help + "</p><form data-add=\"" + group + "\"><input name=\"key\" placeholder=\"" + keyPh + "\"><input name=\"label\" placeholder=\"" + labelPh + "\"><button>추가</button></form><ul>" + items.map(function (item, i) { return row(group, item, i); }).join("") + "</ul></section>";
    }
    function itemCard(item) {
      var name = (item.brand || "") + " " + (item.model || item.name || "");
      return "<a class=\"cct-item\" href=\"/catalog/" + esc(item.key) + "\"><b>" + esc(name) + "</b><span>" + esc(item.kind || "") + "</span></a>";
    }
    function paint() {
      var ai = state.ai || {};
      var opts = ["ollama", "openai", "grok", "gemini", "claude"].map(function (p) {
        return "<option value=\"" + p + "\"" + (ai.provider === p ? " selected" : "") + ">" + p + "</option>";
      }).join("");
      var css = ".cct-set{max-width:980px;margin:0 auto;padding:24px 16px 72px;color:#0f172a}.cct-set h1{margin:0 0 6px}.cct-card{background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:16px;margin:0 0 12px}.cct-card form,.cct-tools{display:flex;gap:8px;flex-wrap:wrap;align-items:center}.cct-card input,.cct-card select{height:40px;border:1px solid #cbd5e1;border-radius:10px;padding:0 10px}.cct-card button,.cct-tools a{height:40px;border:0;border-radius:10px;background:#0f766e;color:#fff;font-weight:700;padding:0 14px;display:inline-flex;align-items:center;text-decoration:none}.cct-card ul{list-style:none;padding:0;margin:10px 0 0}.cct-card li{display:flex;gap:8px;align-items:center;padding:8px 0;border-top:1px solid #e2e8f0}.cct-card li span,.cct-note{color:#64748b}.cct-items{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:8px}.cct-item{border:1px solid #e2e8f0;border-radius:12px;padding:10px;text-decoration:none;color:inherit;display:flex;justify-content:space-between;gap:8px}";
      var body = settings
        ? card("장비 종류", "equipment_kinds", "laser", "레이저", "카탈로그 탭에 나옵니다. 예: fdm, sla, laser") +
          card("재료 종류", "material_kinds", "resin", "레진", "필라멘트와 레진을 나눕니다.") +
          card("재원 항목", "spec_fields", "power", "소비 전력", "상세에 더 보여줄 항목 이름입니다.") +
          "<section class=\"cct-card\"><h2>AI</h2><p class=\"cct-note\">제안만 하고 기존 카드는 덮지 않습니다. 로컬 Ollama면 주소에 http://localhost:11434</p><form id=\"cct-ai\"><label>사용 <input name=\"enabled\" type=\"checkbox\"" + (ai.enabled ? " checked" : "") + "></label><select name=\"provider\">" + opts + "</select><input name=\"url\" value=\"" + esc(ai.url || "http://localhost:11434") + "\" placeholder=\"주소\"><input name=\"model\" value=\"" + esc(ai.model || "qwen2.5:7b") + "\" placeholder=\"모델\"><input name=\"api_key\" placeholder=\"키, 비우면 유지\"><button>저장</button></form></section>"
        : "<section class=\"cct-card\"><h2>장비 · 재료</h2><p class=\"cct-note\">카드를 누르면 상세에서 사진, 제원, 삭제를 할 수 있습니다. 권한은 관리자 또는 카탈로그 편집입니다.</p><div class=\"cct-items\">" + state.equipment.concat(state.materials).slice(0, 60).map(itemCard).join("") + "</div></section>";
      root.innerHTML = "<style>" + css + "</style><div class=\"cct-set\"><h1>" + (settings ? "3D 카탈로그 설정" : "3D 카탈로그 제원") + "</h1><p class=\"cct-note\">" + esc(state.note || "종류, 재원 항목, AI는 설정에 있습니다.") + "</p><div class=\"cct-tools\"><a href=\"/admin/catalog\">제원 입력</a><a href=\"/admin/catalog/settings\">설정</a><a href=\"/catalog\">공개 카탈로그</a></div>" + body + "</div>";
      bind();
    }
    function bind() {
      [].forEach.call(root.querySelectorAll("[data-add]"), function (f) {
        f.onsubmit = function (e) {
          e.preventDefault();
          var g = f.getAttribute("data-add");
          var key = f.key.value.trim();
          var label = f.label.value.trim();
          if (!key || !label) return;
          state[g] = state[g] || [];
          state[g].push({ key: key, label: label });
          api("POST", "kinds", state).then(function (j) {
            if (!j.success) state.note = j.message || "저장 권한이 없습니다.";
            state = Object.assign(state, j.data || {});
            paint();
          }).catch(function () { state.note = "저장하지 못했습니다."; paint(); });
        };
      });
      [].forEach.call(root.querySelectorAll("[data-del]"), function (b) {
        b.onclick = function () {
          var parts = b.getAttribute("data-del").split(":");
          state[parts[0]].splice(Number(parts[1]), 1);
          api("POST", "kinds", state).then(function (j) { state = Object.assign(state, j.data || {}); paint(); });
        };
      });
      var aiForm = document.getElementById("cct-ai");
      if (aiForm) aiForm.onsubmit = function (e) {
        e.preventDefault();
        api("POST", "ai", { enabled: aiForm.enabled.checked, provider: aiForm.provider.value, url: aiForm.url.value, model: aiForm.model.value, api_key: aiForm.api_key.value }).then(function (j) {
          if (!j.success) state.note = j.message || "저장 권한이 없습니다.";
          state.ai = j.data || state.ai;
          paint();
        });
      };
    }
    paint();
    if (settings) {
      api("GET", "kinds").then(function (j) {
        if (j && j.data) state = Object.assign(state, j.data);
        return api("GET", "ai");
      }).then(function (j) {
        state.ai = (j && j.data) || {};
        paint();
      }).catch(function () { state.note = "설정을 불러오지 못했습니다. 로그인 상태를 확인하세요."; paint(); });
    } else {
      Promise.all([api("GET", "equipment"), api("GET", "materials")]).then(function (xs) {
        state.equipment = (xs[0].data && xs[0].data.items) || [];
        state.materials = (xs[1].data && xs[1].data.items) || [];
        if (!xs[0].success && !xs[1].success) state.note = (xs[0].message || "제원 입력 권한이 없습니다.");
        paint();
      }).catch(function () { state.note = "목록을 불러오지 못했습니다."; paint(); });
    }
  }
  if (!boot()) {
    var n = 0;
    var timer = setInterval(function () { if (boot() || ++n > 40) clearInterval(timer); }, 250);
  }
})();
