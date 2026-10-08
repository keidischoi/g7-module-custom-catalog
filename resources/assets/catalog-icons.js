/*! custom-catalog — 입체 그림 (업체검색 custom-companies 의 장비 · 재료 그림을 그대로 가져옴 · scripts 로 뽑은 파일, 직접 고치지 마세요) */
(function () {
  'use strict';
  if (window.CCTIcons) return;
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  /* 카탈로그에는 업체의 색 견본 목록이 없음 — 항목에 적힌 색(없으면 기본)을 씀 */
  function eqTint(e) { return /^#[0-9a-f]{6}$/i.test(e.tint || '') ? e.tint : '#3b82f6'; }
  function spSwatch(s) { return s.color_hex || '#cbd5e1'; }
  var EQ_ICON = { fdm: '🖨️', sla: '🧪', dlp: '🧪', sls: '⚗️', mjf: '⚗️', metal: '🔩', cnc: '⚙️', laser: '🔦', scanner: '📡', wash_cure: '🫧', dryer: '♨️', post: '🎨', vacuum: '🌀', injection: '💉', uv_print: '🌈', plotter: '✂️', measure: '📏', other: '🧰' };
  function spInk(sw) {
    var m = /^#([0-9a-f]{3}|[0-9a-f]{6})$/i.exec(String(sw || '')); if (!m) return '#111827';
    var h = m[1].length === 3 ? m[1].replace(/(.)/g, '$1$1') : m[1], r = parseInt(h.slice(0, 2), 16), g = parseInt(h.slice(2, 4), 16), b = parseInt(h.slice(4, 6), 16);
    return (r * 299 + g * 587 + b * 114) / 1000 > 150 ? '#111827' : '#ffffff';
  }
  function spMatKey(m) { var k = String(m || '').toLowerCase().split(/[\s\-(]/)[0]; return k === 'pla+' ? k : k.replace(/[0-9+]+$/, ''); }
  var EQ_LED = { ok: '#22c55e', busy: '#eab308', repair: '#f97316', broken: '#ef4444', off: '#64748b' };
  function eq3d(e, px) {
    px = px || 96;
    var u = 'eq' + (++SP3D), k = e.kind || 'other', led = EQ_LED[e.status] || EQ_LED.ok, tint = eqTint(e), body;
    var bc = /^#[0-9a-f]{6}$/i.test(e.body_color || '') ? e.body_color : (k === 'sla' || k === 'dlp' ? '#f97316' : k === 'sls' || k === 'mjf' || k === 'metal' ? '#e5e7eb' : '#9aa1ab');   // 0.2.16 🎨 본체 색
    var defs = '<filter id="' + u + 's"><feDropShadow dx="0" dy="1.5" stdDeviation="1.3" flood-opacity=".3"/></filter>' +
      '<linearGradient id="' + u + 'm" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="' + shade(bc, .28) + '"/><stop offset=".5" stop-color="' + bc + '"/><stop offset="1" stop-color="' + shade(bc, -.22) + '"/></linearGradient>' +
      '<linearGradient id="' + u + 'q" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#c4c9d1"/><stop offset=".5" stop-color="#9aa1ab"/><stop offset="1" stop-color="#7b828d"/></linearGradient>' +
      '<linearGradient id="' + u + 'i" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#2a2f37"/><stop offset="1" stop-color="#4b525d"/></linearGradient>' +
      '<linearGradient id="' + u + 'o" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="' + shade(tint, .35) + '"/><stop offset="1" stop-color="' + shade(tint, -.25) + '"/></linearGradient>' +
      '<linearGradient id="' + u + 'n" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#7dd3fc"/><stop offset="1" stop-color="#0369a1"/></linearGradient>';
    var ledDot = function (x, y) { return '<circle cx="' + x + '" cy="' + y + '" r="2.6" fill="' + led + '"/><circle cx="' + x + '" cy="' + y + '" r="5" fill="' + led + '" opacity=".25"/>'; };
    if (k === 'fdm' && !e.enclosed) {
      // 🔓 개방형 (베드가 앞뒤로 · 기둥 + 가로대)
      body = '<polygon points="96,92 108,85 108,97 96,104" fill="' + shade(bc, -.35) + '"/><polygon points="14,92 26,85 108,85 96,92" fill="' + shade(bc, .3) + '"/><rect x="14" y="92" width="82" height="12" rx="4" fill="url(#' + u + 'm)"/>' +
        '<rect x="20" y="95" width="12" height="6" rx="1.5" fill="url(#' + u + 'n)"/>' + ledDot(38, 98) +
        '<polygon points="26,90 76,90 90,80 40,80" fill="#e7dcc4"/><polygon points="26,90 76,90 76,92.5 26,92.5" fill="#b9ab8c"/>' +
        '<path d="M52 85 L54 74 L58 67 L62 74 L64 85Z" fill="url(#' + u + 'o)"/><path d="M58 67 L62 74 L64 85 L59 85Z" fill="#000" opacity=".15"/>' +
        '<rect x="22" y="16" width="7" height="72" rx="2" fill="url(#' + u + 'm)"/><rect x="91" y="16" width="7" height="72" rx="2" fill="url(#' + u + 'm)"/><rect x="20" y="12" width="80" height="7" rx="2.5" fill="' + shade(bc, -.25) + '"/>' +
        '<rect x="23" y="18" width="2" height="68" fill="#fff" opacity=".18"/>' +
        '<rect x="26" y="46" width="68" height="5" rx="1.5" fill="#111827"/>' +
        '<rect x="51" y="41" width="15" height="15" rx="3" fill="#252a33"/><rect x="53" y="43" width="4" height="9" rx="1" fill="#fff" opacity=".12"/><polygon points="56.5,56 60.5,56 58.5,61" fill="#9ca3af"/>' +
        '<path d="M58 41 C58 28 96 30 104 22" fill="none" stroke="#111827" stroke-width="2.4" stroke-linecap="round"/>' +
        '<rect x="98" y="20" width="10" height="3" rx="1" fill="#4b5563"/><ellipse cx="108" cy="34" rx="7" ry="14" fill="#1f2937"/><ellipse cx="107" cy="34" rx="5.2" ry="11" fill="' + shade(tint, -.2) + '"/><ellipse cx="107" cy="34" rx="2" ry="4.5" fill="#0b0f15"/>';
    } else if (k === 'fdm') {
      // 📦 챔버형 (닫힌 틀 + 유리문)
      body = '<polygon points="92,22 104,14 104,96 92,106" fill="' + shade(bc, -.35) + '"/><polygon points="20,22 32,14 104,14 92,22" fill="' + shade(bc, .3) + '"/>' +
        '<rect x="20" y="22" width="72" height="84" rx="6" fill="url(#' + u + 'm)"/><rect x="28" y="30" width="56" height="58" rx="3" fill="url(#' + u + 'i)"/>' +
        '<polygon points="84,30 92,24 92,88 84,88" fill="#000" opacity=".18"/>' +
        '<rect x="34" y="78" width="44" height="7" rx="1.5" fill="#e7dcc4"/><rect x="34" y="83" width="44" height="2.5" rx="1" fill="#b9ab8c"/>' +
        '<path d="M49 78 L51 67 L56 59 L61 67 L63 78Z" fill="url(#' + u + 'o)"/><path d="M56 59 L61 67 L63 78 L57 78Z" fill="#000" opacity=".15"/>' +
        '<rect x="28" y="38" width="56" height="5" rx="1.5" fill="#1f2937"/>' +
        '<path d="M57 36 C57 18 78 20 76 8" fill="none" stroke="#111827" stroke-width="2.6" stroke-linecap="round"/>' +
        '<rect x="49" y="33" width="15" height="15" rx="3" fill="#252a33"/><rect x="51" y="35" width="4" height="9" rx="1" fill="#fff" opacity=".12"/><polygon points="54.5,48 58.5,48 56.5,53" fill="#9ca3af"/>' +
        '<ellipse cx="105" cy="58" rx="8" ry="17" fill="#1f2937"/><ellipse cx="104" cy="58" rx="6" ry="13" fill="' + shade(tint, -.2) + '"/><ellipse cx="104" cy="58" rx="2.4" ry="5" fill="#0b0f15"/>' +
        '<rect x="20" y="92" width="72" height="14" rx="5" fill="' + shade(bc, -.12) + '"/><rect x="26" y="95" width="13" height="8" rx="2" fill="url(#' + u + 'n)"/>' + ledDot(46, 99) +
        '<path d="M24 26 L88 26" stroke="#fff" stroke-opacity=".45" stroke-width="1.2"/>' +
        '<rect x="28" y="30" width="56" height="58" rx="3" fill="#dbeafe" opacity=".22"/><rect x="28" y="30" width="56" height="58" rx="3" fill="none" stroke="#e5e7eb" stroke-width="1.4"/>' +
        '<path d="M33 84 L52 34 M42 86 L60 38" stroke="#fff" stroke-opacity=".45" stroke-width="2.2" stroke-linecap="round"/><rect x="79" y="50" width="3" height="16" rx="1.5" fill="#e5e7eb"/>';
    } else if (k === 'sla' || k === 'dlp') {
      body = '<rect x="34" y="60" width="46" height="12" rx="2" fill="#1e3a8a"/><rect x="36" y="62" width="42" height="8" rx="1.5" fill="#3b82f6"/>' +
        '<rect x="54" y="18" width="7" height="46" rx="2" fill="#4b5563"/><rect x="40" y="28" width="35" height="7" rx="2" fill="#6b7280"/><rect x="52" y="35" width="10" height="6" fill="#4b5563"/>' +
        '<polygon points="48,41 66,41 69,47 57,60 45,47" fill="#9ca3af"/><polygon points="57,41 66,41 69,47 57,60" fill="#6b7280"/>' +
        '<polygon points="90,14 102,8 102,70 90,76" fill="' + shade(bc, -.3) + '" opacity=".9"/><polygon points="24,14 36,8 102,8 90,14" fill="' + shade(bc, .35) + '"/>' +
        '<rect x="24" y="14" width="66" height="62" rx="8" fill="' + bc + '" opacity=".55"/><rect x="24" y="14" width="66" height="62" rx="8" fill="none" stroke="' + shade(bc, -.15) + '" stroke-width="2"/>' +
        '<path d="M30 20 L30 66" stroke="#fff" stroke-opacity=".45" stroke-width="2.4" stroke-linecap="round"/>' +
        '<polygon points="92,74 104,66 104,100 92,108" fill="#6b7280"/><rect x="22" y="74" width="70" height="34" rx="6" fill="url(#' + u + 'q)"/><rect x="22" y="100" width="70" height="8" rx="4" fill="#4b5563"/>' +
        '<circle cx="35" cy="88" r="6" fill="#374151"/><circle cx="34" cy="87" r="2" fill="#fff" opacity=".25"/><rect x="48" y="82" width="34" height="12" rx="3" fill="url(#' + u + 'n)"/>' + ledDot(86, 79);
    } else if (k === 'sls' || k === 'mjf' || k === 'metal') {
      var glow = k === 'metal' ? '#f97316' : k === 'mjf' ? '#38bdf8' : '#ef4444';
      body = '<polygon points="92,14 104,6 104,98 92,108" fill="' + shade(bc, -.3) + '"/><polygon points="22,14 34,6 104,6 92,14" fill="' + shade(bc, .35) + '"/>' +
        '<rect x="22" y="14" width="70" height="94" rx="6" fill="url(#' + u + 'm)"/><rect x="22" y="14" width="70" height="94" rx="6" fill="none" stroke="#cbd5e1"/>' +
        '<rect x="32" y="24" width="50" height="40" rx="5" fill="#111827"/><radialGradient id="' + u + 'g" cx=".5" cy=".7" r=".7"><stop offset="0" stop-color="' + glow + '"/><stop offset="1" stop-color="#111827" stop-opacity="0"/></radialGradient>' +
        '<rect x="32" y="24" width="50" height="40" rx="5" fill="url(#' + u + 'g)"/><rect x="38" y="52" width="38" height="6" rx="1" fill="#9ca3af"/>' +
        '<rect x="32" y="72" width="22" height="13" rx="3" fill="url(#' + u + 'n)"/>' + ledDot(64, 78) +
        '<path d="M32 94 H82 M32 99 H82" stroke="#9ca3af" stroke-width="1.6" stroke-linecap="round"/>';
    } else {
      body = '<polygon points="94,32 106,24 106,96 94,106" fill="' + shade(bc, -.35) + '"/><polygon points="18,32 30,24 106,24 94,32" fill="' + shade(bc, .35) + '"/>' +
        '<rect x="18" y="32" width="76" height="74" rx="8" fill="url(#' + u + 'm)"/><rect x="28" y="42" width="56" height="38" rx="6" fill="#0f172a"/><rect x="28" y="42" width="56" height="38" rx="6" fill="url(#' + u + 'n)" opacity=".35"/>' +
        '<text x="56" y="69" text-anchor="middle" font-size="22">' + (EQ_ICON[k] || '🧰') + '</text>' +
        '<rect x="28" y="88" width="30" height="8" rx="3" fill="#475569"/>' + ledDot(80, 92);
    }
    var badge = e.status === 'repair' || e.status === 'broken' ? '<g filter="url(#' + u + 's)"><circle cx="100" cy="102" r="12" fill="' + led + '"/><text x="100" y="107" text-anchor="middle" font-size="13">' + (e.status === 'repair' ? '🔧' : '⛔') + '</text></g>' : e.qty > 1 ? '<g filter="url(#' + u + 's)"><rect x="86" y="94" width="28" height="18" rx="9" fill="#0f172a"/><text x="100" y="107" text-anchor="middle" font-family="system-ui,sans-serif" font-weight="900" font-size="11" fill="#fff">×' + e.qty + '</text></g>' : '';
    return '<svg class="ccm-3d ccm-3d--eq' + (e.status === 'off' || e.status === 'broken' ? ' is-off' : '') + '" viewBox="0 0 120 120" width="' + px + '" height="' + px + '" aria-hidden="true"><defs>' + defs + '</defs>' +
      '<ellipse cx="62" cy="111" rx="46" ry="6" fill="#0f172a" opacity=".16"/><g filter="url(#' + u + 's)">' + body + '</g>' + badge + '</svg>';
  }
  var SP3D = 0;
  var SP_TAG = { pla: '#f97316', 'pla+': '#fb923c', petg: '#3b82f6', pet: '#0ea5e9', abs: '#f59e0b', asa: '#a855f7', tpu: '#22c55e', tpe: '#16a34a', pc: '#14b8a6', pa: '#64748b', pp: '#84cc16', pva: '#06b6d4', hips: '#eab308', peek: '#b45309', pei: '#d97706' };
  function spHex(sw) { return (/#[0-9a-f]{6}|#[0-9a-f]{3}\b/i.exec(String(sw || '')) || ['#94a3b8'])[0]; }
  function shade(hex, a) {
    var h = spHex(hex).slice(1); if (h.length === 3) h = h.replace(/(.)/g, '$1$1');
    var c = [0, 2, 4].map(function (i) { var v = parseInt(h.slice(i, i + 2), 16); return Math.round(a >= 0 ? v + (255 - v) * a : v * (1 + a)); });
    return '#' + c.map(function (v) { return ('0' + Math.max(0, Math.min(255, v)).toString(16)).slice(-2); }).join('');
  }
  function spShort(s) { var w = String(s.material || '').split(/[\s(]/)[0]; return (w.length > 6 ? w.slice(0, 6) : w).toUpperCase(); }
  function spPct(s) { return s.status === 'empty' ? 0 : Math.max(0, Math.min(100, s.remain_pct == null ? 100 : +s.remain_pct)) / 100; }
  function spFill(sw, u, dir) {
    var multi = /gradient/.test(String(sw || '')), hex = spHex(sw);
    if (!multi) return { fill: hex, hex: hex, defs: '' };
    var cols = String(sw).match(/#[0-9a-f]{6}|#[0-9a-f]{3}\b/gi) || [hex];
    if (/conic/.test(sw) || cols.length < 2) cols = ['#ef4444', '#f59e0b', '#22c55e', '#3b82f6', '#8b5cf6'];
    return { fill: 'url(#' + u + 'r)', hex: cols[0], defs: '<linearGradient id="' + u + 'r" ' + (dir || 'x1="0" y1="0" x2="1" y2="1"') + '>' + cols.map(function (c, i) { return '<stop offset="' + (i / Math.max(1, cols.length - 1)) + '" stop-color="' + c + '"/>'; }).join('') + '</linearGradient>' };
  }
  function sp3d(s, px, noTag) {
    if (s.kind === 'resin') return sp3dBottle(s, px);
    if (s.kind === 'powder') return sp3dJar(s, px);
    return sp3dSpool(s, px, noTag);
  }
  function sp3dSpool(s, px, noTag) {
    px = px || 96;
    var u = 'sp' + (++SP3D), p = spPct(s), f = spFill(spSwatch(s), u, 'x1="0" y1="0" x2="1" y2="1"'), hex = f.hex;
    var lite = shade(hex, .55), mid = shade(hex, .15), dark = shade(hex, -.4), F = [50, 63], B = [68, 49], R = 44;
    var rc = p > 0 ? 21 + 19 * p : 13;
    var tag = s.status === 'empty' ? '#94a3b8' : (SP_TAG[spMatKey(s.material)] || '#ef4444'), txt = spShort(s) || 'FIL';
    var coil = f.defs ? f.fill : 'url(#' + u + 'c)', rings = '';
    if (p > 0) for (var r = 15; r < rc - .5; r += 2.4) rings += '<circle cx="' + F[0] + '" cy="' + F[1] + '" r="' + r.toFixed(1) + '" fill="none" stroke="' + dark + '" stroke-opacity=".32" stroke-width=".8"/>';
    var spokes = [0, 1, 2, 3, 4].map(function (k) { return '<rect x="' + (F[0] - 3.6) + '" y="' + (F[1] - 41) + '" width="7.2" height="29" rx="2.4" fill="url(#' + u + 'k)" transform="rotate(' + (k * 72 + 18) + ' ' + F[0] + ' ' + F[1] + ')"/>'; }).join('');
    return '<svg class="ccm-3d" viewBox="0 0 120 120" width="' + px + '" height="' + px + '" aria-hidden="true"><defs>' + f.defs +
      '<linearGradient id="' + u + 'c" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="' + lite + '"/><stop offset=".45" stop-color="' + mid + '"/><stop offset="1" stop-color="' + dark + '"/></linearGradient>' +
      '<linearGradient id="' + u + 'e" gradientUnits="userSpaceOnUse" x1="0" y1="15" x2="0" y2="110"><stop offset="0" stop-color="' + lite + '"/><stop offset=".5" stop-color="' + hex + '"/><stop offset="1" stop-color="' + dark + '"/></linearGradient>' +
      '<pattern id="' + u + 'p" width="2.6" height="2.6" patternUnits="userSpaceOnUse" patternTransform="rotate(-38)"><rect width="2.6" height="1.1" fill="' + dark + '" fill-opacity=".28"/></pattern>' +
      '<linearGradient id="' + u + 'k" x1="0" y1="0" x2="1" y2="0"><stop offset="0" stop-color="#4b5563"/><stop offset="1" stop-color="#1f2937"/></linearGradient>' +
      '<radialGradient id="' + u + 'b" cx=".4" cy=".3" r=".8"><stop offset="0" stop-color="#4b5563"/><stop offset="1" stop-color="#111827"/></radialGradient>' +
      '<radialGradient id="' + u + 'h" cx=".5" cy=".5" r=".5"><stop offset="0" stop-color="#000"/><stop offset="1" stop-color="#374151"/></radialGradient>' +
      '<linearGradient id="' + u + 't" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="' + shade(tag, .25) + '"/><stop offset="1" stop-color="' + shade(tag, -.15) + '"/></linearGradient>' +
      '<filter id="' + u + 's" x="-20%" y="-20%" width="140%" height="160%"><feDropShadow dx="0" dy="2" stdDeviation="1.6" flood-opacity=".35"/></filter></defs>' +
      '<ellipse cx="60" cy="111" rx="44" ry="5.5" fill="#0f172a" opacity=".16"/>' +
      '<circle cx="' + B[0] + '" cy="' + B[1] + '" r="' + R + '" fill="url(#' + u + 'b)"/>' +
      (p > 0 ? '<line x1="' + B[0] + '" y1="' + B[1] + '" x2="' + F[0] + '" y2="' + F[1] + '" stroke="' + (f.defs ? f.fill : 'url(#' + u + 'e)') + '" stroke-width="' + (rc * 2).toFixed(1) + '" stroke-linecap="round"/>' +
        '<line x1="' + B[0] + '" y1="' + B[1] + '" x2="' + F[0] + '" y2="' + F[1] + '" stroke="url(#' + u + 'p)" stroke-width="' + (rc * 2).toFixed(1) + '" stroke-linecap="round"/>' : '') +
      '<circle cx="' + F[0] + '" cy="' + F[1] + '" r="' + (R - 3) + '" fill="#0b0f15"/>' +
      (p > 0 ? '<circle cx="' + F[0] + '" cy="' + F[1] + '" r="' + rc.toFixed(1) + '" fill="' + coil + '"/>' + rings +
        '<path d="M' + (F[0] - rc * .7).toFixed(1) + ' ' + (F[1] - rc * .7).toFixed(1) + ' A' + rc.toFixed(1) + ' ' + rc.toFixed(1) + ' 0 0 1 ' + (F[0] + rc * .5).toFixed(1) + ' ' + (F[1] - rc * .86).toFixed(1) + '" fill="none" stroke="#fff" stroke-opacity=".55" stroke-width="2" stroke-linecap="round"/>' : '') +
      '<circle cx="' + F[0] + '" cy="' + F[1] + '" r="' + (R - 3) + '" fill="#94a3b8" fill-opacity=".08"/>' +
      spokes +
      '<circle cx="' + (F[0] + 2.4) + '" cy="' + (F[1] - 1.8) + '" r="' + (R - 2.5) + '" fill="none" stroke="#0b0f15" stroke-width="5"/>' +
      '<circle cx="' + F[0] + '" cy="' + F[1] + '" r="' + (R - 2.5) + '" fill="none" stroke="url(#' + u + 'k)" stroke-width="5"/>' +
      '<path d="M' + (F[0] - 38) + ' ' + (F[1] - 16) + ' A' + (R - 2.5) + ' ' + (R - 2.5) + ' 0 0 1 ' + (F[0] + 14) + ' ' + (F[1] - 39) + '" fill="none" stroke="#fff" stroke-opacity=".3" stroke-width="1.6" stroke-linecap="round"/>' +
      '<circle cx="' + F[0] + '" cy="' + F[1] + '" r="13" fill="#2b313c" stroke="#4b5563" stroke-width="1.2"/><circle cx="' + F[0] + '" cy="' + F[1] + '" r="8" fill="url(#' + u + 'h)"/>' +
      (p > 0 ? '<path d="M12 84 C2 96 6 111 19 106" fill="none" stroke="' + dark + '" stroke-width="5" stroke-linecap="round"/><path d="M12 84 C2 96 6 111 19 106" fill="none" stroke="' + hex + '" stroke-width="3.4" stroke-linecap="round"/><path d="M11 86 C4 96 7 106 15 105" fill="none" stroke="' + lite + '" stroke-width="1.1" stroke-linecap="round" opacity=".85"/>' : '') +
      (noTag ? '' : '<g filter="url(#' + u + 's)"><rect x="64" y="80" width="50" height="26" rx="7" fill="url(#' + u + 't)" stroke="#fff" stroke-opacity=".55" stroke-width="1.2"/>' +
      '<text x="89" y="98" text-anchor="middle" font-family="system-ui,\'Malgun Gothic\',sans-serif" font-weight="900" font-size="' + (txt.length > 4 ? 10.5 : 14) + '" fill="#fff" stroke="' + shade(tag, -.35) + '" stroke-width=".6" paint-order="stroke">' + esc(txt) + '</text></g>') + '</svg>';
  }
  function sp3dBottle(s, px) {
    px = px || 96;
    var u = 'sb' + (++SP3D), p = spPct(s), f = spFill(spSwatch(s), u), hex = f.hex, tag = s.status === 'empty' ? '#94a3b8' : '#f97316', lv = 100 - 64 * p;
    return '<svg class="ccm-3d" viewBox="0 0 120 120" width="' + px + '" height="' + px + '" aria-hidden="true"><defs>' + f.defs +
      '<linearGradient id="' + u + 'g" x1="0" y1="0" x2="1" y2="0"><stop offset="0" stop-color="' + shade(hex, .3) + '"/><stop offset=".4" stop-color="' + hex + '"/><stop offset="1" stop-color="' + shade(hex, -.5) + '"/></linearGradient>' +
      '<linearGradient id="' + u + 'c" x1="0" y1="0" x2="1" y2="0"><stop offset="0" stop-color="' + shade(hex, .35) + '"/><stop offset=".6" stop-color="' + hex + '"/><stop offset="1" stop-color="' + shade(hex, -.4) + '"/></linearGradient>' +
      '<linearGradient id="' + u + 't" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="' + shade(tag, .2) + '"/><stop offset="1" stop-color="' + shade(tag, -.15) + '"/></linearGradient>' +
      '<filter id="' + u + 's"><feDropShadow dx="0" dy="1.5" stdDeviation="1.2" flood-opacity=".3"/></filter></defs>' +
      '<ellipse cx="60" cy="111" rx="32" ry="5" fill="#0f172a" opacity=".16"/>' +
      '<path d="M32 44 Q32 30 46 28 L74 28 Q88 30 88 44 L88 100 Q88 108 80 108 L40 108 Q32 108 32 100Z" fill="' + shade(hex, .55) + '" fill-opacity=".75" stroke="' + shade(hex, -.25) + '" stroke-width="1.4"/>' +
      (p > 0 ? '<clipPath id="' + u + 'k"><path d="M32 44 Q32 30 46 28 L74 28 Q88 30 88 44 L88 100 Q88 108 80 108 L40 108 Q32 108 32 100Z"/></clipPath><rect clip-path="url(#' + u + 'k)" x="30" y="' + (108 - 78 * p).toFixed(1) + '" width="60" height="80" fill="' + (f.defs ? f.fill : 'url(#' + u + 'g)') + '"/>' : '') +
      '<rect x="46" y="18" width="28" height="12" rx="3" fill="' + shade(hex, -.2) + '"/><rect x="44" y="5" width="32" height="15" rx="5" fill="url(#' + u + 'c)"/>' +
      '<rect x="49" y="7" width="5" height="11" rx="2" fill="#fff" opacity=".25"/>' +
      '<g filter="url(#' + u + 's)"><rect x="48" y="48" width="34" height="44" rx="7" fill="url(#' + u + 't)"/>' +
      '<text x="65" y="63" text-anchor="middle" font-family="system-ui,sans-serif" font-weight="900" font-size="11" fill="#fff">RESIN</text><circle cx="65" cy="79" r="8" fill="' + f.fill + '" stroke="#fff" stroke-width="2"/>' +
      '</g>' +
      '<path d="M78 34 Q84 38 84 48 L84 96" fill="none" stroke="#fff" stroke-opacity=".18" stroke-width="2.5" stroke-linecap="round"/></svg>';
  }
  function sp3dJar(s, px) {
    px = px || 96;
    var u = 'sj' + (++SP3D), p = spPct(s), f = spFill(spSwatch(s), u), hex = f.hex;
    return '<svg class="ccm-3d" viewBox="0 0 120 120" width="' + px + '" height="' + px + '" aria-hidden="true"><defs>' + f.defs +
      '<linearGradient id="' + u + 'g" x1="0" y1="0" x2="1" y2="0"><stop offset="0" stop-color="#e2e8f0"/><stop offset=".4" stop-color="#f8fafc"/><stop offset="1" stop-color="#94a3b8"/></linearGradient>' +
      '<linearGradient id="' + u + 'l" x1="0" y1="0" x2="1" y2="0"><stop offset="0" stop-color="#475569"/><stop offset="1" stop-color="#1e293b"/></linearGradient></defs>' +
      '<ellipse cx="60" cy="110" rx="40" ry="6" fill="#0f172a" opacity=".16"/>' +
      '<path d="M24 34 L24 98 A36 10 0 0 0 96 98 L96 34Z" fill="url(#' + u + 'g)"/>' +
      (p > 0 ? '<path d="M27 ' + (98 - 58 * p).toFixed(1) + ' L27 98 A33 8 0 0 0 93 98 L93 ' + (98 - 58 * p).toFixed(1) + 'Z" fill="' + f.fill + '" opacity=".85"/>' : '') +
      '<path d="M22 22 L22 34 A38 10 0 0 0 98 34 L98 22Z" fill="url(#' + u + 'l)"/><ellipse cx="60" cy="22" rx="38" ry="10" fill="#64748b"/><ellipse cx="60" cy="21" rx="30" ry="6.5" fill="#475569"/>' +
      '<rect x="38" y="58" width="44" height="24" rx="6" fill="#0f172a" opacity=".85"/><text x="60" y="75" text-anchor="middle" font-family="system-ui,sans-serif" font-weight="900" font-size="11" fill="#fff">' + esc(spShort(s) || 'POWDER') + '</text></svg>';
  }
  window.CCTIcons = {
    /** 장비 그림: {kind, enclosed, tint} */
    equipment: function (e, px) { return eq3d({ kind: e.kind, enclosed: !!e.enclosed, status: 'ok', tint: e.tint, qty: 1 }, px || 120); },
    /** 재료 그림: {kind(fdm|resin|powder), material, color_hex} */
    material: function (m, px) { return sp3d({ kind: m.kind, material: m.material, color_hex: m.color_hex, remain_pct: 100, status: 'new' }, px || 120); }
  };
})();
