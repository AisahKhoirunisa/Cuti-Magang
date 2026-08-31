<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class Pegawai extends Authenticatable
{
    use Notifiable;

    /**
     * Kolom yang boleh diisi secara mass-assignment
     * (penting untuk fitur Admin HR: create / update data pegawai)
     *
     * ---> PERBAIKAN <---
     * Ditambahkan 'jabatan' karena sebelumnya TIDAK ada di daftar ini.
     * Akibatnya, setiap kali Admin HR menambah/mengedit pegawai lewat
     * modal Tambah/Edit, field "Jabatan" yang diisi di form SELALU
     * diam-diam diabaikan oleh Eloquent (tidak error, tapi datanya
     * tidak pernah tersimpan ke database).
     *
     * Ditambahkan juga 'is_first_login' supaya controller bisa langsung
     * men-set status ini saat pegawai baru dibuat (lihat AdminController::
     * storePegawai()), sehingga pegawai baru otomatis diarahkan untuk
     * mengganti password default saat login pertama kali.
     *
     * ---> PENAMBAHAN BARU <---
     * 'no_telp' dan 'alamat' ditambahkan supaya modal Edit Pegawai bisa
     * menyimpan Nomor Telepon & Alamat Domisili (menyesuaikan referensi
     * tampilan SiCuti BRMP). Kolomnya dibuat lewat migration
     * 2026_08_29_000000_add_kontak_fields_to_pegawais_table.php
     */
    protected $fillable = [
        'nama',
        'nip',
        'departemen',
        'divisi',
        'jabatan',        // <-- PENAMBAHAN: field ini sebelumnya hilang
        'role_id',
        'password',
        'is_first_login', // <-- PENAMBAHAN: dipakai saat create pegawai baru
        'no_telp',         // <-- PENAMBAHAN BARU: Nomor Telepon
        'alamat',          // <-- PENAMBAHAN BARU: Alamat Domisili
        'email',           // jika dipakai
        // tambahkan kolom lain yang memang ada di tabel pegawais di sini
    ];

    protected $hidden = [
        'password',
    ];

    protected $casts = [
        'role_id' => 'integer',
    ];

    // ================= RELASI YANG SUDAH ADA =================

    // Relasi untuk modul Admin HR (melihat semua riwayat saldo cuti)
    // Jangan dihapus – dipakai di kelolaSaldo() dan modul cuti
    public function saldoCuti()
    {
        return $this->hasMany(SaldoCuti::class, 'pegawai_id');
    }

    // Relasi tambahan khusus untuk mengambil saldo cuti tahun berjalan (untuk Approval)
    public function saldoCutiTahunIni()
    {
        return $this->hasOne(SaldoCuti::class, 'pegawai_id')->where('tahun', date('Y'));
    }

    // Relasi tambahan yang sering dibutuhkan (opsional tapi sangat berguna)
    public function pengajuanCuti()
    {
        return $this->hasMany(PengajuanCuti::class, 'pegawai_id');
    }
}
