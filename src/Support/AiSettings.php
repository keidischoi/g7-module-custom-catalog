<?php

namespace Modules\Custom\Catalog\Support;

use Illuminate\Support\Facades\Storage;

final class AiSettings
{
    public const PROVIDERS = ["ollama", "openai", "grok", "gemini", "claude"];

    public static function defaults(): array
    {
        return [
            "enabled" => false,
            "provider" => "ollama",
            "url" => "http://localhost:11434",
            "model" => "qwen2.5:7b",
            "api_key" => "",
        ];
    }

    public static function all(): array
    {
        $d = self::defaults();
        $raw = Storage::disk("local")->exists("modules/custom-catalog/ai.json")
            ? Storage::disk("local")->get("modules/custom-catalog/ai.json") : "";
        $j = is_string($raw) ? json_decode($raw, true) : [];
        if (! is_array($j)) {
            return $d;
        }
        foreach (["enabled", "provider", "url", "model", "api_key"] as $k) {
            if (array_key_exists($k, $j)) {
                $d[$k] = $j[$k];
            }
        }
        $d["enabled"] = (bool) $d["enabled"];
        $d["provider"] = in_array($d["provider"], self::PROVIDERS, true) ? $d["provider"] : "ollama";

        return $d;
    }

    public static function save(array $in): array
    {
        $cur = self::all();
        $cur["enabled"] = ! empty($in["enabled"]);
        $cur["provider"] = in_array($in["provider"] ?? "", self::PROVIDERS, true) ? $in["provider"] : $cur["provider"];
        $cur["url"] = mb_substr(trim((string) ($in["url"] ?? $cur["url"])), 0, 200);
        $cur["model"] = mb_substr(trim((string) ($in["model"] ?? $cur["model"])), 0, 80);
        if (! empty($in["clear_key"])) {
            $cur["api_key"] = "";
        } elseif (trim((string) ($in["api_key"] ?? "")) !== "") {
            $cur["api_key"] = (string) $in["api_key"];
        }
        Storage::disk("local")->put("modules/custom-catalog/ai.json", json_encode($cur, JSON_UNESCAPED_UNICODE));

        return $cur;
    }

    public static function public(): array
    {
        $s = self::all();
        $s["has_key"] = $s["api_key"] !== "";
        unset($s["api_key"]);

        return $s;
    }
}
