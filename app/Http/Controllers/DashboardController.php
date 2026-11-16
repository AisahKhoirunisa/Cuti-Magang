<?php

namespace App\Http\Controllers;

use App\Models\CutiDitangguhkan;
use App\Models\Pegawai;
use App\Models\PengajuanCuti;
use App\Models\SaldoCuti;
use App\Models\HariLibur;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Carbon\Carbon;

// ================= PERBAIKAN (L4 / KEPALA BIRO DISETUJUI OTOMATIS) =================
// Semua query yang memuat relasi 'approvalLogs' memakai
// 'approvalLogs.approver:id,nama,jabatan' (eager load terbatas). Tanpa ini,
// nama pejabat pada modal Detail Cuti (Dashboard.vue) bisa tampil "-" untuk
// log persetujuan otomatis Kepala Biro. Kolom dibatasi supaya remember_token
// / hash password tidak bocor di response Inertia.
// ================= END PERBAIKAN =================
//
// ================= PERBAIKAN (TOTAL ANGGOTA TIM: PENSIUN & ANGGOTA TAMBAHAN) =================
// Query "Anggota Tim" (yang menghasilkan 'anggotaTim' dan stats
// 'total_anggota_tim') sekarang:
//   1. Membuang pegawai berstatus Pensiun (lihat KOLOM_STATUS_PEGAWAI &
//      STATUS_PENSIUN). Pengecekan aman untuk NULL: pegawai yang kolom
//      statusnya kosong tetap dihitung.
//   2. Menambahkan anggota tambahan lewat nama (ANGGOTA_TAMBAHAN_NAMA)
//      di luar aturan wilayah. orWhere dibungkus satu grup sehingga TIDAK
//      merusak filter lain (pensiun, bukan diri sendiri). Anggota tambahan
//      tidak ditambahkan saat filter Divisi sedang aktif.
//   3. 'total_anggota_tim' dihitung dari hasil query yang sama dengan
//      daftar 'anggotaTim', jadi angkanya pasti cocok dengan daftar.
// ================= END PERBAIKAN TOTAL ANGGOTA TIM =================
//
// ================= PERBAIKAN (TGH / CUTI DITANGGUHKAN MENGURANGI SALDO) =================
// Halaman Rekap Kuota (admin) mengurangi Sisa 2026 dan Saldo Akhir dengan
// Tgh (cuti ditangguhkan) tahun berjalan, sedangkan dashboard sebelumnya
// tidak, sehingga angkanya selisih (mis. Sisa 10 vs 8, Total 16 vs 14).
// hitungSaldoPegawai() sekarang ikut mengurangi Tgh tahun berjalan lewat
// hitungTangguh(), dan mengirim 'cuti_ditangguhkan' ke frontend.
// ================= END PERBAIKAN TGH =================
//
// ================= PERBAIKAN BARU (CUTI DITANGGUHKAN DIKEMBALIKAN TAHUN DEPAN) =================
// Aturan:
//   - Tgh tahun berjalan tetap mengurangi Sisa Hak tahun itu (status sudah terpakai).
//   - Tgh tahun LALU DIKEMBALIKAN ke Total Tersedia tahun ini ('cuti_dikembalikan').
//   - Angka dikembalikan dihitung LANGSUNG dari tabel cuti_ditangguhkans setiap
//     dashboard dibuka (Cara A), jadi penangguhan baru setelah Generate Saldo
//     ikut terhitung tanpa generate ulang.
//   - Tidak ada batas 24 hari: hak + bawaan + dikembalikan tidak dipotong.
//   - 'akan_dikembalikan_tahun_depan' = Tgh tahun berjalan (informasi saja).
// CATATAN: batas 24 hari (kalau masih ada) berada di SaldoCuti::hitungBawaan().
// ================= END PERBAIKAN DIKEMBALIKAN =================
//
// ================= PERBAIKAN (GRAFIK CUTI LINTAS TAHUN) =================
// Sebelumnya data chart & donut memakai whereYear('tanggal_mulai', $tahunChart)
// dan menaruh SELURUH jumlah_hari di bulan tanggal_mulai. Akibatnya cuti
// lintas tahun (mis. 31 Des - 1 Jan) terbaca utuh (2 hari) di tahun awal dan
// tidak muncul sama sekali di tahun berikutnya.
// Sekarang:
//   1. Query chart mengambil cuti yang BERIRISAN dengan tahun terpilih.
//   2. potongCutiKeTahun() memotong cuti ke bagian yang jatuh di tahun
//      terpilih (tanggal_mulai, tanggal_selesai, jumlah_hari pada SALINAN
//      data chart saja, database tidak berubah).
//   3. Cuti yang tidak melewati batas tahun tidak diubah.
// ================= END PERBAIKAN LINTAS TAHUN =================
//
// ================= PERBAIKAN (KPI, KALENDER TIM & ANGGOTA AKTIF PER TAHUN) =================
//   1. 'cuti_tim_bulan_ini' menghitung cuti disetujui yang BERIRISAN dengan
//      bulan berjalan (bukan hanya yang tanggal_mulai-nya di bulan itu).
//   2. Cuti disetujui untuk kalender "Ketersediaan Tim" ($allCutiDisetujui)
//      dan data pribadi ($cutiPribadiDisetujui) memakai filter BERIRISAN
//      dengan tahun berjalan, sehingga cuti lintas tahun tetap tampil di
//      tanggalnya (mis. 4 Jan padahal mulai 31 Des).
//   3. 'Anggota Tim' hanya pegawai AKTIF (status_aktif NULL / 'Aktif') dan
//      yang sudah terdaftar pada/ sebelum akhir tahun berjalan
//      (KOLOM_TANGGAL_MASUK). Pegawai baru di tahun ini otomatis menambah
//      total karena daftar diambil langsung dari database.
//   4. 'Perlu persetujuan' (total_antrean) TIDAK diubah: pengajuan boleh
//      dibuat setelah tanggal cutinya, jadi antrean tidak difilter tanggal.
// ================= END PERBAIKAN KPI & ANGGOTA AKTIF =================
//
// ================= PERBAIKAN (ATASAN IKUT DI DAFTAR & ANGKA KONSISTEN) =================
//   1. Akun atasan sendiri (role 2, 3, 4, 6) IKUT di daftar 'anggotaTim'
//      (sebelumnya selalu dibuang lewat id != user->id), sehingga namanya
//      bisa dicari di popup Daftar Anggota Tim. Admin HR (role 5) tetap
//      tidak dihitung seperti sebelumnya.
//   2. 'cuti_tim_bulan_ini' dan 'timCutiHariIni' hanya menghitung pegawai
//      yang ADA di daftar 'anggotaTim' (whereIn id), jadi angka di kartu
//      selalu sama dengan isi popup: 3 orang cuti = 3 nama di popup.
//   3. 'cutiDisetujuiData' ($allCutiDisetujui) tidak lagi membuang cuti
//      atasan sendiri.
//   4. Prop baru 'cutiDisetujuiBulanIni': daftar cuti disetujui yang
//      BERIRISAN dengan bulan berjalan, untuk popup "Daftar Cuti Disetujui
//      (Bulan Ini)". Dashboard.vue perlu memakai prop ini (bukan memfilter
//      bulan tanggal_mulai di sisi frontend).
// ================= END PERBAIKAN ATASAN & ANGKA KONSISTEN =================
//
// ================= PERBAIKAN (L3 IKUT DI TIM L1 & L2) =================
//   1. Untuk L1 (role 2) dan L2 (role 3), pejabat L3 (role 4, Kasubag TU)
//      IKUT di daftar 'anggotaTim' (ROLE_L3). Aturan ini berada di grup
//      yang sama dengan anggota tambahan, jadi filter AKTIF & tanggal masuk
//      tetap berlaku.
//   2. Karena KPI ('cuti_tim_bulan_ini', 'timCutiHariIni') dihitung dari
//      daftar anggota, cuti L3 otomatis ikut terhitung & tampil di popup.
//   3. Kalender "Ketersediaan Tim" ($allCutiDisetujui) sekarang memakai
//      daftar anggota yang sama (whereIn pegawai_id), sehingga cuti L3 juga
//      tertandai di kalender L1/L2 dan konsisten dengan popup.
// ================= END PERBAIKAN L3 IKUT DI TIM L1 & L2 =================
//
// ================= PERBAIKAN (DISETUJUI BULAN INI = YANG SAYA SETUJUI) =================
// 'cuti_tim_bulan_ini' dan 'cutiDisetujuiBulanIni' (popup "Daftar Cuti
// Disetujui (Bulan Ini)") untuk atasan role 2, 3, 4, 6 hanya memuat cuti
// yang disetujui oleh atasan yang login sendiri (approvalLogs: approver_id =
// user & keputusan = 'setuju'). Cuti atasan lain atau cuti diri sendiri yang
// disetujui orang lain tidak ikut. Admin HR (role 5) tetap melihat semua.
// Kartu "Anggota Tim Hadir" / "cuti hari ini" TIDAK berubah.
// ================= END PERBAIKAN DISETUJUI BULAN INI =================
class DashboardController extends Controller
{
    // Kolom & nilai yang menandai pegawai pensiun. Ubah KOLOM_STATUS_PEGAWAI
    // menjadi 'status_aktif' bila di tabel pegawais nilai Pensiun disimpan di
    // kolom itu.
    private const KOLOM_STATUS_PEGAWAI = 'status_aktif';
    private const STATUS_PENSIUN = 'Pensiun';

