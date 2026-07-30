<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Tidak ada user: tidak ada login. Isi datanya dari pipeline —
 * `php artisan packages:crawl accounts.txt` lalu `php artisan queue:work`.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void {}
}
