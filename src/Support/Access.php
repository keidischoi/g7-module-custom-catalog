<?php

namespace Modules\Custom\Catalog\Support;

use Illuminate\Http\Request;

/** 0.2.0 누가 카탈로그를 고칠 수 있나 — 관리자 · 「카탈로그 편집」 역할 · 제원 입력 권한 */
final class Access
{
    /** 테스트용 */
    public static ?bool $override = null;

    public static function user(Request $r): mixed
    {
        try {
            return $r->user() ?: (function_exists('auth') ? auth('sanctum')->user() : null);
        } catch (\Throwable) {
            return null;
        }
    }

    public static function canEdit(Request $r): bool
    {
        if (self::$override !== null) {
            return self::$override;
        }
        $u = self::user($r);
        if (! $u) {
            return false;
        }
        try {
            if (method_exists($u, 'hasRole') && ($u->hasRole('admin') || $u->hasRole('super_admin') || $u->hasRole('custom-catalog.editor'))) {
                return true;
            }
            foreach (['hasPermission', 'can'] as $m) {
                if (method_exists($u, $m) && $u->{$m}('custom-catalog.specs.update')) {
                    return true;
                }
            }
            if (method_exists($u, 'isAdmin') && $u->isAdmin()) {
                return true;
            }
        } catch (\Throwable) {
        }

        return false;
    }
}