    // Nilai status yang dianggap AKTIF (NULL juga dianggap aktif).
    private const STATUS_AKTIF = 'Aktif';

    // Kolom tanggal pegawai mulai terdaftar/masuk. Ganti ke 'tanggal_masuk'
    // (atau nama kolom yang sesuai) jika tabel pegawais punya kolom itu.
    private const KOLOM_TANGGAL_MASUK = 'created_at';

    // role_id pejabat L3 (Kasubag TU). Ikut tampil di tim milik L1 & L2.
    private const ROLE_L3 = 4;

    // Pegawai yang harus selalu ikut terhitung sebagai anggota tim (dicocokkan
    // dengan LIKE pada kolom nama), walaupun data wilayahnya tidak cocok.
    // Contoh menambah Pak Agus: tambahkan nama lengkapnya, mis. 'AGUS ...'.
    private const ANGGOTA_TAMBAHAN_NAMA = ['SETA RUKMALASARI'];


    /**
     * Jumlah hari cuti ditangguhkan (Tgh) dengan tahun asal $tahun.
     * SUMBER SAMA dengan RekapKuotaDetailController::hitungKuota():
     * tabel cuti_ditangguhkans (SUM jumlah_hari per pegawai & tahun_asal),
     * sehingga koreksi manual Admin HR ikut terhitung. Tgh tahun berjalan
     * mengurangi Sisa & Total Tersedia; Tgh tahun sebelumnya dikembalikan
     * ke saldo tahun berikutnya (lihat hitungSaldoPegawai()).
     */
    private function hitungTangguh(int $pegawaiId, int $tahun): int
    {
        return (int) CutiDitangguhkan::where('pegawai_id', $pegawaiId)
            ->where('tahun_asal', $tahun)
            ->sum('jumlah_hari');
    }


