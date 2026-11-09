<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Pengingat bertahap ke atasan + kedaluwarsa otomatis pengajuan cuti yang
// menggantung (lihat app/Console/Commands/ProsesCutiMenunggu.php).
Schedule::command('cuti:proses-menunggu')
    ->dailyAt('07:00')
    ->withoutOverlapping();

// Generate Saldo Tahun Baru SENGAJA TIDAK dijadwalkan otomatis.
// Dompet tahun baru dibuat manual oleh Admin HR lewat tombol Generate
// di Rekap Kuota Detail (lihat app/Console/Commands/GenerateSaldoCutiTahunBaru.php).