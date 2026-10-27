/*! custom-catalog 0.3.23 — 헤더 메인 메뉴 「3D 카탈로그」
 * 0.3.23 G7Core.router 는 없음 → 늘 location.href 로 빠져 페이지 전체가 다시 그려졌음(번쩍). 코어 navigate 로 */
(function () {
  "use strict";
  if (window.__cctNav) { try { window.__cctNav(); } catch (e) {} return; }
  var PATH = "/catalog";
  var DESK = "cct-nav-catalog";
  var MOB = "cct-nav-catalog-mobile";
  // 0.2.10 메뉴 이름 — 이 스크립트 주소의 l= (모듈 설정 「메뉴 이름」), 없으면 기본 이름
  var LABEL = "3D 카탈로그";
  try { var cur = document.currentScript, ql = cur && cur.src ? new URL(cur.src, location.href).searchParams.get("l") : ""; if (ql) LABEL = ql; } catch (eL) {}
  function path() { return location.pathname || ""; }
  function here() { var p = path(); return p === PATH || p.indexOf(PATH + "/") === 0; }
  function text(el) { return el ? String(el.textContent || "").replace(/\s+/g, "") : ""; }
  function header() { return document.getElementById("desktop_header") || document.querySelector("header"); }
  function find(label) {
    var root = header() || document;
    var nodes = root.querySelectorAll("a, button");
    for (var i = 0; i < nodes.length; i++) {
      var n = nodes[i];
      if (n.id === DESK || n.id === MOB) continue;
      if (text(n) === label) return n;
    }
    return null;
  }
  function make(sib, id) {
    var el = document.createElement("a");
    el.id = id;
    el.href = PATH;
    el.setAttribute("data-cext-nav", "1");
    el.setAttribute("data-slug", "catalog");
    el.textContent = LABEL;
    if (sib && sib.getAttribute("class")) el.setAttribute("class", sib.getAttribute("class"));
    if (here()) el.setAttribute("aria-current", "page");
    el.addEventListener("click", function (e) {
      if (e.metaKey || e.ctrlKey || e.shiftKey) return;
      e.preventDefault();
      try { if (window.G7Core && typeof G7Core.dispatch === "function") { G7Core.dispatch({ handler: "navigate", params: { path: PATH } }); return; } } catch (err) {}
      location.href = PATH;
    });
    return el;
  }
  function place() {
    var sib = find("업체검색") || find("홈") || find("쇼핑") || find("게시판");
    if (sib && sib.parentNode && !document.getElementById(DESK)) sib.parentNode.insertBefore(make(sib, DESK), sib.nextSibling);
    var mob = document.getElementById("mobile_nav_drawer") || document.getElementById("mobile_header");
    if (mob && !document.getElementById(MOB)) mob.appendChild(make(null, MOB));
  }
  window.__cctNav = place;
  place();
  setTimeout(place, 300);
  setTimeout(place, 1200);
})();
