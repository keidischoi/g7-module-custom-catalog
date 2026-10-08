"""업체검색(companies-app.js)의 입체 그림 함수를 그대로 뽑아 카탈로그용 catalog-icons.js 로 — 그림을 고치면 이 스크립트를 다시 돌림."""
import io, re
SRC = 'C:/Users/gbook/source/repos/g7-module-custom-companies/resources/assets/companies-app.js'
R = 'C:/Users/gbook/source/repos/g7-module-custom-catalog/'
L = io.open(SRC, encoding='utf-8').read().replace('\r\n', '\n').split('\n')


def at(prefix):
    for i, x in enumerate(L):
        if x.strip().startswith(prefix):
            return i
    raise SystemExit('not found: ' + prefix)


def func(prefix):
    """함수 하나 (줄 시작이 prefix 인 곳부터 같은 들여쓰기의 닫는 중괄호까지)"""
    i = at(prefix)
    if L[i].rstrip().endswith('}') and L[i].count('{') == L[i].count('}'):
        return L[i]
    j = i + 1
    while not L[j].startswith('  }'):
        j += 1
    line = L[j]
    return '\n'.join(L[i:j] + ['  }'])   # 닫는 줄 뒤에 붙은 주석은 버림


parts = [
    L[at('var EQ_ICON = ')],
    func('function spInk('),
    func('function spMatKey('),
    L[at('var EQ_LED = ')],
    func('function eq3d('),
    L[at('var SP3D = ')],
    L[at('var SP_TAG = ')],
    func('function spHex('),
    func('function shade('),
    func('function spShort('),
    func('function spPct('),
    func('function spFill('),
    func('function sp3d('),
    func('function sp3dSpool('),
    func('function sp3dBottle('),
    func('function sp3dJar('),
]
body = '\n'.join(parts)
for name in ('eqTint', 'spSwatch', 'esc'):
    assert name + '(' in body, name
assert 'META' not in body, 'META 를 쓰는 곳이 섞임'
out = """/*! custom-catalog — 입체 그림 (업체검색 custom-companies 의 장비 · 재료 그림을 그대로 가져옴 · scripts 로 뽑은 파일, 직접 고치지 마세요) */
(function () {
  'use strict';
  if (window.CCTIcons) return;
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  /* 카탈로그에는 업체의 색 견본 목록이 없음 — 항목에 적힌 색(없으면 기본)을 씀 */
  function eqTint(e) { return /^#[0-9a-f]{6}$/i.test(e.tint || '') ? e.tint : '#3b82f6'; }
  function spSwatch(s) { return s.color_hex || '#cbd5e1'; }
""" + body + """
  window.CCTIcons = {
    /** 장비 그림: {kind, enclosed, tint} */
    equipment: function (e, px) { return eq3d({ kind: e.kind, enclosed: !!e.enclosed, status: 'ok', tint: e.tint, qty: 1 }, px || 120); },
    /** 재료 그림: {kind(fdm|resin|powder), material, color_hex} */
    material: function (m, px) { return sp3d({ kind: m.kind, material: m.material, color_hex: m.color_hex, remain_pct: 100, status: 'new' }, px || 120); }
  };
})();
"""
io.open(R + 'resources/assets/catalog-icons.js', 'w', encoding='utf-8', newline='\n').write(out)
print('ok', len(out))
