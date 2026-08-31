<?php

namespace App\Http\Controllers;

use Barryvdh\DomPDF\Facade\Pdf;
use App\Models\PengajuanCuti;
use App\Models\SaldoCuti;
use App\Models\Notifikasi;
use App\Models\Pegawai;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Carbon\Carbon;

// ================= CATATAN =================
// File ini adalah controller SISI KARYAWAN (form pengajuan cuti, riwayat,
// kalender tim, unduh PDF, batal, revisi). TIDAK ADA perubahan di file ini
// pada sesi perbaikan kali ini — logika approve/reject (termasuk perbaikan
// field 'alasan' & level_saat_ini) ada di app/Http/Controllers/
// MonitoringCutiController.php (controller SISI ATASAN), bukan di sini.
// File ini ditampilkan kembali apa adanya untuk referensi/kelengkapan.
// ================= END CATATAN =================
class CutiController extends Controller
{
    private function normalizeJenisCuti(?string $value)
    {
        if ($value === null) {
            return 'Cuti Tahunan';
        }

        $normalized = trim((string) $value);

        $map = [
            'cuti tahunan' => 'Cuti Tahunan',
            'cuti melahirkan' => 'Cuti Melahirkan',
            'cuti besar' => 'Cuti Besar',
            'cuti alasan penting' => 'Cuti Alasan Penting',
            'cuti_alasan_penting' => 'Cuti Alasan Penting',
        ];

        $lower = strtolower($normalized);

        return $map[$lower] ?? $normalized;
    }

    // 1. Menampilkan Halaman Form Pengajuan
    public function create()
    {
        /** @var \App\Models\Pegawai $user */
        $user = Auth::user();
        $tahunCuti = date('Y');

        // Hitung cuti yang MASIH ANTRE agar langsung memotong tampilan saldo
        $cutiMenunggu = PengajuanCuti::where('pegawai_id', $user->id)
            ->where('jenis_cuti', 'Cuti Tahunan')
            ->whereIn('status', ['menunggu_l1', 'menunggu_l2', 'menunggu_l3', 'menunggu_l4'])
            ->whereYear('tanggal_mulai', $tahunCuti)
            ->sum('jumlah_hari');

        // Ambil saldo dari tabel SaldoCuti (Data Master HR)
        $saldo = SaldoCuti::where('pegawai_id', $user->id)->where('tahun', $tahunCuti)->first();

        if ($saldo) {
            $sisaCutiAsli = $saldo->sisa - $cutiMenunggu;
        } else {
            // Fallback jika Admin HR belum membuat data SaldoCuti
            $jatahCuti = $user->jatah_cuti ?? 12;
            $cutiTerpakai = PengajuanCuti::where('pegawai_id', $user->id)
                ->where('jenis_cuti', 'Cuti Tahunan')
                ->where('status', 'disetujui')
                ->whereYear('tanggal_mulai', $tahunCuti)
                ->sum('jumlah_hari');

            $sisaCutiAsli = $jatahCuti - $cutiTerpakai - $cutiMenunggu;
        }

        return Inertia::render('Karyawan/AjukanCuti', [
            'sisa_cuti' => (int) $sisaCutiAsli
        ]);
    }

