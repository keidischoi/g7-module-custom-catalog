/*! custom-catalog 0.1.7 — /catalog 장비 · 필라멘트 검색 */
(function () {
  "use strict";
  var root = document.getElementById("cct_root");
  if (!root || root.dataset.ready) return;
  root.dataset.ready = "1";
  var q = "";
  var kind = "";
  var CSS = ".cct{--ink:#0f172a;--mute:#64748b;--line:#e2e8f0;--card:#fff;--soft:#f8fafc;--acc:#0f766e;--acc2:#0891b2;color:var(--ink);font-size:14px;line-height:1.55;max-width:1100px;margin:0 auto;padding:20px 16px 64px}" +
    "html.dark .cct,.dark .cct{--ink:#f1f5f9;--mute:#94a3b8;--line:#334155;--card:#1e293b;--soft:#0f172a;--acc:#2dd4bf;--acc2:#22d3ee}" +
    ".cct *{box-sizing:border-box}.cct h1,.cct h2{margin:0;letter-spacing:-.02em}" +
    ".cct-hero{border-radius:28px;padding:28px 26px;margin:0 0 16px;color:#fff;background:radial-gradient(90% 120% at 0% 0%,#0d9488 0,#0f766e 42%,transparent 70%),radial-gradient(80% 120% at 100% 100%,#0891b2 0,transparent 60%),linear-gradient(135deg,#134e4a,#164e63)}" +
    ".cct-hero p{margin:6px 0 0;color:rgba(255,255,255,.82)}" +
    ".cct-find{display:flex;gap:8px;flex-wrap:wrap;margin-top:16px}" +
    ".cct-find input,.cct-find select{height:44px;border:0;border-radius:12px;padding:0 12px;font:inherit}" +
    ".cct-find input{flex:1;min-width:200px}" +
    ".cct-find button{height:44px;padding:0 16px;border:0;border-radius:12px;background:#fff;color:#0f766e;font-weight:800;cursor:pointer}" +
    ".cct-chips{display:flex;gap:6px;flex-wrap:wrap;margin:0 0 16px}" +
    ".cct-chip{border:1px solid var(--line);background:var(--card);border-radius:999px;padding:6px 12px;cursor:pointer;font-weight:700}" +
    ".cct-chip.on{background:var(--acc);color:#fff;border-color:var(--acc)}" +
    ".cct-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:10px}" +
    ".cct-card{background:var(--card);border:1px solid var(--line);border-radius:16px;padding:14px}" +
    ".cct-card b{display:block;font-size:15px}.cct-card .sub{color:var(--mute);font-size:12.5px;margin-top:2px}.cct-card .meta{margin-top:8px;font-size:13px}" +
    ".cct h2{margin:18px 0 8px;font-size:18px}.cct-empty{color:var(--mute);background:var(--soft);border-radius:14px;padding:14px}";
  function esc(s) {
    return String(s == null ? "" : s).replace(/[&<>"]/g, function (c) {
      return {"&": "&", "<": "<", ">": ">", '"': """}[c];
    });
  }
  function card(title, sub, lines) {
    return '<article class="cct-card"><b>' + esc(title) + '</b><div class="sub">' + esc(sub) + '</div><div class="meta">' + lines.filter(Boolean).join("<br>") + "</div></article>";
  }
  function paint(eq, mt, err) {
    var chips = [["", "전체"], ["fdm", "FDM · 필라멘트"], ["resin", "레진"], ["sla", "SLA"], ["powder", "분말"]];
    var eqHtml = eq.length ? eq.map(function (r) {
      var size = [r.build_x_mm, r.build_y_mm, r.build_z_mm].filter(Boolean).join(" × ");
      return card(r.brand + " " + r.model, r.kind, [size ? "크기 " + esc(size) + " mm" : "", r.nozzle ? "노즐 " + esc(r.nozzle) : "", r.enclosed ? "밀폐형" : ""]);
    }).join("") : '<p class="cct-empty">맞는 장비가 없습니다.</p>';
    var mtHtml = mt.length ? mt.map(function (r) {
      var temp = r.nozzle_min ? "노즐 " + r.nozzle_min + (r.nozzle_max ? "–" + r.nozzle_max : "") + "°C" : "";
      var dry = r.dry_temp ? "건조 " + r.dry_temp + "°C" + (r.dry_hours ? " · " + r.dry_hours + "시간" : "") : "";
      var sds = r.sds_url ? '<a href="' + esc(r.sds_url) + '" target="_blank" rel="noopener">SDS</a>' : "";
      return card(r.brand + " " + r.name, (r.material || "") + (r.color ? " · " + r.color : ""), [temp, dry, r.caution ? esc(r.caution) : "", sds]);
    }).join("") : '<p class="cct-empty">맞는 필라멘트 · 레진이 없습니다.</p>';
    root.innerHTML = '<style>' + CSS + '</style><div class="cct"><section class="cct-hero"><h1>3D 카탈로그</h1><p>장비 · 필라멘트 · 레진을 한곳에서 찾습니다.</p><form class="cct-find" id="cct-find"><input id="cct-q" value="' + esc(q) + '" placeholder="제조사, 모델, PLA, PETG"><button type="submit">찾기</button></form></section>' +
      '<div class="cct-chips">' + chips.map(function (c) { return '<button type="button" class="cct-chip' + (c[0] === kind ? " on" : "") + '" data-k="' + c[0] + '">' + c[1] + "</button>"; }).join("") + "</div>" +
      (err ? '<p class="cct-empty">목록을 불러오지 못했습니다.</p>' : "") +
      "<h2>장비 " + eq.length + '</h2><div class="cct-grid">' + eqHtml + "</div><h2>필라멘트 · 레진 " + mt.length + '</h2><div class="cct-grid">' + mtHtml + "</div></div>";
    document.getElementById("cct-find").addEventListener("submit", function (e) {
      e.preventDefault();
      q = document.getElementById("cct-q").value.trim();
      load();
    });
    [].forEach.call(root.querySelectorAll("[data-k]"), function (b) {
      b.addEventListener("click", function () { kind = b.getAttribute("data-k") || ""; load(); });
    });
  }
  function load() {
    var p = new URLSearchParams();
    if (q) p.set("q", q);
    if (kind) p.set("kind", kind);
    var qs = p.toString() ? "?" + p.toString() : "";
    Promise.all([
      fetch("/api/modules/custom-catalog/equipment" + qs).then(function (r) { return r.json(); }),
      fetch("/api/modules/custom-catalog/materials" + qs).then(function (r) { return r.json(); })
    ]).then(function (xs) {
      paint((xs[0].data && xs[0].data.items) || [], (xs[1].data && xs[1].data.items) || [], false);
    }).catch(function () { paint([], [], true); });
  }
  paint([], [], false);
  load();
})();
