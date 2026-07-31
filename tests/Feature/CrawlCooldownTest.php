<?php

namespace Tests\Feature;

use App\Jobs\FetchAccount;
use App\Models\SourceAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CrawlCooldownTest extends TestCase
{
    use RefreshDatabase;

    public function test_cooldown_melewati_akun_yang_baru_di_scrap(): void
    {
        Queue::fake();

        SourceAccount::create(['username' => 'baru', 'status' => 'approved', 'last_fetched_at' => now()->subHour()]);
        SourceAccount::create(['username' => 'lama', 'status' => 'approved', 'last_fetched_at' => now()->subDay()]);
        SourceAccount::create(['username' => 'belum', 'status' => 'approved']);

        $this->artisan('packages:crawl', ['--cooldown' => 6])->assertSuccessful();

        $antri = [];
        Queue::assertPushed(FetchAccount::class, function ($job) use (&$antri) {
            $antri[] = $job->account->username;

            return true;
        });

        $this->assertEqualsCanonicalizing(['lama', 'belum'], $antri);
    }
}
