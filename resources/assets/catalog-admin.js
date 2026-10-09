/*! custom-catalog 0.2.11 — 관리자 (항목 · 제안함 · 자동 수집 · AI 연결 · 설정) */
(function () {
  'use strict';
  if (window.__cctAdmin) { try { window.__cctAdmin(); } catch (e) {} return; }
  var PAGES = [['items', '📦 항목'], ['suggest', '💡 제안함'], ['auto', '🌙 자동 수집'], ['ai', '🤖 AI 연결'], ['settings', '⚙️ 설정']];
  function C() { return window.CCT; }
  function need(cb) {
    if (window.CCT) return cb();
    if (!document.getElementById('cct-app-js')) { var s = document.createElement('script'); s.id = 'cct-app-js'; s.src = '/api/modules/custom-catalog/assets/catalog-app.js?v=0.2.11'; document.head.appendChild(s); }
    var n = 0, t = setInterval(function () { if (window.CCT || ++n > 100) { clearInterval(t); if (window.CCT) cb(); } }, 60);
  }
  function page() {
    var p = new URLSearchParams(location.search).get('p');
    if (!p && /\/admin\/catalog\/settings\/?$/.test(location.pathname)) p = 'auto';
    return PAGES.some(function (x) { return x[0] === p; }) ? p : 'items';
  }
  function sw(on, attr) { return '<button type="button" class="cct-sw' + (on ? ' on' : '') + '" ' + attr + ' aria-pressed="' + !!on + '"></button>'; }
  var pending = 0;
  function frame(root, cur, body) {
    root.innerHTML = '<div class="cct cct-adm"><div class="cct-adm__head"><h1>🧊 3D 카탈로그</h1><a class="cct-btn cct-btn--s" href="/catalog" target="_blank" rel="noopener">사이트에서 보기 ↗</a>' +
      '<p>프린터 · 장비 · 필라멘트 · 레진 자료를 모아 두는 곳이에요. 업체검색의 장비 · 재고 등록 화면이 여기 목록을 가져다 써요 (사진은 업체검색 것을 그대로).</p></div>' +
      '<div class="cct-nav">' + PAGES.map(function (p) { return '<a href="?p=' + p[0] + '" data-p="' + p[0] + '" class="' + (p[0] === cur ? 'on' : '') + '">' + p[1] + (p[0] === 'suggest' && pending ? '<b>' + pending + '</b>' : '') + '</a>'; }).join('') + '</div>' +
      '<div data-body>' + (body || '<div class="cct-skel"></div>') + '</div></div>';
    Array.prototype.forEach.call(root.querySelectorAll('[data-p]'), function (a) { a.onclick = function (e) {
      if (e.metaKey || e.ctrlKey) return; e.preventDefault();
      try { history.pushState({}, '', location.pathname.replace(/\/settings\/?$/, '') + '?p=' + a.getAttribute('data-p')); } catch (er) {}
      root.__cctA = ''; tick();
    }; });
    return root.querySelector('[data-body]');
  }
  function fail(body, e) { body.innerHTML = '<div class="cct-empty"><b>⚠️</b>' + C().esc(e.message) + '</div>'; }

  /* ───────── 항목 ───────── */
  function itemsPage(root) {
    var esc = C().esc, api = C().api, body = frame(root, 'items'), M = C().getMeta();
    var S = { tab: (M.tabs[0] || {}).key, q: '', status: 'active', flags: '', page: 1 };
    var draw = function () {
      var p = new URLSearchParams({ tab: S.tab, page: S.page, per: 30, status: S.status, sort: S.q ? '' : 'updated' });
      if (S.q) p.set('q', S.q); if (S.flags) p.set('flags', S.flags);
      api('GET', '/items?' + p.toString()).then(function (r) {
        var tab = M.tabs.filter(function (t) { return t.key === S.tab; })[0];
        body.innerHTML = '<div class="cct-panel"><div class="cct-bar"><select class="cct-sel" data-tab>' + M.tabs.map(function (t) { return '<option value="' + t.key + '"' + (t.key === S.tab ? ' selected' : '') + '>' + t.icon + ' ' + esc(t.label) + ' (' + (M.counts[t.key] || 0) + ')</option>'; }).join('') + '</select>' +
          '<form data-f style="display:flex;gap:6px;flex:1;min-width:180px"><input class="cct-in" style="height:36px" name="q" placeholder="제조사 · 모델 찾기" value="' + esc(S.q) + '"><button class="cct-btn cct-btn--s" style="height:36px">찾기</button></form>' +
          '<button type="button" class="cct-chip' + (S.flags === 'nophoto' ? ' on' : '') + '" data-nophoto>🖼️ 사진 없는 것</button>' +
          '<button type="button" class="cct-chip' + (S.status === 'archived' ? ' on' : '') + '" data-arch>🗄️ 보관한 것</button>' +
          '<button type="button" class="cct-btn cct-btn--p cct-btn--s" style="height:36px" data-new>➕ 새 항목</button></div>' +
          '<div class="cct-count"><b>' + r.total.toLocaleString() + '</b>개' + (r.pages > 1 ? ' · ' + r.page + ' / ' + r.pages + '쪽' : '') + '</div>' +
          (r.items.length ? '<div style="overflow-x:auto"><table class="cct-table"><thead><tr><th></th><th>제조사</th><th>' + (tab.type === 'materials' ? '제품' : '모델') + '</th><th>종류</th><th>요약</th><th></th></tr></thead><tbody>' +
            r.items.map(function (c) {
              return '<tr><td>' + (c.image ? '<img alt="" loading="lazy" src="' + esc(c.image) + '">' : '<span class="cct-noimg">없음</span>') + '</td><td>' + esc(c.brand) + '</td><td><b>' + esc(c.title) + '</b></td><td>' + esc(c.kind_label) + '</td><td style="color:var(--mute);font-size:13px">' + esc(c.chips.join(' · ')) + '</td>' +
                '<td style="white-space:nowrap;text-align:right"><a class="cct-btn cct-btn--s" target="_blank" rel="noopener" href="/catalog/' + esc(c.key) + '">보기</a> <button type="button" class="cct-btn cct-btn--s" data-ed="' + esc(c.key) + '">✏️ 수정</button> ' +
                (c.status === 'active' ? '<button type="button" class="cct-btn cct-btn--s cct-btn--d" data-del="' + esc(c.key) + '" data-name="' + esc(c.brand + ' ' + c.title) + '">지우기</button>' : '<button type="button" class="cct-btn cct-btn--s" data-res="' + esc(c.key) + '">되살리기</button> <button type="button" class="cct-btn cct-btn--s cct-btn--d" data-hard="' + esc(c.key) + '">아주 지우기</button>') + '</td></tr>';
            }).join('') + '</tbody></table></div>' : '<div class="cct-empty"><b>📭</b>항목이 없어요.</div>') +
          (r.pages > 1 ? '<div class="cct-more"><button type="button" class="cct-btn cct-btn--s" data-pg="' + (r.page - 1) + '"' + (r.page <= 1 ? ' disabled' : '') + '>‹ 앞</button>&nbsp;<button type="button" class="cct-btn cct-btn--s" data-pg="' + (r.page + 1) + '"' + (r.page >= r.pages ? ' disabled' : '') + '>뒤 ›</button></div>' : '') +
          '<p class="cct-note" style="margin-top:12px">사진은 「보기」로 들어간 상세 화면에서 올리거나 주소로 넣을 수 있어요 (대표 사진 정하기 · 지우기도 거기서).</p></div>';
        var q = function (s) { return body.querySelector(s); }, all = function (s, fn) { Array.prototype.forEach.call(body.querySelectorAll(s), fn); };
        var again = function () { C().meta(true).then(function (m) { M = m; draw(); }); };
        q('[data-tab]').onchange = function () { S.tab = this.value; S.page = 1; draw(); };
        q('[data-f]').onsubmit = function (e) { e.preventDefault(); S.q = this.q.value.trim(); S.page = 1; draw(); };
        q('[data-nophoto]').onclick = function () { S.flags = S.flags ? '' : 'nophoto'; S.page = 1; draw(); };
        q('[data-arch]').onclick = function () { S.status = S.status === 'archived' ? 'active' : 'archived'; S.page = 1; draw(); };
        q('[data-new]').onclick = function () { C().openEditor({ type: tab.type, kind: tab.kinds[0] }, again); };
        all('[data-pg]', function (b) { b.onclick = function () { S.page = +b.getAttribute('data-pg'); draw(); }; });
        all('[data-ed]', function (b) { b.onclick = function () { C().openEditor({ key: b.getAttribute('data-ed') }, again); }; });
        all('[data-del]', function (b) { b.onclick = function () { if (confirm('「' + b.getAttribute('data-name') + '」 을(를) 지울까요? (보관함으로 옮겨져요)')) api('POST', '/admin/items/' + b.getAttribute('data-del') + '/delete').then(function () { C().toast('보관함으로 옮겼어요.'); again(); }).catch(function (e) { C().toast(e.message); }); }; });
        all('[data-res]', function (b) { b.onclick = function () { api('POST', '/admin/items/' + b.getAttribute('data-res') + '/restore').then(function () { C().toast('되살렸어요.'); again(); }).catch(function (e) { C().toast(e.message); }); }; });
        all('[data-hard]', function (b) { b.onclick = function () { if (confirm('아주 지우면 되살릴 수 없어요. 지울까요?')) api('POST', '/admin/items/' + b.getAttribute('data-hard') + '/delete', { hard: 1 }).then(function () { C().toast('아주 지웠어요.'); again(); }).catch(function (e) { C().toast(e.message); }); }; });
      }).catch(function (e) { fail(body, e); });
    };
    draw();
  }

  /* ───────── 제안함 ───────── */
  function suggestPage(root) {
    var esc = C().esc, api = C().api, body = frame(root, 'suggest'), st = 'pending';
    var NAME = { 'new': '새 항목', fill: '제원 채우기', photo: '사진', sds: '안전 자료' };
    // 0.2.11 🔗 출처 — AI 가 댄 주소(✅ 열림 · ⚠️ 안 열림) · 없으면 「AI 기억」 경고 · 누가(서버 · 모델) 찾았는지
    var srcHtml = function (s) {
      if (s.task === 'photo') return '';
      var by = s.source ? '<small class="cct-src__by">' + (/회원|안전 자료/.test(s.source) ? '📥 ' : '🤖 ') + esc(s.source) + '</small>' : '';
      if (!s.sources || !s.sources.length) return '<div class="cct-src none"><b>🔗 출처</b>' + (/회원/.test(s.source || '') ? '<span>업체검색 회원 등록</span>' : '<span>⚠️ 출처 없음 — AI 가 기억으로 적은 값이에요 (틀릴 수 있어요)</span>') + by + '</div>';
      return '<div class="cct-src"><b>🔗 출처</b>' + s.sources.map(function (x) {
        var h = x.url.replace(/^https?:\/\/(www\.)?/i, '').slice(0, 60);
        return '<a target="_blank" rel="noopener noreferrer" href="' + esc(x.url) + '" title="' + esc(x.url) + '">' + (x.ok ? '✅ ' : '⚠️ ') + esc(h) + '</a>';
      }).join('') + (s.sources.some(function (x) { return !x.ok; }) ? '<small>⚠️ 는 열리지 않은 주소 — AI 가 지어냈을 수 있어요</small>' : '') + by + '</div>';
    };
    var draw = function () {
      api('GET', '/admin/suggestions?status=' + st).then(function (r) {
        pending = r.pending;
        var nb = root.querySelector('[data-p="suggest"]'); if (nb) nb.innerHTML = '💡 제안함' + (pending ? '<b>' + pending + '</b>' : '');
        body.innerHTML = '<div class="cct-panel"><h3>💡 AI 가 찾아온 것</h3><p>자동 수집이 찾은 새 모델 · 제원 · 사진 · 안전 자료가 여기 쌓여요. 맞는지 보고 「반영」을 누르면 카탈로그에 들어가요. AI 는 틀릴 수 있으니 숫자는 한 번 확인해 주세요. 「제원 채우기」는 비어 있던 칸만 채워요.</p>' +
          '<div class="cct-bar">' + [['pending', '기다리는 것 ' + r.pending], ['applied', '반영한 것'], ['rejected', '버린 것']].map(function (x) { return '<button type="button" class="cct-chip' + (st === x[0] ? ' on' : '') + '" data-st="' + x[0] + '">' + x[1] + '</button>'; }).join('') +
          '<span style="flex:1"></span>' + (st === 'pending' && r.items.length > 1 ? '<button type="button" class="cct-btn cct-btn--s" data-all>보이는 것 모두 반영</button>' : '') + '</div></div>' +
          (r.items.length ? '<div class="cct-sg">' + r.items.map(function (s) {
            return '<div class="cct-sgc" data-sg="' + s.id + '"><div class="cct-sgc__t"><em class="' + s.task + '">' + NAME[s.task] + '</em><span>' + esc(s.title) + '</span></div>' +
              (s.kind_label ? '<small>' + esc(s.kind_label) + '</small>' : '') +
              (s.image_url ? '<img alt="" loading="lazy" referrerpolicy="no-referrer" src="' + esc(s.image_url) + '"><small>출처: ' + (s.page_url ? '<a target="_blank" rel="noopener" style="text-decoration:underline" href="' + esc(s.page_url) + '">' + esc(s.credit || s.page_url) + '</a>' : esc(s.credit)) + '</small>' : '') +
              (s.summary ? '<p style="font-size:13.5px">' + esc(s.summary) + '</p>' : '') +
              (s.lines.length ? '<dl>' + s.lines.map(function (l) { return '<div><dt>' + esc(l.label) + '</dt><dd>' + esc(l.value) + '</dd></div>'; }).join('') + '</dl>' : '') +
              srcHtml(s) +
              (s.task === 'sds' && s.page_url ? '<a class="cct-btn cct-btn--s" target="_blank" rel="noopener" href="' + esc(s.page_url) + '">🧾 열어서 확인</a>' : '') +
              '<small>' + esc(s.at) + '</small>' +
              (s.status === 'pending' ? '<div class="cct-bar" style="margin:4px 0 0">' + (s.draft ? '<button type="button" class="cct-btn cct-btn--p cct-btn--s" data-fix>✏️ 고쳐서 반영</button>' : '') + '<button type="button" class="cct-btn cct-btn--s" data-ok>✔ 그대로 반영</button><button type="button" class="cct-btn cct-btn--s cct-btn--d" data-no>버리기</button>' +
                (s.key ? '<a class="cct-btn cct-btn--s" target="_blank" rel="noopener" href="/catalog/' + esc(s.key) + '">지금 항목 보기</a>' : '') + '</div>' :
                (s.key ? '<a class="cct-btn cct-btn--s" target="_blank" rel="noopener" href="/catalog/' + esc(s.key) + '">항목 보기</a>' : '')) + '</div>';
          }).join('') + '</div>' : '<div class="cct-empty"><b>🌙</b>' + (st === 'pending' ? '기다리는 제안이 없어요. 「🌙 자동 수집」에서 켜거나 「지금 한 번 돌리기」를 눌러 보세요.' : '없어요.') + '</div>');
        Array.prototype.forEach.call(body.querySelectorAll('[data-st]'), function (b) { b.onclick = function () { st = b.getAttribute('data-st'); draw(); }; });
        var act = function (card, what) { return api('POST', '/admin/suggestions/' + card.getAttribute('data-sg') + '/' + what).then(function () { card.remove(); }); };
        Array.prototype.forEach.call(body.querySelectorAll('[data-sg]'), function (card) {
          var ok = card.querySelector('[data-ok]'), no = card.querySelector('[data-no]');
          if (ok) ok.onclick = function () { ok.disabled = true; act(card, 'apply').then(function () { C().toast('반영했어요.'); C().meta(true); draw(); }).catch(function (e) { ok.disabled = false; C().toast(e.message); }); };
          if (no) no.onclick = function () { act(card, 'reject').then(draw).catch(function (e) { C().toast(e.message); }); };
          // 0.2.11 편집 창에서 고친 뒤 반영 (새 항목 · 제원 · 안전 자료) — 이름 말고 틀린 값이 많아서
          var fx = card.querySelector('[data-fix]'), sg = r.items.filter(function (x) { return String(x.id) === card.getAttribute('data-sg'); })[0];
          if (fx && sg) fx.onclick = function () {
            var save = function (body) { return api('POST', '/admin/suggestions/' + sg.id + '/apply', { edited: body }); };
            var note = '💡 AI 가 가져온 값이에요 — 틀린 것은 고치고, 아래 「🤖 AI 로 정리해 넣기」에 제조사 페이지 글을 붙여 넣으면 진짜 값으로 채워져요.';
            var after = function () { C().toast('반영했어요.'); C().meta(true); draw(); };
            if (sg.task === 'new') C().openEditor({ type: sg.type, kind: (sg.draft || {}).kind, draft: sg.draft, head: '💡 ' + sg.title + ' — 고쳐서 반영', note: note, sources: sg.sources, save: save }, after);
            else C().openEditor({ key: sg.key, draft: sg.draft, head: '💡 ' + sg.title + ' — 고쳐서 반영', note: note, sources: sg.sources, save: save }, after);
          };
        });
        var all = body.querySelector('[data-all]');
        if (all) all.onclick = function () {
          if (!confirm('보이는 제안 ' + r.items.length + '개를 모두 반영할까요?')) return;
          all.disabled = true;
          var cards = Array.prototype.slice.call(body.querySelectorAll('[data-sg]')), n = 0, bad = 0;
          (function next() { var c = cards.shift(); if (!c) { C().toast(n + '개 반영' + (bad ? ' · ' + bad + '개 실패' : '')); C().meta(true); draw(); return; } act(c, 'apply').then(function () { n++; next(); }, function () { bad++; next(); }); })();
        };
      }).catch(function (e) { fail(body, e); });
    };
    draw();
  }

  /* ───────── 자동 수집 ───────── */
  function autoPage(root) {
    var esc = C().esc, api = C().api, body = frame(root, 'auto'), S = null;
    var row = function (title, sub, ctl) { return '<div class="cct-row"><div><b>' + title + '</b><small>' + sub + '</small></div>' + ctl + '</div>'; };
    var num = function (k, unit, min, max) { return '<span class="cct-num"><input class="cct-in" type="number" min="' + min + '" max="' + max + '" data-n="' + k + '" value="' + S[k] + '"> ' + unit + '</span>'; };
    var draw = function (d) {
      S = d.settings; var c = d.collect; pending = c.pending;
      body.innerHTML = '<div class="cct-panel"><h3>🌙 조용할 때 자동 수집</h3><p>서버가 한가한 시간에 AI 가 새 모델 · 재료를 찾고, 빈 제원을 채우고, 사진을 찾아요. 한 번에 조금씩만 해요.</p>' +
        '<div class="cct-bar"><span class="cct-state ' + (c.quiet.ok ? 'ok' : 'no') + '">' + (c.quiet.ok ? '🟢 지금 돌 수 있어요' : '⏸ ' + esc(c.quiet.reason)) + '</span>' +
        '<span class="cct-state ' + (c.ai.enabled ? 'ok' : 'no') + '">' + (c.ai.enabled ? '🤖 AI 연결됨' : '🤖 ' + esc(c.ai.reason)) + '</span>' +
        (c.load !== null ? '<span class="cct-chip">서버 부하 ' + c.load + '%</span>' : '') + '<span class="cct-chip">오늘 ' + c.today + '개</span>' + (c.last ? '<span class="cct-chip">마지막 ' + esc(c.last) + '</span>' : '') +
        (c.pending ? '<a class="cct-chip on" href="?p=suggest" data-to-sg>💡 기다리는 제안 ' + c.pending + '</a>' : '') + '</div>' +
        row('자동 수집 켜기', '끄면 아래 「지금 한 번 돌리기」로만 돌아요', sw(S.auto, 'data-b="auto"')) +
        row('도는 시간', '이 시간대에만 (시작과 끝이 같으면 하루 종일) · 자정을 넘겨도 돼요 (예: 22시 ~ 6시)', '<span class="cct-num"><input class="cct-in" type="number" min="0" max="23" data-n="auto_from" value="' + S.auto_from + '"> 시 ~ <input class="cct-in" type="number" min="0" max="23" data-n="auto_to" value="' + S.auto_to + '"> 시</span>') +
        row('서버 부하 기준', '이 서버(웹 서버)의 CPU 부하가 이보다 낮을 때만 돌아요', num('auto_load', '% 아래', 5, 100)) +
        row('쉬는 시간', '한 번 돌고 나서 다음까지', num('auto_every', '분', 1, 720)) +
        row('한 번에 하는 일', 'AI 에게 묻는 횟수와 같아요', num('auto_per_run', '개', 1, 10)) +
        row('하루 최대', '', num('auto_per_day', '개', 1, 500)) + '</div>' +
        '<div class="cct-panel"><h3>🧩 할 일</h3><p>켠 것을 차례로 돌아가며 해요.</p>' +
        row('🏢 회원이 등록한 것 가져오기', '업체검색에서 회원이 적어 넣은 장비 모델 · 재료 — 업체검색 규칙 그대로 (관리자가 승인했거나 서로 다른 업체 여러 곳이 쓴 것만 · 숨김/합침은 빼고). AI 를 쓰지 않아요', sw(S.task_members, 'data-b="task_members"')) +
        row('🆕 새 모델 · 재료 찾기', '제조사를 돌아가며 「목록에 없는 제품」을 AI 에게 물어요', sw(S.task_new, 'data-b="task_new"') ) +
        row('📝 빈 제원 채우기', '제원이 덜 찬 항목의 빈 칸만 물어요 (적혀 있는 값은 건드리지 않아요)', sw(S.task_fill, 'data-b="task_fill"')) +
        row('🖼️ 사진 찾기', '사진 없는 항목 — 제품 공식 페이지의 대표 사진 → 위키미디어 공용 순서로 (검색 키가 있으면 검색 먼저)', sw(S.task_photo, 'data-b="task_photo"')) +
        row('🧾 안전 자료(MSDS) 찾기', 'MSDS 가 빈 필라멘트 · 레진 · 분말 — 제품 공식 페이지의 SDS 링크 → 검색(키가 있으면) → AI 순서로. 주소를 열어 SDS 가 맞는지 확인한 것만 올려요', sw(S.task_sds, 'data-b="task_sds"')) +
        row('🆕 새 항목에 제원도 넣기', '끄면(권장) 새 항목 제안은 <b>이름 · 종류만</b> — AI 가 기억으로 적는 제원 · 소개 · 주소는 틀린 것이 많아요. 「✏️ 고쳐서 반영」 창의 「🤖 AI 로 정리해 넣기」로 진짜 자료를 붙여 넣어 채우세요', sw(S.new_values, 'data-b="new_values"')) +
        row('🤖 AI 로 정리해 넣기', '항목 편집 창 위에 상자 — 제품 페이지 글 · 주소를 붙여 넣거나 끌어 놓으면 AI 가 칸에 맞게 정리해 채워요 (글에 없는 값은 안 넣음)', sw(S.ai_paste, 'data-b="ai_paste"')) +
        row('찾은 것을', '「확인 후 반영」을 권해요 — AI 는 없는 모델이나 틀린 숫자를 지어낼 수 있어요', '<select class="cct-sel" data-s="apply"><option value="review"' + (S.apply === 'review' ? ' selected' : '') + '>💡 제안함에 쌓기 (확인 후 반영)</option><option value="auto"' + (S.apply === 'auto' ? ' selected' : '') + '>⚡ 바로 반영</option></select>') +
        '</div><div class="cct-panel"><h3>🔎 사진 검색 (선택)</h3><p>검색 키가 없어도 돌아요 (공식 페이지 · 위키미디어 공용). Brave Search API 키를 넣으면 사진을 훨씬 잘 찾아요 — 키는 api.search.brave.com 에서 받아요. 제품 사진은 제조사에 저작권이 있으니, 출처가 같이 저장돼요.</p>' +
        row('검색', '', '<select class="cct-sel" data-s="search"><option value="none"' + (S.search === 'none' ? ' selected' : '') + '>쓰지 않음</option><option value="brave"' + (S.search === 'brave' ? ' selected' : '') + '>Brave Search</option></select>') +
        row('Brave API 키', S.brave_key_set ? '저장됨 · ' + esc(S.brave_key_hint) + ' (비워 두면 그대로)' : '없음', '<span class="cct-num"><input class="cct-in" style="width:220px;text-align:left" type="password" autocomplete="new-password" data-key placeholder="' + (S.brave_key_set ? '바꿀 때만 입력' : '키 입력') + '">' + (S.brave_key_set ? '<label style="font-size:12.5px"><input type="checkbox" data-key-clear> 지우기</label>' : '') + '</span>') +
        '</div><div class="cct-bar" style="margin-bottom:16px"><button type="button" class="cct-btn cct-btn--p" data-save>저장</button><span style="flex:1"></span>' +
        '<button type="button" class="cct-btn" data-run="members">🏢 회원 등록 가져오기</button><button type="button" class="cct-btn" data-run="new">🆕 새 항목 찾기 한 번</button><button type="button" class="cct-btn" data-run="fill">📝 제원 채우기 한 번</button><button type="button" class="cct-btn" data-run="photo">🖼️ 사진 찾기 한 번</button><button type="button" class="cct-btn" data-run="sds">🧾 안전 자료 찾기 한 번</button></div>' +
        '<div class="cct-out" data-run-out></div>' +
        '<div class="cct-panel"><h3>📜 한 일</h3>' + (c.log.length ? '<div class="cct-log">' + c.log.map(function (l) { return '<div><time>' + esc(l.at) + '</time>' + esc(l.text) + '</div>'; }).join('') + '</div>' : '<p>아직 없어요.</p>') + '</div>' +
        '<p class="cct-note">서버 스케줄(크론)이 돌고 있으면 10분마다 알아서 살펴봐요. 스케줄이 없는 서버에서는 누가 카탈로그 화면을 열 때 살펴봐요. 직접 돌리려면: <code>php artisan catalog:collect --force</code></p>';
      var all = function (s, fn) { Array.prototype.forEach.call(body.querySelectorAll(s), fn); };
      all('[data-b]', function (b) { b.onclick = function () { var k = b.getAttribute('data-b'); S[k] = !S[k]; b.classList.toggle('on', S[k]); }; });
      all('[data-n]', function (i) { i.onchange = function () { S[i.getAttribute('data-n')] = Number(i.value); }; });
      all('[data-s]', function (i) { i.onchange = function () { S[i.getAttribute('data-s')] = i.value; }; });
      var sg = body.querySelector('[data-to-sg]'); if (sg) sg.onclick = function (e) { e.preventDefault(); root.querySelector('[data-p="suggest"]').click(); };
      var save = function () {
        var out = JSON.parse(JSON.stringify(S)); out.brave_key = body.querySelector('[data-key]').value.trim();
        var cl = body.querySelector('[data-key-clear]'); if (cl && cl.checked) out.clear_brave_key = true;
        return api('POST', '/admin/settings', { settings: out });
      };
      body.querySelector('[data-save]').onclick = function () { var b = this; b.disabled = true; save().then(function (r) { C().toast('저장했어요.'); draw(r); }).catch(function (e) { b.disabled = false; C().toast(e.message); }); };
      all('[data-run]', function (b) { b.onclick = function () {
        var out = body.querySelector('[data-run-out]'); all('[data-run]', function (x) { x.disabled = true; });
        out.textContent = '⏳ AI 에게 묻는 중… (모델에 따라 몇 분 걸릴 수 있어요)';
        save().then(function () { return api('POST', '/admin/collect/run', { task: b.getAttribute('data-run') }); }).then(function (r) { draw({ settings: S_after(r), collect: r.collect }); var o = body.querySelector('[data-run-out]'); o.textContent = (r.ran || []).join('\n') || api.last || ''; })
          .catch(function (e) { out.textContent = '❌ ' + e.message; all('[data-run]', function (x) { x.disabled = false; }); });
      }; });
    };
    var S_after = function () { return S; };
    api('GET', '/admin/settings').then(function (d) { S = d.settings; draw(d); }).catch(function (e) { fail(body, e); });
  }

  /* ───────── AI 연결 (구인구직과 같은 화면) ───────── */
  function aiPage(root) {
    var esc = C().esc, api = C().api, body = frame(root, 'ai'), S = null, PROV = [], jobsOk = false;
    var load = function (msg) { api('GET', '/admin/ai').then(function (d) { S = d.ai; PROV = S.providers || []; delete S.providers; jobsOk = !!d.jobs_available; draw(d.status, msg); }).catch(function (e) { fail(body, e); }); };
    var provOf = function (k) { return PROV.filter(function (p) { return p.value === k; })[0] || { url: '', model: '', name: k }; };
    var draw = function (status, msg) {
      body.innerHTML = (msg ? '<div class="cct-panel" style="border-color:var(--warn)">' + esc(msg) + '</div>' : '') +
        '<div class="cct-panel"><h3>🤖 AI 연결</h3><p>자동 수집(새 모델 찾기 · 제원 채우기)에 쓸 AI 서버예요. 구인구직 AI 연결과 같은 방식 — 서버를 여러 개, 서버마다 모델을 여러 개 넣고, 위에서부터 물어서 안 되면 다음으로 넘어가요. 다른 모듈과 따로 저장돼요.</p>' +
        [['enabled', 'AI 쓰기', '끄면 자동 수집의 AI 일(새 항목 · 제원)이 멈춰요 — 사진 찾기는 AI 없이도 돌아요'], ['failover', '안 되면 다음 서버 · 모델로', '위 순위대로 차례로 물어봐요']].map(function (x) {
          return '<div class="cct-row"><div><b>' + x[1] + '</b><small>' + x[2] + '</small></div>' + sw(S[x[0]], 'data-top="' + x[0] + '"') + '</div>'; }).join('') +
        '<div class="cct-row"><div><b>기다리는 시간</b><small>서버 하나가 답할 때까지 (10 ~ 600)</small></div><span class="cct-num"><input class="cct-in" type="number" min="10" max="600" data-topn="timeout" value="' + S.timeout + '"> 초</span></div>' +
        '<div class="cct-row"><div><b>최대 답 길이</b><small>AI 가 한 번에 쓰는 길이</small></div><span class="cct-num"><input class="cct-in" type="number" data-topn="max_tokens" value="' + S.max_tokens + '"> 토큰</span></div>' +
        '<p style="margin:10px 0 0;font-weight:700">' + (status.enabled ? '✅ 쓸 수 있어요' : '⚠ ' + esc(status.reason || '꺼져 있어요')) + '</p>' +
        (jobsOk ? '<div class="cct-bar" style="margin-top:10px"><button type="button" class="cct-btn cct-btn--s" data-import>📥 구인구직 AI 연결 설정 가져오기</button><small style="color:var(--mute)">구인구직에 넣은 서버 · API 키 · 모델 순위를 그대로 복사해요</small></div>' : '') +
        S.servers.map(function (sv, i) {
          var pv = provOf(sv.provider);
          return '<div class="cct-sv" data-sv="' + i + '"><div class="cct-sv__h"><b>' + (i + 1) + '순위</b><input class="cct-in" style="max-width:200px;height:34px" data-f="name" value="' + esc(sv.name) + '"><label style="font-size:13px;font-weight:700"><input type="checkbox" data-f="enabled"' + (sv.enabled ? ' checked' : '') + '> 켜기</label>' +
            '<span style="flex:1"></span><button type="button" class="cct-btn cct-btn--s" data-sup>▲</button><button type="button" class="cct-btn cct-btn--s" data-sdown>▼</button><button type="button" class="cct-btn cct-btn--s cct-btn--d" data-srm>지우기</button></div><div class="cct-sv__g">' +
            '<label>종류</label><select class="cct-in" style="max-width:240px" data-f="provider">' + PROV.map(function (p) { return '<option value="' + p.value + '"' + (p.value === sv.provider ? ' selected' : '') + '>' + esc(p.name) + '</option>'; }).join('') + '</select>' +
            '<label>주소<small>비우면 ' + esc(pv.url) + '</small></label><input class="cct-in" data-f="url" value="' + esc(sv.url) + '" placeholder="' + esc(pv.url) + '">' +
            '<label>API 키<small>' + (sv.key_set ? '저장됨 · ' + esc(sv.key_hint) + ' (비워 두면 그대로)' : (sv.provider === 'ollama' ? 'Ollama 는 없어도 돼요' : '없음')) + '</small></label><div><input class="cct-in" type="password" autocomplete="new-password" data-f="api_key" placeholder="' + (sv.key_set ? '바꿀 때만 입력' : '키 입력') + '">' + (sv.key_set ? ' <label style="font-size:12.5px"><input type="checkbox" data-f="clear_key"> 키 지우기</label>' : '') + '</div>' +
            '<label>이 서버 기다리는 시간<small>0 = 위 기본 값</small></label><span class="cct-num"><input class="cct-in" type="number" data-f="timeout" value="' + (sv.timeout || 0) + '"> 초</span>' +
            '<label>모델 순위<small>위에서부터 물어봐요 · 기본 ' + esc(pv.model) + '</small></label><div><div data-models>' + sv.models.map(function (md, j) {
              return '<div class="cct-md" data-md="' + j + '"><input type="checkbox" data-me' + (md.enabled ? ' checked' : '') + ' title="켜기"><code>' + esc(md.name) + '</code><button type="button" class="cct-btn cct-btn--s" data-mtest>시험</button><button type="button" class="cct-btn cct-btn--s" data-mup>▲</button><button type="button" class="cct-btn cct-btn--s" data-mdown>▼</button><button type="button" class="cct-btn cct-btn--s cct-btn--d" data-mrm>✕</button></div>'; }).join('') + '</div>' +
            '<div class="cct-bar" style="margin:6px 0 0"><input class="cct-in" style="max-width:220px;height:34px" data-madd placeholder="모델 이름 더하기"><button type="button" class="cct-btn cct-btn--s" data-madd-btn>➕</button><button type="button" class="cct-btn cct-btn--s" data-find>🔎 모델 찾기</button></div><div class="cct-opts" style="margin-top:6px" data-found></div><div class="cct-out" data-out></div></div></div></div>';
        }).join('') +
        '<div class="cct-bar" style="margin-top:12px"><button type="button" class="cct-btn cct-btn--s" data-sadd>➕ 서버 더하기</button></div></div>' +
        '<div class="cct-bar"><span class="cct-out" style="flex:1;margin:0" data-live-out></span><button type="button" class="cct-btn" data-live>🧪 실제 연결 테스트</button><button type="button" class="cct-btn cct-btn--p" data-save>저장</button></div>';
      var all = function (s, fn, base) { Array.prototype.forEach.call((base || body).querySelectorAll(s), fn); };
      var imp = body.querySelector('[data-import]');
      if (imp) imp.onclick = function () { if (!confirm('구인구직의 AI 연결 설정(서버 · API 키 · 모델)으로 카탈로그 AI 설정을 바꿀까요?')) return; imp.disabled = true;
        api('POST', '/admin/ai/import-jobs', {}).then(function (d) { S = d.ai; PROV = S.providers || PROV; delete S.providers; draw(d.status, '📥 구인구직 AI 설정을 가져왔어요.'); }).catch(function (e) { imp.disabled = false; C().toast(e.message); }); };
      var serverFrom = function (i) { return JSON.parse(JSON.stringify(S.servers[i])); };
      all('[data-top]', function (b) { b.onclick = function () { var k = b.getAttribute('data-top'); S[k] = !S[k]; b.classList.toggle('on', S[k]); }; });
      all('[data-topn]', function (el) { el.onchange = function () { S[el.getAttribute('data-topn')] = Number(el.value); }; });
      all('[data-sv]', function (card) {
        var i = Number(card.getAttribute('data-sv')), sv = S.servers[i];
        all('[data-f]', function (el) { el.onchange = el.oninput = function () { var k = el.getAttribute('data-f'); sv[k] = el.type === 'checkbox' ? el.checked : (el.type === 'number' ? Number(el.value) : el.value); if (k === 'provider' && el.tagName === 'SELECT') draw(status); }; }, card);
        var mv = function (arr, a, b) { if (b < 0 || b >= arr.length) return; arr.splice(b, 0, arr.splice(a, 1)[0]); draw(status); };
        card.querySelector('[data-sup]').onclick = function () { mv(S.servers, i, i - 1); };
        card.querySelector('[data-sdown]').onclick = function () { mv(S.servers, i, i + 1); };
        card.querySelector('[data-srm]').onclick = function () { if (confirm('이 서버를 지울까요?')) { S.servers.splice(i, 1); draw(status); } };
        all('[data-md]', function (r) {
          var j = Number(r.getAttribute('data-md'));
          r.querySelector('[data-me]').onchange = function () { sv.models[j].enabled = this.checked; };
          r.querySelector('[data-mup]').onclick = function () { mv(sv.models, j, j - 1); };
          r.querySelector('[data-mdown]').onclick = function () { mv(sv.models, j, j + 1); };
          r.querySelector('[data-mrm]').onclick = function () { sv.models.splice(j, 1); draw(status); };
          r.querySelector('[data-mtest]').onclick = function () { var out = card.querySelector('[data-out]'); out.textContent = '⏳ ' + sv.models[j].name + ' 시험 중…';
            api('POST', '/admin/ai/test', { server: serverFrom(i), model: sv.models[j].name }).then(function (x) { out.textContent = x.message + (x.answer ? '\n답: ' + x.answer : ''); }).catch(function (e) { out.textContent = '❌ ' + e.message; }); };
        }, card);
        var addModel = function (name) { name = String(name || '').trim(); if (!name || sv.models.some(function (m) { return m.name === name; })) return; sv.models.push({ name: name, enabled: true }); draw(status); };
        card.querySelector('[data-madd-btn]').onclick = function () { addModel(card.querySelector('[data-madd]').value); };
        card.querySelector('[data-find]').onclick = function () {
          var out = card.querySelector('[data-out]'), found = card.querySelector('[data-found]'); out.textContent = '⏳ 모델 찾는 중…';
          api('POST', '/admin/ai/models', { server: serverFrom(i) }).then(function (x) {
            out.textContent = x.message;
            found.innerHTML = x.models.slice(0, 60).map(function (n) { return '<button type="button" data-pick="' + esc(n) + '">' + esc(n) + '</button>'; }).join('');
            all('[data-pick]', function (b) { b.onclick = function () { addModel(b.getAttribute('data-pick')); }; }, found);
          }).catch(function (e) { out.textContent = '❌ ' + e.message; });
        };
      });
      body.querySelector('[data-sadd]').onclick = function () { var p = PROV.filter(function (x) { return x.value === 'ollama'; })[0] || PROV[0] || { value: 'ollama', name: 'Ollama' }; S.servers.push({ id: '', name: p.name + ' ' + (S.servers.length + 1), enabled: true, provider: p.value, url: '', api_key: '', models: [], timeout: 0 }); draw(status); };
      body.querySelector('[data-live]').onclick = function () { var out = body.querySelector('[data-live-out]'); out.textContent = '⏳ 순서대로 물어보는 중… (최대 25초)';
        api('POST', '/admin/ai/live', { ai: S }).then(function (x) { out.textContent = x.message + (x.tries || []).map(function (t) { return '\n· ' + t.server + ' · ' + t.model + ' — ' + (t.ok ? t.sec + '초' : String(t.message).replace(/^❌\s*/, '')); }).join(''); }).catch(function (e) { out.textContent = '❌ ' + e.message; }); };
      body.querySelector('[data-save]').onclick = function () { var b = this; b.disabled = true;
        api('POST', '/admin/ai', { ai: S }).then(function () { C().toast('저장했어요.'); load(); }).catch(function (e) { b.disabled = false; if (e.status === 409) load('⚠ ' + e.message); else C().toast(e.message); }); };
    };
    load();
  }

  /* ───────── 설정 ───────── */
  function settingsPage(root) {
    var esc = C().esc, api = C().api, body = frame(root, 'settings'), M = C().getMeta(), S = null;
    var draw = function (d) {
      S = d.settings;
      body.innerHTML = '<div class="cct-panel"><h3>🖼️ 어떤 그림을 보여 줄까</h3><p>목록 카드와 상세 화면에 보이는 그림이에요. 「그림」은 업체검색의 장비 · 재고에서 쓰는 것과 같은 입체 그림(프린터 · 필라멘트 롤 · 레진 병 · 분말 통)이에요.</p>' +
        [['auto', '카탈로그 사진 → 업체검색 대표 사진 → 그림', '카탈로그에 올린 사진이 있으면 그것, 없으면 업체검색에서 관리자가 고른 모델 대표 사진, 그것도 없으면 그림'],
          ['company', '업체검색 대표 사진 먼저', '업체검색에서 고른 모델 대표 사진이 있으면 그것을 먼저 (없으면 카탈로그 사진 → 그림)'],
          ['catalog', '카탈로그 사진만', '카탈로그에 올린 사진만 쓰고, 없으면 그림'],
          ['icon', '그림만', '사진을 쓰지 않고 모두 그림으로 — 업체검색과 똑같은 모양']].map(function (o) {
          return '<label class="cct-row" style="cursor:pointer"><div><b>' + o[1] + '</b><small>' + o[2] + '</small></div><input type="radio" name="cct-imode" value="' + o[0] + '"' + (S.image_mode === o[0] ? ' checked' : '') + ' style="width:20px;height:20px"></label>'; }).join('') + '</div>' +
        '<div class="cct-panel"><h3>🧭 헤더 메뉴</h3>' +
        '<label class="cct-row" style="cursor:pointer"><div><b>헤더 메뉴 보이기</b><small>헤더 메인 메뉴에 넣어요 — 홈 디자인 › 헤더 › 메뉴 순서와 서로 맞춰져요 (메뉴 자리는 홈 디자인에서)</small></div><input type="checkbox" data-menu-on' + (S.menu_enabled !== false ? ' checked' : '') + '></label>' +
        '<div class="cct-row"><div><b>메뉴 이름</b><small>헤더 메뉴에 보일 이름 (20자) — 홈 디자인 메뉴 목록과 서로 맞춰져요</small></div><input class="cct-in" type="text" maxlength="20" data-menu-label value="' + String(S.menu_label || '3D 카탈로그').replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }) + '"></div></div>' +
        '<div class="cct-panel"><h3>🖥️ 화면</h3>' +
        '<div class="cct-row"><div><b>한 번에 보이는 개수</b><small>목록에서 「더 보기」 전까지</small></div><span class="cct-num"><input class="cct-in" type="number" min="8" max="96" data-n="per_page" value="' + S.per_page + '"> 개</span></div>' +
        '<div class="cct-row"><div><b>사진 크기</b><small>올리거나 가져온 사진은 이 크기(긴 변)로 줄여 JPEG 로 저장해요. 목록에는 더 작은 사진(480px)을 따로 만들어 써요</small></div><span class="cct-num"><input class="cct-in" type="number" min="480" max="2400" step="20" data-n="photo_px" value="' + S.photo_px + '"> px</span></div>' +
        '<div class="cct-row"><div><b>사진 품질</b><small>낮을수록 파일이 작아요 (권장 78 ~ 85)</small></div><span class="cct-num"><input class="cct-in" type="number" min="50" max="95" data-n="photo_quality" value="' + S.photo_quality + '"></span></div></div>' +
        '<div class="cct-panel"><h3>🏷️ 제조사 로고</h3><p>카드 · 상세 · 제조사 칩에 보이는 로고예요. 로고가 없는 제조사는 머리글자 배지로 보여요. 제조사 하나를 바꾸면 그 제조사의 모든 항목이 같이 바뀌어요 (항목 상세 화면의 「🏷️ 로고 바꾸기」로도 돼요). 🌐 는 그 제조사 홈페이지의 아이콘(회사가 쓰는 정식 마크)을 받아 오고, 📁 는 직접 올려요 — 글자까지 들어간 정식 로고를 쓰려면 제조사 홈페이지의 보도 자료(Press · Media kit)에서 받아 여기에 올리면 돼요 (옆으로 긴 로고도 그대로 보여요). 로고는 각 회사의 상표예요.</p>' +
        '<div class="cct-bar"><button type="button" class="cct-btn cct-btn--s" data-logo-all>🌐 없는 로고 모두 홈페이지에서 가져오기</button><span class="cct-out" style="margin:0" data-logo-out></span></div><div class="cct-lg" data-logos><div class="cct-skel" style="height:60px"></div></div></div>' +
        '<div class="cct-panel"><h3>🗂️ 보이는 탭</h3><p>끄면 사이트 카탈로그 화면에서 그 탭이 안 보여요 (자료는 그대로).</p>' + M0.map(function (t) {
          return '<div class="cct-row"><div><b>' + t[2] + ' ' + esc(t[1]) + '</b></div>' + sw(S.tabs_off.indexOf(t[0]) < 0, 'data-tab="' + t[0] + '"') + '</div>'; }).join('') + '</div>' +
        '<div class="cct-panel"><h3>🔗 다른 모듈과 잇기</h3><p>카탈로그는 목록의 주인이에요. 다른 모듈은 여기서 목록만 뽑아 가요.</p>' +
        '<div class="cct-row"><div><b>🏢 업체검색</b><small>장비 · 재고 등록 화면의 「제조사 · 모델 고르기」 목록 — 사진과 모델 추가 규칙은 업체검색 것을 그대로 써요</small></div><a class="cct-btn cct-btn--s" target="_blank" rel="noopener" href="/api/modules/custom-catalog/book">목록 보기 ↗</a></div>' +
        '<div class="cct-row"><div><b>🔎 통합 검색</b><small>사이트 검색 결과에 「카탈로그」 탭으로 나와요</small></div><span class="cct-state ok">연결됨</span></div>' +
        '<div class="cct-row"><div><b>🔥 인기 검색어</b><small>카탈로그에서 찾은 말이 인기 검색어에 쌓여요 (인기 검색어 플러그인 0.3.12 이상)</small></div><span class="cct-state ok">연결됨</span></div>' +
        '<div class="cct-row"><div><b>🏠 홈 화면</b><small>홈 디자인의 칸으로 「3D 카탈로그」를 고를 수 있어요</small></div><span class="cct-state ok">연결됨</span></div></div>' +
        '<div class="cct-bar"><button type="button" class="cct-btn cct-btn--p" data-save>저장</button></div>';
      Array.prototype.forEach.call(body.querySelectorAll('[data-n]'), function (i) { i.onchange = function () { S[i.getAttribute('data-n')] = Number(i.value); }; });
      Array.prototype.forEach.call(body.querySelectorAll('[name="cct-imode"]'), function (i) { i.onchange = function () { if (i.checked) S.image_mode = i.value; }; });
      var mOn = body.querySelector('[data-menu-on]'), mLabel = body.querySelector('[data-menu-label]');
      if (mOn) mOn.onchange = function () { S.menu_enabled = mOn.checked; };
      if (mLabel) mLabel.oninput = function () { S.menu_label = mLabel.value; };
      Array.prototype.forEach.call(body.querySelectorAll('[data-tab]'), function (b) { b.onclick = function () {
        var k = b.getAttribute('data-tab'), i = S.tabs_off.indexOf(k); if (i >= 0) S.tabs_off.splice(i, 1); else S.tabs_off.push(k); b.classList.toggle('on', i >= 0); }; });
      var drawLogos = function (items) {
        var box = body.querySelector('[data-logos]'); if (!box) return;
        box.innerHTML = items.map(function (x, i) {
          return '<div class="cct-lg__i">' + (x.logo ? '<img class="cct-logo" alt="" loading="lazy" src="' + esc(x.logo) + '">' : C().logoImg(x.brand)) + '<b title="' + esc(x.brand) + '">' + esc(x.brand) + '</b>' +
            (x.home ? '<button type="button" class="cct-btn cct-btn--s" title="홈페이지(' + esc(x.home) + ')에서 가져오기" data-logo-web="' + i + '">🌐</button>' : '') +
            '<label class="cct-btn cct-btn--s" title="로고 올리기">📁<input type="file" accept="image/*" hidden data-logo-up="' + i + '"></label>' +
            (x.logo ? '<button type="button" class="cct-btn cct-btn--s cct-btn--d" title="로고 지우기" data-logo-del="' + i + '">✕</button>' : '') + '</div>';
        }).join('') || '<p>제조사가 아직 없어요.</p>';
        Array.prototype.forEach.call(box.querySelectorAll('[data-logo-up]'), function (inp) { inp.onchange = function () {
          if (!inp.files.length) return;
          var fd = new FormData(); fd.append('brand', items[+inp.getAttribute('data-logo-up')].brand); fd.append('logo', inp.files[0]);
          api('POST', '/admin/logos', fd).then(function (r) { C().toast(api.last || '로고를 바꿨어요.'); C().meta(true); drawLogos(r.items); }).catch(function (e) { C().toast(e.message); });
        }; });
        Array.prototype.forEach.call(box.querySelectorAll('[data-logo-web]'), function (b) { b.onclick = function () {
          b.disabled = true; b.textContent = '⏳';
          api('POST', '/admin/logos/fetch', { brand: items[+b.getAttribute('data-logo-web')].brand }).then(function (r) { C().toast('로고를 가져왔어요.'); C().meta(true); drawLogos(r.items); }).catch(function (e) { b.disabled = false; b.textContent = '🌐'; C().toast(e.message); });
        }; });
        var allBtn = body.querySelector('[data-logo-all]'), out = body.querySelector('[data-logo-out]');
        if (allBtn) allBtn.onclick = function () {
          var todo = items.filter(function (x) { return !x.logo && x.home; }), ok = 0, bad = 0, last = items;
          if (!todo.length) { out.textContent = '가져올 곳이 없어요 (홈페이지 주소를 아는 제조사는 모두 로고가 있어요).'; return; }
          allBtn.disabled = true;
          (function next() {
            var x = todo.shift();
            if (!x) { allBtn.disabled = false; out.textContent = '끝 — ' + ok + '곳 가져옴' + (bad ? ' · ' + bad + '곳은 못 찾음 (직접 올려 주세요)' : ''); C().meta(true); drawLogos(last); return; }
            out.textContent = '⏳ ' + x.brand + ' … (' + (ok + bad + 1) + ' / ' + (ok + bad + 1 + todo.length) + ')';
            api('POST', '/admin/logos/fetch', { brand: x.brand }).then(function (r) { ok++; last = r.items; next(); }, function () { bad++; next(); });
          })();
        };
        Array.prototype.forEach.call(box.querySelectorAll('[data-logo-del]'), function (b) { b.onclick = function () {
          api('POST', '/admin/logos/delete', { brand: items[+b.getAttribute('data-logo-del')].brand }).then(function (r) { C().toast('로고를 지웠어요.'); C().meta(true); drawLogos(r.items); }).catch(function (e) { C().toast(e.message); });
        }; });
      };
      api('GET', '/admin/logos').then(function (r) { drawLogos(r.items); }).catch(function () {});
      body.querySelector('[data-save]').onclick = function () { var b = this; b.disabled = true; var out = JSON.parse(JSON.stringify(S)); delete out.brave_key; delete out.logos;
        api('POST', '/admin/settings', { settings: out }).then(function (r) { C().toast('저장했어요.'); C().meta(true); draw(r); }).catch(function (e) { b.disabled = false; C().toast(e.message); }); };
    };
    var M0 = [['fdm', 'FDM 프린터', '🖨️'], ['resin_printer', '레진 프린터', '💡'], ['industrial', '산업용 프린터', '🏭'], ['maker', '가공 장비', '⚙️'], ['tools', '주변 장비', '🧰'], ['filament', '필라멘트', '🧵'], ['resin', '레진', '🧪'], ['powder', '분말 · 금속', '⚗️']];
    api('GET', '/admin/settings').then(draw).catch(function (e) { fail(body, e); });
  }

  function tick() {
    var root = document.getElementById('cct_admin_root');
    if (!root) return;
    var sig = location.pathname + '|' + page();
    if (root.__cctA === sig && root.firstChild) return;
    root.__cctA = sig;
    need(function () {
      C().css();
      C().meta(true).then(function (m) {
        if (!m.can_edit) { root.innerHTML = '<div class="cct cct-adm"><div class="cct-empty"><b>🔒</b>카탈로그를 고칠 권한이 없어요.</div></div>'; return; }
        ({ items: itemsPage, suggest: suggestPage, auto: autoPage, ai: aiPage, settings: settingsPage })[page()](root);
        if (page() !== 'suggest' && page() !== 'auto') C().api('GET', '/admin/suggestions?status=pending').then(function (r) { pending = r.pending; var nb = root.querySelector('[data-p="suggest"]'); if (nb && pending) nb.innerHTML = '💡 제안함<b>' + pending + '</b>'; }).catch(function () {});
      }).catch(function (e) { root.innerHTML = '<div class="cct cct-adm"><div class="cct-empty"><b>⚠️</b>' + String(e.message).replace(/</g, '&lt;') + '</div></div>'; });
    });
  }
  window.__cctAdmin = tick;
  window.addEventListener('popstate', function () { setTimeout(tick, 0); });
  try { new MutationObserver(function () { var r = document.getElementById('cct_admin_root'); if (r && !r.firstChild) tick(); }).observe(document.body, { childList: true, subtree: true }); } catch (e) {}
  setInterval(tick, 900);
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', tick); else tick();
})();
