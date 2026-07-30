<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dua tabel saja: akun sumber + paket. Semua komponen paket (hotel, fasilitas,
 * akun yang repost) ikut di baris paket — tidak ada tabel master dan tidak ada
 * tabel anak. Nama hotel disimpan apa adanya dari flyer.
 *
 * Tidak ada data PPIU di paket: izin travel nanti melekat ke source_accounts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('source_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('username')->unique();
            // Submit username masuk antrian approval — tidak ada crawl langsung.
            $table->string('status')->default('pending'); // pending|approved|rejected
            $table->timestamp('last_fetched_at')->nullable();
            $table->timestamps();
        });

        Schema::create('packages', function (Blueprint $table) {
            $table->id();

            // Post asal ekstraksi. Folder rawnya (= sumber flyer) ada di
            // storage/raw/{source_account}/{media_id}.
            $table->string('source_account')->nullable();
            $table->string('media_id')->nullable();
            $table->string('source_permalink')->nullable();
            $table->timestamp('source_posted_at')->nullable();

            // Satu carousel bisa memuat beberapa paket berbeda (gambar 1 Ramadhan,
            // gambar 2 Syawal). Tiap gambar jadi barisnya sendiri, jadi idempotensinya
            // per (media_id, gambar) — bukan per media_id.
            $table->unsignedTinyInteger('flyer_index')->nullable();

            // Akun lain yang memposting paket yang sama. Audit saja — dedupnya
            // per paket, bukan per akun. [{media_id, account, permalink, posted_at}]
            $table->json('reposts')->nullable();

            $table->date('departure_date')->nullable();
            // exact|month|season|unknown — menentukan seberapa jauh tanggal boleh dipercaya
            $table->string('date_certainty')->default('unknown');
            $table->unsignedSmallInteger('duration_days')->nullable();
            $table->string('departure_city')->nullable();
            $table->string('airline')->nullable();
            $table->string('guide_name')->nullable(); // pembimbing/ustadz rombongan
            $table->string('extension')->default('none'); // turki|dubai|aqsa|none|unknown

            // Tier harga tetap: single/double/triple/quad. Satu paket = satu baris.
            $table->unsignedBigInteger('price_single')->nullable();
            $table->unsignedBigInteger('price_double')->nullable();
            $table->unsignedBigInteger('price_triple')->nullable();
            $table->unsignedBigInteger('price_quad')->nullable();
            $table->string('currency', 3)->default('IDR');
            $table->boolean('price_starting_from')->default(false);

            // Nama hotel apa adanya dari flyer, tanpa master & tanpa fuzzy match.
            $table->string('hotel_makkah')->nullable();
            $table->string('hotel_madinah')->nullable();
            $table->unsignedTinyInteger('nights_makkah')->nullable();
            $table->unsignedTinyInteger('nights_madinah')->nullable();

            $table->json('facilities')->nullable();           // ['visa','tiket',...]
            $table->text('facilities_raw')->nullable();

            $table->string('status')->default('draft'); // draft|review|published|rejected
            $table->timestamp('extracted_at')->nullable();
            $table->float('confidence')->nullable();
            $table->json('raw_extraction')->nullable();

            // Koreksi manusia atas hasil ekstraksi — bahan perbaikan prompt,
            // bukan status publikasi.
            $table->string('review_verdict')->nullable();
            $table->text('review_note')->nullable();
            $table->timestamp('reviewed_at')->nullable();

            // Dedup per paket, bukan per akun sumber. Diisi oleh Package::dedupKey().
            $table->string('dedup_key')->nullable()->index();
            $table->timestamps();

            $table->index(['status', 'departure_date']);
            $table->index(['departure_city', 'departure_date']);
            $table->unique(['media_id', 'flyer_index']);
        });

        // Post yang tidak perlu di-scrap lagi: bukan penawaran paket, keberangkatannya
        // sebelum ambang, atau dibuang manual. probe.php membacanya langsung lewat PDO
        // (excludedIds()) supaya fetch tidak men-download ulang dan extract tidak
        // membayar model untuk post yang sama.
        Schema::create('excluded_posts', function (Blueprint $table) {
            $table->id();
            $table->string('media_id')->unique();
            $table->string('source_account')->nullable();
            $table->string('reason'); // bukan_paket|haji|sebelum_ambang|manual
            $table->text('note')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('excluded_posts');
        Schema::dropIfExists('packages');
        Schema::dropIfExists('source_accounts');
    }
};
