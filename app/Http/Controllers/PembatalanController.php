<?php

namespace App\Http\Controllers;

use App\Models\ApprovalLog;
use App\Models\CutiDitangguhkan;
use App\Models\Pegawai;
use App\Models\PengajuanCuti;
use App\Models\SaldoCuti;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class PembatalanController extends Controller
{
    private const ROLE_PENANGGUH = 6;

    /**
     * Menampilkan daftar cuti yang bisa ditangguhkan.
     */
    public function index(Request $request)
    {
        abort_unless((int) $request->user()->role_id === self::ROLE_PENANGGUH, 403, 'Hanya L4 yang dapat menangguhkan cuti.');

        $search = $request->input('search');
        $kelompok = $request->input('kelompok_substansi');

        $daftarCuti = PengajuanCuti::with('pegawai')
            ->bisaDitangguhkan()
            ->when($search, function ($query) use ($search) {
                $query->whereHas('pegawai', function ($q) use ($search) {
                    $q->where('nama', 'like', "%{$search}%")
                        ->orWhere('nip', 'like', "%{$search}%");
                });
            })
            ->when($kelompok, function ($query) use ($kelompok) {
                $query->whereHas('pegawai', function ($q) use ($kelompok) {
                    $q->where('kelompok_substansi', $kelompok);
                });
            })
            ->orderBy('tanggal_mulai')
            ->get()
            ->each(function (PengajuanCuti $pengajuan) {
                $pengajuan->bisa_ditangguhkan = $pengajuan->bisaDitangguhkan();
                $pengajuan->alasan_tidak_bisa = $pengajuan->alasanTidakBisaDitangguhkan();
            });

        $daftarKelompokSubstansi = Pegawai::query()
            ->whereNotNull('kelompok_substansi')
            ->where('kelompok_substansi', '!=', '')
            ->where('kelompok_substansi', '!=', '-')
            ->distinct()
            ->orderBy('kelompok_substansi')
            ->pluck('kelompok_substansi');

        return Inertia::render('Atasan/PembatalanCuti', [
            'daftarCuti' => $daftarCuti,
            'daftarKelompokSubstansi' => $daftarKelompokSubstansi,
            'filters' => $request->only(['search', 'kelompok_substansi']),
        ]);
    }

    /**
     * Memproses eksekusi penangguhan oleh Kepala Biro.
     *
     * [DIUBAH] Cuti Tahunan LINTAS TAHUN (mis. 29 Des 2026 - 4 Jan 2027)
     * dikembalikan PER TAHUN: hari kerja di Desember kembali ke saldo 2026,
     * hari kerja di Januari kembali ke saldo 2027. Catatan penangguhan
     * (CutiDitangguhkan) juga dibuat satu per tahun asal, jadi "Ditangguhkan
     * 2026" dan "Ditangguhkan 2027" tercatat terpisah. Cuti satu tahun
     * berperilaku sama persis seperti sebelumnya.
     *
     * [DITAMBAH] Di akhir transaksi, bawaan disinkronkan lewat
     * SaldoCuti::sinkronkanBawaanPengajuan(), sehingga ikut rollback bila ada error.
     */
    public function process(Request $request, int $id)
    {
        $user = $request->user();

        abort_unless((int) $user->role_id === self::ROLE_PENANGGUH, 403, 'Hanya L4 yang dapat menangguhkan cuti.');

        $request->validate([
            'alasan' => ['required', 'string', 'max:255'],
        ], [
            'alasan.required' => 'Alasan penangguhan wajib diisi.',
        ]);

        DB::transaction(function () use ($request, $user, $id) {
            $pengajuan = PengajuanCuti::with('pegawai')->lockForUpdate()->findOrFail($id);
            $statusSebelum = $pengajuan->status;
            $alasanTidakBisa = $pengajuan->alasanTidakBisaDitangguhkan();

            if ($alasanTidakBisa !== null) {
                throw ValidationException::withMessages([
                    'status' => [$alasanTidakBisa],
                ]);
            }

            $keteranganBaru = trim((string) $pengajuan->keterangan) === ''
                ? '[DITANGGUHKAN: ' . $request->alasan . ']'
                : $pengajuan->keterangan . ' | [DITANGGUHKAN: ' . $request->alasan . ']';

            $pengajuan->update([
                'status' => 'dibatalkan_ditangguhkan',
                'keterangan' => $keteranganBaru,
            ]);

            ApprovalLog::create([
                'pengajuan_id' => $pengajuan->id,
                'approver_id' => $user->id,
                'level_approval' => 5,
                'keputusan' => 'tangguh',
                'catatan' => $request->alasan,
                'tanggal_keputusan' => now(),
            ]);

            if (
                strtolower(trim((string) $pengajuan->jenis_cuti)) === 'cuti tahunan'
                && $statusSebelum === 'disetujui'
            ) {
                // Kembalikan kolom legacy `sisa` PER TAHUN.
                SaldoCuti::sesuaikanSisaLegacy($pengajuan, +1);

                // Catatan ini hanya untuk pelaporan; bukan carry-over dan saldo sudah dikembalikan.
                // Satu catatan per tahun asal (cuti lintas tahun menghasilkan dua catatan).
                $rincian = SaldoCuti::hariPerTahunDariPengajuan(
                    $pengajuan->tanggal_mulai,
                    $pengajuan->tanggal_selesai,
                    (int) $pengajuan->jumlah_hari
                );

                foreach ($rincian as $tahunAsal => $hari) {
                    if ($hari <= 0) {
                        continue;
                    }

                    CutiDitangguhkan::updateOrCreate(
                        [
                            'pengajuan_asal_id' => $pengajuan->id,
                            'tahun_asal' => (int) $tahunAsal,
                        ],
                        [
                            'pegawai_id' => $pengajuan->pegawai_id,
                            'jumlah_hari' => (int) $hari,
                            'status_pakai' => 'belum_dipakai',
                            'alasan_penangguhan' => $request->alasan,
                        ]
                    );
                }
            }

            // Paling akhir di dalam transaksi: status sudah 'dibatalkan_ditangguhkan',
            // saldo per tahun sudah dikembalikan, dan catatan CutiDitangguhkan sudah dibuat.
            SaldoCuti::sinkronkanBawaanPengajuan($pengajuan);
        });

        return back()->with('success', 'Cuti berhasil ditangguhkan.');
    }
}