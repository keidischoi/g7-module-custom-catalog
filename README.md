# 3D 카탈로그 (custom-catalog)

3D 프린터 · 장비 · 필라멘트 · 레진 제원을 모아 두는 곳입니다. 다른 모듈(업체검색)은 여기서 **목록만** 뽑아 갑니다.

- 사이트: `/catalog` · `/catalog/{key}`
- 관리자: `/admin/catalog` (📦 항목 · 💡 제안함 · 🌙 자동 수집 · 🤖 AI 연결 · ⚙️ 설정)

## 표

- `cat_equipment` · `cat_materials` — 항목. 자주 쓰는 값은 칸으로, 나머지 제원은 `specs`(JSON), 자유 항목은 `facts`(JSON)
- `cat_photos` — 사진 여러 장 (출처 포함). 파일은 `storage/app/modules/custom-catalog/images/` (`이름.jpg` + 목록용 `이름-t.jpg`)
- `cat_suggestions` — 자동 수집이 찾아온 제안 (pending · applied · rejected)

칸 정의는 `src/Support/Fields.php` 한 곳 — 화면(입력 · 상세)과 AI 가 모두 이것을 씁니다. 종류 키는 업체검색의 장비 종류 · 재고 종류와 같습니다.

## API

- `GET /api/modules/custom-catalog/meta` · `items?tab=&brand=&q=&sort=&flags=&page=` · `items/{key}`
- `GET /api/modules/custom-catalog/book` (장비) · `book?type=materials` — 다른 모듈용 목록
- PHP: `\Modules\Custom\Catalog\Api\Catalog::equipmentBook()` · `materialBook()` · `brands()`
- 편집: `POST admin/items` · `admin/items/{key}/delete|restore|photos` · `admin/photos/{id}/delete|main`

## 자동 수집

`php artisan catalog:collect` (스케줄 10분마다 · `--force` 조건 무시 · `--task=new|fill|photo`). 설정 `storage/app/modules/custom-catalog/settings.json`, AI 서버 `ai.json`.

## 검사

```
G7_VENDOR=/경로/g7-core/vendor php -d extension=gd tests/sim.php
```
