<?php

namespace App\Http\Controllers;

use App\Models\Pegawai;
use App\Models\PengajuanCuti;
use App\Models\SaldoCuti;
use App\Models\HariLibur;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class AdminController extends Controller
{
    // Daftar key & label bulan yang dipakai bersama oleh buildLaporanData() dan exportExcel()
    private array $keyBulan = ['jan', 'feb', 'mar', 'apr', 'mei', 'jun', 'jul', 'agu', 'sep', 'okt', 'nov', 'des'];
    private array $labelBulan = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];

    // ================= URUTAN HIERARKI JABATAN =================
    // Mapping role_id -> urutan tampil (angka lebih kecil = tampil lebih atas).
    // Dipakai untuk Rekap Laporan supaya urutan pegawai mengikuti struktur
    // organisasi (jabatan tertinggi ke terendah), BUKAN hardcode nama satu-satu,
    // supaya otomatis menyesuaikan walau ada penambahan/perubahan data pegawai.
    //
    // Urutan hierarki (dari atas ke bawah):
    // role_id 6 -> Kepala Biro Perencanaan (L4 / Approval tertinggi)
    // role_id 4 -> Kasubag TU (L3)
    // role_id 3 -> Ketua Kelompok Substansi (L2)
    // role_id 2 -> Ketua Tim Kerja (L1)
    // role_id 1 -> Pegawai / Staf (paling bawah)
    // role_id lain yang tidak terdaftar di mapping ini akan otomatis
    // ditempatkan paling bawah (lihat fallback ?? 99 di bawah).
    private array $urutanJabatan = [
        6 => 1,
        4 => 2,
        3 => 3,
        2 => 4,
        1 => 5,
    ];
    // ================= END URUTAN HIERARKI JABATAN =================

    // ================= HELPER BERSAMA: BANGUN DATA REKAP =================
    // Logic pembuatan data rekap (ambil pegawai, urutkan sesuai hierarki jabatan,
    // hitung cuti tahunan disetujui per bulan) dipusatkan di sini, supaya
    // rekapLaporan() (tampilan web) dan exportExcel() (file Excel) SELALU
    // memakai data & aturan yang SAMA PERSIS.
    //
    // Aturan yang dipakai:
    // - Hanya menghitung "Cuti Tahunan" berstatus "disetujui" (jenis cuti lain
    //   seperti Melahirkan/Besar/Alasan Penting tidak memotong kuota tahunan
    //   sehingga tidak ikut direkap di sini).
    // - Urutan baris mengikuti hierarki jabatan (role_id via $urutanJabatan),
    //   dengan nama (abjad) sebagai tie-breaker di level yang sama.
    // - Filter "bulan" HANYA mempersempit kolom yang ditampilkan/di-export,
    //   BUKAN menyaring baris pegawai — semua pegawai tetap muncul (termasuk
    //   yang 0 hari cuti), sesuai konvensi laporan resmi instansi pemerintah
    //   yang perlu menunjukkan juga siapa yang TIDAK mengambil cuti.
    private function buildLaporanData(Request $request): array
    {
        $tahun = $request->input('tahun', date('Y'));
        $search = $request->input('search');
        $bulanFilter = $request->input('bulan');

        $query = Pegawai::where('role_id', '!=', 5);

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('nama', 'like', "%{$search}%")
                    ->orWhere('nip', 'like', "%{$search}%");
            });
        }

        $pegawais = $query->get();

        // Urutkan dulu berdasarkan nama (abjad) sebagai tie-breaker, baru
        // urutkan berdasarkan hierarki jabatan sebagai kunci utama. PHP sort
        // sudah stable sejak PHP 8, jadi urutan nama di dalam role_id yang
        // sama tetap terjaga.
        $pegawais = $pegawais
            ->sortBy('nama')
            ->sortBy(function ($pegawai) {
                return $this->urutanJabatan[$pegawai->role_id] ?? 99;
            })
            ->values();

        $laporan = $pegawais->map(function ($pegawai) use ($tahun) {
            $cutis = PengajuanCuti::where('pegawai_id', $pegawai->id)
                ->where('jenis_cuti', 'Cuti Tahunan')
                ->whereYear('tanggal_mulai', $tahun)
                ->where('status', 'disetujui')
                ->get();

            $rekapBulan = array_fill_keys($this->keyBulan, 0);

            foreach ($cutis as $cuti) {
                $bulanAngka = (int) date('n', strtotime($cuti->tanggal_mulai));
                $keyBulan = $this->keyBulan[$bulanAngka - 1];
                $rekapBulan[$keyBulan] += $cuti->jumlah_hari;
            }

            return [
                'id' => $pegawai->id,
                'nama' => $pegawai->nama,
                'nip' => $pegawai->nip,
                'jabatan' => $pegawai->jabatan,
                'departemen' => $pegawai->departemen,
                'role_id' => $pegawai->role_id,
                'cuti' => $rekapBulan,
                'total' => array_sum($rekapBulan),
            ];
        })->values();

        return [
            'laporan' => $laporan,
            'tahun' => (int) $tahun,
            'bulanFilter' => $bulanFilter ? (int) $bulanFilter : null,
        ];
    }
    // ================= END HELPER BERSAMA =================

    // 1. Menampilkan Daftar Pegawai
    //
    // ---> PERBAIKAN <---
    // Sebelumnya query ini hanya mengambil data pegawai tanpa relasi apapun,
    // sehingga modal Edit Pegawai tidak bisa menampilkan/mengisi field
    // "Jatah Cuti", "Sisa Cuti Tahun Ini", dan "Sisa Cuti Tahun Kemarin"
    // (data itu ada di tabel saldo_cutis, bukan di tabel pegawais).
    // Sekarang di-eager-load relasi saldoCuti, dibatasi hanya baris tahun
    // berjalan saja (bukan seluruh riwayat), supaya payload tetap ringan.
    public function kelolaPegawai()
    {
        $tahun = date('Y');

        $pegawai = Pegawai::with(['saldoCuti' => function ($query) use ($tahun) {
            $query->where('tahun', $tahun);
        }])->orderBy('nama', 'asc')->get();

        return Inertia::render('Admin/KelolaPegawai', [
            'pegawai' => $pegawai,
            'tahunBerjalan' => $tahun,
        ]);
    }

    // ---> Menyimpan Data Pegawai Baru dari Modal <---
    // Password default diisi & di-hash supaya pegawai baru otomatis bisa
    // login (kolom password NOT NULL di database). is_first_login=true
    // memaksa pegawai mengganti password lewat alur /ganti-password.
    //
    // ---> PENAMBAHAN <---
    // Sekarang juga menyimpan no_telp & alamat (kolom baru di tabel pegawais),
    // dan langsung membuat baris saldo_cutis tahun berjalan untuk pegawai baru
    // (Jatah Cuti / Sisa Cuti Tahun Ini / Sisa Cuti Tahun Kemarin), memakai
    // nilai dari form kalau diisi Admin HR, atau default standar bila kosong.
    public function storePegawai(Request $request)
    {
        $request->validate([
            'nip' => ['required', 'string', 'max:50', 'unique:pegawais,nip'],
            'nama' => ['required', 'string', 'max:255'],
            'departemen' => ['required', 'string', 'max:255'],
            'divisi' => ['nullable', 'string', 'max:255'],
            'jabatan' => ['required', 'string', 'max:255'],
            'role_id' => ['required', 'integer'],
            'no_telp' => ['nullable', 'string', 'max:30'],
            'alamat' => ['nullable', 'string', 'max:500'],
            'kuota_tahunan' => ['nullable', 'integer', 'min:0'],
            'sisa' => ['nullable', 'integer', 'min:0'],
            'carry_forward_normal' => ['nullable', 'integer', 'min:0'],
        ]);

        $pegawai = Pegawai::create([
            'nip' => $request->nip,
            'nama' => $request->nama,
            'departemen' => $request->departemen,
            'divisi' => $request->divisi,
            'jabatan' => $request->jabatan,
            'role_id' => $request->role_id,
            'no_telp' => $request->no_telp,
            'alamat' => $request->alamat,
            'password' => Hash::make('password123'),
            'is_first_login' => true,
        ]);

        $kuotaTahunan = $request->kuota_tahunan ?? 12;

        SaldoCuti::create([
            'pegawai_id' => $pegawai->id,
            'tahun' => date('Y'),
            'kuota_tahunan' => $kuotaTahunan,
            'sisa' => $request->sisa ?? $kuotaTahunan,
            'carry_forward_normal' => $request->carry_forward_normal ?? 0,
        ]);

        return back()->with('success', 'Data pegawai berhasil ditambahkan.');
    }

    // ---> Mengupdate Data Pegawai dari Modal Edit <---
    //
    // ---> PENAMBAHAN <---
    // Sekarang juga menyimpan no_telp & alamat, serta meng-update (atau
    // membuat, kalau belum ada) baris saldo_cutis tahun berjalan pegawai ini
    // untuk field Jatah Cuti / Sisa Cuti Tahun Ini / Sisa Cuti Tahun Kemarin.
    // Ini memakai tabel & kolom yang SAMA dengan halaman Kelola Saldo
    // (updateSaldo()) supaya datanya selalu konsisten di kedua tempat.
    public function updatePegawai(Request $request, int $id)
    {
        $pegawai = Pegawai::findOrFail($id);

        $request->validate([
            'nip' => ['required', 'string', 'max:50', 'unique:pegawais,nip,' . $id],
            'nama' => ['required', 'string', 'max:255'],
            'departemen' => ['required', 'string', 'max:255'],
            'divisi' => ['nullable', 'string', 'max:255'],
            'jabatan' => ['required', 'string', 'max:255'],
            'role_id' => ['required', 'integer'],
            'no_telp' => ['nullable', 'string', 'max:30'],
            'alamat' => ['nullable', 'string', 'max:500'],
            'kuota_tahunan' => ['nullable', 'integer', 'min:0'],
            'sisa' => ['nullable', 'integer', 'min:0'],
            'carry_forward_normal' => ['nullable', 'integer', 'min:0'],
        ]);

        $pegawai->update([
            'nip' => $request->nip,
            'nama' => $request->nama,
            'departemen' => $request->departemen,
            'divisi' => $request->divisi,
            'jabatan' => $request->jabatan,
            'role_id' => $request->role_id,
            'no_telp' => $request->no_telp,
            'alamat' => $request->alamat,
        ]);

        // Hanya sentuh saldo_cutis kalau salah satu field saldo memang diisi
        // dari form, supaya modal Edit Pegawai tetap aman dipakai walau
        // field saldo dikosongkan/tidak dikirim sama sekali.
        if ($request->filled('kuota_tahunan') || $request->filled('sisa') || $request->filled('carry_forward_normal')) {
            SaldoCuti::updateOrCreate(
                ['pegawai_id' => $pegawai->id, 'tahun' => date('Y')],
                array_filter(
                    [
                        'kuota_tahunan' => $request->kuota_tahunan,
                        'sisa' => $request->sisa,
                        'carry_forward_normal' => $request->carry_forward_normal,
                    ],
                    fn($value) => $value !== null
                )
            );
        }

        return back()->with('success', 'Data pegawai berhasil diperbarui.');
    }

    // ---> Reset Password Pegawai yang Lupa Sandi <---
    // Dipanggil oleh Admin HR lewat tombol "Reset Password" di halaman
    // Kelola Pegawai. Password dikembalikan ke default 'password123'
    // (di-hash), dan is_first_login diset true supaya pegawai wajib
    // menggantinya sendiri saat login berikutnya.
    public function resetPasswordPegawai(int $id)
    {
        $pegawai = Pegawai::findOrFail($id);

        $pegawai->update([
            'password' => Hash::make('password123'),
            'is_first_login' => true,
        ]);

        return back()->with('success', "Password untuk {$pegawai->nama} berhasil direset ke default. Pegawai wajib menggantinya saat login berikutnya.");
    }

    // 2. Menampilkan Daftar Saldo Cuti Pegawai Tahun Ini
    public function kelolaSaldo()
    {
        $tahun = date('Y');

        $pegawai = Pegawai::with(['saldoCuti' => function ($query) use ($tahun) {
            $query->where('tahun', $tahun);
        }])->orderBy('nama', 'asc')->get();

        return Inertia::render('Admin/KelolaSaldo', [
            'pegawai' => $pegawai,
            'tahun' => $tahun
        ]);
    }

    // 3. Memperbarui atau Membuat Saldo Cuti Baru
    public function updateSaldo(Request $request, int $id)
    {
        $request->validate([
            'kuota_tahunan' => ['required', 'numeric', 'min:0'],
            'sisa' => ['required', 'numeric', 'min:0'],
        ]);

        $tahun = date('Y');

        SaldoCuti::updateOrCreate(
            ['pegawai_id' => $id, 'tahun' => $tahun],
            ['kuota_tahunan' => $request->kuota_tahunan, 'sisa' => $request->sisa]
        );

        return back();
    }

    // 4. Menampilkan Rekap Laporan Cuti Seluruh Pegawai (Rekap Per Bulan)
    // Urutan pegawai mengikuti HIERARKI JABATAN (role_id via $urutanJabatan),
    // dari jabatan tertinggi ke terendah, dengan abjad nama sebagai
    // tie-breaker — lebih sesuai untuk laporan resmi instansi pemerintah
    // yang biasanya dibaca berdasarkan struktur organisasi.
    public function rekapLaporan(Request $request)
    {
        $data = $this->buildLaporanData($request);

        return Inertia::render('Admin/RekapLaporan', [
            'laporan' => $data['laporan'],
            'tahun' => $data['tahun'],
            'filters' => $request->only(['search', 'tahun', 'bulan']),
        ]);
    }

    // 5. Export Data Rekap Cuti ke Excel (bergaya: header hijau/ungu, badge warna
    // jabatan, sorot bulan berjalan, kolom Total pakai formula, legenda warna,
    // freeze panes). Memakai buildLaporanData() yang SAMA dengan rekapLaporan(),
    // jadi data, urutan pegawai, dan filter (search/tahun/bulan) di file Excel
    // SELALU sama persis dengan yang tampil di layar.
    //
    // Kalau ada filter bulan aktif, export cuma menampilkan kolom bulan itu
    // (selaras dengan tampilan layar yang mempersempit kolom, bukan baris).
    // Kalau tidak ada filter, export tetap 12 kolom seperti biasa.
    public function exportExcel(Request $request)
    {
        $data = $this->buildLaporanData($request);
        $laporan = $data['laporan'];
        $tahun = $data['tahun'];
        $bulanFilter = $data['bulanFilter'];

        if ($bulanFilter && isset($this->keyBulan[$bulanFilter - 1])) {
            $exportKeyBulan = [$this->keyBulan[$bulanFilter - 1]];
            $exportLabelBulan = [$this->labelBulan[$bulanFilter - 1]];
        } else {
            $exportKeyBulan = $this->keyBulan;
            $exportLabelBulan = $this->labelBulan;
        }

        $bulanBerjalanIdx = null;
        if ($tahun == date('Y')) {
            $currentKey = $this->keyBulan[(int) date('n') - 1];
            $idxInExport = array_search($currentKey, $exportKeyBulan);
            $bulanBerjalanIdx = $idxInExport !== false ? $idxInExport : null;
        }

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle("Rekap Cuti {$tahun}");

        $nBulan = count($exportLabelBulan);
        $bulanStartCol = 5; // E
        $totalCol = $bulanStartCol + $nBulan;
        $lastColLetter = Coordinate::stringFromColumnIndex($totalCol);

        // ---------- Warna ----------
        $GREEN_DARK   = '1D9E75';
        $GREEN_LIGHT  = 'E1F5EE';
        $GREEN_HEADER = '97C459';
        $GREEN_TOTAL  = '639922';
        $AMBER        = 'FAC775';
        $BLUE_BADGE   = '85B7EB';
        $PURPLE_TINGGI = 'AFA9EC';
        $PURPLE_MUDA   = 'CECBF6';
        $GRAY_STAF    = 'D3D1C7';
        $TOTAL_ROW_BG = 'FAEEDA';
        $ZEBRA        = 'F4F8F0';

        // ---------- Judul ----------
        $sheet->mergeCells("A1:{$lastColLetter}1");
        $sheet->setCellValue('A1', 'REKAPITULASI CUTI TAHUNAN PEGAWAI — TAHUN ' . $tahun);
        $sheet->getStyle('A1')->getFont()->setName('Arial')->setSize(14)->setBold(true)->getColor()->setRGB('FFFFFF');
        $sheet->getStyle('A1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($GREEN_DARK);
        $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getRowDimension(1)->setRowHeight(26);

        $sheet->mergeCells("A2:{$lastColLetter}2");
        $sheet->setCellValue('A2', 'Kementerian Pertanian RI — Biro Organisasi dan SDM Aparatur');
        $sheet->getStyle('A2')->getFont()->setName('Arial')->setSize(10)->getColor()->setRGB('04342C');
        $sheet->getStyle('A2')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($GREEN_LIGHT);
        $sheet->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getRowDimension(2)->setRowHeight(18);
        $sheet->getRowDimension(3)->setRowHeight(6);

        // ---------- Header 2 tingkat ----------
        $headerRow1 = 4;
        $headerRow2 = 5;
        $headersFixed = ['No', 'Nama Pegawai', 'NIP', 'Jabatan'];

        foreach ($headersFixed as $idx => $label) {
            $col = $idx + 1;
            $letter = Coordinate::stringFromColumnIndex($col);
            $sheet->mergeCells("{$letter}{$headerRow1}:{$letter}{$headerRow2}");
            $sheet->setCellValue("{$letter}{$headerRow1}", $label);
            $style = $sheet->getStyle("{$letter}{$headerRow1}:{$letter}{$headerRow2}");
            $style->getFont()->setName('Arial')->setSize(10)->setBold(true)->getColor()->setRGB('FFFFFF');
            $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('26215C');
            $style->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
            $style->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('B4B2A9');
        }

        $bulanStartLetter = Coordinate::stringFromColumnIndex($bulanStartCol);
        $bulanEndLetter = Coordinate::stringFromColumnIndex($bulanStartCol + $nBulan - 1);
        $sheet->mergeCells("{$bulanStartLetter}{$headerRow1}:{$bulanEndLetter}{$headerRow1}");
        $sheet->setCellValue("{$bulanStartLetter}{$headerRow1}", 'Jumlah Hari Cuti per Bulan');
        $sheet->getStyle("{$bulanStartLetter}{$headerRow1}:{$bulanEndLetter}{$headerRow1}")->applyFromArray([
            'font' => ['name' => 'Arial', 'size' => 10, 'bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $GREEN_HEADER]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'B4B2A9']]],
        ]);

        foreach ($exportLabelBulan as $i => $label) {
            $col = $bulanStartCol + $i;
            $letter = Coordinate::stringFromColumnIndex($col);
            $sheet->setCellValue("{$letter}{$headerRow2}", $label);
            $isCurrent = $bulanBerjalanIdx !== null && $i === $bulanBerjalanIdx;
            $sheet->getStyle("{$letter}{$headerRow2}")->applyFromArray([
                'font' => [
                    'name' => 'Arial',
                    'size' => 10,
                    'bold' => true,
                    'color' => ['rgb' => $isCurrent ? '412402' : 'FFFFFF'],
                ],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $isCurrent ? $AMBER : $GREEN_HEADER]],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'B4B2A9']]],
            ]);
        }

        $totalLetter = Coordinate::stringFromColumnIndex($totalCol);
        $sheet->mergeCells("{$totalLetter}{$headerRow1}:{$totalLetter}{$headerRow2}");
        $sheet->setCellValue("{$totalLetter}{$headerRow1}", 'Total Cuti');
        $sheet->getStyle("{$totalLetter}{$headerRow1}")->applyFromArray([
            'font' => ['name' => 'Arial', 'size' => 10, 'bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $GREEN_TOTAL]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'B4B2A9']]],
        ]);

        $sheet->getRowDimension($headerRow1)->setRowHeight(18);
        $sheet->getRowDimension($headerRow2)->setRowHeight(16);

        // ---------- Data ----------
        $dataStartRow = 6;
        $r = $dataStartRow;

        foreach ($laporan as $i => $item) {
            $zebra = $i % 2 === 1 ? $ZEBRA : null;

            // Warna badge jabatan berdasarkan role_id pegawai (level hierarki)
            $roleId = $item['role_id'] ?? 1;
            if ($roleId >= 3) {
                $jabatanColor = $PURPLE_TINGGI;
                $jabatanTextColor = '26215C';
            } elseif ($roleId === 2) {
                $jabatanColor = $PURPLE_MUDA;
                $jabatanTextColor = '3C3489';
            } else {
                $jabatanColor = $GRAY_STAF;
                $jabatanTextColor = '444441';
            }

            $sheet->setCellValue("A{$r}", $i + 1);
            $sheet->setCellValue("B{$r}", $item['nama']);
            $sheet->setCellValueExplicit("C{$r}", $item['nip'], DataType::TYPE_STRING);
            $sheet->setCellValue("D{$r}", $item['jabatan'] ?? '-');

            $sheet->getStyle("A{$r}")->applyFromArray([
                'font' => ['name' => 'Arial', 'size' => 10],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'B4B2A9']]],
                'fill' => $zebra ? ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $zebra]] : [],
            ]);
            $sheet->getStyle("B{$r}")->applyFromArray([
                'font' => ['name' => 'Arial', 'size' => 10],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'B4B2A9']]],
                'fill' => $zebra ? ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $zebra]] : [],
            ]);
            $sheet->getStyle("C{$r}")->applyFromArray([
                'font' => ['name' => 'Arial', 'size' => 10],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'B4B2A9']]],
                'fill' => $zebra ? ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $zebra]] : [],
            ]);
            $sheet->getStyle("D{$r}")->applyFromArray([
                'font' => ['name' => 'Arial', 'size' => 9, 'bold' => true, 'color' => ['rgb' => $jabatanTextColor]],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $jabatanColor]],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'B4B2A9']]],
            ]);

            foreach ($exportKeyBulan as $j => $key) {
                $col = $bulanStartCol + $j;
                $letter = Coordinate::stringFromColumnIndex($col);
                $val = $item['cuti'][$key] ?? 0;
                $sheet->setCellValue("{$letter}{$r}", $val);

                if ($val > 0) {
                    $sheet->getStyle("{$letter}{$r}")->applyFromArray([
                        'font' => ['name' => 'Arial', 'size' => 10, 'bold' => true, 'color' => ['rgb' => '042C53']],
                        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $BLUE_BADGE]],
                    ]);
                } else {
                    $sheet->getStyle("{$letter}{$r}")->applyFromArray([
                        'font' => ['name' => 'Arial', 'size' => 10, 'color' => ['rgb' => '888780']],
                        'fill' => $zebra ? ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $zebra]] : [],
                    ]);
                }
                $sheet->getStyle("{$letter}{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
                $sheet->getStyle("{$letter}{$r}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('B4B2A9');
            }

            // Kolom Total pakai formula SUM atas kolom bulan yang benar-benar
            // ditampilkan (mengikuti filter bulan bila aktif), bukan angka hardcode
            $sheet->setCellValue("{$totalLetter}{$r}", "=SUM({$bulanStartLetter}{$r}:{$bulanEndLetter}{$r})");
            $sheet->getStyle("{$totalLetter}{$r}")->applyFromArray([
                'font' => ['name' => 'Arial', 'size' => 10, 'bold' => true, 'color' => ['rgb' => '173404']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'EAF3DE']],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'B4B2A9']]],
            ]);

            $r++;
        }

        $dataEndRow = $r - 1;

        // ---------- Baris Total Keseluruhan ----------
        $totalRow = $dataEndRow + 2;
        $sheet->mergeCells("A{$totalRow}:D{$totalRow}");
        $sheet->setCellValue("A{$totalRow}", 'Total Keseluruhan');
        $sheet->getStyle("A{$totalRow}:D{$totalRow}")->applyFromArray([
            'font' => ['name' => 'Arial', 'size' => 10, 'bold' => true, 'color' => ['rgb' => '412402']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $TOTAL_ROW_BG]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'B4B2A9']]],
        ]);

        foreach ($exportKeyBulan as $j => $key) {
            $col = $bulanStartCol + $j;
            $letter = Coordinate::stringFromColumnIndex($col);
            $sheet->setCellValue("{$letter}{$totalRow}", "=SUM({$letter}{$dataStartRow}:{$letter}{$dataEndRow})");
            $sheet->getStyle("{$letter}{$totalRow}")->applyFromArray([
                'font' => ['name' => 'Arial', 'size' => 10, 'bold' => true, 'color' => ['rgb' => '412402']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $TOTAL_ROW_BG]],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'B4B2A9']]],
            ]);
        }

        $sheet->setCellValue("{$totalLetter}{$totalRow}", "=SUM({$totalLetter}{$dataStartRow}:{$totalLetter}{$dataEndRow})");
        $sheet->getStyle("{$totalLetter}{$totalRow}")->applyFromArray([
            'font' => ['name' => 'Arial', 'size' => 11, 'bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $GREEN_DARK]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'B4B2A9']]],
        ]);

        // ---------- Legenda warna (memanjang ke bawah) ----------
        $legendTitleRow = $totalRow + 2;
        $sheet->setCellValue("A{$legendTitleRow}", 'Keterangan warna:');
        $sheet->getStyle("A{$legendTitleRow}")->getFont()->setName('Arial')->setSize(9)->setItalic(true)->setBold(true)->getColor()->setRGB('5F5E5A');

        $legends = [
            ['Jabatan struktural (Kepala Biro / Kasubag)', $PURPLE_TINGGI],
            ['Ketua Kelompok / Ketua Tim Kerja', $PURPLE_MUDA],
            ['Staf', $GRAY_STAF],
            ['Ada cuti di bulan tersebut', $BLUE_BADGE],
            ['Bulan berjalan', $AMBER],
        ];

        foreach ($legends as $i => $item) {
            [$label, $color] = $item;
            $row = $legendTitleRow + 1 + $i;
            $sheet->getStyle("A{$row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($color);
            $sheet->getStyle("A{$row}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('B4B2A9');
            $sheet->mergeCells("B{$row}:D{$row}");
            $sheet->setCellValue("B{$row}", $label);
            $sheet->getStyle("B{$row}")->getFont()->setName('Arial')->setSize(9)->getColor()->setRGB('2C2C2A');
            $sheet->getStyle("B{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT)->setVertical(Alignment::VERTICAL_CENTER);
            $sheet->getRowDimension($row)->setRowHeight(16);
        }

        // ---------- Lebar kolom ----------
        $sheet->getColumnDimension('A')->setWidth(5);
        $sheet->getColumnDimension('B')->setWidth(38);
        $sheet->getColumnDimension('C')->setWidth(20);
        $sheet->getColumnDimension('D')->setWidth(22);
        foreach ($exportKeyBulan as $j => $key) {
            $letter = Coordinate::stringFromColumnIndex($bulanStartCol + $j);
            $sheet->getColumnDimension($letter)->setWidth(6);
        }
        $sheet->getColumnDimension($totalLetter)->setWidth(11);

        // Freeze panes: header + kolom No/Nama/NIP/Jabatan tetap kelihatan saat scroll
        $sheet->freezePane('E' . $dataStartRow);

        $filename = "Rekap_Cuti_Tahunan_{$tahun}_" . date('Y-m-d') . ".xlsx";

        $writer = new Xlsx($spreadsheet);

        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    // 6. Fungsi Menangguhkan Cuti (Oleh Admin/L4)
    public function suspendCuti(Request $request, int $id)
    {
        abort_unless($request->user()->role_id === 6, 403, 'Hanya L4 yang dapat menangguhkan cuti.');

        $request->validate([
            'alasan' => ['required', 'string', 'max:255']
        ]);

        $pengajuan = PengajuanCuti::findOrFail($id);

        // Hanya cuti yang sudah disetujui yang bisa ditangguhkan
        if ($pengajuan->status !== 'disetujui') {
            return back()->with('error', 'Hanya cuti yang telah disetujui yang dapat ditangguhkan.');
        }

        // Kembalikan Saldo Cuti Pegawai (Berdasarkan tahun cuti tersebut)
        $tahunCuti = date('Y', strtotime($pengajuan->tanggal_mulai));
        $saldo = SaldoCuti::where('pegawai_id', $pengajuan->pegawai_id)
            ->where('tahun', $tahunCuti)
            ->first();

        if ($saldo) {
            $saldo->update([
                'sisa' => $saldo->sisa + $pengajuan->jumlah_hari
            ]);
        }

        // Sisipkan alasan penangguhan ke kolom keterangan yang sudah ada
        $keteranganBaru = $pengajuan->keterangan . ' | [DITANGGUHKAN: ' . $request->alasan . ']';

        // Update status menjadi dibatalkan_ditangguhkan
        $pengajuan->update([
            'status' => 'dibatalkan_ditangguhkan',
            'keterangan' => $keteranganBaru
        ]);

        return back()->with('success', 'Cuti berhasil ditangguhkan dan saldo telah dikembalikan.');
    }

    // 7. Menampilkan Halaman Kelola Hari Libur
    public function kelolaLibur()
    {
        // HANYA MENAMPILKAN LIBUR MULAI HARI INI KE DEPAN, MAKSIMAL 7 BARIS
        $libur = HariLibur::where('tanggal', '>=', Carbon::today()->toDateString())
            ->orderBy('tanggal', 'asc')
            ->paginate(7);

        return Inertia::render('Admin/KelolaLibur', [
            'libur' => $libur
        ]);
    }

    // 8. Menyimpan Data Hari Libur Baru
    public function storeLibur(Request $request)
    {
        $request->validate([
            'tanggal' => ['required', 'date', 'unique:hari_liburs,tanggal'],
            'keterangan' => ['required', 'string', 'max:255'],
            'is_cuti_bersama' => ['required', 'boolean'],
        ], [
            'tanggal.unique' => 'Tanggal ini sudah didaftarkan sebagai hari libur.'
        ]);

        HariLibur::create([
            'tanggal' => $request->tanggal,
            'keterangan' => $request->keterangan,
            'is_cuti_bersama' => $request->is_cuti_bersama,
        ]);

        return back();
    }

    // 9. Mengimpor Data Hari Libur dari File CSV
    public function importLiburCsv(Request $request)
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt'],
        ]);

        $file = $request->file('file');
        $path = $file->getRealPath();
        $handle = fopen($path, 'r');

        if ($handle === false) {
            return back()->with('error', 'File CSV tidak dapat dibuka.');
        }

        $delimiter = ',';
        $firstLine = fgets($handle);

        if ($firstLine !== false) {
            $delimiter = substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',';
        }

        rewind($handle);

        $inserted = 0;
        $skipped = 0;
        $headerSkipped = false;

        while (($row = fgetcsv($handle, 1000, $delimiter)) !== false) {
            $row = array_map(function ($value) {
                return trim((string) $value);
            }, $row);

            if (empty(array_filter($row, fn($value) => $value !== ''))) {
                continue;
            }

            $firstCell = strtolower($row[0] ?? '');
            if (!$headerSkipped && in_array($firstCell, ['tanggal', 'date', 'tgl'])) {
                $headerSkipped = true;
                continue;
            }

            if (count($row) < 2) {
                $skipped++;
                continue;
            }

            $tanggal = $row[0];
            $keterangan = $row[1];
            $isCutiBersama = $row[2] ?? 'false';

            if (empty($tanggal) || empty($keterangan)) {
                $skipped++;
                continue;
            }

            try {
                $parsedDate = Carbon::parse($tanggal)->format('Y-m-d');
            } catch (\Exception $e) {
                $skipped++;
                continue;
            }

            if (HariLibur::whereDate('tanggal', $parsedDate)->exists()) {
                $skipped++;
                continue;
            }

            $jenis = strtolower((string) $isCutiBersama);
            $isCutiBersamaFlag = in_array($jenis, ['1', 'true', 'yes', 'ya', 'cuti bersama', 'cuti_bersama'], true);

            HariLibur::create([
                'tanggal' => $parsedDate,
                'keterangan' => $keterangan,
                'is_cuti_bersama' => $isCutiBersamaFlag ? 1 : 0,
            ]);

            $inserted++;
        }

        fclose($handle);

        return back()->with(
            'success',
            "Berhasil mengimpor {$inserted} hari libur dari CSV. {$skipped} baris dilewati."
        );
    }

    // 10. Menghapus Data Hari Libur
    public function destroyLibur(int $id)
    {
        HariLibur::findOrFail($id)->delete();
        return back();
    }
}
