<?php

namespace App\Http\Controllers;

use App\Models\PengajuanCuti;
use App\Models\SaldoCuti;
use App\Models\Notifikasi;
use App\Models\Pegawai;
use App\Models\ApprovalLog;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Facades\Auth;

// ================= PENTING: NAMA FILE & NAMA CLASS =================
// File ini WAJIB disimpan persis di:
//     app/Http/Controllers/MonitoringCutiController.php
// dengan nama class persis "MonitoringCutiController" (sama seperti di
// bawah ini). Laravel memakai autoload PSR-4: nama file HARUS sama persis
// dengan nama class di dalamnya. Kalau file ini masih bernama
// ApprovalController.php (dengan class ApprovalController) di project Boss,
// maka route di web.php yang memanggil MonitoringCutiController::class akan
// selalu gagal dengan error "Target class [MonitoringCutiController] does
// not exist" — walaupun rute di web.php sendiri sudah benar. Jadi:
//   1. Kalau Boss masih punya file lama app/Http/Controllers/ApprovalController.php,
//      HAPUS file itu (atau rename jadi MonitoringCutiController.php lalu
//      timpa isinya dengan file ini) supaya tidak ada dua controller yang
//      tumpang tindih.
//   2. Simpan file ini sebagai app/Http/Controllers/MonitoringCutiController.php.
//   3. Jalankan `php artisan optimize:clear` (atau minimal
//      `php artisan route:clear` & `php artisan config:clear`) supaya cache
//      lama tidak ikut membingungkan.
// ================= END CATATAN PENTING =================
//
// ================= RIWAYAT PERBAIKAN YANG SUDAH DIGABUNG DI FILE INI =================
// 1. SINKRONISASI WORDING "DITOLAK": saat Atasan menekan tombol Tolak, teks
//    keterangan sekarang konsisten memakai kata "Ditolak" (bukan lagi
//    "Ditangguhkan") supaya sinkron dengan kolom status yang memang di-set
//    'ditolak'. Data LAMA yang keterangannya masih menyebut "Ditangguhkan"
//    tetap didukung lewat helper isKeteranganDitangguhkan() di sisi Vue,
//    supaya histori lama tidak berubah makna.
// 2. SUMBER KEBENARAN LEVEL PENOLAK: kolom `level_saat_ini` SEKARANG SELALU
//    diisi dengan angka level yang benar-benar menolak (1-4), diambil dari
//    $levelApproval yang sudah tervalidasi lebih dulu lewat pengecekan
//    $roleYangDibutuhkan di awal method process(). Ini menggantikan cara
//    lama yang mengandalkan tebak-tebakan dari teks `keterangan` (rawan
//    meleset). Frontend (MonitoringCuti.vue -> approvalTimeline) membaca
//    kolom ini sebagai prioritas utama, dengan fallback ke scan teks
//    keterangan lalu ke step terakhir untuk data yang sangat lama.
//    (Fix ini menggantikan/menuntaskan Langkah 1 dari sesi perbaikan
//    sebelumnya — pendekatan di sini lebih aman daripada menebak level dari
//    role_id user yang login, karena $levelApproval sudah lolos validasi
//    $roleYangDibutuhkan di awal method, jadi dijamin sinkron dengan status
//    'menunggu_lX' yang sedang diproses.)
// ================= END RIWAYAT PERBAIKAN =================
class MonitoringCutiController extends Controller
{
    // 1. Menampilkan Daftar Antrean — sekarang berfungsi sebagai Pemantau
    //    Seluruh Transaksi (bisa melihat semua aksi: menunggu, disetujui,
    //    ditolak, ditangguhkan, dll), dengan search & filter jenis cuti + status.
    public function index(Request $request)
    {
        $user = Auth::user();
        // Relasi 'saldoCutiTahunIni' tetap dipertahankan agar sisa cuti terbawa ke frontend
        $query = PengajuanCuti::with(['pegawai.saldoCutiTahunIni']);

        // --- BATASAN WILAYAH (bukan lagi batasan status) ---
        // Kunci hardcode status 'menunggu_lX' per role sudah DIHAPUS supaya
        // halaman ini bisa menampilkan semua aksi transaksi, bukan cuma yang
        // masih menunggu. Yang tersisa hanya batasan wilayah/akses:
        if ($user->role_id === 2) {
            // L1 (Ketua Tim): hanya bisa melihat pengajuan di departemennya saja
            $query->whereHas('pegawai', function ($q) use ($user) {
                $q->where('departemen', $user->departemen);
            });
        } elseif (!in_array($user->role_id, [3, 4, 5, 6])) {
            // Jika bukan L1, L2, L3, L4, atau Admin HR -> blokir aksesnya
            $query->where('id', 0);
        }
        // Catatan: L2, L3, L4, dan Admin HR (role 5) bebas melihat skala biro/pusat.

        // Fitur filter status dari dropdown Vue yang disempurnakan
        // PERBAIKAN LANJUTAN: filter jenis_cuti dan status kini eksplisit
        // mengabaikan string placeholder "Semua Jenis Cuti"/"Semua Status"
        // maupun string kosong, supaya tidak pernah mencari baris dengan
        // nilai kolom yang memang tidak ada di database (yang membuat
        // tabel tampak kosong padahal datanya ada).
        if ($request->filled('status') && $request->status !== '' && $request->status !== 'Semua Status') {
            $stVal = $request->status;
            if ($stVal === 'ditangguhkan') {
                // PERBAIKAN: sebelumnya orWhere('keterangan', 'like', '%ditangguhkan%')
                // berdiri sendiri di level teratas, sehingga baris yang statusnya
                // MASIH 'menunggu_lX' tapi kebetulan keterangannya membawa kata
                // "ditangguhkan" (mis. dari teks pengajuan cuti ulang) ikut lolos
                // dan tampil sebagai "Menunggu ..." padahal filter yang dipilih
                // adalah "Ditangguhkan". Sekarang syarat keterangan HANYA berlaku
                // ketika status memang 'ditolak' (dibungkus sub-closure sendiri),
                // sehingga status menunggu_l1/l2/l3/l4 tidak pernah ikut ke-match.
                //
                // CATATAN TAMBAHAN setelah perbaikan sinkronisasi status "Ditolak":
                // Karena teks keterangan penolakan sekarang memakai kata "Ditolak"
                // (bukan lagi "Ditangguhkan"), sub-closure pencarian keyword
                // '%Ditangguhkan%'/'%ditangguhkan%' pada status 'ditolak' ini
                // secara alami tidak akan match lagi untuk pengajuan BARU yang
                // ditolak — filter "Ditangguhkan" jadi murni mengacu ke status asli
                // 'ditangguhkan'/'dibatalkan_ditangguhkan'. Baris lama (sebelum
                // perbaikan ini) yang keterangannya masih memuat kata lama tetap
                // ikut match, supaya data historis tidak "hilang" dari pencarian.
                $query->where(function ($sub) {
                    $sub->where('status', 'ditangguhkan')
                        ->orWhere('status', 'dibatalkan_ditangguhkan')
                        ->orWhere(function ($ditolakDitangguhkan) {
                            $ditolakDitangguhkan->where('status', 'ditolak')
                                ->where(function ($k) {
                                    $k->where('keterangan', 'like', '%Ditangguhkan%')
                                        ->orWhere('keterangan', 'like', '%ditangguhkan%');
                                });
                        });
                });
            } elseif ($stVal === 'ditolak') {
                // Hanya ambil yang ditolak murni (tidak mengandung kata tangguh)
                $query->where('status', 'ditolak')
                    ->where('keterangan', 'not like', '%Ditangguhkan%')
                    ->where('keterangan', 'not like', '%ditangguhkan%');
            } else {
                $query->where('status', $stVal);
            }
        }

        // Fitur pencarian nama / NIP pegawai
        if ($request->filled('search')) {
            $search = $request->search;
            $query->whereHas('pegawai', function ($q) use ($search) {
                $q->where('nama', 'like', '%' . $search . '%')
                    ->orWhere('nip', 'like', '%' . $search . '%');
            });
        }

        // Fitur filter jenis cuti yang aman dari string "Semua Jenis Cuti"
        if ($request->filled('jenis_cuti') && $request->jenis_cuti !== '' && $request->jenis_cuti !== 'Semua Jenis Cuti') {
            $query->where('jenis_cuti', $request->jenis_cuti);
        }

        // Pengurutan data terbaru di paling atas
        $antrean = $query->orderBy('created_at', 'desc')
            ->paginate(10)
            ->withQueryString();

        // Hitung sisa cuti dinamis (sama persis logika Dashboard) untuk setiap item
        $tahun = date('Y');
        $antrean->getCollection()->transform(function ($item) use ($tahun) {
            $saldo = $item->pegawai->saldoCutiTahunIni ?? null;

            $kuotaTahunan  = $saldo->kuota_tahunan ?? 0;
            $sisaTahunLalu = $saldo->sisa_cuti_tahun_lalu ?? 0;

            $cutiTerpakai = PengajuanCuti::where('pegawai_id', $item->pegawai_id)
                ->where('jenis_cuti', 'Cuti Tahunan')
                ->where('status', 'disetujui')
                ->whereYear('tanggal_mulai', $tahun)
                ->sum('jumlah_hari');

            $totalTersedia = ($kuotaTahunan + $sisaTahunLalu) - $cutiTerpakai;

            // Tambahkan property baru ke object pegawai agar bisa dibaca di Vue
            $item->pegawai->sisa_cuti_tersedia = $totalTersedia;
            $item->pegawai->kuota_tahunan      = $kuotaTahunan;
            $item->pegawai->sisa_tahun_lalu    = $sisaTahunLalu;
            $item->pegawai->cuti_terpakai      = $cutiTerpakai;

            return $item;
        });

        // Inertia::render diarahkan ke 'Atasan/MonitoringCuti' — pastikan file
        // resources/js/Pages/Atasan/MonitoringCuti.vue ada di project Boss
        // (hasil salinan dari AntreanApproval.vue yang sudah diperbaiki
        // approvalTimeline-nya di percakapan sebelumnya).
        return Inertia::render('Atasan/MonitoringCuti', [
            'antrean' => $antrean,
            // 'status' ditambahkan agar filter dropdown Vue tetap terisi setelah reload/paginate
            'filters' => $request->only(['search', 'jenis_cuti', 'status']),
        ]);
    }

