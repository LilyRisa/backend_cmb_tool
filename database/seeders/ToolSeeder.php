<?php

namespace Database\Seeders;

use App\Models\Tool;
use Illuminate\Database\Seeder;

class ToolSeeder extends Seeder
{
    public function run(): void
    {
        if (Tool::where('type', 'cmb_core')->exists()) {
            $this->command->warn('cmb_core tools already seeded, skipping.');
            return;
        }

        $releases = [
            ['version' => '4.1.4', 'changelog' => 'Cập nhật tính năng auto comment facebook và sửa lỗi, tối ưu', 'file_size' => '193.84 MB', 'released_at' => '2026-03-11', 'download_count' => 0],
            ['version' => '4.1.5', 'changelog' => 'Fix bug', 'file_size' => '193.9 MB', 'released_at' => '2026-03-13', 'download_count' => 0],
            ['version' => '4.1.6', 'changelog' => 'fix bug youtube check cookie & validate title video max 100 char', 'file_size' => '193.84 MB', 'released_at' => '2026-03-13', 'download_count' => 2],
            ['version' => '4.1.7', 'changelog' => 'Chuyển đổi đăng nhập Oauth callback, fix bug tải video', 'file_size' => '193.9 MB', 'released_at' => '2026-03-16', 'download_count' => 0],
            ['version' => '4.1.8', 'changelog' => 'Thêm tính năng tạo video story AI', 'file_size' => '201.74 MB', 'released_at' => '2026-03-19', 'download_count' => 0],
            ['version' => '4.1.9', 'changelog' => 'fix bug tạo video story AI', 'file_size' => '201.79 MB', 'released_at' => '2026-03-20', 'download_count' => 0],
            ['version' => '4.2.0', 'changelog' => 'fix bug get page facebook', 'file_size' => '201.79 MB', 'released_at' => '2026-03-21', 'download_count' => 1],
            // sha256 computed 2026-08-02 by downloading the live CDN file and hashing it —
            // this is the current is_latest release, and the desktop client's OTA
            // updater (UpdateService.js) hard-refuses to download without a valid
            // sha256, so this one can't be left null like the older, non-latest rows.
            ['version' => '4.2.1', 'changelog' => 'Fix lỗi đăng nhập và xử lý facebook', 'file_size' => '202 MB', 'released_at' => '2026-07-05', 'download_count' => 0, 'sha256' => '17c8248621be5c34cc7fe2ba3f49f404aa98dff79447bbc374cc97a01fe33a40'],
        ];

        foreach ($releases as $i => $r) {
            Tool::create([
                'name' => 'CMB Core Marketing',
                'slug' => 'cmb-core-marketing-' . str_replace('.', '', $r['version']),
                'type' => 'cmb_core',
                'version' => $r['version'],
                'description' => 'Phần mềm tự động hóa marketing video đa nền tảng CMB Core Marketing.',
                'download_url' => 'https://cdn.cmbcore.com/cmb-core-marketing/CMBcoreMKT%20Setup%20' . $r['version'] . '.exe',
                'file_size' => $r['file_size'],
                'sha256' => $r['sha256'] ?? null,
                'changelog' => $r['changelog'],
                'is_active' => true,
                'is_latest' => $i === count($releases) - 1,
                'download_count' => $r['download_count'],
                'released_at' => $r['released_at'],
            ]);
        }

        $this->command->info('Seeded 8 cmb_core Tool releases.');
    }
}
