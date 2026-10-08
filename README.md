# 3D 카탈로그 (custom-catalog)

장비 · 프린터 · 필라멘트 · 레진 자료의 주인입니다. 업체검색은 보유 · 재고만 두고 `catalog_key` 로 이 카드를 가리킵니다.

## 표

- `cat_equipment` — 기종 카드. `kind` 는 업체검색 `cmp_equipment.kind` 와 같음
- `cat_materials` — 재료 카드. `kind` 는 `cmp_spools.kind` 와 같음 (`fdm` `resin` `powder`)
- `cat_barcodes` — 제조사 바코드 → `cat_materials.key`

SDS 는 제조사 링크만 둡니다. 배합은 저장하지 않습니다.

## 검색

- `GET /api/modules/custom-catalog/equipment?q=&kind=`
- `GET /api/modules/custom-catalog/materials?q=&kind=`
