<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('cat_equipment')) {
            $drop = ['bambulab-x1c', 'bambulab-p1s', 'bambulab-a1', 'bambulab-a1-mini', 'prusa-mk4s', 'creality-k1c', 'elegoo-saturn-4-ultra', 'anycubic-photon-mono-m5s'];
            DB::table('cat_equipment')->whereIn('key', $drop)->update(['status' => 'archived', 'updated_at' => now()]);
        }
        $photos = [
            'bambu-lab-x1-carbon-fdm' => 'photo-bambulab-x1c.jpg',
            'bambu-lab-p1s-fdm' => 'photo-bambulab-p1s.jpg',
            'bambu-lab-a1-fdm' => 'photo-bambu-lab-a1-fdm.jpg',
            'anycubic-kobra-3-fdm' => 'photo-anycubic-kobra-3-fdm.jpg',
            'anycubic-kobra-3-max-fdm' => 'photo-anycubic-kobra-3-max.jpg',
            'creality-k1-fdm' => 'photo-creality-k1-fdm.jpg',
            'creality-k1c-fdm' => 'photo-creality-k1c-fdm.jpg',
            'creality-k1-max-fdm' => 'photo-creality-k1-max-fdm.jpg',
            'creality-k2-plus-fdm' => 'photo-creality-k2-plus-fdm.jpg',
            'elegoo-centauri-carbon-fdm' => 'photo-elegoo-centauri-carbon-fdm.jpg',
            'elegoo-neptune-4-pro-fdm' => 'photo-elegoo-neptune-4-pro-fdm.jpg',
            'flashforge-adventurer-5m-fdm' => 'photo-flashforge-adventurer-5m-fdm.jpg',
            'prusa-research-original-prusa-mk4s-fdm' => 'photo-prusa-research-original-prusa-mk4s-fdm.jpg',
            'prusa-research-original-prusa-xl-fdm' => 'photo-prusa-research-original-prusa-xl-fdm.jpg',
            'prusa-research-prusa-core-one-fdm' => 'photo-prusa-research-prusa-core-one-fdm.jpg',
            'xtool-p2s-laser' => 'photo-xtool-p2s-laser.jpg',
            'xtool-s1-laser' => 'photo-xtool-s1-laser.jpg',
            'xtool-f1-ultra-laser' => 'photo-xtool-f1-ultra-laser.jpg',
        ];
        if (Schema::hasTable('cat_equipment') && Schema::hasColumn('cat_equipment', 'image_url')) {
            foreach ($photos as $key => $file) {
                DB::table('cat_equipment')->where('key', $key)->whereNull('image_url')->update([
                    'image_url' => '/api/modules/custom-catalog/assets/'.$file,
                    'updated_at' => now(),
                ]);
            }
        }
        $file = base_path('modules/custom/catalog/resources/assets/briefs.json');
        if (! is_file($file)) {
            $file = dirname(__DIR__, 2).'/resources/assets/briefs.json';
        }
        if (! is_file($file) || ! Schema::hasTable('cat_equipment') || ! Schema::hasColumn('cat_equipment', 'summary')) {
            return;
        }
        $briefs = json_decode((string) file_get_contents($file), true) ?: [];
        foreach ($briefs as $key => $row) {
            $cur = DB::table('cat_equipment')->where('key', $key)->first();
            if (! $cur) {
                continue;
            }
            DB::table('cat_equipment')->where('key', $key)->update([
                'summary' => $cur->summary ?: ($row['summary'] ?? null),
                'wiki_url' => $cur->wiki_url ?: ($row['wiki_url'] ?? null),
                'facts' => $cur->facts ?: json_encode($row['facts'] ?? [], JSON_UNESCAPED_UNICODE),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
    }
};
