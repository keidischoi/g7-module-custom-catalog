/*! custom-catalog — 헤더 메뉴 「3D 카탈로그」 */
(function () {
  "use strict";
  if (window.__cctNav) return;
  window.__cctNav = 1;
  var PATH = "/catalog";
  function path() { return location.pathname || ""; }
  function here() { var p = path(); return p === PATH || p.indexOf(PATH + "/") === 0; }
  function link(id, mobile) {
    var a = document.createElement("a");
    a.id = id;
    a.href = PATH;
    a.textContent = "3D 카탈로그";
    a.setAttribute("data-cct-nav", "1");
    if (here()) a.setAttribute("aria-current", "page");
    a.style.cssText = mobile ? "display:block;padding:10px 12px" : "display:inline-flex;align-items:center;padding:8px 10px";
    return a;
  }
  function place() {
    if (document.getElementById("cct-nav-catalog")) return;
    var desk = document.getElementById("desktop_header") || document.querySelector("header nav");
    if (desk) desk.appendChild(link("cct-nav-catalog", false));
    var mob = document.getElementById("mobile_nav_drawer") || document.getElementById("mobile_header");
    if (mob) mob.appendChild(link("cct-nav-catalog-mobile", true));
  }
  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", place);
  else place();
})();