    private function isCutiTahunan(?string $jenisCuti): bool
    {
        if ($jenisCuti === null || $jenisCuti === '') {
            return true;
        }

        return strtolower(trim((string) $jenisCuti)) === 'cuti tahunan';
    }

    // 2. Memproses Persetujuan atau Penolakan
    public function process(Request $request, int $id)
    {
        $request->validate([
            'action' => ['required', 'in:approve,reject'],
        ]);

        $pengajuan = PengajuanCuti::with('pegawai')->findOrFail($id);
        $user = Auth::user();
        $targetUserId = $pengajuan->pegawai_id ?? $pengajuan->user_id;
        $statusSaatIni = $pengajuan->status;
        $roleYangDibutuhkan = [
            'menunggu_l1' => 2,
            'menunggu_l2' => 3,
            'menunggu_l3' => 4,
            'menunggu_l4' => 6,
        ][$statusSaatIni] ?? null;

        if ($roleYangDibutuhkan === null || $user->role_id !== $roleYangDibutuhkan) {
            abort(403, 'Anda tidak berwenang memproses pengajuan pada tahap ini.');
        }

        if ($user->role_id === 2 && $pengajuan->pegawai->departemen !== $user->departemen) {
            abort(403, 'Pengajuan berada di luar departemen Anda.');
        }

        // $levelApproval = angka level (1-4) tempat pengajuan SEDANG berada
        // sebelum diproses. Nilai ini didapat dari status 'menunggu_lX' yang
        // SUDAH divalidasi lewat pengecekan $roleYangDibutuhkan di atas, jadi
        // sudah pasti sinkron dengan role atasan yang sedang login — dipakai
        // sebagai SATU-SATUNYA sumber kebenaran level, baik untuk ApprovalLog
        // maupun kolom level_saat_ini di database.
        $levelApproval = [
            'menunggu_l1' => 1,
            'menunggu_l2' => 2,
            'menunggu_l3' => 3,
            'menunggu_l4' => 4,
        ][$statusSaatIni];

        // --- JIKA DITOLAK ---
        if ($request->action === 'reject') {
            // PERBAIKAN: alasan penolakan sekarang dibaca dari field 'alasan'
            // (bukan lagi 'catatan'), sinkron dengan payload yang dikirim
            // MonitoringCuti.vue -> processApproval() saat Atasan mengisi
            // prompt() alasan penolakan. Default teks juga diganti jadi
            // 'Tidak disetujui' sesuai kesepakatan wording terbaru.
            $alasanPenolakan = $request->input('alasan', 'Tidak disetujui');
            $namaApprover = $user->nama ?? 'Atasan'; // Mengambil nama atasan yang sedang login

            // Ambil alasan murni pegawai (bagian sebelum tanda '|')
            $alasanUtama = trim(explode('|', $pengajuan->keterangan)[0]);
            if (empty($alasanUtama) || $alasanUtama === '-') {
                $alasanUtama = 'Tidak ada alasan awal';
            }

            // PERBAIKAN SINKRONISASI WORDING: kata "Ditangguhkan" diganti jadi
            // "Ditolak" supaya teks keterangan sinkron dengan kolom status
            // yang memang di-set 'ditolak' di bawah. Sebelumnya teks ini
            // memakai kata "Ditangguhkan" walau status database-nya 'ditolak',
            // yang membuat badge di layar Atasan (yang membaca keyword dari
            // keterangan) salah menampilkan "DITANGGUHKAN" abu-abu padahal
            // seharusnya "DITOLAK" merah.
            $keteranganBaru = "{$alasanUtama} (Ditolak oleh {$namaApprover}: {$alasanPenolakan} — Pengajuan Cuti Ulang)";

            // ================= SUMBER KEBENARAN LEVEL PENOLAK =================
            // level_saat_ini diisi eksplisit dengan $levelApproval (di-cast
            // (int) untuk jaga-jaga tipe data) sebagai sumber kebenaran satu-
            // satunya yang dibaca frontend (MonitoringCuti.vue ->
            // approvalTimeline) untuk menentukan level mana yang menolak.
            // Ini menggantikan cara lama yang menebak level dari teks
            // keterangan, yang rawan meleset kalau format kalimat berubah.
            $pengajuan->update([
                'status' => 'ditolak',
                'keterangan' => $keteranganBaru,
                'level_saat_ini' => (int) $levelApproval,
            ]);
            // ================= END PERBAIKAN =================

            ApprovalLog::create([
                'pengajuan_id' => $pengajuan->id,
                'approver_id' => $user->id,
                'level_approval' => $levelApproval,
                'keputusan' => 'tolak',
                'catatan' => $alasanPenolakan,
                'tanggal_keputusan' => now(),
            ]);

            // Kirim notifikasi penolakan ke pegawai
            Notifikasi::create([
                'pegawai_id' => $targetUserId,
                'judul'      => 'Pengajuan Cuti Ditolak',
                'pesan'      => 'Pengajuan Anda ditolak oleh ' . $namaApprover . '. Alasan: ' . $alasanPenolakan,
                'is_read'    => false,
            ]);

            return back()->with('success', 'Pengajuan cuti berhasil ditolak.');
        }

        // --- JIKA DISETUJUI (LOGIKA ALUR BARU KEMENTAN) ---
        $statusPesan = '';

        // TAHAP 1: L1 MENGAPPROVE (Otomatis lompat L2, langsung ke L3)
        if ($statusSaatIni === 'menunggu_l1' && $user->role_id === 2) {
            // Hapus notifikasi lama milik L1 agar tidak menumpuk
            Notifikasi::where('pegawai_id', $user->id)->where('judul', 'Pengajuan Cuti Baru')->delete();

            // ALUR STAF: Langsung melompat ke L3 (Kasubag TU)
            $pengajuan->update(['status' => 'menunggu_l3', 'level_saat_ini' => 3, 'atasan_l1_id' => $user->id]);

            // Kirim Notifikasi ke L3 (Role 4)
            $atasanL3 = Pegawai::where('role_id', 4)->where('departemen', $pengajuan->pegawai->departemen)->first()
                ?? Pegawai::where('role_id', 4)->first();

            if ($atasanL3) {
                Notifikasi::create([
                    'pegawai_id' => $atasanL3->id,
                    'judul'      => 'Pengajuan Cuti Baru',
                    'pesan'      => 'Ada pengajuan cuti dari ' . $pengajuan->pegawai->nama . ' yang butuh persetujuan Anda.',
                    'tautan'     => route('atasan.approval'),
                    'is_read'    => false,
                ]);
            }
            $statusPesan = 'Disetujui Ketua Tim Kerja (L1) - Menunggu Kasubag TU (L3)';
        }

        // TAHAP 2: L2 MENGAPPROVE (Hanya terjadi jika L1 yang mengajukan cuti)
        elseif ($statusSaatIni === 'menunggu_l2' && $user->role_id === 3) {
            // Hapus notifikasi lama milik L2
            Notifikasi::where('pegawai_id', $user->id)->where('judul', 'Pengajuan Cuti Baru')->delete();

            // L2 Setuju -> Lanjut ke L3
            $pengajuan->update(['status' => 'menunggu_l3', 'level_saat_ini' => 3]);

            // Notifikasi ke L3 (Role 4)
            $atasanL3 = Pegawai::where('role_id', 4)->where('departemen', $pengajuan->pegawai->departemen)->first()
                ?? Pegawai::where('role_id', 4)->first();

            if ($atasanL3) {
                Notifikasi::create([
                    'pegawai_id' => $atasanL3->id,
                    'judul'      => 'Pengajuan Cuti Baru',
                    'pesan'      => 'Ada pengajuan cuti dari ' . $pengajuan->pegawai->nama . ' yang butuh persetujuan Anda.',
                    'tautan'     => route('atasan.approval'),
                    'is_read'    => false,
                ]);
            }
            $statusPesan = 'Disetujui Ketua Kelompok Substansi (L2) - Menunggu Kasubag TU (L3)';
        }

        // TAHAP 3: L3 MENGAPPROVE (Diteruskan ke L4)
        elseif ($statusSaatIni === 'menunggu_l3' && $user->role_id === 4) {
            // Hapus notifikasi lama milik L3
            Notifikasi::where('pegawai_id', $user->id)->where('judul', 'Pengajuan Cuti Baru')->delete();

            // L3 Setuju -> Lanjut ke L4
            $pengajuan->update(['status' => 'menunggu_l4', 'level_saat_ini' => 4, 'atasan_l3_id' => $user->id]);

            // Notifikasi ke L4 (KABIRO - ROLE 6)
            $atasanL4 = Pegawai::where('role_id', 6)->where('departemen', $pengajuan->pegawai->departemen)->first()
                ?? Pegawai::where('role_id', 6)->first();

            if ($atasanL4) {
                Notifikasi::create([
                    'pegawai_id' => $atasanL4->id,
                    'judul'      => 'Pengajuan Cuti Baru',
                    'pesan'      => 'Ada pengajuan cuti dari ' . $pengajuan->pegawai->nama . ' yang butuh persetujuan Anda.',
                    'tautan'     => route('atasan.approval'),
                    'is_read'    => false,
                ]);
            }
            $statusPesan = 'Disetujui Kasubag TU (L3) - Menunggu Kepala Biro Perencanaan (L4)';
        }

        // TAHAP 4: L4 MENGAPPROVE (FINAL)
        elseif ($statusSaatIni === 'menunggu_l4' && $user->role_id === 6) { // KABIRO = ROLE 6
            // Hapus notifikasi lama milik L4
            Notifikasi::where('pegawai_id', $user->id)->where('judul', 'Pengajuan Cuti Baru')->delete();

            if ($this->isCutiTahunan($pengajuan->jenis_cuti)) {
                $saldo = SaldoCuti::where('pegawai_id', $targetUserId)
                    ->where('tahun', date('Y', strtotime($pengajuan->tanggal_mulai)))
                    ->first();

                if (!$saldo || $saldo->sisa < $pengajuan->jumlah_hari) {
                    return back()->with('error', 'Saldo Cuti Tahunan tidak mencukupi untuk menyetujui pengajuan ini.');
                }
            }

            // L4 Setuju -> Status FINAL (Disetujui)
            $pengajuan->update([
                'status' => 'disetujui',
                'level_saat_ini' => 6,
                'atasan_l4_id' => $user->id,
            ]);

            // Potong saldo HANYA untuk jenis cuti tahunan (Dieksekusi di akhir/final)
            if ($this->isCutiTahunan($pengajuan->jenis_cuti)) {
                $saldo->decrement('sisa', $pengajuan->jumlah_hari);
            }

            $statusPesan = 'Disetujui Kepala Biro Perencanaan (L4)';
        }

        // Kirim Notifikasi Update Status ke Pegawai Pemohon
        if (!empty($statusPesan)) {
            ApprovalLog::create([
                'pengajuan_id' => $pengajuan->id,
                'approver_id' => $user->id,
                'level_approval' => $levelApproval,
                'keputusan' => 'setuju',
                'catatan' => null,
                'tanggal_keputusan' => now(),
            ]);

            Notifikasi::create([
                'pegawai_id' => $targetUserId,
                'judul'      => 'Status Cuti Diperbarui',
                'pesan'      => $statusPesan,
                'is_read'    => false,
            ]);
        }

        return back()->with('success', 'Pengajuan cuti berhasil diproses dan diteruskan.');
    }

