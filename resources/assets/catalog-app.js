/*! custom-catalog 0.1.5 — /catalog 장비 · 필라멘트 검색 */
(function () {
  "use strict";
  var root = document.getElementById("cct_root");
  if (!root || root.dataset.ready) return;
  root.dataset.ready = "1";
  var q = "";
  var kind = "";
  function esc(s) {
    return String(s == null ? "" : s).replace(/[&<>"]/g, function (c) {
      return {"&": "&", "<": "<", ">": ">", '"': """}[c];
    });
  }
  function card(title, sub, lines) {
    return '<article style="border:1px solid rgba(127,127,127,.25);border-radius:12px;padding:12px 14px"><b>' + esc(title) + '</b><div style="color:#64748b;font-size:13px;margin-top:2px">' + esc(sub) + "</div>" + lines.map(function (l) { return '<div style="font-size:13px;margin-top:4px">' + l + "</div>"; }).join("") + "</article>";
  }
  function paint(eq, mt, err) {
    var eqHtml = !eq.length ? "<p>맞는 장비가 없습니다.</p>" : eq.map(function (r) {
      var size = [r.build_x_mm, r.build_y_mm, r.build_z_mm].filter(Boolean).join(" × ");
      return card(r.brand + " " + r.model, r.kind, [size ? "크기 " + esc(size) + " mm" : "", r.nozzle ? "노즐 " + esc(r.nozzle) : "", r.enclosed === 1 || r.enclosed === true ? "밀폐" : ""].filter(Boolean));
    }).join("");
    var mtHtml = !mt.length ? "<p>맞는 필라멘트 · 레진이 없습니다.</p>" : mt.map(function (r) {
      var temp = r.nozzle_min ? "노즐 " + r.nozzle_min + (r.nozzle_max ? "–" + r.nozzle_max : "") + "°C" : "";
      var dry = r.dry_temp ? "건조 " + r.dry_temp + "°C" + (r.dry_hours ? " / " + r.dry_hours + "시간" : "") : "";
      var sds = r.sds_url ? '<a href="' + esc(r.sds_url) + '" target="_blank" rel="noopener">SDS</a>' : "";
      return card(r.brand + " " + r.name, r.material + (r.color ? " · " + r.color : ""), [temp, dry, r.caution ? esc(r.caution) : "", sds].filter(Boolean));
    }).join("");
    root.innerHTML = '<h1 style="font-size:28px;margin:0 0 6px">3D 카탈로그</h1><p style="color:#64748b;margin:0 0 14px">장비 · 필라멘트 · 레진을 찾습니다.</p>' +
      '<form id="cct-find" style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px"><input id="cct-q" value="' + esc(q) + '" placeholder="제조사, 모델, PLA, PETG" style="height:40px;min-width:220px;padding:0 10px;border:1px solid rgba(127,127,127,.35);border-radius:8px"><select id="cct-kind" style="height:40px;border:1px solid rgba(127,127,127,.35);border-radius:8px"><option value="">전체</option><option value="fdm">FDM · 필라멘트</option><option value="resin">레진</option><option value="sla">SLA</option><option value="powder">분말</option></select><button type="submit" style="height:40px;padding:0 14px;border:0;border-radius:8px;background:#b45309;color:#fff">찾기</button></form>' +
      (err ? '<p style="color:#b91c1c">목록을 불러오지 못했습니다.</p>' : "") +
      '<h2 style="font-size:18px">장비 ' + eq.length + '</h2><div style="display:grid;gap:8px">' + eqHtml + '</div>' +
      '<h2 style="font-size:18px;margin-top:18px">필라멘트 · 레진 ' + mt.length + '</h2><div style="display:grid;gap:8px">' + mtHtml + "</div>";
    var kindEl = document.getElementById("cct-kind");
    if (kindEl) kindEl.value = kind;
    document.getElementById("cct-find").addEventListener("submit", function (e) {
      e.preventDefault();
      q = document.getElementById("cct-q").value.trim();
      kind = document.getElementById("cct-kind").value;
      load();
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