    /**
     * SATU-SATUNYA tempat perhitungan saldo cuti untuk dashboard.
     * Rumus disamakan dengan RekapKuotaDetailController::hitungKuota()
     * (halaman admin):
     *   bawaan eligible = 12 jika sisa (tahun-2) & sisa (tahun-1) sama-sama 12,
     *                     selain itu min(6, sisa tahun-1)
     *   dikembalikan    = Tgh tahun-1 (cuti ditangguhkan tahun lalu)
     *   terpakai total  = terpakai (digunakan) + Tgh tahun ini
     *   sisa tahun ini  = kuota - terpakai total
     *   total tersedia  = kuota + bawaan eligible + dikembalikan - terpakai total
     * Dipakai oleh cabang karyawan, cabang atasan, dan attachSaldoInfoKePegawai().
     */
    private function hitungSaldoPegawai(int $pegawaiId, int $tahun, ?SaldoCuti $saldo = null): array
    {
        $saldo ??= SaldoCuti::where('pegawai_id', $pegawaiId)->where('tahun', $tahun)->first();
        $saldoTahunLalu = SaldoCuti::where('pegawai_id', $pegawaiId)->where('tahun', $tahun - 1)->first();


        $kuota            = (int) ($saldo->kuota_tahunan ?? 12);
        $sisaTahunLalu    = (int) ($saldo->carry_forward_normal ?? 0);
        $sisaDuaTahunLalu = (int) ($saldoTahunLalu->carry_forward_normal ?? 0);


        // Ikut koreksi_saldo Admin HR (NULL = ikut sistem).
        $terpakai = SaldoCuti::terpakaiEfektif($pegawaiId, $tahun, $saldo);


        // Tgh (cuti ditangguhkan) tahun berjalan: mengurangi Sisa & Total.
        $tangguh = $this->hitungTangguh($pegawaiId, $tahun);


        // Tgh tahun LALU: dikembalikan ke saldo tahun ini (dihitung langsung).
        $dikembalikan = $this->hitungTangguh($pegawaiId, $tahun - 1);


        $bawaanEligible = SaldoCuti::hitungBawaan(
            $sisaDuaTahunLalu,
            $sisaTahunLalu,
            $kuota
        );


        // Terpakai Total = digunakan (aktual, sudah termasuk koreksi Admin HR)
        // + ditangguhkan. Inilah angka yang dikurangkan dari Hak, sehingga
        // Sisa = Hak - Terpakai Total (rumus standar, tanpa pengurang kedua).
        $terpakaiTotal = $terpakai + $tangguh;

        $sisaKuotaTahunIni = max($kuota - $terpakaiTotal, 0);
        $totalTersedia     = max(($kuota + $bawaanEligible + $dikembalikan) - $terpakaiTotal, 0);


        return [
            'sisa_cuti'                     => $totalTersedia,
            'tahun_saldo'                   => $tahun,
            'kuota_tahunan'                 => $kuota,
            'sisa_kuota_tahun_ini'          => $sisaKuotaTahunIni,
            'carry_forward_normal'          => $sisaTahunLalu,
            'sisa_cuti_dua_tahun_lalu'      => $sisaDuaTahunLalu,
            'saldo_bawaan_eligible'         => $bawaanEligible,
            // TIDAK diubah artinya: hanya cuti yang benar-benar digunakan.
            // Dipakai fitur lain (koreksi Admin HR, selisih_koreksi, dsb).
            'cuti_terpakai'                 => $terpakai,
            // Alias eksplisit untuk tampilan "Rincian: X hari digunakan".
            'cuti_digunakan'                => $terpakai,
            // Digunakan + ditangguhkan: angka utama kartu "Cuti Terpakai".
            'cuti_terpakai_total'           => $terpakaiTotal,
            'cuti_ditangguhkan'             => $tangguh,
            // Masuk ke total tahun ini (berasal dari penangguhan tahun lalu).
            'cuti_dikembalikan'             => $dikembalikan,
            // Hanya informasi: akan dikembalikan ke saldo tahun depan.
            'akan_dikembalikan_tahun_depan' => $tangguh,
            'total_cuti_tersedia'           => $totalTersedia,
            'saldo_tahunan'                 => [
                ['tahun' => $tahun - 2, 'nilai' => $sisaDuaTahunLalu],
                ['tahun' => $tahun - 1, 'nilai' => $sisaTahunLalu],
                ['tahun' => $tahun,     'nilai' => $sisaKuotaTahunIni],
            ],
        ];
    }


    /**
     * Menempelkan info saldo cuti tahunan milik PEGAWAI PEMILIK PENGAJUAN
     * (bukan Auth::user()) ke setiap item collection, agar modal detail
     * menampilkan saldo yang benar.
     *
     * @param  iterable<int, mixed>  $collection
     */
    private function attachSaldoInfoKePegawai(iterable $collection, int $tahun): void
    {
        \App\Support\RincianSaldo::lengkapi($collection, $tahun);
    }


