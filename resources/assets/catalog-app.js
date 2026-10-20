/*! custom-catalog 0.3.0 — 3D 카탈로그 (목록 · 상세 · 편집 · 🔩 부품 호환표) */
(function () {
  'use strict';
  if (window.CCT) { try { window.CCT.tick(); } catch (e) {} return; }
  var VERSION = '0.3.2', API = '/api/modules/custom-catalog', BASE = '/catalog';

  /* ───────── 도구 ───────── */
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function token() {
    try { if (window.G7Core && G7Core.api && typeof G7Core.api.getToken === 'function') { var t = G7Core.api.getToken(); if (t) return String(t); } } catch (e) {}
    try { return localStorage.getItem('auth_token') || sessionStorage.getItem('auth_token') || ''; } catch (e) { return ''; }
  }
  function api(method, path, body) {
    var h = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, t = token(), form = typeof FormData !== 'undefined' && body instanceof FormData;
    if (t) h.Authorization = 'Bearer ' + String(t).replace(/^"+|"+$/g, '');
    if (body && !form) h['Content-Type'] = 'application/json';
    return fetch(API + path, { method: method, headers: h, credentials: 'same-origin', body: body ? (form ? body : JSON.stringify(body)) : undefined }).then(function (r) {
      return r.json().catch(function () { return {}; }).then(function (j) {
        if (!r.ok || j.success === false) { var e = new Error(j.message || (r.status === 401 ? '로그인이 필요해요.' : '요청을 처리하지 못했어요 (' + r.status + ')')); e.status = r.status; e.data = j.data; throw e; }
        if (j.message) api.last = j.message;
        return j.data;
      });
    });
  }
  function toast(msg) {
    var old = document.querySelector('.cct-toast'); if (old) old.remove();
    var el = document.createElement('div'); el.className = 'cct cct-toast'; el.textContent = msg; document.body.appendChild(el);
    setTimeout(function () { el.remove(); }, 3200);
  }
  function css() {
    if (document.getElementById('cct-css')) return;
    var l = document.createElement('link'); l.id = 'cct-css'; l.rel = 'stylesheet'; l.href = API + '/assets/catalog.css?v=' + VERSION; document.head.appendChild(l);
  }
  function go(url) {
    try { if (window.G7Core && G7Core.router && G7Core.router.push) { G7Core.router.push(url); setTimeout(tick, 60); return; } } catch (e) {}
    try { history.pushState({}, '', url); tick(); } catch (e2) { location.href = url; }
  }
  /* 사진이 없을 때 그림 — 업체검색과 같은 입체 그림 (catalog-icons.js) */
  var iconsP = null;
  function icons() {
    if (window.CCTIcons) return Promise.resolve();
    if (!iconsP) iconsP = new Promise(function (ok) {
      var s = document.createElement('script'); s.src = API + '/assets/catalog-icons.js?v=' + VERSION; s.onload = s.onerror = function () { ok(); };
      document.head.appendChild(s); setTimeout(ok, 2500);
    });
    return iconsP;
  }
  function hueHex(h) {
    var s = 0.62, l = 0.55, a = s * Math.min(l, 1 - l), f = function (n) { var k = (n + h / 30) % 12, c = l - a * Math.max(Math.min(k - 3, 9 - k, 1), -1); return ('0' + Math.round(255 * c).toString(16)).slice(-2); };
    return '#' + f(0) + f(8) + f(4);
  }
  function art(c, px) {
    var I = window.CCTIcons;
    if (!I) return '<i>' + kindIcon(c.type, c.kind) + '</i>';
    if (c.type === 'parts') return partArt(c, px);   // 0.3.0
    try {
      if (c.type === 'materials') return I.material({ kind: c.kind, material: c.material || c.title, color_hex: /^#[0-9a-f]{3,6}$/i.test(c.color_hex || '') || /gradient/.test(c.color_hex || '') ? c.color_hex : hueHex(hue(c.brand + c.title)) }, px);
      return I.equipment({ kind: c.kind, enclosed: c.enclosed, tint: hueHex(hue(c.brand)) }, px);
    } catch (e) { return '<i>' + kindIcon(c.type, c.kind) + '</i>'; }
  }
  /** 0.3.0 🔩 부품 그림 — 종류 색 타일 + 아이콘 */
  var PART_HUE = { nozzle: 28, hotend: 8, heater: 352, extruder: 215, bed: 262, motion: 190, motor: 172, cooling: 198, board: 140, display: 230, electronics: 150, enclosure: 42, resin_part: 285, multi: 320, tool: 0, other: 225 };
  function partArt(c, px) {
    px = px || 118; var h = PART_HUE[c.kind]; if (h == null) h = 225;
    return '<span class="cct-partart" style="--h:' + h + ';width:' + px + 'px;height:' + px + 'px;font-size:' + Math.round(px * 0.46) + 'px">' + kindIcon('parts', c.kind) + '</span>';
  }
  function hue(s) { var h = 0; s = String(s || ''); for (var i = 0; i < s.length; i++) h = (h * 31 + s.charCodeAt(i)) % 360; return h; }
  var META = null, metaP = null;
  function meta(force) {
    if (META && !force) return Promise.resolve(META);
    if (!metaP || force) metaP = api('GET', '/meta').then(function (m) { META = m; return m; }).catch(function (e) { metaP = null; throw e; });
    return metaP;
  }
  function kindIcon(type, kind) { var k = ((META && META.kinds[type]) || []).filter(function (x) { return x.key === kind; })[0]; return k ? k.icon : '🧊'; }
  function tabOf(key) { return ((META && META.tabs) || []).filter(function (t) { return t.key === key; })[0] || null; }

  /* 제조사 로고 (meta.logos: 정리한 이름 → 주소) — 서버 CatalogService::norm 과 같은 정리 */
  function normBrand(s) { return String(s || '').trim().toLowerCase().replace(/[\s\-_.·\/]+/g, ''); }
  function logoOf(brand) { return (META && META.logos && META.logos[normBrand(brand)]) || ''; }
  /* 로고가 없으면 머리글자 배지 (제조사마다 색) */
  function logoImg(brand, cls) {
    var u = logoOf(brand), c = 'cct-logo' + (cls ? ' ' + cls : '');
    if (u) return '<img class="' + c + '" alt="" loading="lazy" src="' + esc(u) + '">';
    var ch = (String(brand || '').replace(/^[^0-9A-Za-z가-힣]+/, '').charAt(0) || '?').toUpperCase();
    return '<span class="' + c + ' cct-logo--mono" style="--h:' + hue(brand) + '" aria-hidden="true">' + esc(ch) + '</span>';
  }

  /* ───────── 카드 ───────── */
  function cardHtml(c, can) {
    var pic = c.image ? '<img loading="lazy" alt="" src="' + esc(c.image) + '">' :
      '<div class="cct-ph">' + art(c, 118) + '</div>';
    return '<a class="cct-card' + (c.status !== 'active' ? ' is-off' : '') + '" href="' + BASE + '/' + esc(c.key) + '" data-key="' + esc(c.key) + '">' +
      '<div class="cct-pic">' + pic + '<span class="cct-kind">' + esc(c.kind_label) + '</span></div>' +
      '<div class="cct-card__body"><span class="cct-card__brand">' + logoImg(c.brand) + esc(c.brand) + '</span><span class="cct-card__title">' +
      (c.color_hex && /^#|^rgb|^linear/.test(c.color_hex) ? '<i class="cct-dot" style="background:' + esc(c.color_hex) + '"></i>' : '') + esc(c.title) + '</span>' +
      (c.chips.length ? '<div class="cct-tags">' + c.chips.map(function (x) { return '<span>' + esc(x) + '</span>'; }).join('') + '</div>' : '') + '</div>' +
      (can ? '<button type="button" class="cct-card__edit" data-edit="' + esc(c.key) + '" title="수정" aria-label="수정">✏️</button>' : '') + '</a>';
  }
  function bindCards(root, reload) {
    root.addEventListener('click', function (e) {
      var ed = e.target.closest && e.target.closest('[data-edit]');
      if (ed) { e.preventDefault(); e.stopPropagation(); openEditor({ key: ed.getAttribute('data-edit') }, reload); return; }
      var a = e.target.closest && e.target.closest('a.cct-card, a[data-go]');
      if (!a || e.metaKey || e.ctrlKey || e.shiftKey || e.button) return;
      e.preventDefault(); go(a.getAttribute('href'));
    });
  }

  /* ───────── 목록 ───────── */
  function readState() {
    var p = new URLSearchParams(location.search);
    return { tab: p.get('tab') || '', brand: p.get('brand') || '', q: (p.get('q') || '').trim(), sort: p.get('sort') || '', flags: (p.get('flags') || '').split(',').filter(Boolean), status: p.get('status') || '',
      printer: p.get('printer') || '', pname: p.get('pname') || '', mine: p.get('mine') || '' };   // 0.3.0 🔩 이 프린터에 맞는 부품
  }
  function urlOf(s) {
    var p = new URLSearchParams();
    ['tab', 'brand', 'q', 'sort', 'status', 'printer', 'pname', 'mine'].forEach(function (k) { if (s[k]) p.set(k, s[k]); });
    if (s.flags && s.flags.length) p.set('flags', s.flags.join(','));
    var qs = p.toString();
    return BASE + (qs ? '?' + qs : '');
  }
  function record(term) {
    if (!term || term.length > 40) return;
    try { if (typeof window.__cpsRecord === 'function') { window.__cpsRecord(term, 'catalog'); return; } } catch (e) {}
    try { fetch('/api/plugins/custom-popular_search/record', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify({ scope: 'catalog', term: term }) }).catch(function () {}); } catch (e2) {}
  }
  // 가로로 넘치는 줄에 작은 ‹ › 스크롤 버튼을 붙여요.
  function scroller(el) {
    if (!el || el.parentNode.classList.contains('cct-scr')) return;
    var w = document.createElement('div'); w.className = 'cct-scr' + (el.classList.contains('cct-brands') ? ' cct-scr--brands' : '');
    el.parentNode.insertBefore(w, el); w.appendChild(el);
    w.insertAdjacentHTML('afterbegin', '<button type="button" class="cct-scr__b cct-scr__b--l" aria-label="왼쪽으로 넘기기">‹</button>');
    w.insertAdjacentHTML('beforeend', '<button type="button" class="cct-scr__b cct-scr__b--r" aria-label="오른쪽으로 넘기기">›</button>');
    var l = w.firstChild, r = w.lastChild;
    var upd = function () { var max = el.scrollWidth - el.clientWidth; w.classList.toggle('can-l', el.scrollLeft > 2); w.classList.toggle('can-r', el.scrollLeft < max - 2); };
    var by = function (d) { el.scrollBy({ left: d * Math.max(160, el.clientWidth * 0.7), behavior: 'smooth' }); };
    l.onclick = function () { by(-1); }; r.onclick = function () { by(1); };
    el.addEventListener('scroll', upd, { passive: true });
    if (window.ResizeObserver) new ResizeObserver(upd).observe(el); else window.addEventListener('resize', upd);
    var on = el.querySelector('.on'); if (on && on.offsetLeft + on.offsetWidth > el.clientWidth) el.scrollLeft = on.offsetLeft - 40;
    upd(); setTimeout(upd, 300);
  }
  function listPage(root) {
    var S = readState(), can = !!META.can_edit;
    if (!S.q && !tabOf(S.tab)) S.tab = (META.tabs[0] || {}).key || '';
    var tab = tabOf(S.tab), total = 0;
    Object.keys(META.counts).forEach(function (k) { total += META.counts[k]; });
    var eq = 0, mt = 0, pt = 0; META.tabs.forEach(function (t) { if (t.type === 'materials') mt += META.counts[t.key] || 0; else if (t.type === 'parts') pt += META.counts[t.key] || 0; else eq += META.counts[t.key] || 0; });
    root.innerHTML = '<div class="cct"><div class="cct-hero"><div class="cct-hero__grid"></div><h1>🧊 3D 카탈로그</h1><p>3D 프린터 · 장비 · 필라멘트 · 레진의 제원을 한곳에서 찾아보세요.</p>' +
      '<form class="cct-search" data-search><input type="search" name="q" autocomplete="off" placeholder="제조사 · 모델 · 재료 이름으로 찾기 (예: Bambu P1S, PETG)" value="' + esc(S.q) + '"><button type="submit">찾기</button></form>' +
      '<div class="cct-stats"><span>장비 ' + eq.toLocaleString() + '종</span><span>재료 ' + mt.toLocaleString() + '종</span><span>부품 ' + pt.toLocaleString() + '종</span>' + (can ? '<span>✏️ 편집할 수 있어요</span>' : '') + '</div></div>' +
      '<div class="cct-tabs" role="tablist">' + META.tabs.map(function (t) {
        return '<button type="button" role="tab" class="cct-tab' + (tab && t.key === tab.key ? ' on' : '') + '" data-tab="' + t.key + '"><i>' + t.icon + '</i>' + esc(t.label) + '<small>' + (META.counts[t.key] || 0) + '</small></button>';
      }).join('') + '</div><div data-bar></div><div class="cct-count" data-count></div><div class="cct-grid" data-grid>' + new Array(9).join('<div class="cct-skel"></div>') + '</div><div class="cct-more" data-more></div></div>';
    var box = root.querySelector('.cct'), grid = box.querySelector('[data-grid]'), bar = box.querySelector('[data-bar]'), more = box.querySelector('[data-more]'), cnt = box.querySelector('[data-count]');
    var nav = function (patch) { var n = {}; Object.keys(S).forEach(function (k) { n[k] = S[k]; }); Object.keys(patch).forEach(function (k) { n[k] = patch[k]; }); go(urlOf(n)); };
    box.querySelector('[data-search]').onsubmit = function (e) { e.preventDefault(); var q = this.q.value.trim(); if (q) record(q); nav({ q: q, brand: '' }); };
    Array.prototype.forEach.call(box.querySelectorAll('[data-tab]'), function (b) { b.onclick = function () { nav({ tab: b.getAttribute('data-tab'), brand: '', flags: [], q: '' }); }; });
    scroller(box.querySelector('.cct-tabs'));
    bindCards(box, function () { meta(true).then(function () { listPage(root); }); });

    var types = S.q && !tab ? ['equipment', 'materials', 'parts'] : [tab ? tab.type : 'equipment'], page = 1, pages = 1, shown = 0, sum = 0;
    var query = function (type, pg) {
      var p = new URLSearchParams({ page: pg, per: META.per_page });
      if (tab) p.set('tab', tab.key); else p.set('type', type);
      ['brand', 'q', 'sort', 'status'].forEach(function (k) { if (S[k]) p.set(k, S[k]); });
      if (S.flags.length) p.set('flags', S.flags.join(','));
      if (tab && tab.type === 'parts') { if (S.mine) p.set('mine', '1'); else if (S.printer) p.set('printer', S.printer); }
      return api('GET', '/items?' + p.toString());
    };
    var drawBar = function (brands) {
      var isPrinter = tab && tab.type === 'equipment' && ['fdm', 'resin_printer', 'industrial'].indexOf(tab.key) >= 0, flag = function (k, label) {
        return '<button type="button" class="cct-chip' + (S.flags.indexOf(k) >= 0 ? ' on' : '') + '" data-flag="' + k + '">' + label + '</button>'; };
      bar.innerHTML = (brands.length > 1 || S.brand ? '<div class="cct-bar"><div class="cct-brands" data-brands><button type="button" class="cct-chip' + (S.brand ? '' : ' on') + '" data-brand="">전체</button>' +
        brands.map(function (b) { return '<button type="button" class="cct-chip' + (S.brand === b.name ? ' on' : '') + '" data-brand="' + esc(b.name) + '">' + logoImg(b.name) + esc(b.name) + ' <small>' + b.n + '</small></button>'; }).join('') +
        '</div></div>' : '') +
        (tab && tab.type === 'parts' ? '<div class="cct-bar cct-pbar">' + (META.login ? '<button type="button" class="cct-chip' + (S.mine ? ' on' : '') + '" data-mine>🖨️ 내 프린터용</button>' : '') +
          (S.printer ? '<button type="button" class="cct-chip on" data-pclear>🖨️ ' + esc(S.pname || S.printer) + ' 에 맞는 것 ✕</button>' : '<span class="cct-pick"><input class="cct-in" data-pq placeholder="🔎 프린터 이름으로 거르기 (예: P1S · MK4)" autocomplete="off"><span class="cct-pick__list" data-plist hidden></span></span>') +
          (META.login && !can && META.parts_member ? '<span style="flex:1"></span><button type="button" class="cct-btn cct-btn--s cct-btn--p" data-propose>💡 부품 제안</button>' : '') + '</div>' : '') +   // 0.3.0
        '<div class="cct-bar">' + (tab && tab.key === 'fdm' ? flag('multicolor', '🌈 다색') : '') + (isPrinter ? flag('enclosed', '📦 밀폐형') : '') + flag('photo', '🖼️ 사진 있는 것') +
        (S.q ? '<button type="button" class="cct-chip on" data-clear-q>「' + esc(S.q) + '」 ✕</button>' : '') +
        '<span style="flex:1"></span>' + (can ? '<button type="button" class="cct-chip' + (S.status === 'archived' ? ' on' : '') + '" data-arch>🗄️ 보관한 것</button><button type="button" class="cct-btn cct-btn--s cct-btn--p" data-new>➕ 새 항목</button>' : '') +
        '<select class="cct-sel" data-sort aria-label="정렬"><option value="">사진 있는 것부터</option><option value="name"' + (S.sort === 'name' ? ' selected' : '') + '>이름순</option><option value="new"' + (S.sort === 'new' ? ' selected' : '') + '>새로 들어온 순</option>' +
        (tab && tab.type === 'equipment' ? '<option value="size"' + (S.sort === 'size' ? ' selected' : '') + '>출력 크기 큰 순</option>' : '') + '<option value="updated"' + (S.sort === 'updated' ? ' selected' : '') + '>최근 고친 순</option></select></div>';
      Array.prototype.forEach.call(bar.querySelectorAll('[data-brand]'), function (b) { b.onclick = function () { nav({ brand: b.getAttribute('data-brand') }); }; });
      Array.prototype.forEach.call(bar.querySelectorAll('[data-flag]'), function (b) { b.onclick = function () { var k = b.getAttribute('data-flag'), f = S.flags.slice(), i = f.indexOf(k); if (i >= 0) f.splice(i, 1); else f.push(k); nav({ flags: f }); }; });
      scroller(bar.querySelector('[data-brands]'));
      var cq = bar.querySelector('[data-clear-q]'); if (cq) cq.onclick = function () { nav({ q: '', tab: S.tab || (META.tabs[0] || {}).key }); };
      var ar = bar.querySelector('[data-arch]'); if (ar) ar.onclick = function () { nav({ status: S.status === 'archived' ? '' : 'archived' }); };
      var nw = bar.querySelector('[data-new]'); if (nw) nw.onclick = function () { openEditor({ type: tab ? tab.type : 'equipment', kind: tab ? tab.kinds[0] : 'fdm' }, function (key) { meta(true); go(BASE + '/' + key); }); };
      bar.querySelector('[data-sort]').onchange = function () { nav({ sort: this.value }); };
      // 0.3.0 🔩 프린터로 거르기 · 내 프린터용 · 부품 제안
      var mn = bar.querySelector('[data-mine]'); if (mn) mn.onclick = function () { nav({ mine: S.mine ? '' : '1', printer: '', pname: '' }); };
      var pc = bar.querySelector('[data-pclear]'); if (pc) pc.onclick = function () { nav({ printer: '', pname: '' }); };
      var pq = bar.querySelector('[data-pq]'); if (pq) picker(pq, bar.querySelector('[data-plist]'), function (p) { nav({ printer: p.key, pname: p.title, mine: '' }); });
      var pp = bar.querySelector('[data-propose]'); if (pp) pp.onclick = function () { proposePart(); };
    };
    var load = function (append) {
      Promise.all(types.map(function (t) { return query(t, page); })).then(function (res) {
        if (!document.body.contains(grid)) return;
        var items = [], brands = []; sum = 0; pages = 1;
        res.forEach(function (r) { items = items.concat(r.items); sum += r.total; pages = Math.max(pages, r.pages); if (!brands.length) brands = r.brands; });
        if (!append) { drawBar(types.length > 1 ? [] : brands); grid.innerHTML = ''; shown = 0; }
        shown += items.length;
        if (!shown) grid.outerHTML = '<div class="cct-empty" data-grid><b>🔎</b>' + (S.q ? '「' + esc(S.q) + '」에 맞는 항목이 없어요.' : S.status === 'archived' ? '보관한 항목이 없어요.' : '아직 항목이 없어요.') +
          (can && !S.status ? '<br><br><button type="button" class="cct-btn cct-btn--p" onclick="this.closest(\'.cct\').querySelector(\'[data-new]\').click()">➕ 새 항목 넣기</button>' : '') + '</div>';
        else grid.insertAdjacentHTML('beforeend', items.map(function (c) { return cardHtml(c, can); }).join(''));
        cnt.innerHTML = shown ? (S.q ? '「' + esc(S.q) + '」 ' : '') + '<b>' + sum.toLocaleString() + '</b>개' + (S.brand ? ' · ' + esc(S.brand) : '') : '';
        more.innerHTML = page < pages ? '<button type="button" class="cct-btn">더 보기 (' + shown + ' / ' + sum + ')</button>' : '';
        var mb = more.querySelector('button'); if (mb) mb.onclick = function () { mb.disabled = true; page++; load(true); };
      }).catch(function (e) { if (document.body.contains(grid)) grid.outerHTML = '<div class="cct-empty"><b>⚠️</b>' + esc(e.message) + '</div>'; });
    };
    load(false);
  }

  /* 제조사 로고 · 지원 주소 (resources/assets/makers.json) — 상세를 열 때 한 번 */
  var makersP = null;
  function makerOf(brand) {
    if (!makersP) makersP = fetch(API + '/assets/makers.json?v=' + VERSION).then(function (r) { return r.json(); }).catch(function () { return []; });
    var b = String(brand || '').toLowerCase();
    return makersP.then(function (list) { return (list || []).filter(function (m) { return m.match && (b === m.match || b.indexOf(m.match) === 0 || b.indexOf('(' + m.match + ')') >= 0); })[0] || null; });
  }

  /* ───────── 상세 ───────── */
  var KEYS = ['material', 'speed_max', 'nozzle_temp_max', 'min_layer_um', 'xy_um', 'laser_w', 'wavelength_nm', 'diameter', 'weight_g', 'density', 'hdt_c', 'colors_max', 'weight_kg', 'price_krw'];
  function detailPage(root, key) {
    root.innerHTML = '<div class="cct"><div class="cct-skel" style="height:420px"></div></div>';
    var can = !!META.can_edit;
    var reload = function () { detailPage(root, key); };
    api('GET', '/items/' + encodeURIComponent(key)).then(function (d) {
      if (!document.body.contains(root)) return;
      var tab = META.tabs.filter(function (t) { return t.type === d.type && t.kinds.indexOf(d.kind) >= 0; })[0];
      var keys = [], by = {};
      d.groups.forEach(function (g) { g.items.forEach(function (it) { by[it.key] = it; }); });
      if (d.type === 'equipment' && d.chips[0] && / mm$/.test(d.chips[0])) keys.push({ label: '출력 · 작업 크기', value: d.chips[0] });
      if (d.type === 'materials' && by.nozzle_min && by.nozzle_max) keys.push({ label: '노즐 온도', value: by.nozzle_min.value.replace(/ ?°C/, '') + '–' + by.nozzle_max.value });
      if (d.type === 'materials' && by.bed_min && by.bed_max) keys.push({ label: '베드 온도', value: by.bed_min.value.replace(/ ?°C/, '') + '–' + by.bed_max.value });
      if (by.dry_temp) keys.push({ label: '건조', value: by.dry_temp.value + (by.dry_hours ? ' · ' + by.dry_hours.value : '') });
      KEYS.forEach(function (k) { if (by[k] && keys.length < 6) keys.push(by[k]); });
      var ph = d.photos, mainPic = ph.length ? '<img alt="' + esc(d.brand + ' ' + d.title) + '" src="' + esc(ph[0].url) + '" data-main>' + (ph[0].credit ? '<span class="cct-gal__credit" data-credit>📷 ' + esc(ph[0].credit) + '</span>' : '<span class="cct-gal__credit" data-credit hidden></span>') :
        '<div class="cct-ph">' + art(d, 230) + '</div>';
      var h = '<div class="cct"><a class="cct-back" data-go href="' + BASE + (tab ? '?tab=' + tab.key : '') + '">‹ ' + esc(tab ? tab.label : '카탈로그') + ' 목록</a>' +
        '<div class="cct-top"><div class="cct-gal"><div class="cct-gal__main">' + mainPic + '</div>' +
        (ph.length > 1 || (can && ph.length) ? '<div class="cct-thumbs">' + ph.map(function (p, i) { return '<button type="button" class="cct-thumb' + (i ? '' : ' on') + '" data-ph="' + i + '"><img alt="" loading="lazy" src="' + esc(p.thumb || p.url) + '">' + (i ? '' : '<em>대표</em>') + '</button>'; }).join('') + '</div>' : '') +
        (can ? '<div class="cct-photo-tools"><label class="cct-btn cct-btn--s">📁 사진 올리기<input type="file" accept="image/*" multiple hidden data-up></label><input class="cct-in" style="height:32px" type="text" placeholder="또는 사진 주소 (https://…)" data-url>' +
          '<button type="button" class="cct-btn cct-btn--s" data-url-add>주소로 넣기</button><button type="button" class="cct-btn cct-btn--s" data-ph-main hidden>⭐ 대표로</button><button type="button" class="cct-btn cct-btn--s cct-btn--d" data-ph-del hidden>🗑 이 사진 지우기</button></div>' : '') + '</div>' +
        '<div class="cct-info"><span class="cct-info__kind">' + kindIcon(d.type, d.kind) + ' ' + esc(d.kind_label) + '</span>' + (d.status !== 'active' ? ' <span class="cct-info__kind" style="color:var(--warn)">🗄️ 보관함</span>' : '') +
        '<div class="cct-info__brand">' + logoImg(d.brand, 'cct-logo--lg') + esc(d.brand) + (can ? ' <button type="button" class="cct-btn cct-btn--s" data-logo-edit title="이 제조사의 로고 바꾸기">🏷️ 로고 바꾸기</button>' : '') + '</div><h1>' + esc(d.title) + '</h1>' +
        (d.summary ? '<p class="cct-info__sum">' + esc(d.summary) + '</p>' : '') + (d.note ? '<p class="cct-info__sum" style="font-size:14px">※ ' + esc(d.note) + '</p>' : '') +
        (keys.length ? '<div class="cct-keys">' + keys.map(function (k) { return '<div class="cct-key"><small>' + esc(k.label) + '</small><b>' + esc(k.value) + '</b></div>'; }).join('') + '</div>' : '') +
        (by.materials ? '<div class="cct-mats"><small>' + (d.kind === 'fdm' ? '🧵 쓸 수 있는 필라멘트' : d.kind === 'sla' || d.kind === 'dlp' ? '🧪 쓸 수 있는 레진' : '🧩 쓸 수 있는 재료') + '</small><div class="cct-tags">' +
          by.materials.value.split(' · ').map(function (x) { return '<span>' + esc(x) + '</span>'; }).join('') + '</div></div>' : '') +
        '<div class="cct-links">' + (d.homepage_url ? '<a class="cct-btn cct-btn--p" target="_blank" rel="noopener" href="' + esc(d.homepage_url) + '">🌐 제조사 페이지</a>' : '') +
        (d.wiki_url && d.wiki_url !== d.homepage_url ? '<a class="cct-btn" target="_blank" rel="noopener" href="' + esc(d.wiki_url) + '">📖 더 알아보기</a>' : '') +
        (by.sds_url ? '<a class="cct-btn" target="_blank" rel="noopener" href="' + esc(by.sds_url.value) + '">🧾 안전 자료 (MSDS)</a>' : '') +
        (d.type === 'parts' ? '' : d.type === 'equipment' ? '<a class="cct-btn" href="/companies?q=' + encodeURIComponent(d.title) + '">🏢 이 장비를 가진 업체 찾기</a>' : '<a class="cct-btn" href="/companies?q=' + encodeURIComponent(by.material ? by.material.value : d.title) + '">🏢 이 재료로 출력하는 업체 찾기</a>') +
        '<a class="cct-btn" href="/wiki/search?q=' + encodeURIComponent(d.title) + '">📚 위키에서 찾아보기</a>' +
        '<button type="button" class="cct-btn" data-print>🖨️ 인쇄</button></div><div class="cct-maker" data-maker hidden></div>' +
        (can ? '<div class="cct-edbar"><div class="cct-meter" style="--p:' + Math.round(d.filled / Math.max(1, d.fields) * 100) + '%">제원 ' + d.filled + ' / ' + d.fields + '칸<i></i></div>' +
          '<button type="button" class="cct-btn cct-btn--p cct-btn--s" data-ed>✏️ 수정</button>' + (d.status === 'active' ? '<button type="button" class="cct-btn cct-btn--s cct-btn--d" data-del>🗑 지우기</button>' :
          '<button type="button" class="cct-btn cct-btn--s" data-restore>↩ 되살리기</button><button type="button" class="cct-btn cct-btn--s cct-btn--d" data-hard>아주 지우기</button>') + '</div>' : '') + '</div></div>';
      var specs = d.groups.map(function (g) {
        return '<div class="cct-spec"><h3>' + esc(g.group) + '</h3><dl>' + g.items.map(function (it) {
          return '<div><dt>' + esc(it.label) + '</dt><dd>' + (it.url ? '<a target="_blank" rel="noopener" href="' + esc(it.value) + '">열기 ↗</a>' : esc(it.value)) + '</dd></div>'; }).join('') + '</dl></div>';
      }).join('') + (d.facts.length ? '<div class="cct-spec"><h3>기타</h3><dl>' + d.facts.map(function (f) { return '<div><dt>' + esc(f.label) + '</dt><dd>' + esc(f.value) + '</dd></div>'; }).join('') + '</dl></div>' : '');
      h += '<div class="cct-sec"><h2>📋 제원</h2>' + (specs ? '<div class="cct-specs">' + specs + '</div>' : '<div class="cct-empty"><b>📝</b>아직 적힌 제원이 없어요.' + (can ? ' 「✏️ 수정」으로 채워 주세요.' : '') + '</div>') +
        '<p class="cct-note" style="margin-top:4px">제조사가 공개한 자료를 정리한 것으로, 실제 제품과 다를 수 있어요. 구매 · 사용 전에는 제조사 자료를 확인해 주세요.' + (d.updated_at ? ' (마지막 고침 ' + esc(d.updated_at) + ')' : '') + '</p></div>';
      if (d.detail) h += '<div class="cct-sec"><h2>📖 자세한 설명</h2><div class="cct-text">' + esc(d.detail) + '</div></div>';
      if (d.issues.length) h += '<div class="cct-sec"><h2>⚠️ 알려진 문제 · 고질병</h2><ul class="cct-issues">' + d.issues.map(function (x) { return '<li>' + esc(x) + '</li>'; }).join('') + '</ul>' +
        '<p class="cct-note" style="margin-top:8px">쓰는 사람들 사이에 알려진 내용을 정리한 것이에요. 제품 개선판 · 펌웨어에 따라 달라졌을 수 있어요.</p></div>';
      if (d.memo) h += '<div class="cct-sec"><h2>📝 메모</h2><div class="cct-text cct-text--memo">' + esc(d.memo) + '</div></div>';
      if (d.type === 'parts') h += partsHtml(d, can);   // 0.3.0 🔩 관련 프린터 · 후기 · 구매처
      if (d.related.length) h += '<div class="cct-sec"><h2>🏷️ ' + esc(d.brand) + '의 다른 제품</h2><div class="cct-grid">' + d.related.map(function (c) { return cardHtml(c, false); }).join('') + '</div></div>';
      if (d.type === 'equipment') h += printerPartsHtml(d);   // 0.3.2 🔩 관련 부품 — 프린터 상세 맨 아래
      root.innerHTML = h + '</div>';
      var box = root.querySelector('.cct'), cur = 0, q = function (s) { return box.querySelector(s); };
      bindCards(box, reload);
      try { document.title = d.brand + ' ' + d.title + ' — 3D 카탈로그'; } catch (e) {}
      q('[data-print]').onclick = function () { window.print(); };
      if (d.type === 'parts') partsBind(box, d, reload, can);   // 0.3.0
      makerOf(d.brand).then(function (mk) {
        var el = q('[data-maker]'); if (!mk || !el) return;
        el.hidden = false;
        el.innerHTML = logoImg(d.brand, 'cct-logo--lg') + '<div><b>' + esc(mk.name || d.brand) + '</b><small>제조사</small></div><span style="flex:1"></span>' +
          (mk.homepage ? '<a class="cct-btn cct-btn--s" target="_blank" rel="noopener" href="' + esc(mk.homepage) + '">홈페이지 ↗</a>' : '') +
          (mk.support ? '<a class="cct-btn cct-btn--s" target="_blank" rel="noopener" href="' + esc(mk.support) + '">고객 지원 ↗</a>' : '');
      });
      Array.prototype.forEach.call(box.querySelectorAll('[data-ph]'), function (b) { b.onclick = function () {
        cur = +b.getAttribute('data-ph'); var p = ph[cur], img = q('[data-main]'), cr = q('[data-credit]');
        if (img) img.src = p.url; if (cr) { cr.hidden = !p.credit; cr.textContent = '📷 ' + p.credit; }
        Array.prototype.forEach.call(box.querySelectorAll('[data-ph]'), function (x) { x.classList.toggle('on', x === b); });
        var m = q('[data-ph-main]'), dl = q('[data-ph-del]'); if (m) m.hidden = !p.id || cur === 0; if (dl) dl.hidden = !p.id;
      }; });
      if (!can) return;
      var busy = function (b, on) { b.disabled = on; };
      if (ph.length && ph[0].id) q('[data-ph-del]').hidden = false;
      q('[data-up]').onchange = function () {
        if (!this.files.length) return;
        var fd = new FormData(); Array.prototype.forEach.call(this.files, function (f) { fd.append('photos[]', f); });
        toast('사진을 올리는 중…');
        api('POST', '/admin/items/' + key + '/photos', fd).then(function () { toast(api.last || '사진을 넣었어요.'); reload(); }).catch(function (e) { toast(e.message); });
      };
      q('[data-url-add]').onclick = function () {
        var u = q('[data-url]').value.trim(), b = this; if (!/^https?:\/\//i.test(u)) { toast('https:// 로 시작하는 사진 주소를 넣어 주세요.'); return; }
        busy(b, true);
        api('POST', '/admin/items/' + key + '/photos', { url: u }).then(function () { toast(api.last || '사진을 넣었어요.'); reload(); }).catch(function (e) { busy(b, false); toast(e.message); });
      };
      q('[data-ph-main]').onclick = function () { api('POST', '/admin/photos/' + ph[cur].id + '/main').then(function () { toast('대표 사진으로 정했어요.'); reload(); }).catch(function (e) { toast(e.message); }); };
      q('[data-ph-del]').onclick = function () { if (!confirm('이 사진을 지울까요?')) return; api('POST', '/admin/photos/' + ph[cur].id + '/delete').then(function () { toast('사진을 지웠어요.'); reload(); }).catch(function (e) { toast(e.message); }); };
      q('[data-ed]').onclick = function () { openEditor({ key: key, detail: d }, reload); };
      q('[data-logo-edit]').onclick = function () { logoEditor(d.brand, function () { meta(true).then(reload); }); };
      var del = q('[data-del]'); if (del) del.onclick = function () { if (!confirm('「' + d.brand + ' ' + d.title + '」 을(를) 지울까요?\n보관함으로 옮겨지고, 나중에 되살릴 수 있어요.')) return; api('POST', '/admin/items/' + key + '/delete').then(function () { toast(api.last); meta(true); go(BASE + (tab ? '?tab=' + tab.key : '')); }).catch(function (e) { toast(e.message); }); };
      var rs = q('[data-restore]'); if (rs) rs.onclick = function () { api('POST', '/admin/items/' + key + '/restore').then(function () { toast('되살렸어요.'); meta(true); reload(); }).catch(function (e) { toast(e.message); }); };
      var hd = q('[data-hard]'); if (hd) hd.onclick = function () { if (!confirm('아주 지우면 되살릴 수 없어요. 사진도 함께 지워져요. 지울까요?')) return; api('POST', '/admin/items/' + key + '/delete', { hard: 1 }).then(function () { toast('아주 지웠어요.'); go(BASE + '?status=archived' + (tab ? '&tab=' + tab.key : '')); }).catch(function (e) { toast(e.message); }); };
    }).catch(function (e) {
      root.innerHTML = '<div class="cct"><div class="cct-empty"><b>🧐</b>' + esc(e.message) + '<br><br><a class="cct-btn" data-go href="' + BASE + '">카탈로그 목록으로</a></div></div>';
      bindCards(root.querySelector('.cct'), function () {});
    });
  }

  /* ───────── 0.3.0 🔩 부품 · 업그레이드 호환표 ───────── */
  /** 프린터 이름으로 찾아 고르기 (목록 거르기 · 맞는 프린터 알려 주기 · 제안 창) */
  function picker(input, list, onPick) {
    if (!input || !list) return;
    var t = 0, seq = 0;
    var hide = function () { list.hidden = true; };
    input.addEventListener('input', function () {
      clearTimeout(t); var qv = input.value.trim();
      if (qv.length < 1) { hide(); return; }
      t = setTimeout(function () {
        var my = ++seq;
        api('GET', '/parts/printers?q=' + encodeURIComponent(qv)).then(function (rows) {
          if (my !== seq) return;
          list.innerHTML = rows.length ? rows.map(function (p, i) { return '<button type="button" data-i="' + i + '">' + logoImg(p.title.split(' ')[0]) + esc(p.title) + '</button>'; }).join('') : '<em>카탈로그에 없는 프린터예요</em>';
          list.hidden = false;
          Array.prototype.forEach.call(list.querySelectorAll('[data-i]'), function (b) { b.onmousedown = function (e) { e.preventDefault(); var p = rows[+b.getAttribute('data-i')]; input.value = ''; hide(); onPick(p); }; });
        }).catch(hide);
      }, 220);
    });
    input.addEventListener('blur', function () { setTimeout(hide, 150); });
  }
  var FIT = { fit: ['✅', '그대로 맞음', 'ok'], mod: ['🛠️', '개조 필요', 'mod'], no: ['⛔', '안 맞음', 'no'], pending: ['🕓', '확인 중', 'pend'] };
  function fitRow(f, can, login) {
    var p = f.printer, v = f.votes, st = FIT[f.status] || FIT.pending;
    return '<div class="cct-fit" data-fit="' + f.id + '" data-pk="' + esc(p.key) + '"><a class="cct-fit__p" data-go href="' + BASE + '/' + esc(p.key) + '">' + (p.image ? '<img alt="" loading="lazy" src="' + esc(p.image) + '">' : '<span class="cct-fit__ic">' + art(p, 44) + '</span>') +
      '<span><small>' + esc(p.brand) + '</small><b>' + esc(p.title) + '</b></span></a>' +
      '<span class="cct-fitst cct-fitst--' + st[2] + '">' + st[0] + ' ' + st[1] + (f.set_by_editor ? ' <small>· 확인함</small>' : '') + (f.need ? ' <small>· ' + f.need + '표 더</small>' : '') + '</span>' +
      '<span class="cct-fit__votes">맞음 ' + v.fit + ' · 개조 ' + v.mod + ' · 안 맞음 ' + v.no + (f.real_use ? ' · <b>✔ 실사용 ' + f.real_use + '</b>' : '') + '</span>' +
      (f.note ? '<span class="cct-fit__note">📝 ' + esc(f.note) + '</span>' : '') +
      (login ? '<span class="cct-fit__my">' + [['fit', '✅ 맞아요'], ['mod', '🛠️ 개조 필요'], ['no', '⛔ 안 맞아요']].map(function (o) { return '<button type="button" class="cct-chip' + (f.mine === o[0] ? ' on' : '') + '" data-vote="' + o[0] + '" title="' + (f.mine === o[0] ? '한 번 더 누르면 표를 거둬요' : '') + '">' + o[1] + '</button>'; }).join('') + '</span>' : '') +
      (can ? '<span class="cct-fit__ed"><select class="cct-sel" data-fst><option value="">투표로 정함</option>' + [['fit', '✅ 맞음으로'], ['mod', '🛠️ 개조 필요로'], ['no', '⛔ 안 맞음으로']].map(function (o) { return '<option value="' + o[0] + '"' + (f.set_by_editor && f.status === o[0] ? ' selected' : '') + '>' + o[1] + '</option>'; }).join('') + '</select>' +
        (!f.approved ? '<button type="button" class="cct-btn cct-btn--s" data-fapprove>✔ 확인</button>' : '') + '<button type="button" class="cct-btn cct-btn--s" data-fnote title="메모">📝</button><button type="button" class="cct-btn cct-btn--s cct-btn--d" data-fdel title="연결 지우기">✕</button></span>' : '') + '</div>';
  }
  function partsHtml(d, can) {
    var F = d.fits || { items: [], pending: [] }, R = d.reviews || { items: [] }, L = d.links || [], login = !!d.login, mine = R.mine;
    var h = '<div class="cct-sec" id="fits"><h2>🖨️ 관련 프린터 <small>' + F.items.length + '</small></h2>' +
      (F.items.length ? '<div class="cct-fits">' + F.items.map(function (f) { return fitRow(f, can, login); }).join('') + '</div>' : '<p class="cct-note">아직 연결된 프린터가 없어요. 써 본 분이 알려 주세요.</p>') +
      (F.pending.length ? '<details class="cct-pend"' + (can ? ' open' : '') + '><summary>🕓 확인 중 ' + F.pending.length + '개 <small>표가 ' + (META.parts_auto_votes || 3) + '개 쌓이거나 관리자가 확인하면 위로 올라가요</small></summary><div class="cct-fits">' + F.pending.map(function (f) { return fitRow(f, can, login); }).join('') + '</div></details>' : '') +
      (login ? '<div class="cct-addfit"><span class="cct-pick"><input class="cct-in" data-fq placeholder="➕ 이 부품이 맞는 프린터 알려 주기 — 이름으로 찾기" autocomplete="off"><span class="cct-pick__list" data-flist hidden></span></span></div>' : '<p class="cct-note">로그인하면 맞음 · 안 맞음을 알려 줄 수 있어요. 내 프린터에 등록한 프린터라면 <b>✔ 실사용</b>으로 두 배로 세요.</p>') + '</div>';
    h += '<div class="cct-sec" id="reviews"><h2>⭐ 후기 ' + (R.avg ? '<small>★ ' + R.avg + ' · ' + R.n + '개</small>' : '') + '</h2>' +
      (R.items.length ? R.items.map(function (r) {
        return '<div class="cct-rv' + (r.hidden ? ' is-off' : '') + '" data-rv="' + r.id + '"><div class="cct-rv__h"><b class="cct-stars">' + new Array(r.score + 1).join('★') + '<i>' + new Array(6 - r.score).join('★') + '</i></b><span>' + esc(r.user) + '</span>' + (r.real_use ? '<em class="cct-real">✔ 실사용</em>' : '') +
          (r.printer ? '<small>🖨️ ' + esc(r.printer) + '</small>' : '') + (r.months != null ? '<small>⏳ ' + r.months + '개월</small>' : '') + (r.materials ? '<small>🧵 ' + esc(r.materials) + '</small>' : '') + '<small class="cct-rv__at">' + esc(r.at) + '</small>' + (r.hidden ? '<small>🙈 숨김</small>' : '') +
          (r.mine ? '<button type="button" class="cct-x" data-rvdel title="내 후기 지우기">✕</button>' : '') + (can ? '<button type="button" class="cct-btn cct-btn--s" data-rvhide="' + (r.hidden ? 0 : 1) + '">' + (r.hidden ? '보이기' : '숨기기') + '</button>' : '') + '</div>' + (r.body ? '<p>' + esc(r.body) + '</p>' : '') + '</div>';
      }).join('') : '<p class="cct-note">아직 후기가 없어요.</p>') +
      (login ? '<form class="cct-rvform" data-rvform><b>' + (mine ? '✏️ 내 후기 고치기' : '✍️ 후기 남기기') + '</b><div class="cct-starpick" data-score="' + (mine ? mine.score : 0) + '">' + [1, 2, 3, 4, 5].map(function (n) { return '<button type="button" data-s="' + n + '" class="' + (mine && n <= mine.score ? 'on' : '') + '" aria-label="' + n + '점">★</button>'; }).join('') + '</div>' +
        '<div class="cct-rvrow"><select class="cct-sel" name="printer"><option value="">쓴 프린터</option>' + F.items.concat(F.pending).map(function (f) { return '<option value="' + esc(f.printer.key) + '"' + (mine && mine.printer_key === f.printer.key ? ' selected' : '') + '>' + esc(f.printer.brand + ' ' + f.printer.title) + '</option>'; }).join('') + '</select>' +
        '<input class="cct-in" name="months" type="number" min="0" max="240" placeholder="몇 개월 썼나요" value="' + (mine && mine.months != null ? mine.months : '') + '"><input class="cct-in" name="materials" maxlength="120" placeholder="같이 쓴 소재 (예: PLA, PA-CF)" value="' + esc(mine ? mine.materials : '') + '"></div>' +
        '<textarea class="cct-in" name="body" maxlength="2000" placeholder="써 보니 어땠나요? 좋은 점 · 주의할 점 (예: 펌웨어에서 노즐 재질을 바꿔야 해요)">' + esc(mine ? mine.body : '') + '</textarea><div><button class="cct-btn cct-btn--p cct-btn--s">저장</button></div></form>' : '') + '</div>';
    h += '<div class="cct-sec" id="buy"><h2>🛒 어디서 사나</h2>' +
      (L.length ? '<div class="cct-buys">' + L.map(function (l) {
        return '<div class="cct-buy' + (l.hidden ? ' is-off' : '') + '" data-lk="' + l.id + '"><a target="_blank" rel="noopener nofollow ugc" href="' + esc(l.url) + '"><b>' + esc(l.label) + '</b>' + (l.price ? '<span>' + l.price.toLocaleString() + '원</span>' : '') + '<i>↗</i></a><small>' + esc(l.at) + '</small>' +
          (can && l.reports ? '<small>🚩 ' + l.reports + '</small>' : '') + (l.mine || can ? '<button type="button" class="cct-x" data-lkact="delete" title="지우기">✕</button>' : (login ? '<button type="button" class="cct-x" data-lkact="report" title="잘못된 주소 · 광고 신고">🚩</button>' : '')) +
          (can ? '<button type="button" class="cct-btn cct-btn--s" data-lkact="' + (l.hidden ? 'show' : 'hide') + '">' + (l.hidden ? '보이기' : '숨기기') + '</button>' : '') + '</div>';
      }).join('') + '</div>' : '<p class="cct-note">아직 남긴 구매처가 없어요.</p>') +
      (login ? '<form class="cct-buyform" data-lkform><input class="cct-in" name="url" placeholder="가게 주소 https://" required><input class="cct-in" name="label" maxlength="60" placeholder="가게 이름 (비우면 주소로)"><input class="cct-in" name="price" inputmode="numeric" placeholder="가격 (원)"><button class="cct-btn cct-btn--s">➕ 남기기</button></form>' : '') +
      '<p class="cct-note">회원이 남긴 가게예요 — 가격은 남긴 날 기준이에요. 광고 · 잘못된 주소는 🚩 로 알려 주세요.</p></div>';
    return h;
  }
  function printerPartsHtml(d) {
    if (d.type !== 'equipment') return '';
    var groups = d.parts || [], n = 0; groups.forEach(function (g) { n += g.items.length; });
    var all = BASE + '?tab=parts&printer=' + encodeURIComponent(d.key) + '&pname=' + encodeURIComponent(d.brand + ' ' + d.title);
    if (!n) return ['fdm', 'sla', 'dlp'].indexOf(d.kind) >= 0 ? '<div class="cct-sec"><h2>🔩 관련 부품</h2><p class="cct-note">아직 연결된 부품이 없어요. <a data-go href="' + BASE + '?tab=parts">부품 목록</a>에서 쓰고 있는 부품을 찾아 「맞는 프린터」로 알려 주세요.</p></div>' : '';
    return '<div class="cct-sec" id="parts"><h2>🔩 관련 부품 <small>' + n + '</small> <a class="cct-more" data-go href="' + all + '">모두 보기 ›</a></h2>' + groups.map(function (g) {
      return '<h3 class="cct-pgh">' + g.icon + ' ' + esc(g.label) + ' <small>' + g.items.length + '</small>' + (g.items.length > 6 ? ' <a class="cct-more" data-go href="' + all + '">더 보기 ›</a>' : '') + '</h3><div class="cct-grid cct-grid--s">' + g.items.slice(0, 6).map(function (c) {
        return cardHtml(c, false).replace('<div class="cct-card__body">', '<div class="cct-card__body">' + (c.fit === 'mod' ? '<span class="cct-fitst cct-fitst--mod">🛠️ 개조 필요</span>' : '') + (c.score ? '<span class="cct-score">★ ' + c.score.avg + ' <small>(' + c.score.n + ')</small></span>' : ''));
      }).join('') + '</div>';
    }).join('') + '</div>';
  }
  function partsBind(box, d, reload, can) {
    var key = d.key, fail = function (e) { toast(e.message); };
    Array.prototype.forEach.call(box.querySelectorAll('[data-fit]'), function (row) {
      var id = +row.getAttribute('data-fit'), pk = row.getAttribute('data-pk');
      Array.prototype.forEach.call(row.querySelectorAll('[data-vote]'), function (b) { b.onclick = function () {
        var undo = b.classList.contains('on');
        api('POST', '/parts/' + key + '/vote', { printer: pk, vote: undo ? '' : b.getAttribute('data-vote') }).then(function () { toast(undo ? '표를 거뒀어요.' : '고마워요 — 표를 남겼어요.'); reload(); }).catch(fail);
      }; });
      var ed = function (body) { return api('POST', '/admin/parts/fits/' + id, body).then(reload).catch(fail); };
      var s = row.querySelector('[data-fst]'); if (s) s.onchange = function () { ed({ action: 'status', status: s.value }); };
      var ap = row.querySelector('[data-fapprove]'); if (ap) ap.onclick = function () { ed({ action: 'approve' }); };
      var nt = row.querySelector('[data-fnote]'); if (nt) nt.onclick = function () { var t = prompt('이 프린터에서 쓸 때 메모 (예: 브래킷을 바꿔야 해요 · 비우면 지움)', ''); if (t !== null) ed({ action: 'note', note: t }); };
      var dl = row.querySelector('[data-fdel]'); if (dl) dl.onclick = function () { if (confirm('이 프린터 연결과 표를 지울까요?')) ed({ action: 'delete' }); };
    });
    picker(box.querySelector('[data-fq]'), box.querySelector('[data-flist]'), function (p) {
      api('POST', '/parts/' + key + '/vote', { printer: p.key, vote: 'fit' }).then(function () { toast('고마워요 — 「' + p.title + '」에 맞아요로 남겼어요.'); reload(); }).catch(fail);
    });
    var rf = box.querySelector('[data-rvform]');
    if (rf) {
      var sp = rf.querySelector('.cct-starpick');
      Array.prototype.forEach.call(sp.querySelectorAll('[data-s]'), function (b) { b.onclick = function () { var n = +b.getAttribute('data-s'); sp.setAttribute('data-score', n); Array.prototype.forEach.call(sp.querySelectorAll('[data-s]'), function (x) { x.classList.toggle('on', +x.getAttribute('data-s') <= n); }); }; });
      rf.onsubmit = function (e) {
        e.preventDefault();
        var sc = +sp.getAttribute('data-score'); if (!sc) { toast('별점을 골라 주세요.'); return; }
        api('POST', '/parts/' + key + '/reviews', { score: sc, printer: rf.printer.value, months: rf.months.value, materials: rf.materials.value, body: rf.body.value }).then(function () { toast('후기를 남겼어요.'); reload(); }).catch(fail);
      };
    }
    Array.prototype.forEach.call(box.querySelectorAll('[data-rv]'), function (row) {
      var id = row.getAttribute('data-rv');
      var dl = row.querySelector('[data-rvdel]'); if (dl) dl.onclick = function () { if (confirm('내 후기를 지울까요?')) api('POST', '/parts/reviews/' + id + '/delete').then(reload).catch(fail); };
      var hd = row.querySelector('[data-rvhide]'); if (hd) hd.onclick = function () { api('POST', '/admin/parts/reviews/' + id + '/hide', { hide: hd.getAttribute('data-rvhide') === '1' }).then(reload).catch(fail); };
    });
    var lf = box.querySelector('[data-lkform]');
    if (lf) lf.onsubmit = function (e) { e.preventDefault(); api('POST', '/parts/' + key + '/links', { url: lf.url.value.trim(), label: lf.label.value, price: lf.price.value }).then(function () { toast('구매처를 남겼어요.'); reload(); }).catch(fail); };
    Array.prototype.forEach.call(box.querySelectorAll('[data-lk]'), function (row) {
      Array.prototype.forEach.call(row.querySelectorAll('[data-lkact]'), function (b) { b.onclick = function () {
        var act = b.getAttribute('data-lkact');
        if (act === 'delete' && !confirm('이 구매처를 지울까요?')) return;
        if (act === 'report' && !confirm('잘못된 주소 · 광고로 신고할까요? 여러 번 신고되면 숨겨져요.')) return;
        api('POST', '/parts/links/' + row.getAttribute('data-lk') + '/' + act).then(function () { toast(api.last || '바꿨어요.'); reload(); }).catch(fail);
      }; });
    });
  }
  /** 💡 회원 부품 제안 — 관리자가 확인하면 카탈로그에 올라감 */
  function proposePart() {
    css();
    var m = document.createElement('div'); m.className = 'cct cct-modal'; document.body.appendChild(m);
    var picked = [], close = function () { m.remove(); };
    var gen = ['정품', '호환품', '자작 · 3D 출력'];
    m.innerHTML = '<div class="cct-modal__box" style="width:min(620px,100%)"><div class="cct-modal__head"><h3>💡 부품 제안</h3><button type="button" class="cct-x" data-close aria-label="닫기">✕</button></div>' +
      '<form class="cct-modal__body" data-pf><p class="cct-note" style="margin:0 0 10px">카탈로그에 없는 부품을 알려 주세요. 관리자가 확인하면 올라가고, 고른 프린터가 「맞는 프린터」로 연결돼요.</p><div class="cct-form">' +
      '<label class="cct-f"><span>종류</span><select class="cct-in" name="kind">' + META.kinds.parts.map(function (k) { return '<option value="' + k.key + '">' + k.icon + ' ' + esc(k.label) + '</option>'; }).join('') + '</select></label>' +
      '<label class="cct-f"><span>정품 여부</span><select class="cct-in" name="genuine"><option value="">—</option>' + gen.map(function (g) { return '<option>' + g + '</option>'; }).join('') + '</select></label>' +
      '<label class="cct-f"><span>제조사</span><input class="cct-in" name="brand" required maxlength="60" placeholder="예: Bambu Lab · E3D · Bondtech"></label>' +
      '<label class="cct-f"><span>부품 이름</span><input class="cct-in" name="title" required maxlength="80" placeholder="예: 경화강 노즐 0.6 mm"></label>' +
      '<label class="cct-f"><span>부품 번호 <small>있으면</small></span><input class="cct-in" name="part_no" maxlength="60"></label>' +
      '<label class="cct-f"><span>참고 가격 (원)</span><input class="cct-in" name="price" inputmode="numeric"></label>' +
      '<label class="cct-f cct-f--w"><span>제품 페이지</span><input class="cct-in" name="homepage_url" placeholder="https://"></label>' +
      '<label class="cct-f cct-f--w"><span>소개 <small>한두 문장</small></span><textarea class="cct-in" name="summary" maxlength="600"></textarea></label>' +
      '<div class="cct-f cct-f--w"><span>🖨️ 맞는 프린터</span><div class="cct-tags" data-picked></div><span class="cct-pick"><input class="cct-in" data-pq placeholder="이름으로 찾아 더하기 (예: P1S)" autocomplete="off"><span class="cct-pick__list" data-plist hidden></span></span></div></div></form>' +
      '<div class="cct-modal__foot"><span class="cct-msg" data-msg></span><button type="button" class="cct-btn" data-close>닫기</button><button type="button" class="cct-btn cct-btn--p" data-send>보내기</button></div></div>';
    var f = m.querySelector('[data-pf]'), pk = m.querySelector('[data-picked]');
    var drawPicked = function () { pk.innerHTML = picked.map(function (p, i) { return '<span>' + esc(p.title) + ' <button type="button" class="cct-x" data-un="' + i + '">✕</button></span>'; }).join(''); Array.prototype.forEach.call(pk.querySelectorAll('[data-un]'), function (b) { b.onclick = function () { picked.splice(+b.getAttribute('data-un'), 1); drawPicked(); }; }); };
    picker(m.querySelector('[data-pq]'), m.querySelector('[data-plist]'), function (p) { if (!picked.some(function (x) { return x.key === p.key; })) picked.push(p); drawPicked(); });
    Array.prototype.forEach.call(m.querySelectorAll('[data-close]'), function (b) { b.onclick = close; });
    m.querySelector('[data-send]').onclick = function () {
      var b = this, msg = m.querySelector('[data-msg]');
      if (!f.brand.value.trim() || !f.title.value.trim()) { msg.textContent = '제조사 · 부품 이름을 적어 주세요.'; return; }
      b.disabled = true;
      api('POST', '/parts/propose', { kind: f.kind.value, brand: f.brand.value, title: f.title.value, homepage_url: f.homepage_url.value, summary: f.summary.value,
        values: { genuine: f.genuine.value, part_no: f.part_no.value, price_krw: f.price.value }, printers: picked.map(function (p) { return p.key; }) })
        .then(function () { close(); toast(api.last || '제안을 보냈어요.'); }).catch(function (e) { b.disabled = false; msg.textContent = e.message; });
    };
  }

  /* ───────── 로고 바꾸기 — 제조사 하나의 로고를 바꾸면 그 제조사의 모든 항목이 같이 바뀜 ───────── */
  function logoEditor(brand, done) {
    css();
    var m = document.createElement('div'); m.className = 'cct cct-modal'; document.body.appendChild(m);
    var changed = false, close = function () { m.remove(); if (changed && done) done(); };
    var draw = function (msg) {
      var u = logoOf(brand);
      m.innerHTML = '<div class="cct-modal__box" style="width:min(460px,100%)"><div class="cct-modal__head"><h3>🏷️ ' + esc(brand) + ' 로고</h3><button type="button" class="cct-x" data-close aria-label="닫기">✕</button></div>' +
        '<div class="cct-modal__body"><div class="cct-logobox">' + (u ? '<img alt="" src="' + esc(u) + '">' : logoImg(brand, 'cct-logo--lg')) + '</div>' +
        '<p class="cct-note" style="margin:12px 0">여기서 바꾸면 <b>' + esc(brand) + '</b> 의 모든 장비 · 재료에 같이 적용돼요. 이름을 조금 다르게 적은 같은 회사(예: QIDI · QIDI Tech)도 같이 바뀌어요.</p>' +
        '<div class="cct-bar"><label class="cct-btn cct-btn--p">📁 로고 올리기<input type="file" accept="image/*" hidden data-up></label><button type="button" class="cct-btn" data-web>🌐 홈페이지에서 가져오기</button>' +
        (u ? '<button type="button" class="cct-btn cct-btn--d" data-del>지우기</button>' : '') + '</div><p class="cct-msg" style="margin-top:10px;color:var(--mute)" data-msg>' + esc(msg || '') + '</p></div>' +
        '<div class="cct-modal__foot"><span style="flex:1"></span><button type="button" class="cct-btn" data-close>닫기</button></div></div>';
      var q = function (s) { return m.querySelector(s); }, say = function (t) { q('[data-msg]').textContent = t; };
      Array.prototype.forEach.call(m.querySelectorAll('[data-close]'), function (b) { b.onclick = close; });
      var after = function (text) { changed = true; return meta(true).then(function () { draw(text); }); };
      q('[data-up]').onchange = function () {
        if (!this.files.length) return;
        var fd = new FormData(); fd.append('brand', brand); fd.append('logo', this.files[0]); say('⏳ 올리는 중…');
        api('POST', '/admin/logos', fd).then(function () { return after('✅ 바꿨어요.'); }).catch(function (e) { say('❌ ' + e.message); });
      };
      q('[data-web]').onclick = function () { say('⏳ 홈페이지에서 찾는 중…');
        api('POST', '/admin/logos/fetch', { brand: brand }).then(function () { return after('✅ 홈페이지에서 가져왔어요.'); }).catch(function (e) { say('❌ ' + e.message); }); };
      var del = q('[data-del]'); if (del) del.onclick = function () { api('POST', '/admin/logos/delete', { brand: brand }).then(function () { return after('지웠어요 — 머리글자 배지로 보여요.'); }).catch(function (e) { say('❌ ' + e.message); }); };
    };
    meta().then(function () { draw(''); });
  }

  /* ───────── 편집 창 ───────── */
  function openEditor(opt, done) {
    css();
    var start = function (d) {
      var type = d ? d.type : (opt.type || 'equipment'), kind = d ? d.kind : (opt.kind || (type === 'materials' ? 'fdm' : 'fdm'));
      var V = d ? JSON.parse(JSON.stringify(d.values || {})) : {}, facts = d ? Object.keys(d.facts_raw || {}).map(function (k) { return [k, d.facts_raw[k]]; }) : [];
      var base = { brand: d ? d.brand : (opt.brand || ''), title: d ? d.title : '', summary: d ? d.summary : '', note: d ? d.note : '', homepage_url: d ? d.homepage_url : '', wiki_url: d ? d.wiki_url : '',
        detail: d ? d.detail || '' : '', issues: d ? (d.issues || []).join('\n') : '', memo: d ? d.memo || '' : '' };
      /* 0.2.11 미리 채울 값(opt.draft — 제안함 「고쳐서 반영」) · 🤖 AI 로 정리해 넣기 — 채운 칸은 💡 표시 */
      /* 0.2.16 바뀐 칸 표시 · 이전 값 — PREV 에 처음 값(고치기 전)을 남기고, 값이 실제로 달라진 칸만 AIK 로 표시 */
      var AIK = {}, PREV = {}, aipText = '';
      var BL = { brand: '제조사', title: '이름', summary: '소개', homepage_url: '제품 공식 페이지', wiki_url: '더 알아보기 주소', note: '한 줄 메모', detail: '자세한 설명', issues: '알려진 문제', memo: '메모', kind: '종류' };
      var same = function (a, b) { var n = function (x) { return x === undefined || x === null || (Array.isArray(x) && !x.length) ? '' : Array.isArray(x) ? x.map(String).join(',') : String(x).trim(); }; return n(a) === n(b); };   // 입력칸에서 다시 읽으면 숫자가 글이 되므로 글로 견줌
      var mark = function (id, old, now) {
        if (!(id in PREV)) PREV[id] = old === undefined ? null : JSON.parse(JSON.stringify(old));
        if (same(PREV[id], now)) { delete AIK[id]; return 0; }
        AIK[id] = 1; return 1;
      };
      var put = function (dr, keepName) {
        if (!dr) return 0;
        var n = 0;
        if (dr.kind && dr.kind !== kind && META.kinds[type].some(function (k) { return k.key === dr.kind; })) { n += mark('kind', kind, dr.kind); kind = dr.kind; }
        ['brand', 'title', 'summary', 'homepage_url', 'wiki_url', 'note', 'detail', 'issues', 'memo'].forEach(function (k) {
          var v = dr[k]; if (Array.isArray(v)) v = v.join('\n');
          if (v == null || String(v).trim() === '') return;
          if (keepName && (k === 'brand' || k === 'title') && String(base[k] || '').trim() !== '') return;
          n += mark('b:' + k, base[k], String(v)); base[k] = String(v);
        });
        Object.keys(dr.values || {}).forEach(function (k) { var v = dr.values[k]; if (v === null || v === '' || (Array.isArray(v) && !v.length)) return; n += mark(k, V[k], v); V[k] = v; });
        Object.keys(dr.facts || {}).forEach(function (nm) {
          var i = -1; facts.forEach(function (f, j) { if (f[0] === nm) i = j; });
          n += mark('f:' + nm, i >= 0 ? facts[i][1] : null, dr.facts[nm]);
          if (i >= 0) facts[i][1] = dr.facts[nm]; else facts.push([nm, dr.facts[nm]]);
        });
        return n;
      };
      var fieldOf = function (k) { var hit = null; (META.fields[type] || []).forEach(function (g) { g.fields.forEach(function (f) { if (f.key === k) hit = f; }); }); return hit; };
      var show = function (id, v) {
        if (v === undefined || v === null || v === '' || (Array.isArray(v) && !v.length)) return '(비어 있음)';
        if (id === 'kind') { var kk = META.kinds[type].filter(function (x) { return x.key === v; })[0]; return kk ? kk.label : v; }
        if (v === true) return '예'; if (v === false) return '아니오';
        var f = id.indexOf(':') < 0 ? fieldOf(id) : null;
        var s = Array.isArray(v) ? v.join(', ') : String(v);
        if (s.length > 80) s = s.slice(0, 80) + '…';
        return s + (f && f.unit && /^-?\d+(\.\d+)?$/.test(s) ? ' ' + f.unit : '');
      };
      var nowOf = function (id) { if (id === 'kind') return kind; if (id.indexOf('b:') === 0) return base[id.slice(2)]; if (id.indexOf('f:') === 0) { var hit = null; facts.forEach(function (f) { if (f[0] === id.slice(2)) hit = f[1]; }); return hit; } return V[id]; };
      var labelOf = function (id) { if (id === 'kind') return BL.kind; if (id.indexOf('b:') === 0) return BL[id.slice(2)] || id.slice(2); if (id.indexOf('f:') === 0) return id.slice(2) + ' (기타)'; var f = fieldOf(id); return f ? f.label : id; };
      var undo = function (id) {
        var o = PREV[id];
        if (id === 'kind') kind = o || kind;
        else if (id.indexOf('b:') === 0) base[id.slice(2)] = o == null ? '' : o;
        else if (id.indexOf('f:') === 0) { var nm = id.slice(2); facts = facts.filter(function (f) { return f[0] !== nm || o != null; }).map(function (f) { return f[0] === nm ? [nm, o] : f; }); }
        else if (o == null) delete V[id]; else V[id] = o;
        delete AIK[id];
      };
      put(opt.draft, false);
      var m = document.createElement('div'); m.className = 'cct cct-modal'; document.body.appendChild(m);
      var close = function () { m.remove(); document.removeEventListener('keydown', onKey); };
      var onKey = function (e) { if (e.key === 'Escape') close(); };
      document.addEventListener('keydown', onKey);
      var ctl = function (f) {
        var v = V[f.key], id = 'cct-f-' + f.key, lab = '<span>' + esc(f.label) + (f.hint ? ' <small>' + esc(f.hint) + '</small>' : '') + '</span>';
        if (f.type === 'bool') return '<div class="cct-f">' + lab + '<div class="cct-yn" data-yn="' + f.key + '">' + [['true', '예'], ['false', '아니오'], ['', '모름']].map(function (o) {
          return '<button type="button" data-v="' + o[0] + '" class="' + (String(v === undefined || v === null ? '' : v) === o[0] ? 'on' : '') + '">' + o[1] + '</button>'; }).join('') + '</div></div>';
        if (f.type === 'sel') return '<label class="cct-f">' + lab + '<div class="cct-f__u"><select class="cct-in" data-k="' + f.key + '"><option value="">—</option>' + (f.options || []).map(function (o) {
          return '<option' + (String(v) === String(o) ? ' selected' : '') + '>' + esc(o) + '</option>'; }).join('') + (v != null && v !== '' && (f.options || []).map(String).indexOf(String(v)) < 0 ? '<option selected>' + esc(v) + '</option>' : '') + '</select>' + (f.unit ? '<em>' + esc(f.unit) + '</em>' : '') + '</div></label>';
        if (f.type === 'tags') return '<label class="cct-f cct-f--w">' + lab + '<input class="cct-in" data-k="' + f.key + '" data-tags value="' + esc((v || []).join(', ')) + '" placeholder="쉼표로 여러 개">' +
          (f.options ? '<div class="cct-opts" data-opts="' + f.key + '">' + f.options.map(function (o) { return '<button type="button" class="' + ((v || []).indexOf(o) >= 0 ? 'on' : '') + '">' + esc(o) + '</button>'; }).join('') + '</div>' : '') + '</label>';
        return '<label class="cct-f' + (f.type === 'url' ? ' cct-f--w' : '') + '" for="' + id + '">' + lab + '<div class="cct-f__u"><input id="' + id + '" class="cct-in" data-k="' + f.key + '" ' + (f.type === 'num' ? 'type="number" step="any" inputmode="decimal"' : 'type="text"') +
          ' value="' + esc(v == null ? '' : v) + '">' + (f.unit ? '<em>' + esc(f.unit) + '</em>' : '') + '</div></label>';
      };
      var collect = function () {
        Array.prototype.forEach.call(m.querySelectorAll('[data-k]'), function (el) {
          var k = el.getAttribute('data-k'); V[k] = el.hasAttribute('data-tags') ? el.value.split(/[,\n]+/).map(function (x) { return x.trim(); }).filter(Boolean) : el.value.trim(); });
        Array.prototype.forEach.call(m.querySelectorAll('[data-b]'), function (el) { base[el.getAttribute('data-b')] = el.value.trim(); });
        facts = Array.prototype.map.call(m.querySelectorAll('[data-fact]'), function (r) { var i = r.querySelectorAll('input'); return [i[0].value.trim(), i[1].value.trim()]; });
        Object.keys(AIK).forEach(function (id) { if (same(PREV[id], nowOf(id))) delete AIK[id]; });
      };
      var draw = function () {
        var groups = META.fields[type].map(function (g) { return { group: g.group, fields: g.fields.filter(function (f) { return !f.kinds.length || f.kinds.indexOf(kind) >= 0; }) }; }).filter(function (g) { return g.fields.length; });
        var has = function (g) { return g.fields.filter(function (f) { var v = V[f.key]; return v !== undefined && v !== null && v !== '' && !(Array.isArray(v) && !v.length); }).length; };
        m.innerHTML = '<div class="cct-modal__box"><div class="cct-modal__head"><h3>' + (opt.head ? esc(opt.head) : d ? '✏️ ' + esc(d.brand + ' ' + d.title) + ' 고치기' : '➕ 새 항목') + '</h3><button type="button" class="cct-x" data-close aria-label="닫기">✕</button></div>' +
          '<div class="cct-modal__body">' + (opt.note ? '<p class="cct-aip__note">' + esc(opt.note) + '</p>' : '') +
          (opt.sources ? '<div class="cct-src' + (opt.sources.length ? '' : ' none') + '"><b>🔗 출처</b>' + (opt.sources.length ? opt.sources.map(function (x) { return '<a target="_blank" rel="noopener noreferrer" href="' + esc(x.url) + '">' + (x.ok ? '✅ ' : '⚠️ ') + esc(x.url.replace(/^https?:\/\/(www\.)?/i, '').slice(0, 70)) + '</a>'; }).join('') : '<span>⚠️ 출처 없음 — AI 기억</span>') + '</div>' : '') +
          (META.ai_paste ? '<div class="cct-aip" data-aip><div class="cct-aip__h"><span class="cct-aip__i">🤖</span><b>AI 로 정리해 넣기</b><small>제품 페이지의 제원 · 소개 글을 복사해 <b>붙여 넣거나 끌어 놓으면</b> 칸에 맞게 정리해 채워요 · 주소만 넣으면 그 페이지를 열어 읽어요 · 글에 없는 값은 넣지 않아요</small></div>' +
            '<textarea class="cct-in" data-aip-t placeholder="여기에 붙여 넣기 · 끌어 놓기 (글 · 주소 · .txt 파일)">' + esc(aipText) + '</textarea>' +
            '<div class="cct-bar" style="margin:6px 0 0"><button type="button" class="cct-btn cct-btn--p cct-btn--s" data-aip-go>✨ 정리해서 칸 채우기</button><span class="cct-msg" data-aip-msg></span></div></div>' : '') +
          (Object.keys(AIK).length ? '<details class="cct-chg" open><summary>✏️ 바뀐 칸 <b>' + Object.keys(AIK).length + '</b>개 <small>AI · 제안이 고친 값 — 맞는지 보고 저장해 주세요</small><button type="button" class="cct-btn cct-btn--s" data-undo-all>↩ 모두 되돌리기</button></summary><ul>' +
            Object.keys(AIK).map(function (id) { return '<li><b>' + esc(labelOf(id)) + '</b><s title="이전 값">' + esc(show(id, PREV[id])) + '</s><i>→</i><em>' + esc(show(id, nowOf(id))) + '</em><button type="button" class="cct-x" data-undo="' + esc(id) + '" title="이전 값으로">↩</button></li>'; }).join('') + '</ul></details>' : '') +
          '<div class="cct-form">' +
          (d ? '' : '<label class="cct-f"><span>무엇</span><select class="cct-in" data-type><option value="equipment"' + (type === 'equipment' ? ' selected' : '') + '>장비 · 프린터</option><option value="materials"' + (type === 'materials' ? ' selected' : '') + '>재료 (필라멘트 · 레진 …)</option><option value="parts"' + (type === 'parts' ? ' selected' : '') + '>🔩 부품 · 업그레이드</option></select></label>') +
          '<label class="cct-f"><span>종류</span><select class="cct-in" data-kind>' + META.kinds[type].map(function (k) { return '<option value="' + k.key + '"' + (k.key === kind ? ' selected' : '') + '>' + k.icon + ' ' + esc(k.label) + '</option>'; }).join('') + '</select></label>' +
          '<label class="cct-f"><span>제조사</span><input class="cct-in" data-b="brand" value="' + esc(base.brand) + '" placeholder="예: Bambu Lab"></label>' +
          '<label class="cct-f"><span>' + (type === 'materials' ? '제품 이름' : type === 'parts' ? '부품 이름' : '모델 이름') + '</span><input class="cct-in" data-b="title" value="' + esc(base.title) + '" placeholder="' + (type === 'materials' ? '예: PLA Basic' : type === 'parts' ? '예: 경화강 노즐 0.6 mm' : '예: P1S') + '"></label>' +
          '<label class="cct-f cct-f--w"><span>소개 <small>두세 문장</small></span><textarea class="cct-in" data-b="summary">' + esc(base.summary) + '</textarea></label>' +
          '<label class="cct-f"><span>제품 공식 페이지</span><input class="cct-in" data-b="homepage_url" value="' + esc(base.homepage_url) + '" placeholder="https://"></label>' +
          '<label class="cct-f"><span>더 알아보기 주소 <small>위키 등</small></span><input class="cct-in" data-b="wiki_url" value="' + esc(base.wiki_url) + '" placeholder="https://"></label>' +
          '<label class="cct-f cct-f--w"><span>한 줄 메모</span><input class="cct-in" data-b="note" value="' + esc(base.note) + '"></label></div>' +
          groups.map(function (g, i) { var n = has(g); return '<details class="cct-grp"' + (i < 2 || n ? ' open' : '') + '><summary>' + esc(g.group) + ' <small>' + n + ' / ' + g.fields.length + '</small></summary><div class="cct-form">' + g.fields.map(ctl).join('') + '</div></details>'; }).join('') +
          '<details class="cct-grp"' + (base.detail || base.issues || base.memo ? ' open' : '') + '><summary>설명 · 알려진 문제 · 메모 <small>위키처럼 길게 적는 곳</small></summary><div class="cct-form" style="grid-template-columns:1fr">' +
          '<label class="cct-f"><span>📖 자세한 설명 <small>특징 · 쓰임새 · 역사 등</small></span><textarea class="cct-in" style="min-height:120px" data-b="detail">' + esc(base.detail) + '</textarea></label>' +
          '<label class="cct-f"><span>⚠️ 알려진 문제 · 고질병 <small>한 줄에 하나 (예: 초기 물량 히트베드 케이블 리콜)</small></span><textarea class="cct-in" style="min-height:100px" data-b="issues">' + esc(base.issues) + '</textarea></label>' +
          '<label class="cct-f"><span>📝 메모 <small>자유롭게 — 상세 화면에 그대로 보여요</small></span><textarea class="cct-in" data-b="memo">' + esc(base.memo) + '</textarea></label></div></details>' +
          '<details class="cct-grp"' + (facts.length ? ' open' : '') + '><summary>기타 제원 <small>위에 없는 것 — 이름과 값을 자유롭게</small></summary><div><div class="cct-facts" data-facts>' +
          facts.map(function (f) { return '<div data-fact><input class="cct-in" value="' + esc(f[0]) + '" placeholder="이름"><input class="cct-in" value="' + esc(f[1]) + '" placeholder="값"><button type="button" class="cct-x" data-fact-del>✕</button></div>'; }).join('') +
          '</div><button type="button" class="cct-btn cct-btn--s" style="margin-top:8px" data-fact-add>➕ 줄 더하기</button></div></details></div>' +
          '<div class="cct-modal__foot"><span class="cct-msg" data-msg></span><button type="button" class="cct-btn" data-close>닫기</button><button type="button" class="cct-btn cct-btn--p" data-save>저장</button></div></div>';
        Array.prototype.forEach.call(m.querySelectorAll('[data-close]'), function (b) { b.onclick = close; });
        Object.keys(AIK).forEach(function (k) {
          var el = k === 'kind' ? m.querySelector('[data-kind]') : k.indexOf('b:') === 0 ? m.querySelector('[data-b="' + k.slice(2) + '"]') : k.indexOf('f:') === 0 ? null : m.querySelector('[data-k="' + k + '"],[data-yn="' + k + '"]');
          var f = el && el.closest('.cct-f');
          if (f) { f.classList.add('cct-f--ai'); f.insertAdjacentHTML('beforeend', '<small class="cct-prev">이전: <s>' + esc(show(k, PREV[k])) + '</s> <button type="button" data-undo="' + esc(k) + '">↩ 되돌리기</button></small>'); }
        });
        Array.prototype.forEach.call(m.querySelectorAll('[data-fact]'), function (r) { var i = r.querySelector('input'); if (i && AIK['f:' + i.value]) { r.classList.add('cct-f--ai'); r.title = '이전: ' + show('f:' + i.value, PREV['f:' + i.value]); } });
        Array.prototype.forEach.call(m.querySelectorAll('[data-undo]'), function (b) { b.onclick = function (e) { e.preventDefault(); e.stopPropagation(); collect(); undo(b.getAttribute('data-undo')); draw(); }; });
        var ua = m.querySelector('[data-undo-all]'); if (ua) ua.onclick = function (e) { e.preventDefault(); collect(); Object.keys(AIK).forEach(undo); draw(); };
        var aip = m.querySelector('[data-aip]');
        if (aip) {
          var ta = aip.querySelector('[data-aip-t]'), go2 = aip.querySelector('[data-aip-go]'), am = aip.querySelector('[data-aip-msg]');
          ta.oninput = function () { aipText = ta.value; };
          var run = function () {
            if (aip.classList.contains('busy')) return;   // 0.2.15 도는 중엔 한 번만 (붙여 넣기 · 단추가 겹쳐 AI 에 두 번 묻지 않게)
            aipText = ta.value.trim();
            if (!aipText) { am.textContent = '붙여 넣을 글이 없어요.'; ta.focus(); return; }
            collect(); go2.disabled = true; aip.classList.add('busy'); am.textContent = '⏳ AI 가 정리하는 중… (모델에 따라 1분쯤)';
            // 0.2.13 바로 일 번호를 받고 2초마다 확인 (AI 가 오래 걸려도 504 로 끊기지 않게)
            var t0 = Date.now(), S2 = {}, job = '';
            try { var rb = new Uint8Array(8); crypto.getRandomValues(rb); job = Array.prototype.map.call(rb, function (x) { return ('0' + x.toString(16)).slice(-2); }).join(''); } catch (e0) {}
            // 0.2.15 묻는 동안 몇 초째인지
            // 0.2.26 묻는 동안에도 3초마다 일 상태를 엿봄 — 어느 서버 · 모델에 묻는지 · 앞 모델 실패 · 서버가 멈췄는지 (예전엔 ⏳ 초만 보였음)
            var peek = new Promise(function (ok, no) { S2.ok = ok; S2.no = no; }), n4 = 0;
            var tk = setInterval(function () { var am4 = m.querySelector('[data-aip-msg]'); if (!document.body.contains(m) || !aip.classList.contains('busy')) return clearInterval(tk);
              if (am4 && !S2.polling) am4.textContent = '⏳ ' + (S2.stage || 'AI 가 정리하는 중') + '… ' + Math.round((Date.now() - t0) / 1000) + '초';
              if (job && !S2.polling && !S2.peeking && ++n4 % 3 === 0) { S2.peeking = true;
                api('GET', '/admin/ai/extract/' + job).then(function (st) { S2.peeking = false; S2.stage = st.stage || S2.stage || '';
                  if (st.status === 'done') S2.ok(st.data); else if (st.status === 'fail') S2.no(new Error(st.message || 'AI 가 정리하지 못했어요.')); }, function () { S2.peeking = false; }); }
            }, 1000);
            var wait = function (job) {
              return new Promise(function (ok, no) {
                (function poll() {
                  if (!document.body.contains(m)) return no(new Error('창을 닫았어요.'));
                  var s = Math.round((Date.now() - t0) / 1000), am3 = m.querySelector('[data-aip-msg]'); if (am3) am3.textContent = '⏳ ' + (S2.stage || 'AI 가 정리하는 중') + '… ' + s + '초';
                  S2.polling = true;
                  api('GET', '/admin/ai/extract/' + job).then(function (st) {
                    S2.stage = st.stage || '';
                    if (st.status === 'done') return ok(st.data);
                    if (st.status === 'fail') return no(new Error(st.message || 'AI 가 정리하지 못했어요.'));
                    // 0.2.25 서버 한도(16분)만큼 기다림 — 예전엔 화면이 7분 30초에 먼저 포기해 서버가 늦게 끝낸 결과를 버렸음
                    if (Date.now() - t0 > 1020000) return no(new Error('너무 오래 걸려요' + (S2.stage ? ' (마지막: ' + S2.stage + ')' : '') + ' — 글을 줄이거나 🤖 AI 연결에서 더 빠른 모델을 위로 올려 주세요.'));
                    setTimeout(poll, 2000);
                  }, function (e) { if (e.status && e.status !== 404 && Date.now() - t0 < 1020000) setTimeout(poll, 3000); else no(e); });
                })();
              });
            };
            Promise.race([api('POST', '/admin/ai/extract', { type: type, kind: kind, text: aipText, brand: base.brand, title: base.title, job: job }).catch(function (e) {
              // 앞단이 기다리다 끊음(504 · 502 · 연결 끊김) — 서버는 계속 돌고 있으니 같은 번호로 결과를 받음
              if (job && (!e.status || e.status === 502 || e.status === 503 || e.status === 504 || e.status === 524)) return wait(job);
              throw e;
            }), peek]).then(function (r) { clearInterval(tk);
              collect(); var n = put(r, true); aipText = ''; draw();
              var am2 = m.querySelector('[data-aip-msg]'); if (am2) am2.textContent = n ? '✅ ' + n + '칸을 채웠어요 (💡) — 맞는지 보고 저장해 주세요.' + (r.source_url ? ' · 📄 출처: ' + r.source_url : ' · 📄 출처: 붙여 넣은 글') + (r.from ? ' · 🤖 ' + r.from : '') : '글에서 채울 값을 찾지 못했어요.';
            }).catch(function (e) { go2.disabled = false; aip.classList.remove('busy'); am.textContent = '❌ ' + e.message; });
          };
          go2.onclick = run;
          ta.onpaste = function () { setTimeout(function () { if (ta.value.trim().length > 20 || /^https?:\/\/\S+$/i.test(ta.value.trim())) run(); }, 30); };
          var drop = function (e) {
            var dt = e.dataTransfer; if (!dt) return;
            var file = dt.files && dt.files[0];
            var txt = dt.getData('text/plain') || dt.getData('text/uri-list') || '';
            if (!file && !txt) return;
            e.preventDefault(); aip.classList.remove('over');
            if (file) {
              if (!/^text\/|\.(txt|md|html?|csv|json)$/i.test(file.type + ' ' + file.name)) { am.textContent = '글 파일(.txt · .html · .md)만 읽을 수 있어요 — PDF 는 글을 복사해 붙여 넣어 주세요.'; return; }
              var fr = new FileReader(); fr.onload = function () { ta.value = String(fr.result || '').slice(0, 60000); run(); }; fr.readAsText(file); return;
            }
            ta.value = txt; run();
          };
          // 상자 · 창 아무 데나 끌어 놓아도 (입력칸 위는 그 칸에 들어가게 둠)
          aip.ondragover = function (e) { e.preventDefault(); aip.classList.add('over'); };
          aip.ondragleave = function () { aip.classList.remove('over'); };
          aip.ondrop = drop;
          var box = m.querySelector('.cct-modal__box');
          box.ondragover = function (e) { if (!e.target.closest('input,textarea,select')) { e.preventDefault(); aip.classList.add('over'); } };
          box.ondrop = function (e) { if (!e.target.closest('input,textarea,select') || e.target === ta) drop(e); };
        }
        var ts = m.querySelector('[data-type]'); if (ts) ts.onchange = function () { collect(); type = ts.value; kind = META.kinds[type][0].key; draw(); };
        m.querySelector('[data-kind]').onchange = function () { collect(); kind = this.value; draw(); };
        Array.prototype.forEach.call(m.querySelectorAll('[data-yn]'), function (box) { Array.prototype.forEach.call(box.querySelectorAll('button'), function (b) { b.onclick = function () {
          var v = b.getAttribute('data-v'); V[box.getAttribute('data-yn')] = v === '' ? '' : v === 'true';
          Array.prototype.forEach.call(box.querySelectorAll('button'), function (x) { x.classList.toggle('on', x === b); }); }; }); });
        Array.prototype.forEach.call(m.querySelectorAll('[data-opts]'), function (box) { var inp = m.querySelector('[data-k="' + box.getAttribute('data-opts') + '"]');
          Array.prototype.forEach.call(box.querySelectorAll('button'), function (b) { b.onclick = function (e) { e.preventDefault();
            var list = inp.value.split(/[,\n]+/).map(function (x) { return x.trim(); }).filter(Boolean), t = b.textContent, i = list.indexOf(t);
            if (i >= 0) list.splice(i, 1); else list.push(t); inp.value = list.join(', '); b.classList.toggle('on', i < 0); }; }); });
        m.querySelector('[data-fact-add]').onclick = function () { collect(); facts.push(['', '']); draw(); var all = m.querySelectorAll('[data-fact] input'); if (all.length) all[all.length - 2].focus(); };
        Array.prototype.forEach.call(m.querySelectorAll('[data-fact-del]'), function (b, i) { b.onclick = function () { collect(); facts.splice(i, 1); draw(); }; });
        m.querySelector('[data-save]').onclick = function () {
          collect();
          var b = this, msg = m.querySelector('[data-msg]'), fo = {}; facts.forEach(function (f) { if (f[0] && f[1]) fo[f[0]] = f[1]; });
          var body = { type: type, kind: kind, brand: base.brand, title: base.title, summary: base.summary, note: base.note, homepage_url: base.homepage_url, wiki_url: base.wiki_url, detail: base.detail, issues: base.issues, memo: base.memo, values: V, facts: fo };
          if (d) body.key = d.key;
          b.disabled = true; msg.textContent = '';
          (opt.save ? opt.save(body) : api('POST', '/admin/items', body)).then(function (r) { close(); toast(api.last || '저장했어요.'); if (done) done(r && r.key); }).catch(function (e) { b.disabled = false; msg.textContent = e.message; });
        };
      };
      draw();
      if (!d) { var f0 = m.querySelector('[data-b="brand"]'); if (f0) f0.focus(); }
    };
    meta().then(function () {
      if (opt.detail && opt.detail.values) return start(opt.detail);
      if (opt.key) return api('GET', '/items/' + encodeURIComponent(opt.key)).then(start);
      start(null);
    }).catch(function (e) { toast(e.message); });
  }

  /* ───────── 시작 ───────── */
  var lastUrl = '';
  function tick() {
    var root = document.getElementById('cct_root');
    if (!root) { lastUrl = ''; return; }
    var url = location.pathname + location.search;
    if (root.__cct === url && root.firstChild) return;
    root.__cct = url; lastUrl = url;
    css();
    var m = /\/catalog\/([a-z0-9][a-z0-9_-]{1,79})\/?$/.exec(location.pathname);
    if (!root.firstChild) root.innerHTML = '<div class="cct"><div class="cct-skel" style="height:190px;border-radius:24px"></div></div>';
    Promise.all([meta(), icons()]).then(function () { if (root.__cct !== url) return; if (m) detailPage(root, m[1]); else listPage(root); try { window.scrollTo(0, 0); } catch (e) {} })
      .catch(function (e) { root.innerHTML = '<div class="cct"><div class="cct-empty"><b>⚠️</b>카탈로그를 열지 못했어요.<br>' + esc(e.message) + '</div></div>'; });
  }
  window.CCT = { version: VERSION, api: api, esc: esc, toast: toast, css: css, meta: meta, getMeta: function () { return META; }, openEditor: openEditor, logoEditor: logoEditor, cardHtml: cardHtml, logoOf: logoOf, logoImg: logoImg, art: art, icons: icons, tick: tick, go: go, hue: hue, kindIcon: kindIcon };
  window.addEventListener('popstate', function () { setTimeout(tick, 0); });
  try { new MutationObserver(function () { var r = document.getElementById('cct_root'); if (r && (r.__cct !== location.pathname + location.search || !r.firstChild)) tick(); }).observe(document.body, { childList: true, subtree: true }); } catch (e) {}
  setInterval(tick, 800);
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', tick); else tick();
})();