    // 2. Memproses Simpan Data
    public function store(Request $request)
    {
        $request->validate([
            'jenis_cuti' => ['required', 'string', 'in:Cuti Tahunan,Cuti Melahirkan,Cuti Besar,Cuti Alasan Penting'],
            'tanggal_mulai' => ['required', 'date', 'after_or_equal:today'],
            'tanggal_selesai' => ['required', 'date', 'after_or_equal:tanggal_mulai'],
            'keterangan' => ['required', 'string'],
            'alamat_cuti' => ['required', 'string'],
            'no_telp' => ['required', 'string', 'max:20'],
            'lampiran' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:2048'], // Validasi file opsional maks 2MB
        ]);

        /** @var \App\Models\Pegawai $user */
        $user = Auth::user();

        // Normalisasi jenis cuti
        $jenisCuti = $this->normalizeJenisCuti($request->jenis_cuti);

        // Pastikan setiap atasan memiliki saldo tahunan standar 12 hari.
        if (in_array($user->role_id, [2, 3, 4, 6], true)) {
            SaldoCuti::firstOrCreate(
                ['pegawai_id' => $user->id, 'tahun' => Carbon::parse($request->tanggal_mulai)->year],
                ['kuota_tahunan' => 12, 'sisa' => 12]
            );
        }

        // Hitung jumlah hari
        $jumlah_hari = Carbon::parse($request->tanggal_mulai)->diffInDays(Carbon::parse($request->tanggal_selesai)) + 1;

        if ($jenisCuti === 'Cuti Tahunan') {
            $tahunCuti = Carbon::parse($request->tanggal_mulai)->year;

            $cutiMenunggu = PengajuanCuti::where('pegawai_id', $user->id)
                ->where('jenis_cuti', 'Cuti Tahunan')
                ->whereIn('status', ['menunggu_l1', 'menunggu_l2', 'menunggu_l3', 'menunggu_l4'])
                ->whereYear('tanggal_mulai', $tahunCuti)
                ->sum('jumlah_hari');

            $saldo = SaldoCuti::where('pegawai_id', $user->id)->where('tahun', $tahunCuti)->first();

            if ($saldo) {
                $sisaTersedia = $saldo->sisa - $cutiMenunggu;
            } else {
                $jatahCuti = $user->jatah_cuti ?? 12;
                $cutiTerpakai = PengajuanCuti::where('pegawai_id', $user->id)
                    ->where('jenis_cuti', 'Cuti Tahunan')
                    ->where('status', 'disetujui')
                    ->whereYear('tanggal_mulai', $tahunCuti)
                    ->sum('jumlah_hari');

                $sisaTersedia = $jatahCuti - $cutiTerpakai - $cutiMenunggu;
            }

            if ($jumlah_hari > $sisaTersedia) {
                return back()->withErrors([
                    'jenis_cuti' => "Pengajuan Cuti Tahunan melebihi sisa saldo. Sisa yang tersedia saat ini: {$sisaTersedia} hari.",
                ])->withInput();
            }
        }

        // =========================================================
        // PROSES UPLOAD FILE LAMPIRAN (JIKA ADA)
        // =========================================================
        $lampiranPath = null;
        if ($request->hasFile('lampiran')) {
            $lampiranPath = $request->file('lampiran')->store('lampiran-cuti', 'public');
        }

        // =========================================================
        // MENENTUKAN STATUS AWAL & TARGET NOTIFIKASI (HIERARKI)
        // =========================================================
        $statusAwal = 'menunggu_l1';
        $levelSaatIni = 1;
        $targetRoleNotifikasi = 2;

        if ($user->role_id === 2) {
            $statusAwal = 'menunggu_l2';
            $levelSaatIni = 2;
            $targetRoleNotifikasi = 3;
        } elseif ($user->role_id === 3) {
            $statusAwal = 'menunggu_l3';
            $levelSaatIni = 3;
            $targetRoleNotifikasi = 4;
        } elseif ($user->role_id === 4) {
            $statusAwal = 'menunggu_l4';
            $levelSaatIni = 4;
            $targetRoleNotifikasi = 6;
        }

        // Simpan ke database (termasuk lampiran)
        $pengajuan = PengajuanCuti::create([
            'pegawai_id' => $user->id,
            'jenis_cuti' => $jenisCuti,
            'tanggal_mulai' => $request->tanggal_mulai,
            'tanggal_selesai' => $request->tanggal_selesai,
            'jumlah_hari' => $jumlah_hari,
            'keterangan' => $request->keterangan,
            'alamat_cuti' => $request->alamat_cuti,
            'no_telp' => $request->no_telp,
            'lampiran' => $lampiranPath, // Simpan path file lampiran
            'status' => $statusAwal,
            'level_saat_ini' => $levelSaatIni,
        ]);

        // =========================================================
        // PROSES TRIGGER NOTIFIKASI KE ATASAN YANG TEPAT
        // =========================================================
        $atasanTarget = Pegawai::where('role_id', $targetRoleNotifikasi)
            ->where('departemen', $user->departemen)
            ->first();

        if (!$atasanTarget) {
            $atasanTarget = Pegawai::where('role_id', $targetRoleNotifikasi)->first();
        }

        if ($atasanTarget) {
            Notifikasi::create([
                'pegawai_id' => $atasanTarget->id,
                'judul'      => 'Pengajuan Cuti Baru',
                'pesan'      => 'Ada pengajuan cuti baru dari ' . $user->nama . ' yang butuh persetujuan Anda.',
                'tautan'     => route('atasan.approval'),
                'is_read'    => false,
            ]);
        }

        return redirect()->route('karyawan.riwayat')->with('success', 'Pengajuan cuti berhasil dikirim.');
    }