    /**
     * Menempelkan nama atasan L1 (Ketua Tim Kerja) & L2 (Ketua Kelompok
     * Substansi) milik PEGAWAI PEMILIK PENGAJUAN ke $item->pegawai.
     *
     * @param  iterable<int, mixed>  $collection
     */
    private function attachNamaApproverL1L2KePegawai(iterable $collection): void
    {
        static $cache = [];
        static $namaL3 = false;
        static $namaL4 = false;


        if ($namaL3 === false) {
            $namaL3 = $this->namaPejabatAktif(4) ?? 'Kasubag TU';
            $namaL4 = $this->namaPejabatAktif(6) ?? 'Kepala Biro Perencanaan';
        }


        foreach ($collection as $item) {
            $pegawai = $item->pegawai ?? null;
            if (!$pegawai) {
                continue;
            }


            if (!isset($cache[$pegawai->id])) {
                $cache[$pegawai->id] = [
                    'nama_l1' => optional($pegawai->ketua_tim_kerja)->nama,
                    'nama_l2' => optional($pegawai->ketua_kelompok)->nama,
                ];
            }


            $pegawai->nama_l1 = $cache[$pegawai->id]['nama_l1'];
            $pegawai->nama_l2 = $cache[$pegawai->id]['nama_l2'];
            $pegawai->nama_l3 = $namaL3;
            $pegawai->nama_l4 = $namaL4;
        }
    }


    private function namaPejabatAktif(int $roleId): ?string
    {
        return Pegawai::where('role_id', $roleId)
            ->where(fn($q) => $q->where('status_aktif', 'Aktif')->orWhereNull('status_aktif'))
            ->orderBy('id')
            ->value('nama');
    }


    /**
     * Query "Anggota Tim" untuk dashboard atasan/admin.
     *   - Aturan wilayah (role, tim_kerja/kelompok_substansi, filter Divisi)
     *     dibungkus dalam SATU grup, lalu anggota tambahan (nama), akun
     *     atasan sendiri (role 2, 3, 4, 6) dan pejabat L3 untuk L1 & L2
     *     ditambahkan dengan orWhere di dalam grup yang sama. Admin HR
     *     (role 5) tidak ikut dihitung.
     *   - Di luar grup (berlaku untuk SEMUA baris, termasuk anggota tambahan,
     *     akun sendiri dan L3):
     *       * AKTIF (status NULL atau 'Aktif'; Pensiun & status lain dibuang),
     *       * sudah terdaftar pada/ sebelum akhir $tahun (pegawai baru di
     *         tahun itu ikut bertambah, pegawai yang baru masuk tahun
     *         berikutnya belum dihitung).
     */
    private function queryAnggotaTim(
        Pegawai $user,
        ?string $wilayahField,
        ?string $wilayahValue,
        ?string $kelompokSubstansiFilter,
        int $tahun
    ) {
        $kolomStatus  = self::KOLOM_STATUS_PEGAWAI;
        $kolomMasuk   = self::KOLOM_TANGGAL_MASUK;
        $akhirTahun   = Carbon::create($tahun, 12, 31)->endOfDay();


        return Pegawai::query()
            ->where(function ($q) use ($user, $wilayahField, $wilayahValue, $kelompokSubstansiFilter) {
                $q->where(function ($scope) use ($user, $wilayahField, $wilayahValue, $kelompokSubstansiFilter) {
                    if (in_array($user->role_id, [3, 4, 5, 6], true)) {
                        $scope->where('role_id', '!=', 5);
                    } else {
                        $scope->where('role_id', 1);
                    }


                    if ($wilayahField) {
                        $scope->where($wilayahField, $wilayahValue);
                    }
                    if ($kelompokSubstansiFilter) {
                        $scope->where('kelompok_substansi', $kelompokSubstansiFilter);
                    }
                });


                // Akun atasan sendiri ikut di daftar (agar cutinya sendiri
                // bisa dilihat & dicari di popup). Tidak dipakai saat filter
                // Divisi aktif (mengikuti aturan wilayah biasa).
                if (!$kelompokSubstansiFilter && in_array($user->role_id, [2, 3, 4, 6], true)) {
                    $q->orWhere('id', $user->id);
                }


                // Pejabat L3 (role 4) ikut di tim milik L1 (role 2) & L2
                // (role 3), tanpa terikat aturan wilayah.
                if (!$kelompokSubstansiFilter && in_array($user->role_id, [2, 3], true)) {
                    $q->orWhere('role_id', self::ROLE_L3);
                }


                // Anggota tambahan (mis. data wilayahnya tidak cocok dengan
                // aturan di atas). Tidak dipakai saat filter Divisi aktif.
                if (!$kelompokSubstansiFilter) {
                    foreach (self::ANGGOTA_TAMBAHAN_NAMA as $nama) {
                        $q->orWhere('nama', 'LIKE', '%' . $nama . '%');
                    }
                }
            })
            // Hanya pegawai AKTIF (NULL dianggap aktif).
            ->where(function ($q) use ($kolomStatus) {
                $q->whereNull($kolomStatus)
                    ->orWhere($kolomStatus, self::STATUS_AKTIF);
            })
            // Sudah terdaftar pada/ sebelum akhir tahun berjalan.
            ->where(function ($q) use ($kolomMasuk, $akhirTahun) {
                $q->whereNull($kolomMasuk)
                    ->orWhere($kolomMasuk, '<=', $akhirTahun);
            });
    }


