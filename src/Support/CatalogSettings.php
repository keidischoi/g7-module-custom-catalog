<?php

namespace Modules\Custom\Catalog\Support;

use Illuminate\Support\Facades\Storage;

final class CatalogSettings
{
    public static function defaults(): array
    {
        return [
            "equipment_kinds" => [
                ["key" => "fdm", "label" => "FDM 프린터"],
                ["key" => "sla", "label" => "레진 프린터"],
                ["key" => "dlp", "label" => "DLP 프린터"],
                ["key" => "other", "label" => "기타 장비"],
            ],
            "material_kinds" => [
                ["key" => "fdm", "label" => "필라멘트"],
                ["key" => "resin", "label" => "레진"],
            ],
            "spec_fields" => [
                ["key" => "build", "label" => "출력 크기", "target" => "equipment"],
                ["key" => "nozzle", "label" => "노즐", "target" => "equipment"],
                ["key" => "temp", "label" => "온도", "target" => "materials"],
            ],
        ];
    }

    public static function all(): array
    {
        $d = self::defaults();
        $raw = Storage::disk("local")->exists("modules/custom-catalog/kinds.json")
            ? Storage::disk("local")->get("modules/custom-catalog/kinds.json") : "";
        $j = is_string($raw) ? json_decode($raw, true) : [];
        if (! is_array($j)) {
            return $d;
        }
        foreach (["equipment_kinds", "material_kinds", "spec_fields"] as $k) {
            if (isset($j[$k]) && is_array($j[$k])) {
                $d[$k] = array_values($j[$k]);
            }
        }

        return $d;
    }

    public static function save(array $in): array
    {
        $cur = self::all();
        foreach (["equipment_kinds", "material_kinds", "spec_fields"] as $k) {
            if (isset($in[$k]) && is_array($in[$k])) {
                $cur[$k] = array_values(array_slice($in[$k], 0, 40));
            }
        }
        Storage::disk("local")->put("modules/custom-catalog/kinds.json", json_encode($cur, JSON_UNESCAPED_UNICODE));

        return $cur;
    }
}
