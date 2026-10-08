/*! custom-catalog 0.2.5 — 3D 카탈로그 (목록 · 상세 · 편집) */
(function () {
  'use strict';
  if (window.CCT) { try { window.CCT.tick(); } catch (e) {} return; }
  var VERSION = '0.2.5', API = '/api/modules/custom-catalog', BASE = '/catalog';

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
    try {
      if (c.type === 'materials') return I.material({ kind: c.kind, material: c.material || c.title, color_hex: /^#[0-9a-f]{3,6}$/i.test(c.color_hex || '') || /gradient/.test(c.color_hex || '') ? c.color_hex : hueHex(hue(c.brand + c.title)) }, px);
      return I.equipment({ kind: c.kind, enclosed: c.enclosed, tint: hueHex(hue(c.brand)) }, px);
    } catch (e) { return '<i>' + kindIcon(c.type, c.kind) + '</i>'; }
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
    return { tab: p.get('tab') || '', brand: p.get('brand') || '', q: (p.get('q') || '').trim(), sort: p.get('sort') || '', flags: (p.get('flags') || '').split(',').filter(Boolean), status: p.get('status') || '' };
  }
  function urlOf(s) {
    var p = new URLSearchParams();
    ['tab', 'brand', 'q', 'sort', 'status'].forEach(function (k) { if (s[k]) p.set(k, s[k]); });
    if (s.flags && s.flags.length) p.set('flags', s.flags.join(','));
    var qs = p.toString();
    return BASE + (qs ? '?' + qs : '');
  }
  function record(term) {
    if (!term || term.length > 40) return;
    try { if (typeof window.__cpsRecord === 'function') { window.__cpsRecord(term, 'catalog'); return; } } catch (e) {}
    try { fetch('/api/plugins/custom-popular_search/record', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify({ scope: 'catalog', term: term }) }).catch(function () {}); } catch (e2) {}
  }
  function listPage(root) {
    var S = readState(), can = !!META.can_edit;
    if (!S.q && !tabOf(S.tab)) S.tab = (META.tabs[0] || {}).key || '';
    var tab = tabOf(S.tab), total = 0;
    Object.keys(META.counts).forEach(function (k) { total += META.counts[k]; });
    var eq = 0, mt = 0; META.tabs.forEach(function (t) { if (t.type === 'materials') mt += META.counts[t.key] || 0; else eq += META.counts[t.key] || 0; });
    root.innerHTML = '<div class="cct"><div class="cct-hero"><div class="cct-hero__grid"></div><h1>🧊 3D 카탈로그</h1><p>3D 프린터 · 장비 · 필라멘트 · 레진의 제원을 한곳에서 찾아보세요.</p>' +
      '<form class="cct-search" data-search><input type="search" name="q" autocomplete="off" placeholder="제조사 · 모델 · 재료 이름으로 찾기 (예: Bambu P1S, PETG)" value="' + esc(S.q) + '"><button type="submit">찾기</button></form>' +
      '<div class="cct-stats"><span>장비 ' + eq.toLocaleString() + '종</span><span>재료 ' + mt.toLocaleString() + '종</span>' + (can ? '<span>✏️ 편집할 수 있어요</span>' : '') + '</div></div>' +
      '<div class="cct-tabs" role="tablist">' + META.tabs.map(function (t) {
        return '<button type="button" role="tab" class="cct-tab' + (tab && t.key === tab.key ? ' on' : '') + '" data-tab="' + t.key + '"><i>' + t.icon + '</i>' + esc(t.label) + '<small>' + (META.counts[t.key] || 0) + '</small></button>';
      }).join('') + '</div><div data-bar></div><div class="cct-count" data-count></div><div class="cct-grid" data-grid>' + new Array(9).join('<div class="cct-skel"></div>') + '</div><div class="cct-more" data-more></div></div>';
    var box = root.querySelector('.cct'), grid = box.querySelector('[data-grid]'), bar = box.querySelector('[data-bar]'), more = box.querySelector('[data-more]'), cnt = box.querySelector('[data-count]');
    var nav = function (patch) { var n = {}; Object.keys(S).forEach(function (k) { n[k] = S[k]; }); Object.keys(patch).forEach(function (k) { n[k] = patch[k]; }); go(urlOf(n)); };
    box.querySelector('[data-search]').onsubmit = function (e) { e.preventDefault(); var q = this.q.value.trim(); if (q) record(q); nav({ q: q, brand: '' }); };
    Array.prototype.forEach.call(box.querySelectorAll('[data-tab]'), function (b) { b.onclick = function () { nav({ tab: b.getAttribute('data-tab'), brand: '', flags: [], q: '' }); }; });
    bindCards(box, function () { meta(true).then(function () { listPage(root); }); });

    var types = S.q && !tab ? ['equipment', 'materials'] : [tab ? tab.type : 'equipment'], page = 1, pages = 1, shown = 0, sum = 0;
    var query = function (type, pg) {
      var p = new URLSearchParams({ page: pg, per: META.per_page });
      if (tab) p.set('tab', tab.key); else p.set('type', type);
      ['brand', 'q', 'sort', 'status'].forEach(function (k) { if (S[k]) p.set(k, S[k]); });
      if (S.flags.length) p.set('flags', S.flags.join(','));
      return api('GET', '/items?' + p.toString());
    };
    var drawBar = function (brands) {
      var isPrinter = tab && tab.type === 'equipment' && ['fdm', 'resin_printer', 'industrial'].indexOf(tab.key) >= 0, flag = function (k, label) {
        return '<button type="button" class="cct-chip' + (S.flags.indexOf(k) >= 0 ? ' on' : '') + '" data-flag="' + k + '">' + label + '</button>'; };
      bar.innerHTML = (brands.length > 1 || S.brand ? '<div class="cct-bar"><div class="cct-brands" data-brands><button type="button" class="cct-chip' + (S.brand ? '' : ' on') + '" data-brand="">전체</button>' +
        brands.map(function (b) { return '<button type="button" class="cct-chip' + (S.brand === b.name ? ' on' : '') + '" data-brand="' + esc(b.name) + '">' + logoImg(b.name) + esc(b.name) + ' <small>' + b.n + '</small></button>'; }).join('') +
        '</div>' + (brands.length > 14 ? '<button type="button" class="cct-btn cct-btn--s" data-brands-more>제조사 더 보기</button>' : '') + '</div>' : '') +
        '<div class="cct-bar">' + (tab && tab.key === 'fdm' ? flag('multicolor', '🌈 다색') : '') + (isPrinter ? flag('enclosed', '📦 밀폐형') : '') + flag('photo', '🖼️ 사진 있는 것') +
        (S.q ? '<button type="button" class="cct-chip on" data-clear-q>「' + esc(S.q) + '」 ✕</button>' : '') +
        '<span style="flex:1"></span>' + (can ? '<button type="button" class="cct-chip' + (S.status === 'archived' ? ' on' : '') + '" data-arch>🗄️ 보관한 것</button><button type="button" class="cct-btn cct-btn--s cct-btn--p" data-new>➕ 새 항목</button>' : '') +
        '<select class="cct-sel" data-sort aria-label="정렬"><option value="">사진 있는 것부터</option><option value="name"' + (S.sort === 'name' ? ' selected' : '') + '>이름순</option><option value="new"' + (S.sort === 'new' ? ' selected' : '') + '>새로 들어온 순</option>' +
        (tab && tab.type === 'equipment' ? '<option value="size"' + (S.sort === 'size' ? ' selected' : '') + '>출력 크기 큰 순</option>' : '') + '<option value="updated"' + (S.sort === 'updated' ? ' selected' : '') + '>최근 고친 순</option></select></div>';
      Array.prototype.forEach.call(bar.querySelectorAll('[data-brand]'), function (b) { b.onclick = function () { nav({ brand: b.getAttribute('data-brand') }); }; });
      Array.prototype.forEach.call(bar.querySelectorAll('[data-flag]'), function (b) { b.onclick = function () { var k = b.getAttribute('data-flag'), f = S.flags.slice(), i = f.indexOf(k); if (i >= 0) f.splice(i, 1); else f.push(k); nav({ flags: f }); }; });
      var bm = bar.querySelector('[data-brands-more]'); if (bm) bm.onclick = function () { var el = bar.querySelector('[data-brands]'); el.classList.toggle('open'); bm.textContent = el.classList.contains('open') ? '접기' : '제조사 더 보기'; };
      var cq = bar.querySelector('[data-clear-q]'); if (cq) cq.onclick = function () { nav({ q: '', tab: S.tab || (META.tabs[0] || {}).key }); };
      var ar = bar.querySelector('[data-arch]'); if (ar) ar.onclick = function () { nav({ status: S.status === 'archived' ? '' : 'archived' }); };
      var nw = bar.querySelector('[data-new]'); if (nw) nw.onclick = function () { openEditor({ type: tab ? tab.type : 'equipment', kind: tab ? tab.kinds[0] : 'fdm' }, function (key) { meta(true); go(BASE + '/' + key); }); };
      bar.querySelector('[data-sort]').onchange = function () { nav({ sort: this.value }); };
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
        (d.type === 'equipment' ? '<a class="cct-btn" href="/companies?q=' + encodeURIComponent(d.title) + '">🏢 이 장비를 가진 업체 찾기</a>' : '<a class="cct-btn" href="/companies?q=' + encodeURIComponent(by.material ? by.material.value : d.title) + '">🏢 이 재료로 출력하는 업체 찾기</a>') +
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
      if (d.related.length) h += '<div class="cct-sec"><h2>🏷️ ' + esc(d.brand) + '의 다른 제품</h2><div class="cct-grid">' + d.related.map(function (c) { return cardHtml(c, false); }).join('') + '</div></div>';
      root.innerHTML = h + '</div>';
      var box = root.querySelector('.cct'), cur = 0, q = function (s) { return box.querySelector(s); };
      bindCards(box, reload);
      try { document.title = d.brand + ' ' + d.title + ' — 3D 카탈로그'; } catch (e) {}
      q('[data-print]').onclick = function () { window.print(); };
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
      };
      var draw = function () {
        var groups = META.fields[type].map(function (g) { return { group: g.group, fields: g.fields.filter(function (f) { return !f.kinds.length || f.kinds.indexOf(kind) >= 0; }) }; }).filter(function (g) { return g.fields.length; });
        var has = function (g) { return g.fields.filter(function (f) { var v = V[f.key]; return v !== undefined && v !== null && v !== '' && !(Array.isArray(v) && !v.length); }).length; };
        m.innerHTML = '<div class="cct-modal__box"><div class="cct-modal__head"><h3>' + (d ? '✏️ ' + esc(d.brand + ' ' + d.title) + ' 고치기' : '➕ 새 항목') + '</h3><button type="button" class="cct-x" data-close aria-label="닫기">✕</button></div>' +
          '<div class="cct-modal__body"><div class="cct-form">' +
          (d ? '' : '<label class="cct-f"><span>무엇</span><select class="cct-in" data-type><option value="equipment"' + (type === 'equipment' ? ' selected' : '') + '>장비 · 프린터</option><option value="materials"' + (type === 'materials' ? ' selected' : '') + '>재료 (필라멘트 · 레진 …)</option></select></label>') +
          '<label class="cct-f"><span>종류</span><select class="cct-in" data-kind>' + META.kinds[type].map(function (k) { return '<option value="' + k.key + '"' + (k.key === kind ? ' selected' : '') + '>' + k.icon + ' ' + esc(k.label) + '</option>'; }).join('') + '</select></label>' +
          '<label class="cct-f"><span>제조사</span><input class="cct-in" data-b="brand" value="' + esc(base.brand) + '" placeholder="예: Bambu Lab"></label>' +
          '<label class="cct-f"><span>' + (type === 'materials' ? '제품 이름' : '모델 이름') + '</span><input class="cct-in" data-b="title" value="' + esc(base.title) + '" placeholder="' + (type === 'materials' ? '예: PLA Basic' : '예: P1S') + '"></label>' +
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
          api('POST', '/admin/items', body).then(function (r) { close(); toast(api.last || '저장했어요.'); if (done) done(r.key); }).catch(function (e) { b.disabled = false; msg.textContent = e.message; });
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
