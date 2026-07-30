<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SourceAccount extends Model
{
    protected $guarded = [];

    protected $casts = ['last_fetched_at' => 'datetime'];

    public function scopeApproved($q)
    {
        return $q->where('status', 'approved');
    }

    /**
     * Baris teks (accounts.txt atau textarea di /akun) -> akun approved.
     * Sudah ada = tidak disentuh, status manual menang.
     *
     * @param  iterable<string>  $lines
     * @return list<string> username yang baru didaftarkan
     */
    public static function register(iterable $lines): array
    {
        $new = [];
        foreach ($lines as $line) {
            $username = static::usernameOf($line);
            if ($username === null || static::where('username', $username)->exists()) {
                continue;
            }
            static::create(['username' => $username, 'status' => 'approved']);
            $new[] = $username;
        }

        return $new;
    }

    /** "https://www.instagram.com/foo/?x=1" atau "@foo" -> "foo". */
    public static function usernameOf(string $line): ?string
    {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            return null;
        }
        if (str_contains($line, 'instagram.com')) {
            $line = parse_url($line, PHP_URL_PATH) ?: '';
        }
        $line = trim($line, "/@ \t");

        return preg_match('/^[A-Za-z0-9._]+$/', $line) ? $line : null;
    }
}