    public function approve(Request $request, int $id)
    {
        $request->merge(['action' => 'approve']);
        return $this->process($request, $id);
    }

    public function reject(Request $request, int $id)
    {
        $request->merge(['action' => 'reject']);
        return $this->process($request, $id);
    }

    // 3. Menampilkan Riwayat Approval
    public function history(Request $request)
    {
        $user = Auth::user();
        // PERBAIKAN: Menambahkan relasi 'saldoCutiTahunIni' pada riwayat
        $query = PengajuanCuti::with(['pegawai.saldoCutiTahunIni']);

        // Logika filter riwayat: Tampilkan data yang SUDAH melewati tahap/level atasan tersebut
        if ($user->role_id === 2) {
            // Atasan L1: Menampilkan cuti di departemennya yang statusnya sudah bukan 'menunggu_l1'
            $query->whereHas('pegawai', function ($q) use ($user) {
                $q->where('departemen', $user->departemen);
            })->whereNotIn('status', ['menunggu_l1']);
        } elseif ($user->role_id === 3) {
            // L2: Menampilkan cuti yang sudah diproses L2
            $query->whereNotIn('status', ['menunggu_l1', 'menunggu_l2']);
        } elseif ($user->role_id === 4) {
            // L3: Menampilkan cuti yang sudah diproses L3
            $query->whereNotIn('status', ['menunggu_l1', 'menunggu_l2', 'menunggu_l3']);
        } elseif ($user->role_id === 6) { // L4 (ROLE 6)
            // L4: Hanya menampilkan cuti yang sudah final
            $query->whereIn('status', ['disetujui', 'ditolak', 'dibatalkan_reguler', 'dibatalkan_ditangguhkan']);
        } else {
            $query->where('id', 0);
        }

        // Fitur pencarian nama pegawai
        if ($request->filled('search')) {
            $query->whereHas('pegawai', function ($q) use ($request) {
                $q->where('nama', 'like', '%' . $request->search . '%');
            });
        }

        $riwayat = $query->orderBy('updated_at', 'desc')->paginate(10)->withQueryString();

        return Inertia::render('Atasan/RiwayatApproval', [
            'riwayat' => $riwayat,
            'filters' => $request->only(['search'])
        ]);
    }
}
