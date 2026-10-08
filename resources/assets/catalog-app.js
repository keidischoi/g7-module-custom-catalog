/*! custom-catalog 0.1.42 */
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
  
  function renderDetail(root, key) {
    root.innerHTML = "<div class=\"cct\"><p>불러오는 중</p></div>";
    var token = "";
    try { token = localStorage.getItem("auth_token") || localStorage.getItem("token") || ""; } catch (e) {}
    function authHeaders(json) {
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
        return "&quot;";
      });
    }
    function show(row, can) {
      if (!row) { root.innerHTML = "<div class=\"cct\"><p>없습니다.</p><p><a href=\"/catalog\">목록</a></p></div>"; return; }
      var title = ((row.brand || "") + " " + (row.model || row.name || "")).trim();
      var type = row.name && !row.model ? "material" : "equipment";
      var photos = row.photos && row.photos.length ? row.photos : (row.image_url ? [{id:0, url:row.image_url}] : []);
      var main = photos.length ? "<img class=\"cct-hero\" id=\"cct-main\" alt=\"" + esc(title) + "\" src=\"" + esc(photos[0].url) + "\">" : "<div class=\"cct-hero cct-ph\">사진 준비 중</div>";
      var thumbs = photos.map(function (ph, i) {
        return "<button type=\"button\" class=\"cct-mini\" data-src=\"" + esc(ph.url) + "\"><img alt=\"\" src=\"" + esc(ph.url) + "\">" + (can && ph.id ? "<span data-del-photo=\"" + ph.id + "\">삭제</span>" : "") + "</button>";
      }).join("");
      var upload = can ? "<form class=\"cct-upload\"><input type=\"file\" accept=\"image/*\" multiple name=\"photos\"><button>사진 올리기</button><small>1장 이상, 여러 장 가능. 긴 변 720px로 저장</small></form>" : "";
      var labels = {brand:"제조사", model:"모델", name:"이름", kind:"종류", material:"재료", build_x_mm:"가로 mm", build_y_mm:"세로 mm", build_z_mm:"높이 mm", min_layer_um:"최소 층 µm", nozzle:"노즐", color:"색", diameter:"직경 mm", nozzle_min:"노즐 최저 °C", nozzle_max:"노즐 최고 °C", bed_min:"베드 최저 °C", bed_max:"베드 최고 °C", dry_temp:"건조 °C", dry_hours:"건조 시간", chamber:"챔버", weight_g:"무게 g", traits:"특성", caution:"주의", storage_note:"보관", note:"메모", homepage_url:"제조사 페이지"};
      var spec = "";
      Object.keys(labels).forEach(function (k) {
        if (row[k] === null || row[k] === undefined || row[k] === "") return;
        var val = row[k];
        if (k === "homepage_url") val = "<a href=\"" + esc(val) + "\" target=\"_blank\" rel=\"noopener\">열기</a>";
        else val = esc(val);
        spec += "<div><dt>" + labels[k] + "</dt><dd>" + val + "</dd></div>";
      });
      var facts = row.facts || {};
      if (typeof facts === "string") { try { facts = JSON.parse(facts); } catch (e) { facts = {}; } }
      Object.keys(facts).forEach(function (k) {
        spec += "<div><dt>" + esc(k) + "</dt><dd>" + esc(facts[k]) + "</dd></div>";
      });
      var summary = row.summary ? "<p class=\"cct-sum\">" + esc(row.summary) + "</p>" : "";
      var wiki = row.wiki_url ? "<a href=\"" + esc(row.wiki_url) + "\" target=\"_blank\" rel=\"noopener\">위키에서 더 보기</a>" : "";
      var tools = can ? "<button type=\"button\" id=\"cct-edit\">수정</button><button type=\"button\" id=\"cct-del\">삭제</button>" : "";
      root.innerHTML = "<style>.cct{max-width:980px;margin:0 auto;padding:28px 16px 72px;color:#0f172a}.cct a{color:#0f766e;font-weight:700;text-decoration:none}.cct-hero{width:100%;height:360px;object-fit:contain;background:linear-gradient(180deg,#f8fafc,#e2e8f0);border-radius:24px}.cct-ph{display:flex;align-items:center;justify-content:center;color:#64748b}.cct h1{font-size:32px;margin:18px 0 6px}.cct-badge{display:inline-block;background:#ccfbf1;color:#0f766e;border-radius:999px;padding:4px 10px;font-weight:700}.cct-spec{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px;margin-top:18px}.cct-spec div{background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:12px}.cct-spec dt{color:#64748b;font-size:12px}.cct-spec dd{margin:4px 0 0;font-weight:700}.cct-actions{display:flex;gap:8px;margin-top:16px;flex-wrap:wrap}.cct-actions button,.cct-actions a{height:40px;border:0;border-radius:12px;background:#0f766e;color:#fff;padding:0 14px;display:inline-flex;align-items:center}.cct-minis{display:flex;gap:8px;overflow:auto;margin-top:10px}.cct-mini{position:relative;border:0;padding:0;background:#f8fafc;border-radius:12px}.cct-mini img{width:92px;height:72px;object-fit:contain;border-radius:12px}.cct-mini span{position:absolute;right:4px;top:4px;background:#0f172a;color:#fff;border-radius:999px;font-size:11px;padding:2px 6px}.cct-upload{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:10px}.cct-sum{line-height:1.6;color:#334155}.cct-form{display:grid;gap:8px;margin-top:16px}.cct-form input,.cct-form textarea{border:1px solid #e2e8f0;border-radius:10px;padding:8px}.cct-form textarea{min-height:90px}.cct-rel{margin-top:28px}.cct-rel h2{font-size:18px;margin:18px 0 8px}.cct-maker{display:flex;gap:12px;align-items:center;background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:12px}.cct-maker img{width:120px;height:48px;object-fit:contain;background:#f8fafc;border-radius:10px}.cct-tips{display:grid;gap:8px}.cct-tips div{background:#f0fdfa;border-radius:12px;padding:10px}.cct-combo{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:8px}.cct-combo a{border:1px solid #e2e8f0;border-radius:14px;padding:8px;color:inherit;text-decoration:none}.cct-combo img{width:100%;height:90px;object-fit:contain;background:#f8fafc;border-radius:10px}</style><div class=\"cct\"><p><a href=\"/catalog\">← 목록</a></p>" + main + "<div class=\"cct-minis\">" + thumbs + "</div>" + upload + "<h1>" + esc(title) + "</h1><span class=\"cct-badge\">" + esc(row.kind || "장비") + "</span>" + summary + "<dl class=\"cct-spec\">" + spec + "</dl><div class=\"cct-actions\">" + tools + "<button type=\"button\" id=\"cct-print\">PDF 출력</button>" + (row.sds_url ? "<a href=\"" + esc(row.sds_url) + "\" target=\"_blank\" rel=\"noopener\">MSDS</a>" : "") + wiki + "</div><div id=\"cct-form\"></div><section id=\"cct-related\"></section></div>";
      var mainImg = document.getElementById("cct-main");
      [].forEach.call(root.querySelectorAll(".cct-mini"), function (b) {
        b.onclick = function (ev) {
          if (ev.target.getAttribute("data-del-photo")) return;
          if (mainImg) mainImg.src = b.getAttribute("data-src");
        };
      });
      [].forEach.call(root.querySelectorAll("[data-del-photo]"), function (b) {
        b.onclick = function (ev) {
          ev.stopPropagation();
          if (!confirm("이 사진을 삭제할까요?")) return;
          fetch("/api/modules/custom-catalog/admin/photos/" + b.getAttribute("data-del-photo") + "/delete", {method:"POST", headers:authHeaders(true), credentials:"same-origin", body:"{}"}).then(function () { load(); });
        };
      });
      var form = root.querySelector(".cct-upload");
      if (form) form.onsubmit = function (ev) {
        ev.preventDefault();
        var input = form.querySelector("input");
        if (!input.files || !input.files.length) return;
        var body = new FormData();
        body.append("key", row.key);
        body.append("type", type);
        [].forEach.call(input.files, function (f) { body.append("photos[]", f); });
        var h = authHeaders(false);
        fetch("/api/modules/custom-catalog/admin/photos", {method:"POST", headers:h, credentials:"same-origin", body:body}).then(function (r) { return r.json(); }).then(function (j) {
          if (!j.success) alert(j.message || "올리지 못했습니다.");
          load();
        });
      };
      var edit = document.getElementById("cct-edit");
      if (edit) edit.onclick = function () {
        var box = document.getElementById("cct-form");
        box.innerHTML = "<form class=\"cct-form\"><input name=\"brand\" value=\"" + esc(row.brand || "") + "\" placeholder=\"제조사\"><input name=\"model\" value=\"" + esc(row.model || row.name || "") + "\" placeholder=\"모델\"><input name=\"build_x_mm\" value=\"" + esc(row.build_x_mm || "") + "\" placeholder=\"가로 mm\"><input name=\"build_y_mm\" value=\"" + esc(row.build_y_mm || "") + "\" placeholder=\"세로 mm\"><input name=\"build_z_mm\" value=\"" + esc(row.build_z_mm || "") + "\" placeholder=\"높이 mm\"><input name=\"nozzle\" value=\"" + esc(row.nozzle || "") + "\" placeholder=\"노즐\"><input name=\"wiki_url\" value=\"" + esc(row.wiki_url || "") + "\" placeholder=\"위키 주소\"><textarea name=\"summary\" placeholder=\"설명\">" + esc(row.summary || "") + "</textarea><textarea name=\"note\" placeholder=\"메모\">" + esc(row.note || "") + "</textarea><button>저장</button></form>";
        box.querySelector("form").onsubmit = function (ev) {
          ev.preventDefault();
          var fd = new FormData(ev.target);
          var payload = {key: row.key, kind: row.kind || "fdm"};
          fd.forEach(function (v, k) { payload[k] = v; });
          if (type === "material") payload.name = payload.model;
          fetch("/api/modules/custom-catalog/admin/" + (type === "material" ? "materials" : "equipment"), {method:"POST", headers:authHeaders(true), credentials:"same-origin", body:JSON.stringify(payload)}).then(function (r) { return r.json(); }).then(function (j) {
            if (!j.success) alert(j.message || "저장하지 못했습니다.");
            else load();
          });
        };
      };
      var del = document.getElementById("cct-del");
      if (del) del.onclick = function () {
        if (!confirm("이 항목을 삭제할까요?")) return;
        fetch("/api/modules/custom-catalog/admin/" + (type === "material" ? "materials" : "equipment") + "/" + row.key + "/delete", {method:"POST", headers:authHeaders(true), credentials:"same-origin", body:"{}"}).then(function () { location.assign("/catalog"); });
      };
      var print = document.getElementById("cct-print");
      if (print) print.onclick = function () { window.print(); };
      loadRelated(row);
    }
    function loadRelated(row) {
      var box = document.getElementById("cct-related");
      if (!box) return;
      Promise.all([
        fetch("/api/modules/custom-catalog/assets/makers.json").then(function (r) { return r.json(); }).catch(function () { return []; }),
        fetch("/api/modules/custom-catalog/equipment").then(function (r) { return r.json(); }).catch(function () { return {}; }),
        fetch("/api/modules/custom-catalog/materials").then(function (r) { return r.json(); }).catch(function () { return {}; })
      ]).then(function (xs) {
        var makers = xs[0] || [];
        var eqs = (xs[1].data && xs[1].data.items) || [];
        var mats = (xs[2].data && xs[2].data.items) || [];
        var brand = String(row.brand || "").toLowerCase();
        var maker = makers.filter(function (m) { return brand.indexOf(m.match) >= 0; })[0];
        var makerHtml = maker ? "<h2>제작사</h2><div class=\"cct-maker\"><img alt=\"\" src=\"" + esc(maker.logo) + "\"><div><b>" + esc(maker.name) + "</b><div>" + esc(maker.note) + "</div><a href=\"" + esc(maker.homepage) + "\" target=\"_blank\" rel=\"noopener\">홈페이지</a></div></div>" : (row.homepage_url ? "<h2>제작사</h2><p><a href=\"" + esc(row.homepage_url) + "\" target=\"_blank\" rel=\"noopener\">홈페이지</a></p>" : "");
        var tips = [];
        var kind = row.kind || "";
        var mat = String(row.material || row.material_norm || "").toLowerCase();
        var isPrinter = !row.name || !!row.model;
        if (isPrinter && (kind === "fdm" || kind === "")) tips.push("PLA가 기본입니다. 첫 출력은 노즐 200–220°C, 베드 55–60°C 근처에서 시작하세요.");
        if (isPrinter && row.enclosed) tips.push("밀폐형이라 ABS · ASA도 가능합니다. 출력 중에는 환기하세요.");
        if (isPrinter && row.enclosed === false) tips.push("개방형입니다. ABS는 수축이 크니 밀폐가 있는 장비를 권합니다.");
        if (isPrinter && (kind === "sla" || kind === "dlp")) tips.push("405 nm 레진만 사용하세요. 장갑, 환기, 세척 · 경화기가 따로 필요합니다.");
        if (!isPrinter && mat.indexOf("pla") >= 0) tips.push("개방형 프린터와 잘 맞습니다. 습하면 55°C에서 말린 뒤 쓰세요.");
        if (!isPrinter && mat.indexOf("petg") >= 0) tips.push("출력 전 건조가 필요합니다. 끈적임이 있으면 노즐을 조금 올리세요.");
        if (!isPrinter && mat.indexOf("abs") >= 0) tips.push("밀폐 프린터와 환기를 권합니다. 개방형에서는 갈라지기 쉽습니다.");
        if (!isPrinter && (kind === "resin" || mat.indexOf("resin") >= 0)) tips.push("레진 프린터 전용입니다. 피부 접촉을 피하고 SDS를 확인하세요.");
        var tipHtml = tips.length ? "<h2>권장</h2><div class=\"cct-tips\">" + tips.map(function (s) { return "<div>" + esc(s) + "</div>"; }).join("") + "</div>" : "";
        var related = [];
        if (isPrinter && (kind === "sla" || kind === "dlp")) related = mats.filter(function (m) { return m.kind === "resin"; });
        else if (isPrinter) related = mats.filter(function (m) { return m.kind === "fdm"; });
        else if (kind === "resin") related = eqs.filter(function (m) { return m.kind === "sla" || m.kind === "dlp"; });
        else related = eqs.filter(function (m) { return m.kind === "fdm"; });
        related = related.filter(function (m) { return m.key !== row.key; }).slice(0, 6);
        var title = isPrinter ? (kind === "sla" || kind === "dlp" ? "같이 쓰는 레진" : "같이 쓰는 필라멘트") : "같이 쓰는 프린터";
        var cards = related.map(function (m) {
          var name = (m.brand || "") + " " + (m.model || m.name || "");
          var img = m.image_url ? "<img alt=\"\" src=\"" + esc(m.image_url) + "\">" : "<div class=\"cct-ph\" style=\"height:90px\">사진 준비 중</div>";
          return "<a href=\"/catalog/" + esc(m.key) + "\">" + img + "<b>" + esc(name) + "</b></a>";
        }).join("");
        box.innerHTML = makerHtml + tipHtml + (cards ? "<h2>" + title + "</h2><div class=\"cct-combo\">" + cards + "</div>" : "");
      });
    }
    function load() {
      Promise.all([
        fetch("/api/modules/custom-catalog/equipment/" + encodeURIComponent(key)).then(function (r) { return r.json(); }).catch(function () { return {}; }),
        fetch("/api/modules/custom-catalog/materials/" + encodeURIComponent(key)).then(function (r) { return r.json(); }).catch(function () { return {}; }),
        fetch("/api/modules/custom-catalog/me", {headers: authHeaders(true), credentials:"same-origin"}).then(function (r) { return r.json(); }).catch(function () { return {}; })
      ]).then(function (xs) {
        var row = (xs[0] && xs[0].success && xs[0].data) || (xs[1] && xs[1].success && xs[1].data) || null;
        fetch("/api/modules/custom-catalog/assets/briefs.json").then(function (r) { return r.json(); }).then(function (briefs) {
          var extra = briefs && row ? briefs[row.key] : null;
          if (extra) {
            if (!row.summary) row.summary = extra.summary;
            if (!row.wiki_url) row.wiki_url = extra.wiki_url;
            row.facts = Object.assign({}, extra.facts || {}, row.facts || {});
          }
          show(row, !!(xs[2] && xs[2].data && xs[2].data.can_edit));
        }).catch(function () { show(row, !!(xs[2] && xs[2].data && xs[2].data.can_edit)); });
      });
    }
    load();
  }

  function start(root) {
    var parts = location.pathname.split("/").filter(Boolean);
    var detailKey = parts[0] === "catalog" && parts[1] ? decodeURIComponent(parts[1]) : "";
    if (detailKey) { renderDetail(root, detailKey); return; }
    var q = "", tab = "fdm", brand = "", can = false, limit = 20, items = {equipment: [], materials: []}, makers = [];
    var CSS = ".cct{--ink:#0f172a;--mute:#64748b;--line:#e2e8f0;--card:#fff;--soft:#f8fafc;--acc:#0f766e;color:var(--ink);font-size:14px;line-height:1.55;max-width:1100px;margin:0 auto;padding:20px 16px 64px}html.dark .cct,.dark .cct{--ink:#f1f5f9;--mute:#94a3b8;--line:#334155;--card:#1e293b;--soft:#0f172a;--acc:#2dd4bf}.cct *{box-sizing:border-box}.cct h1,.cct h2{margin:0;letter-spacing:-.02em}.cct a{color:var(--acc)}.cct-hero{border-radius:28px;padding:28px 26px;margin:0 0 16px;color:#fff;background:linear-gradient(135deg,#134e4a,#164e63)}.cct-find{display:flex;gap:8px;flex-wrap:wrap;margin-top:16px}.cct-find input{flex:1;min-width:200px;height:44px;border:0;border-radius:12px;padding:0 12px}.cct-find button{height:44px;padding:0 16px;border:0;border-radius:12px;background:#fff;color:#0f766e;font-weight:800}.cct-chips{display:flex;gap:6px;flex-wrap:wrap;margin:0 0 16px}.cct-chip{border:1px solid var(--line);background:var(--card);border-radius:999px;padding:6px 12px;cursor:pointer;font-weight:700}.cct-chip.on{background:var(--acc);color:#fff;border-color:var(--acc)}.cct-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:10px}.cct-card{background:var(--card);border:1px solid var(--line);border-radius:16px;padding:14px;cursor:pointer;text-align:left}.cct-card img.cct-thumb,.cct-card .cct-thumb{width:100%;height:132px;object-fit:contain;background:var(--soft);border-radius:10px;margin:0 0 8px;display:flex;align-items:center;justify-content:center;color:var(--mute);font-size:12px}.cct-card b{display:block}.cct-card .sub{color:var(--mute);font-size:12.5px}.cct-empty{color:var(--mute);min-height:280px;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:8px;text-align:center}.cct-empty svg{width:120px;height:90px;color:var(--acc)}.cct-empty b{color:var(--ink);font-size:16px}.cct-sheet{position:fixed;inset:0;background:rgba(15,23,42,.45);z-index:80;display:flex;justify-content:center;align-items:flex-start;padding:24px 12px;overflow:auto}.cct-panel{background:var(--card);color:var(--ink);width:min(760px,100%);border-radius:20px;padding:18px}.cct-photo{width:100%;height:220px;object-fit:contain;border-radius:14px;background:var(--soft)}.cct-table{width:100%;border-collapse:collapse;margin-top:12px}.cct-table td{padding:7px 4px;border-bottom:1px solid var(--line)}.cct-actions{display:flex;gap:8px;margin-top:14px}.cct-actions button,.cct-actions a{height:38px;padding:0 12px;border-radius:10px;border:1px solid var(--line);background:var(--soft);color:inherit;text-decoration:none;display:inline-flex;align-items:center;font-weight:700}@media print{.cct-hero,.cct-chips,.cct-find,.cct-grid,.cct-sheet{display:none!important}#cct-print{display:block!important}}";
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
      var logo = "";
      makers.forEach(function (m) { if (!logo && String(r.brand || "").toLowerCase().indexOf(m.match) >= 0) logo = m.logo; });
      var src = r.image_url || logo;
      var photo = src
        ? "<img class=\"cct-thumb\" alt=\"\" src=\"" + esc(src) + "\">"
        : "<div class=\"cct-thumb cct-ph\">사진 준비 중</div>";
      return "<button class=\"cct-card\" data-type=\"" + type + "\" data-key=\"" + esc(r.key) + "\">" + photo + "<b>" + esc(title) + "</b><div class=\"sub\">" + esc(r.material || r.kind || "") + "</div></button>";
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
      var rows = tab === "filament" ? filament : tab === "resin-eq" ? resinEq : tab === "resin" ? resin : tab === "other" ? other : fdm;
      var title = tab === "filament" ? "필라멘트" : tab === "resin-eq" ? "레진 프린터" : tab === "resin" ? "레진" : tab === "other" ? "기타 장비" : "FDM 프린터";
      var type = tab === "filament" || tab === "resin" ? "materials" : "equipment";
      var brands = [];
      rows.forEach(function (r) { if (r.brand && brands.indexOf(r.brand) < 0) brands.push(r.brand); });
      brands.sort();
      if (brand && brands.indexOf(brand) < 0) brand = "";
      var shown = brand ? rows.filter(function (r) { return r.brand === brand; }) : rows;
      var brandHtml = "<div class=\"cct-chips\">" + "<button type=\"button\" class=\"cct-chip" + (brand === "" ? " on" : "") + "\" data-b=\"\">전체 제조사</button>" + brands.map(function (b) { return "<button type=\"button\" class=\"cct-chip" + (b === brand ? " on" : "") + "\" data-b=\"" + esc(b) + "\">" + esc(b) + "</button>"; }).join("") + "</div>";
      var empty = "<div class=\"cct-empty\"><svg viewBox=\"0 0 120 90\" aria-hidden=\"true\"><rect x=\"18\" y=\"16\" width=\"84\" height=\"58\" rx=\"10\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\"/><circle cx=\"46\" cy=\"40\" r=\"8\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\"/><path d=\"M62 52l10 8\" stroke=\"currentColor\" stroke-width=\"2\"/><path d=\"M28 78h64\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\"/></svg><b>결과가 없습니다</b><span>다른 제조사나 검색어를 골라 보세요.</span></div>";
      var page = shown.slice(0, limit);
      var more = shown.length > page.length ? "<p class=\"cct-more\">" + page.length + " / " + shown.length + " · 스크롤하면 더 봅니다</p>" : "";
      var body = page.length ? section(title, page, type) + more : empty;
      root.innerHTML = "<style>" + CSS + "</style><div class=\"cct\"><section class=\"cct-hero\"><h1>3D 카탈로그</h1><p>종류와 제조사로 좁힐 수 있습니다.</p><form class=\"cct-find\" id=\"cct-find\"><input id=\"cct-q\" value=\"" + esc(q) + "\" placeholder=\"제조사, 모델, PLA\"><button>찾기</button></form></section><div class=\"cct-chips\">" + chips.map(function (c) { return "<button type=\"button\" class=\"cct-chip" + (c[0] === tab ? " on" : "") + "\" data-k=\"" + c[0] + "\">" + c[1] + "</button>"; }).join("") + "</div>" + brandHtml + (err ? empty.replace("결과가 없습니다","목록을 불러오지 못했습니다") : body) + "</div>";
      document.getElementById("cct-find").onsubmit = function (e) { e.preventDefault(); q = document.getElementById("cct-q").value.trim(); if (q && window.__cpsRecord) window.__cpsRecord(q, "catalog"); load(); };
      [].forEach.call(root.querySelectorAll("[data-k]"), function (b) { b.onclick = function () { tab = b.getAttribute("data-k") || "fdm"; brand = ""; limit = 20; paint(false); }; });
      [].forEach.call(root.querySelectorAll("[data-b]"), function (b) { b.onclick = function () { brand = b.getAttribute("data-b") || ""; limit = 20; paint(false); }; });
      [].forEach.call(root.querySelectorAll(".cct-card"), function (b) { b.onclick = function () { location.assign("/catalog/" + b.getAttribute("data-key")); }; });
      if (!window.__cctScroll) {
        window.__cctScroll = function () {
          if (window.scrollY + window.innerHeight < document.body.scrollHeight - 240) return;
          if (limit >= 500) return;
          limit += 20;
          paint(false);
        };
        window.addEventListener("scroll", window.__cctScroll);
      }
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
    fetch("/api/modules/custom-catalog/assets/makers.json").then(function(r){return r.json()}).then(function(j){makers=j||[]; paint(false)}).catch(function(){});
    fetch("/api/modules/custom-catalog/me", {credentials:"same-origin"}).then(function(r){return r.json()}).then(function(j){can=!!(j.data&&j.data.can_edit); paint(false)}).catch(function(){});
    load();
  }
  if (!boot()) {
    var n = 0;
    var timer = setInterval(function () { if (boot() || ++n > 40) clearInterval(timer); }, 250);
  }
})();
