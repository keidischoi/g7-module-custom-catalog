/*! custom-catalog 0.1.19 */
(function () {
  "use strict";
  function boot() {
    var root = document.getElementById("cct_root");
    if (!root) return false;
    if (root.dataset.ready) return true;
    root.dataset.ready = "1";
    start(root);
    return true;
  }
  function start(root) {
    var q = "", tab = "fdm", items = {equipment: [], materials: []};
    var CSS = ".cct{--ink:#0f172a;--mute:#64748b;--line:#e2e8f0;--card:#fff;--soft:#f8fafc;--acc:#0f766e;color:var(--ink);font-size:14px;line-height:1.55;max-width:1100px;margin:0 auto;padding:20px 16px 64px}html.dark .cct,.dark .cct{--ink:#f1f5f9;--mute:#94a3b8;--line:#334155;--card:#1e293b;--soft:#0f172a;--acc:#2dd4bf}.cct *{box-sizing:border-box}.cct h1,.cct h2{margin:0;letter-spacing:-.02em}.cct a{color:var(--acc)}.cct-hero{border-radius:28px;padding:28px 26px;margin:0 0 16px;color:#fff;background:linear-gradient(135deg,#134e4a,#164e63)}.cct-find{display:flex;gap:8px;flex-wrap:wrap;margin-top:16px}.cct-find input{flex:1;min-width:200px;height:44px;border:0;border-radius:12px;padding:0 12px}.cct-find button{height:44px;padding:0 16px;border:0;border-radius:12px;background:#fff;color:#0f766e;font-weight:800}.cct-chips{display:flex;gap:6px;flex-wrap:wrap;margin:0 0 16px}.cct-chip{border:1px solid var(--line);background:var(--card);border-radius:999px;padding:6px 12px;cursor:pointer;font-weight:700}.cct-chip.on{background:var(--acc);color:#fff;border-color:var(--acc)}.cct-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:10px}.cct-card{background:var(--card);border:1px solid var(--line);border-radius:16px;padding:14px;cursor:pointer;text-align:left}.cct-card b{display:block}.cct-card .sub{color:var(--mute);font-size:12.5px}.cct-empty{color:var(--mute);background:var(--soft);border-radius:14px;padding:14px}.cct-sheet{position:fixed;inset:0;background:rgba(15,23,42,.45);z-index:80;display:flex;justify-content:center;align-items:flex-start;padding:24px 12px;overflow:auto}.cct-panel{background:var(--card);color:var(--ink);width:min(760px,100%);border-radius:20px;padding:18px}.cct-photo{width:100%;height:220px;object-fit:cover;border-radius:14px;background:var(--soft)}.cct-table{width:100%;border-collapse:collapse;margin-top:12px}.cct-table td{padding:7px 4px;border-bottom:1px solid var(--line)}.cct-actions{display:flex;gap:8px;margin-top:14px}.cct-actions button,.cct-actions a{height:38px;padding:0 12px;border-radius:10px;border:1px solid var(--line);background:var(--soft);color:inherit;text-decoration:none;display:inline-flex;align-items:center;font-weight:700}@media print{.cct-hero,.cct-chips,.cct-find,.cct-grid,.cct-sheet{display:none!important}#cct-print{display:block!important}}";
    function esc(s) {
      return String(s == null ? "" : s).replace(/[&<>\"]/g, function (c) {
        if (c === "&") return "&amp;";
        if (c === "<") return "&lt;";
        if (c === ">") return "&gt;";
        return "&quot;";
      });
    }
    function card(r, type) {
      var title = r.brand + " " + (r.model || r.name || "");
      return "<button class=\"cct-card\" data-type=\"" + type + "\" data-key=\"" + esc(r.key) + "\"><b>" + esc(title) + "</b><div class=\"sub\">" + esc(r.material || r.kind || "") + "</div></button>";
    }
    function section(title, rows, type) {
      return "<h2>" + title + " " + rows.length + "</h2><div class=\"cct-grid\">" + (rows.map(function (r) { return card(r, type); }).join("") || "<p class=\"cct-empty\">없습니다.</p>") + "</div>";
    }
    function paint(err) {
      var eq = items.equipment, mt = items.materials;
      var fdm = eq.filter(function (r) { return r.kind === "fdm"; });
      var filament = mt.filter(function (r) { return r.kind === "fdm"; });
      var resinEq = eq.filter(function (r) { return r.kind === "sla" || r.kind === "dlp"; });
      var resin = mt.filter(function (r) { return r.kind === "resin"; });
      var other = eq.filter(function (r) { return r.kind !== "fdm" && r.kind !== "sla" && r.kind !== "dlp"; });
      var chips = [["fdm","FDM 프린터"],["filament","필라멘트"],["resin-eq","레진 프린터"],["resin","레진"],["other","기타 장비"]];
      var body = tab === "fdm" ? section("FDM 프린터", fdm, "equipment") : tab === "filament" ? section("필라멘트", filament, "materials") : tab === "resin-eq" ? section("레진 프린터", resinEq, "equipment") : tab === "resin" ? section("레진", resin, "materials") : section("기타 장비", other, "equipment");
      root.innerHTML = "<style>" + CSS + "</style><div class=\"cct\"><section class=\"cct-hero\"><h1>3D 카탈로그</h1><p>FDM 프린터와 필라멘트는 따로 봅니다.</p><form class=\"cct-find\" id=\"cct-find\"><input id=\"cct-q\" value=\"" + esc(q) + "\" placeholder=\"제조사, 모델, PLA\"><button>찾기</button></form></section><div class=\"cct-chips\">" + chips.map(function (c) { return "<button type=\"button\" class=\"cct-chip" + (c[0] === tab ? " on" : "") + "\" data-k=\"" + c[0] + "\">" + c[1] + "</button>"; }).join("") + "</div>" + (err ? "<p class=\"cct-empty\">목록을 불러오지 못했습니다.</p>" : body) + "</div>";
      document.getElementById("cct-find").onsubmit = function (e) { e.preventDefault(); q = document.getElementById("cct-q").value.trim(); load(); };
      [].forEach.call(root.querySelectorAll("[data-k]"), function (b) { b.onclick = function () { tab = b.getAttribute("data-k") || "fdm"; paint(false); }; });
      [].forEach.call(root.querySelectorAll(".cct-card"), function (b) { b.onclick = function () { open(b.getAttribute("data-type"), b.getAttribute("data-key")); }; });
    }
    function open(type, key) {
      var row = (items[type] || []).filter(function (r) { return r.key === key; })[0];
      if (!row) return;
      var sheet = document.createElement("div");
      sheet.className = "cct-sheet";
      sheet.innerHTML = "<article class=\"cct-panel\">" + (row.image_url ? "<img class=\"cct-photo\" alt=\"\" src=\"" + esc(row.image_url) + "\">" : "") + "<h2>" + esc(row.brand + " " + (row.model || row.name || "")) + "</h2><p>" + esc(row.material || row.kind || "") + "</p><div class=\"cct-actions\"><button type=\"button\" data-print>PDF 출력</button>" + (row.sds_url ? "<a href=\"" + esc(row.sds_url) + "\" target=\"_blank\" rel=\"noopener\">MSDS</a>" : "") + "<button type=\"button\" data-close>닫기</button></div></article>";
      document.body.appendChild(sheet);
      sheet.querySelector("[data-close]").onclick = function () { sheet.remove(); };
      sheet.querySelector("[data-print]").onclick = function () { window.print(); };
    }
    function load() {
      var p = new URLSearchParams();
      if (q) p.set("q", q);
      var qs = p.toString() ? "?" + p.toString() : "";
      Promise.all([
        fetch("/api/modules/custom-catalog/equipment" + qs).then(function (r) { return r.json(); }),
        fetch("/api/modules/custom-catalog/materials" + qs).then(function (r) { return r.json(); })
      ]).then(function (xs) {
        items.equipment = (xs[0].data && xs[0].data.items) || [];
        items.materials = (xs[1].data && xs[1].data.items) || [];
        paint(false);
      }).catch(function () { paint(true); });
    }
    paint(false);
    load();
  }
  if (!boot()) {
    var n = 0;
    var timer = setInterval(function () { if (boot() || ++n > 40) clearInterval(timer); }, 250);
  }
})();
