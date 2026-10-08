/*! custom-catalog 0.1.8 — 관리자 제원 입력 */
(function () {
  "use strict";
  var root = document.getElementById("cct_admin");
  if (!root || root.dataset.ready) return;
  root.dataset.ready = "1";
  var token = "";
  try { token = localStorage.getItem("auth_token") || localStorage.getItem("token") || ""; } catch (e) {}
  function headers() { var h = { Accept: "application/json", "Content-Type": "application/json" }; if (token) h.Authorization = "Bearer " + String(token).replace(/^"+|"+$/g, ""); return h; }
  function esc(s) { return String(s == null ? "" : s).replace(/[&<>"]/g, function (c) { return {"&":"&","<":"<",">":">",'"':"""}[c]; }); }
  function field(name, label, value) { return '<label style="display:grid;gap:4px;font-size:13px">' + label + '<input name="' + name + '" value="' + esc(value || "") + '" style="height:36px;border:1px solid #cbd5e1;border-radius:8px;padding:0 8px"></label>'; }
  function form(kind) {
    var eq = kind === "equipment";
    return '<form data-kind="' + kind + '" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:8px;margin:8px 0 16px">' +
      field("brand", "제조사") + field(eq ? "model" : "name", eq ? "모델" : "제품명") + field("kind", "종류", eq ? "fdm" : "fdm") +
      (eq ? field("build_x_mm", "가로 mm") + field("build_y_mm", "세로 mm") + field("build_z_mm", "높이 mm") + field("nozzle", "노즐") : field("material", "재료", "PLA") + field("color", "색") + field("nozzle_min", "노즐 최소") + field("nozzle_max", "노즐 최대") + field("bed_min", "베드 최소") + field("bed_max", "베드 최대") + field("dry_temp", "건조 온도") + field("dry_hours", "건조 시간") + field("sds_url", "SDS 링크") + field("caution", "주의")) +
      '<button type="submit" style="height:36px;border:0;border-radius:8px;background:#0f766e;color:#fff;font-weight:700">저장</button></form>';
  }
  function list(items, kind) {
    if (!items.length) return "<p>아직 없습니다.</p>";
    return items.map(function (r) { return "<li><b>" + esc(r.brand) + "</b> " + esc(r.model || r.name) + " <button type=\"button\" data-del=\"" + kind + ":" + esc(r.key) + "\">보관</button></li>"; }).join("");
  }
  function paint(eq, mt) {
    root.innerHTML = '<div style="max-width:980px;margin:0 auto;padding:20px"><h1 style="font-size:24px">3D 카탈로그 제원</h1><p>관리자 또는 제원 입력 권한이 있는 회원만 넣습니다.</p><h2>장비</h2>' + form("equipment") + "<ul>" + list(eq, "equipment") + "</ul><h2>필라멘트 · 레진</h2>" + form("materials") + "<ul>" + list(mt, "materials") + "</ul></div>";
    [].forEach.call(root.querySelectorAll("form"), function (f) {
      f.addEventListener("submit", function (e) {
        e.preventDefault();
        var body = {};
        [].forEach.call(f.elements, function (el) { if (el.name) body[el.name] = el.value; });
        fetch("/api/modules/custom-catalog/admin/" + f.getAttribute("data-kind"), { method: "POST", headers: headers(), credentials: "same-origin", body: JSON.stringify(body) })
          .then(function (r) { return r.json(); }).then(load);
      });
    });
    [].forEach.call(root.querySelectorAll("[data-del]"), function (b) {
      b.addEventListener("click", function () {
        var p = b.getAttribute("data-del").split(":");
        fetch("/api/modules/custom-catalog/admin/" + p[0] + "/" + encodeURIComponent(p[1]) + "/delete", { method: "POST", headers: headers(), credentials: "same-origin" }).then(load);
      });
    });
  }
  function load() {
    Promise.all([
      fetch("/api/modules/custom-catalog/admin/equipment", { headers: headers(), credentials: "same-origin" }).then(function (r) { return r.json(); }),
      fetch("/api/modules/custom-catalog/admin/materials", { headers: headers(), credentials: "same-origin" }).then(function (r) { return r.json(); })
    ]).then(function (xs) { paint((xs[0].data && xs[0].data.items) || [], (xs[1].data && xs[1].data.items) || []); });
  }
  paint([], []);
  load();
})();