    /**
     * Memotong cuti yang melewati batas tahun ke bagian yang jatuh di $tahun.
     *
     *   - Cuti yang seluruhnya berada di $tahun dikembalikan APA ADANYA.
     *   - Cuti lintas tahun: dibuat SALINAN dengan tanggal_mulai /
     *     tanggal_selesai dipotong ke 1 Jan - 31 Des $tahun, dan jumlah_hari
     *     dihitung ulang hanya untuk bagian di tahun itu. Salinan ini hanya
     *     untuk chart/donut; tidak disimpan ke database.
     *   - Hari yang dihitung: hari kerja (bukan Sabtu/Minggu, bukan hari
     *     libur di tabel HariLibur). Jika cuti aslinya dihitung per hari
     *     kalender (jumlah_hari == selisih hari kalender, mis. cuti
     *     melahirkan), maka semua hari kalender dihitung.
     *   - Bagian yang menghasilkan 0 hari dibuang dari chart.
     *
     * @param  iterable<int, PengajuanCuti>  $daftarCuti
     */
    private function potongCutiKeTahun($daftarCuti, int $tahun)
    {
        $awalTahun  = Carbon::create($tahun, 1, 1)->startOfDay();
        $akhirTahun = Carbon::create($tahun, 12, 31)->startOfDay();


        // Hari libur tahun sebelumnya s.d. sesudahnya, supaya hitungan
        // seluruh rentang cuti (untuk deteksi mode kalender) tetap wajar.
        $libur = HariLibur::whereBetween('tanggal', [
            Carbon::create($tahun - 1, 1, 1)->toDateString(),
            Carbon::create($tahun + 1, 12, 31)->toDateString(),
        ])
            ->pluck('tanggal')
            ->map(fn($t) => Carbon::parse($t)->toDateString())
            ->flip()
            ->all();


        $hitung = function (Carbon $dari, Carbon $sampai, bool $kalender) use ($libur): int {
            $n = 0;
            for ($d = $dari->copy(); $d->lte($sampai); $d->addDay()) {
                if ($kalender || (!$d->isWeekend() && !isset($libur[$d->toDateString()]))) {
                    $n++;
                }
            }
            return $n;
        };


        return collect($daftarCuti)
            ->map(function ($cuti) use ($awalTahun, $akhirTahun, $hitung) {
                $mulai   = Carbon::parse($cuti->tanggal_mulai)->startOfDay();
                $selesai = Carbon::parse($cuti->tanggal_selesai)->startOfDay();


                // Tidak melewati batas tahun: biarkan seperti semula.
                if ($mulai->gte($awalTahun) && $selesai->lte($akhirTahun)) {
                    return $cuti;
                }


                // Deteksi cuti yang dihitung per hari kalender.
                $totalKalender = (int) abs($mulai->diffInDays($selesai)) + 1;
                $totalKerja    = $hitung($mulai, $selesai, false);
                $modeKalender  = ((int) $cuti->jumlah_hari === $totalKalender)
                    && ($totalKalender !== $totalKerja);


                $mulaiPotong   = $mulai->lt($awalTahun) ? $awalTahun->copy() : $mulai->copy();
                $selesaiPotong = $selesai->gt($akhirTahun) ? $akhirTahun->copy() : $selesai->copy();


                if ($mulaiPotong->gt($selesaiPotong)) {
                    return null;
                }


                $hari = $hitung($mulaiPotong, $selesaiPotong, $modeKalender);
                if ($hari <= 0) {
                    return null;
                }


                // Salinan khusus chart (tidak menyentuh model asli / database).
                $salinan = clone $cuti;
                $salinan->setAttribute('tanggal_mulai', $mulaiPotong->toDateString());
                $salinan->setAttribute('tanggal_selesai', $selesaiPotong->toDateString());
                $salinan->setAttribute('jumlah_hari', $hari);


                return $salinan;
            })
            ->filter()
            ->values();
    }


