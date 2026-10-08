/*! custom-catalog 0.1.2 */
(function () {
  "use strict";
  var root = document.querySelector("[data-cct]");
  if (!root || root.dataset.ready) return;
  root.dataset.ready = "1";
  root.innerHTML = '<div style="max-width:960px;margin:24px auto;padding:0 16px"><h1 style="font-size:28px;margin:0 0 8px">3D 카탈로그</h1><p style="color:#64748b;margin:0 0 16px">장비 · 필라멘트 · 레진 자료</p><div id="cct-list">불러오는 중…</div></div>';
  var list = document.getElementById("cct-list");
  function esc(s) {
    return String(s == null ? "" : s).replace(/[&<>"]/g, function (c) {
      return {"&": "&", "<": "<", ">": ">", '"': """}[c];
    });
  }
  function cards(title, rows) {
    if (!rows.length) return "<h2>" + title + "</h2><p>아직 없습니다.</p>";
    return "<h2>" + title + "</h2><ul>" + rows.map(function (r) {
      var name = r.model || r.name || r.key;
      return "<li><b>" + esc(r.brand) + "</b> " + esc(name) + (r.material ? " · " + esc(r.material) : "") + "</li>";
    }).join("") + "</ul>";
  }
  Promise.all([
    fetch("/api/modules/custom-catalog/equipment").then(function (r) { return r.json(); }),
    fetch("/api/modules/custom-catalog/materials").then(function (r) { return r.json(); })
  ]).then(function (xs) {
    var eq = (xs[0].data && xs[0].data.items) || [];
    var mt = (xs[1].data && xs[1].data.items) || [];
    list.innerHTML = cards("장비", eq) + cards("재료", mt);
  }).catch(function () { list.textContent = "목록을 불러오지 못했습니다."; });
})();
