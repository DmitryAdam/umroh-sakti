<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

/**
 * Bikin/ganti operator. Satu-satunya jalan bikin akun — tidak ada halaman daftar,
 * dan memang tidak boleh ada: portalnya publik, operatornya tidak.
 *
 * Email yang sudah ada tidak ditolak, sandinya diganti — itu sekaligus jalur
 * "lupa sandi" (tidak ada reset lewat email; MAIL_MAILER=log).
 */
class CreateUser extends Command
{
    protected $signature = 'user:create
        {email : Email untuk login}
        {--password= : Sandi (kalau kosong, ditanya tanpa ditampilkan)}';

    protected $description = 'Bikin operator baru atau ganti sandinya';

    public function handle(): int
    {
        $email = (string) $this->argument('email');
        $password = (string) ($this->option('password') ?: $this->secret('Sandi (minimal 8 karakter)'));

        $check = Validator::make(compact('email', 'password'), [
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'min:8'],
        ]);

        if ($check->fails()) {
            foreach ($check->errors()->all() as $pesan) {
                $this->error($pesan);
            }

            return self::FAILURE;
        }

        $lama = User::firstWhere('email', $email);

        // Cast `hashed` di model yang mengenkripsinya — jangan Hash::make() lagi di
        // sini, hash ganda bikin loginnya selalu gagal tanpa pesan yang jelas.
        User::updateOrCreate(['email' => $email], ['password' => $password]);

        $this->info($lama ? "Sandi $email diganti." : "Operator $email dibuat. Login di /login.");

        return self::SUCCESS;
    }
}