    public function index(Request $request)
    {
        /** @var \App\Models\Pegawai $user */
        $user = Auth::user();
        $tahun = date('Y');


        // Rentang tahun & bulan berjalan untuk filter cuti yang BERIRISAN
        // (cuti lintas tahun/bulan tetap terbaca di tahun/bulan berikutnya).
        $awalTahunIni  = Carbon::create((int) $tahun, 1, 1)->toDateString();
        $akhirTahunIni = Carbon::create((int) $tahun, 12, 31)->toDateString();
        $awalBulanIni  = Carbon::create((int) $tahun, (int) date('n'), 1)->startOfMonth()->toDateString();
        $akhirBulanIni = Carbon::create((int) $tahun, (int) date('n'), 1)->endOfMonth()->toDateString();


        // Tahun khusus CHART & DONUT (dipilih lewat dropdown di dashboard).
        // $tahun di atas TIDAK diubah karena dipakai kartu saldo/kuota/statistik
        // yang memang harus selalu tahun berjalan.
        $tahunChart = (int) $request->query('tahun', $tahun);
        if ($tahunChart < (int) $tahun - 2 || $tahunChart > (int) $tahun + 1) {
            $tahunChart = (int) $tahun;
        }


        // Diinisialisasi di luar if/elseif supaya bisa dipakai query chart
        // di bagian bawah (untuk role 1 nilainya tetap null).
        $wilayahField = null;
        $wilayahValue = null;


        $stats                 = [];
        $recentCuti            = [];
        $recentCutiPribadi     = [];
        $anggotaTim            = [];
        $allCutiDisetujui      = [];
        $timCutiHariIni        = [];
        $cutiDisetujuiBulanIni = collect();
        $cutiPribadiDisetujui  = collect();
        $chartDataBackend      = array_fill(0, 12, 0);
        $chartDataPribadi      = array_fill(0, 12, 0);


        // Filter Divisi hanya untuk role 4, 5, 6 (L3, Admin HR, L4).
        // Role 1, 2, 3 sudah punya pembatasan wilayah otomatis.
        $kelompokSubstansiFilter = in_array($user->role_id, [1, 2, 3], true)
            ? null
            : $request->query('kelompok_substansi');


        $isAdminHR = $user->role_id === 5;


        $hariLiburs = HariLibur::where('tanggal', '>=', Carbon::today()->toDateString())
            ->orderBy('tanggal', 'asc')
            ->get();


        // Relasi atasan & approver: hanya kolom yang dibutuhkan UI
        // (cegah bocor remember_token / hash password di response Inertia).
        $withAtasanPribadi = [
            'atasanL1:id,nama',
            'atasanL2:id,nama',
            'atasanL3:id,nama',
            'atasanL4:id,nama',
            'approvalLogs.approver:id,nama,jabatan',
        ];
        $withAtasanTim = [
            'pegawai',
            'atasanL1:id,nama',
            'atasanL2:id,nama',
            'atasanL3:id,nama',
            'atasanL4:id,nama',
            'approvalLogs.approver:id,nama,jabatan',
        ];


        // =====================================================================
        // DASHBOARD KARYAWAN (role 1)
        // =====================================================================
        if ($user->role_id === 1) {
            $saldo = SaldoCuti::where('pegawai_id', $user->id)->where('tahun', $tahun)->first();


            $saldoStats   = $this->hitungSaldoPegawai($user->id, (int) $tahun, $saldo);
            $cutiTerpakai = $saldoStats['cuti_terpakai'];


            $cutiTerpakaiSistem = SaldoCuti::terpakaiMurni((int) $user->id, (int) $tahun);


            $adaKoreksi = $saldo
                && $saldo->koreksi_saldo !== null
                && (int) $saldo->koreksi_saldo !== $cutiTerpakaiSistem;


            $stats = array_merge($saldoStats, [
                'ada_koreksi'          => $adaKoreksi,
                'cuti_terpakai_sistem' => $cutiTerpakaiSistem,
                'selisih_koreksi'      => $cutiTerpakai - $cutiTerpakaiSistem,
                'total_pengajuan'      => PengajuanCuti::where('pegawai_id', $user->id)->count(),
                'menunggu'             => PengajuanCuti::where('pegawai_id', $user->id)->where('status', 'like', 'menunggu%')->count(),
                'disetujui'            => PengajuanCuti::where('pegawai_id', $user->id)->where('status', 'disetujui')->count(),
            ]);


            $recentCuti = PengajuanCuti::with($withAtasanPribadi)
                ->where('pegawai_id', $user->id)
                ->orderBy('created_at', 'desc')
                ->take(5)
                ->get();


            $recentCutiPribadi = $recentCuti;


            // Beririsan dengan tahun berjalan (cuti lintas tahun ikut terbaca).
            $allCutiDisetujui = PengajuanCuti::with($withAtasanPribadi)
                ->where('pegawai_id', $user->id)
                ->where('status', 'disetujui')
                ->whereDate('tanggal_mulai', '<=', $akhirTahunIni)
                ->whereDate('tanggal_selesai', '>=', $awalTahunIni)
                ->get();


            $cutiPribadiDisetujui = $allCutiDisetujui;


            // =====================================================================
            // DASHBOARD ATASAN & ADMIN (L1, L2, L3, L4, HR)
            // =====================================================================
        } elseif (in_array($user->role_id, [2, 3, 4, 5, 6])) {


            // Granularitas wilayah:
            //   L1 (role 2) → tim_kerja
            //   L2 (role 3) → kelompok_substansi
            //   L3/L4/HR    → tidak dibatasi
            $wilayahField = null;
            $wilayahValue = null;
            if ($user->role_id === 2) {
                $wilayahField = 'tim_kerja';
                $wilayahValue = $user->tim_kerja;
            } elseif ($user->role_id === 3) {
                $wilayahField = 'kelompok_substansi';
                $wilayahValue = $user->kelompok_substansi;
            }


            $targetStatus = [
                2 => 'menunggu_l1',
                3 => 'menunggu_l2',
                4 => 'menunggu_l3',
                6 => 'menunggu_l4',
            ][$user->role_id] ?? null;


            // --- Antrean ---
            // TIDAK difilter tanggal: pengajuan boleh dibuat setelah tanggal cuti.
            $antreanQuery = $targetStatus
                ? PengajuanCuti::where('status', $targetStatus)
                : PengajuanCuti::where('status', 'like', 'menunggu%');


            if ($wilayahField) {
                $antreanQuery->whereHas('pegawai', function ($q) use ($wilayahField, $wilayahValue) {
                    $q->where($wilayahField, $wilayahValue);
                });
            }
            if ($kelompokSubstansiFilter) {
                $antreanQuery->whereHas('pegawai', function ($q) use ($kelompokSubstansiFilter) {
                    $q->where('kelompok_substansi', $kelompokSubstansiFilter);
                });
            }


            // --- Anggota Tim ---
            // Lewat queryAnggotaTim(): hanya pegawai AKTIF yang sudah
            // terdaftar pada/ sebelum akhir tahun berjalan, ditambah anggota
            // tambahan (ANGGOTA_TAMBAHAN_NAMA), akun atasan sendiri
            // (role 2, 3, 4, 6) dan pejabat L3 untuk L1 & L2.
            $anggotaTim = $this->queryAnggotaTim(
                $user,
                $wilayahField,
                $wilayahValue,
                $kelompokSubstansiFilter,
                (int) $tahun
            )->get();


            // Total dihitung dari daftar yang sama, jadi pasti cocok.
            $totalAnggota = $anggotaTim->count();


            // ID anggota tim: dipakai agar angka KPI, kalender & daftar cuti
            // selalu sama dengan isi popup Daftar Anggota Tim.
            $idAnggota = $anggotaTim->pluck('id')->all();


            // --- Saldo Atasan (role 2,3,4,6) ---
            $saldoAtasan = null;
            if (in_array($user->role_id, [2, 3, 4, 6], true)) {
                $saldoAtasan = SaldoCuti::firstOrCreate(
                    ['pegawai_id' => $user->id, 'tahun' => $tahun],
                    ['kuota_tahunan' => 12, 'sisa' => 12]
                );
            }


            $saldoStats = $this->hitungSaldoPegawai($user->id, (int) $tahun, $saldoAtasan);


            // --- Cuti Disetujui BULAN INI (beririsan dengan bulan berjalan) ---
            // Sumber tunggal untuk KPI "Disetujui bulan ini" dan popup
            // "Daftar Cuti Disetujui (Bulan Ini)". Hanya pegawai yang ada di
            // daftar anggota tim, DAN (untuk atasan role 2, 3, 4, 6) hanya
            // cuti yang benar-benar DISETUJUI OLEH atasan yang sedang login
            // (ada approval log 'setuju' dengan approver_id = user). Cuti
            // atasan lain / cuti diri sendiri yang disetujui orang lain tidak
            // dihitung. Admin HR (role 5) tidak menyetujui, jadi tetap melihat
            // semua.
            $cutiDisetujuiBulanIni = PengajuanCuti::with($withAtasanTim)
                ->whereIn('pegawai_id', $idAnggota)
                ->when($user->role_id !== 5, function ($query) use ($user) {
                    $query->whereHas('approvalLogs', function ($q) use ($user) {
                        $q->where('approver_id', $user->id)
                            ->where('keputusan', 'setuju');
                    });
                })
                ->where('status', 'disetujui')
                ->whereDate('tanggal_mulai', '<=', $akhirBulanIni)
                ->whereDate('tanggal_selesai', '>=', $awalBulanIni)
                ->orderBy('tanggal_mulai')
                ->get();


            $stats = array_merge($saldoStats, [
                'total_antrean'      => $antreanQuery->count(),
                'cuti_tim_bulan_ini' => $cutiDisetujuiBulanIni->count(),
                'total_anggota_tim'  => $totalAnggota,
            ]);


            // --- Recent Cuti (tabel atasan) ---
            $recentCuti = PengajuanCuti::with($withAtasanTim)
                ->when($wilayahField, function ($query) use ($wilayahField, $wilayahValue) {
                    $query->whereHas('pegawai', function ($q) use ($wilayahField, $wilayahValue) {
                        $q->where($wilayahField, $wilayahValue);
                    });
                })
                ->when($kelompokSubstansiFilter, function ($query) use ($kelompokSubstansiFilter) {
                    $query->whereHas('pegawai', function ($q) use ($kelompokSubstansiFilter) {
                        $q->where('kelompok_substansi', $kelompokSubstansiFilter);
                    });
                })
                ->when($targetStatus, function ($query) use ($targetStatus) {
                    $query->orderByRaw("CASE WHEN status = '{$targetStatus}' THEN 1 ELSE 2 END");
                })
                ->orderBy('created_at', 'desc')
                ->take(5)
                ->get();


            // --- Cuti Disetujui TIM (tahun berjalan, BERIRISAN) ---
            // Dipakai kalender "Ketersediaan Tim". Cuti lintas tahun tetap
            // ikut sehingga tanggalnya di tahun ini tetap tertandai.
            // Memakai daftar anggota yang sama dengan popup (whereIn), jadi
            // cuti atasan sendiri dan L3 (untuk L1/L2) ikut tertandai.
            $allCutiDisetujui = PengajuanCuti::with($withAtasanTim)
                ->whereIn('pegawai_id', $idAnggota)
                ->where('status', 'disetujui')
                ->whereDate('tanggal_mulai', '<=', $akhirTahunIni)
                ->whereDate('tanggal_selesai', '>=', $awalTahunIni)
                ->get();


            // --- Tim cuti hari ini ---
            // Hanya pegawai yang ada di daftar anggota tim, sehingga
            // "X orang cuti hari ini" = X nama di popup.
            $timCutiHariIni = PengajuanCuti::with($withAtasanTim)
                ->whereIn('pegawai_id', $idAnggota)
                ->where('status', 'disetujui')
                ->whereDate('tanggal_mulai', '<=', Carbon::today())
                ->whereDate('tanggal_selesai', '>=', Carbon::today())
                ->get();


            // --- Data cuti Pribadi untuk Atasan (role 2,3,4,6) ---
            if (in_array($user->role_id, [2, 3, 4, 6], true)) {
                $cutiPribadiDisetujui = PengajuanCuti::with($withAtasanPribadi)
                    ->where('pegawai_id', $user->id)
                    ->where('status', 'disetujui')
                    ->whereDate('tanggal_mulai', '<=', $akhirTahunIni)
                    ->whereDate('tanggal_selesai', '>=', $awalTahunIni)
                    ->get();


                $recentCutiPribadi = PengajuanCuti::with($withAtasanPribadi)
                    ->where('pegawai_id', $user->id)
                    ->orderBy('created_at', 'desc')
                    ->take(5)
                    ->get();
            }
        }


        // =====================================================================
        // DATA CHART & DONUT — mengikuti tahun terpilih ($tahunChart)
        // Memakai cuti yang BERIRISAN dengan tahun terpilih (bukan hanya yang
        // tanggal_mulai-nya di tahun itu), lalu dipotong per tahun lewat
        // potongCutiKeTahun() supaya cuti lintas tahun terbagi dengan benar.
        // =====================================================================
        $awalTahunChart  = $tahunChart . '-01-01';
        $akhirTahunChart = $tahunChart . '-12-31';


        if ($user->role_id === 1) {
            $cutiChartPribadi = $this->potongCutiKeTahun(
                PengajuanCuti::where('pegawai_id', $user->id)
                    ->where('status', 'disetujui')
                    ->whereDate('tanggal_mulai', '<=', $akhirTahunChart)
                    ->whereDate('tanggal_selesai', '>=', $awalTahunChart)
                    ->get(),
                $tahunChart
            );
            $cutiChartTim = $cutiChartPribadi;
        } else {
            $cutiChartTim = $this->potongCutiKeTahun(
                PengajuanCuti::with('pegawai')
                    ->when($wilayahField, function ($query) use ($wilayahField, $wilayahValue, $user) {
                        $query->whereHas('pegawai', function ($q) use ($wilayahField, $wilayahValue, $user) {
                            $q->where($wilayahField, $wilayahValue)
                                ->where('id', '!=', $user->id);
                        });
                    })
                    ->when($kelompokSubstansiFilter, function ($query) use ($kelompokSubstansiFilter) {
                        $query->whereHas('pegawai', function ($q) use ($kelompokSubstansiFilter) {
                            $q->where('kelompok_substansi', $kelompokSubstansiFilter);
                        });
                    })
                    ->where('status', 'disetujui')
                    ->whereDate('tanggal_mulai', '<=', $akhirTahunChart)
                    ->whereDate('tanggal_selesai', '>=', $awalTahunChart)
                    ->get(),
                $tahunChart
            );


            $cutiChartPribadi = in_array($user->role_id, [2, 3, 4, 6], true)
                ? $this->potongCutiKeTahun(
                    PengajuanCuti::where('pegawai_id', $user->id)
                        ->where('status', 'disetujui')
                        ->whereDate('tanggal_mulai', '<=', $akhirTahunChart)
                        ->whereDate('tanggal_selesai', '>=', $awalTahunChart)
                        ->get(),
                    $tahunChart
                )
                : collect();
        }


        $chartDataBackend = array_fill(0, 12, 0);
        $chartDataPribadi = array_fill(0, 12, 0);


        foreach ($cutiChartTim as $cuti) {
            $i = (int) date('n', strtotime($cuti->tanggal_mulai)) - 1;
            if ($i >= 0 && $i <= 11) {
                $chartDataBackend[$i] += $cuti->jumlah_hari;
            }
        }
        foreach ($cutiChartPribadi as $cuti) {
            $i = (int) date('n', strtotime($cuti->tanggal_mulai)) - 1;
            if ($i >= 0 && $i <= 11) {
                $chartDataPribadi[$i] += $cuti->jumlah_hari;
            }
        }


        $listKelompokSubstansi = Pegawai::whereNotNull('kelompok_substansi')
            ->where('kelompok_substansi', '!=', '')
            ->distinct()
            ->orderBy('kelompok_substansi')
            ->pluck('kelompok_substansi');


        $this->attachSaldoInfoKePegawai($recentCuti, (int) $tahun);
        $this->attachSaldoInfoKePegawai($recentCutiPribadi, (int) $tahun);
        $this->attachSaldoInfoKePegawai($allCutiDisetujui, (int) $tahun);
        $this->attachSaldoInfoKePegawai($cutiPribadiDisetujui, (int) $tahun);
        $this->attachSaldoInfoKePegawai($timCutiHariIni, (int) $tahun);
        $this->attachSaldoInfoKePegawai($cutiDisetujuiBulanIni, (int) $tahun);


        foreach ([$recentCuti, $recentCutiPribadi, $allCutiDisetujui, $cutiPribadiDisetujui, $timCutiHariIni, $cutiDisetujuiBulanIni] as $daftar) {
            if (!$daftar instanceof \Illuminate\Database\Eloquent\Collection) {
                continue;
            }


            $daftar->loadMissing('cutiDitangguhkan');
            $daftar->each(function (PengajuanCuti $cuti) {
                $cuti->bisa_direvisi = $cuti->bisaDirevisi();
            });
        }


        $this->attachNamaApproverL1L2KePegawai($recentCuti);
        $this->attachNamaApproverL1L2KePegawai($recentCutiPribadi);
        $this->attachNamaApproverL1L2KePegawai($allCutiDisetujui);
        $this->attachNamaApproverL1L2KePegawai($cutiPribadiDisetujui);
        $this->attachNamaApproverL1L2KePegawai($timCutiHariIni);
        $this->attachNamaApproverL1L2KePegawai($cutiDisetujuiBulanIni);


        return Inertia::render('Dashboard', [
            'stats'                     => $stats,
            'recentCuti'                => $recentCuti,
            'cutiDisetujuiData'         => $allCutiDisetujui,
            'cutiDisetujuiBulanIni'     => $cutiDisetujuiBulanIni,
            'cutiDisetujuiPribadi'      => $cutiPribadiDisetujui,
            'cutiDisetujuiChart'        => $cutiChartTim,
            'cutiDisetujuiPribadiChart' => $cutiChartPribadi,
            'chartDataBackend'          => $chartDataBackend,
            'chartDataPribadi'          => $chartDataPribadi,
            'hariLiburs'                => $hariLiburs,
            'anggotaTim'                => $anggotaTim,
            'timCutiHariIni'            => $timCutiHariIni,
            'isAdminHR'                 => $isAdminHR,
            'recentCutiPribadi'         => $recentCutiPribadi,
            'listKelompokSubstansi'     => $listKelompokSubstansi,
            'tahun_saldo_tersedia'      => SaldoCuti::where('pegawai_id', $user->id)
                ->pluck('tahun')
                ->map(fn($t) => (int) $t)
                ->unique()
                ->values()
                ->all(),
            'filter'                    => [
                'kelompok_substansi' => $kelompokSubstansiFilter,
                'tahun'              => (string) $tahunChart,
            ],
        ]);
    }
}