    // 3. Menampilkan Halaman Riwayat Pengajuan
    public function history(Request $request)
    {
        /** @var \App\Models\Pegawai $user */
        $user = Auth::user();

        $query = PengajuanCuti::with(['atasanL1', 'atasanL3', 'atasanL4', 'approvalLogs'])
            ->where('pegawai_id', $user->id)
            ->orderBy('created_at', 'desc');

        if ($request->filled('search')) {
            $query->where('keterangan', 'like', '%' . $request->search . '%');
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $riwayat = $query->paginate(5)->withQueryString();

        // Kirim data sisa cuti yang akurat ke halaman Riwayat
        $tahunCuti = date('Y');
        $cutiMenunggu = PengajuanCuti::where('pegawai_id', $user->id)
            ->where('jenis_cuti', 'Cuti Tahunan')
            ->whereIn('status', ['menunggu_l1', 'menunggu_l2', 'menunggu_l3', 'menunggu_l4'])
            ->whereYear('tanggal_mulai', $tahunCuti)
            ->sum('jumlah_hari');

        $saldo = SaldoCuti::where('pegawai_id', $user->id)->where('tahun', $tahunCuti)->first();

        if ($saldo) {
            $sisaCutiAsli = $saldo->sisa - $cutiMenunggu;
        } else {
            $jatahCuti = $user->jatah_cuti ?? 12;
            $cutiTerpakai = PengajuanCuti::where('pegawai_id', $user->id)
                ->where('jenis_cuti', 'Cuti Tahunan')
                ->where('status', 'disetujui')
                ->whereYear('tanggal_mulai', $tahunCuti)
                ->sum('jumlah_hari');
            $sisaCutiAsli = $jatahCuti - $cutiTerpakai - $cutiMenunggu;
        }

        return Inertia::render('Karyawan/RiwayatPengajuan', [
            'riwayat' => $riwayat,
            'filters' => $request->only(['search', 'status']),
            'sisa_cuti' => (int) $sisaCutiAsli
        ]);
    }

    // 4. Menampilkan Kalender Tim
    public function teamCalendar()
    {
        /** @var \App\Models\Pegawai $user */
        $user = Auth::user();

        $cutiTim = PengajuanCuti::with('pegawai:id,nama,departemen,jabatan')
            ->whereHas('pegawai', function ($query) use ($user) {
                $query->where('departemen', $user->departemen);
            })
            ->where('status', 'disetujui')
            ->where('tanggal_selesai', '>=', Carbon::today()->toDateString())
            ->orderBy('tanggal_mulai', 'asc')
            ->get();

        $hariLiburs = \App\Models\HariLibur::all();

        return Inertia::render('Karyawan/KalenderTim', [
            'cutiTim' => $cutiTim,
            'departemen' => $user->departemen,
            'hariLiburs' => $hariLiburs
        ]);
    }

    // 5. Unduh PDF Bukti Cuti
    public function downloadPdf(int $id)
    {
        /** @var \App\Models\Pegawai $user */
        $user = Auth::user();

        $pengajuan = PengajuanCuti::with(['pegawai', 'atasanL1', 'atasanL3', 'atasanL4', 'approvalLogs'])->findOrFail($id);

        if ($pengajuan->pegawai_id !== $user->id || $pengajuan->status !== 'disetujui') {
            abort(403, 'Anda tidak memiliki akses, atau cuti belum disetujui sepenuhnya.');
        }

        $tanggalMasuk = Carbon::parse($pengajuan->pegawai->tanggal_masuk);
        $masaKerja = $tanggalMasuk->diff(Carbon::now())->format('%y Tahun / %m Bulan');

        $tahunCuti = date('Y', strtotime($pengajuan->tanggal_mulai));
        $saldo = SaldoCuti::where('pegawai_id', $pengajuan->pegawai_id)->where('tahun', $tahunCuti)->first();

        if ($saldo) {
            $sisaCutiAsli = $saldo->sisa; // Jika disetujui, cuti_menunggu tidak dihitung
        } else {
            $jatahCuti = $pengajuan->pegawai->jatah_cuti ?? 12;
            $cutiTerpakai = PengajuanCuti::where('pegawai_id', $pengajuan->pegawai_id)
                ->where('jenis_cuti', 'Cuti Tahunan')
                ->where('status', 'disetujui')
                ->whereYear('tanggal_mulai', $tahunCuti)
                ->sum('jumlah_hari');
            $sisaCutiAsli = $jatahCuti - $cutiTerpakai;
        }

        $data = [
            'pengajuan' => $pengajuan,
            'pegawai' => $pengajuan->pegawai,
            'masaKerja' => $masaKerja,
            'sisaCuti' => (int) $sisaCutiAsli,
        ];

        $pdf = Pdf::loadView('pdf.surat-cuti', $data)->setPaper('A4', 'portrait');
        $namaFile = 'Surat_Izin_Cuti_' . $pengajuan->pegawai->nama . '_' . $pengajuan->tanggal_mulai . '.pdf';

        return $pdf->download($namaFile);
    }

    // 6. Karyawan Membatalkan Pengajuan Cuti Sendiri (Yang Masih Antrean)
    public function cancel(int $id)
    {
        /** @var \App\Models\Pegawai $user */
        $user = Auth::user();

        $pengajuan = PengajuanCuti::findOrFail($id);

        if ($pengajuan->pegawai_id !== $user->id) {
            abort(403, 'Anda tidak diizinkan membatalkan pengajuan ini.');
        }

        if (!in_array($pengajuan->status, ['menunggu_l1', 'menunggu_l2', 'menunggu_l3', 'menunggu_l4'])) {
            return back()->with('error', 'Cuti ini sudah tidak dapat dibatalkan.');
        }

        $pengajuan->update([
            'status' => 'dibatalkan_reguler'
        ]);

        return back()->with('success', 'Pengajuan cuti berhasil dibatalkan.');
    }

    // 7. Karyawan Membatalkan Cuti Mandiri (Yang Sudah Disetujui)
    public function batalkanMandiri(Request $request, int $id)
    {
        $request->validate([
            'alasan_pembatalan' => 'required|string|max:255',
        ], [
            'alasan_pembatalan.required' => 'Alasan pembatalan wajib diisi.',
        ]);

        /** @var \App\Models\Pegawai $user */
        $user = Auth::user();

        $cuti = PengajuanCuti::findOrFail($id);

        if ($cuti->pegawai_id !== $user->id || $cuti->status !== 'disetujui') {
            return redirect()->back()->with('error', 'Aksi tidak diizinkan atau cuti tidak dapat dibatalkan.');
        }

        if (strtolower($cuti->jenis_cuti) === 'cuti tahunan') {
            $tahunCuti = date('Y', strtotime($cuti->tanggal_mulai));
            $saldo = SaldoCuti::where('pegawai_id', $cuti->pegawai_id)
                ->where('tahun', $tahunCuti)
                ->first();

            if ($saldo) {
                $saldo->sisa += $cuti->jumlah_hari;
                $saldo->save();
            }
        }

        $cuti->status = 'dibatalkan_reguler';
        $cuti->keterangan = $cuti->keterangan . ' | Batal Mandiri: ' . $request->alasan_pembatalan;
        $cuti->save();

        return redirect()->back()->with('success', 'Cuti berhasil dibatalkan dan saldo telah dikembalikan.');
    }

    // 8. Karyawan Merevisi Cuti (Khusus Status Ditangguhkan)
    public function revisi(Request $request, int $id)
    {
        $request->validate([
            'tanggal_mulai' => ['required', 'date', 'after_or_equal:today'],
            'tanggal_selesai' => ['required', 'date', 'after_or_equal:tanggal_mulai'],
        ]);

        $cuti = PengajuanCuti::findOrFail($id);

        /** @var \App\Models\Pegawai $userLogin */
        $userLogin = Auth::user();

        if ($cuti->pegawai_id !== $userLogin->id || $cuti->status !== 'dibatalkan_ditangguhkan') {
            return redirect()->back()->with('error', 'Cuti ini tidak dapat direvisi.');
        }

        $startDate = Carbon::parse($request->tanggal_mulai);
        $endDate = Carbon::parse($request->tanggal_selesai);
        $jumlah_hari = 0;

        $currentDate = $startDate->copy();
        while ($currentDate->lte($endDate)) {
            if (!$currentDate->isWeekend()) {
                $jumlah_hari++;
            }
            $currentDate->addDay();
        }

        if ($jumlah_hari === 0) {
            return redirect()->back()->with('error', 'Tanggal yang dipilih jatuh pada hari libur sepenuhnya.');
        }

        $cuti->update([
            'tanggal_mulai' => $request->tanggal_mulai,
            'tanggal_selesai' => $request->tanggal_selesai,
            'jumlah_hari' => $jumlah_hari,
            'status' => 'menunggu_l1',
            'level_saat_ini' => 1,
            'keterangan' => $cuti->keterangan . ' | [DIREVISI]',
        ]);

        $atasanL1 = Pegawai::where('role_id', 2)->where('departemen', $userLogin->departemen)->first()
            ?? Pegawai::where('role_id', 2)->first();

        if ($atasanL1) {
            Notifikasi::create([
                'pegawai_id' => $atasanL1->id,
                'judul'      => 'Revisi Pengajuan Cuti',
                'pesan'      => 'Ada revisi tanggal cuti dari ' . $userLogin->nama . ' yang butuh persetujuan Anda.',
                'tautan'     => route('atasan.approval'),
                'is_read'    => false,
            ]);
        }

        return redirect()->back()->with('success', 'Tanggal cuti berhasil direvisi dan diajukan ulang.');
    }
}
