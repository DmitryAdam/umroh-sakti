<?php

namespace Tests\Feature;

use App\Jobs\FetchAccount;
use App\Models\SourceAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class FetchAccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_rate_limit_tidak_menstempel_last_error(): void
    {
        // Rate limit itu keadaan menunggu — jobnya di-release, bukan gagal. Kalau
        // distempel, akun yang fetch terakhirnya berhasil memajang "gagal: semua app
        // kena rate limit" di /accounts, dan stempelnya nyangkut selamanya kalau job
        // yang menunggu itu dibuang lewat tombol batal.
        Process::fake(['*' => Process::result(errorOutput: 'ERROR: rate limit', exitCode: 1)]);

        $account = SourceAccount::create([
            'username' => 'pernahberhasil', 'status' => 'approved', 'last_fetched_at' => now()->subDay(),
        ]);

        (new FetchAccount($account))->handle();

        $this->assertNull($account->fresh()->last_error);
    }
}
