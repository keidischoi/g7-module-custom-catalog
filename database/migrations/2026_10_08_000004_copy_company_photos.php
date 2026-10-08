<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/** 업체검색 대표 사진을 카탈로그 디스크로 복사. 이미 복사한 키는 건너뜀. */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable("cat_equipment") && ! Schema::hasColumn("cat_equipment", "image_path")) {
            Schema::table("cat_equipment", function (Blueprint $t) {
                $t->string("image_path", 180)->nullable();
            });
        }
        if (! Schema::hasTable("cat_equipment") || ! Schema::hasTable("cmp_files") || ! Schema::hasTable("cmp_settings")) {
            return;
        }
        $raw = DB::table("cmp_settings")->where("key", "model_rules")->value("value");
        $rules = is_string($raw) ? json_decode($raw, true) : [];
        if (! is_array($rules)) {
            return;
        }
        foreach (DB::table("cat_equipment")->whereNull("image_path")->get(["id", "kind", "brand", "model"]) as $row) {
            $key = $row->kind."|".$this->norm($row->brand)."|".$this->norm($row->model);
            $hash = (string) (($rules[$key]["img"] ?? ""));
            if ($hash === "") {
                continue;
            }
            $file = DB::table("cmp_files")->where("hash", $hash)->whereNull("deleted_at")->first();
            if (! $file || ! Storage::disk($file->disk ?: "local")->exists($file->path)) {
                continue;
            }
            $ext = pathinfo((string) $file->path, PATHINFO_EXTENSION) ?: "jpg";
            $dest = "modules/custom-catalog/images/".$row->id.".".$ext;
            Storage::disk("local")->put($dest, Storage::disk($file->disk ?: "local")->get($file->path));
            DB::table("cat_equipment")->where("id", $row->id)->update(["image_path" => $dest, "updated_at" => now()]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable("cat_equipment") && Schema::hasColumn("cat_equipment", "image_path")) {
            Schema::table("cat_equipment", function (Blueprint $t) {
                $t->dropColumn("image_path");
            });
        }
    }

    private function norm(?string $s): string
    {
        return (string) preg_replace("/[\\s\\-_.·\\/]+/u", "", mb_strtolower(trim((string) $s)));
    }
};
