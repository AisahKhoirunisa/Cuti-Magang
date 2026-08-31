<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Menambahkan kolom kontak pegawai (Nomor Telepon & Alamat Domisili) yang
// dibutuhkan oleh form Edit Pegawai (menyesuaikan referensi tampilan SiCuti
// BRMP). Kolom ini sebelumnya tidak ada di tabel pegawais.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pegawais', function (Blueprint $table) {
            $table->string('no_telp', 30)->nullable()->after('jabatan');
            $table->text('alamat')->nullable()->after('no_telp');
        });
    }

    public function down(): void
    {
        Schema::table('pegawais', function (Blueprint $table) {
            $table->dropColumn(['no_telp', 'alamat']);
        });
    }
};
