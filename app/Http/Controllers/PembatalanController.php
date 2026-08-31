<?php

namespace App\Http\Controllers;

use App\Models\ApprovalLog;
use App\Models\PengajuanCuti;
use App\Models\SaldoCuti;
use Illuminate\Http\Request;
use Inertia\Inertia;

class PembatalanController extends Controller
{
    // Menampilkan daftar cuti yang bisa dibatalkan (di departemen yang sama)
    public function index()
    {
        $user = auth()->user();

        abort_unless($user->role_id === 6, 403, 'Hanya L4 yang dapat menangguhkan cuti.');

        $daftarCuti = PengajuanCuti::with('pegawai')
            ->whereHas('pegawai', function ($query) use ($user) {
                $query->where('departemen', $user->departemen);
            })
            // Tampilkan yang belum mulai atau sedang berlangsung, dan belum ditolak/dibatalkan
            ->whereNotIn('status', ['ditolak', 'dibatalkan_reguler', 'dibatalkan_ditangguhkan'])
            ->orderBy('created_at', 'desc')
            ->get();

        return Inertia::render('Atasan/PembatalanCuti', [
            'daftarCuti' => $daftarCuti
        ]);
    }

    // Memproses Eksekusi Pembatalan & Penangguhan oleh L4
    public function process(Request $request, $id)
    {
        $user = auth()->user();

        abort_unless($user->role_id === 6, 403, 'Hanya L4 yang dapat menangguhkan cuti.');

        $request->validate([
            'alasan' => ['required', 'string', 'max:255']
        ]);

        $pengajuan = PengajuanCuti::findOrFail($id);

        // Jika cuti sebelumnya sudah disetujui (saldo sudah dipotong), maka kembalikan saldo!
        if ($pengajuan->status === 'disetujui') {
            $saldo = SaldoCuti::where('pegawai_id', $pengajuan->pegawai_id)
                ->where('tahun', date('Y', strtotime($pengajuan->tanggal_mulai)))
                ->first();

            if ($saldo) {
                $saldo->increment('sisa', $pengajuan->jumlah_hari);
            }
        }

        // Sisipkan alasan penangguhan ke dalam keterangan asli
        $keteranganBaru = $pengajuan->keterangan . ' | [DITANGGUHKAN/DIBATALKAN ATASAN: ' . $request->alasan . ']';

        // Ubah status pengajuan menjadi ditangguhkan
        $pengajuan->update([
            'status' => 'dibatalkan_ditangguhkan',
            'keterangan' => $keteranganBaru
        ]);

        // ====== TAMBAHAN: catat aksi penangguhan ke approval_logs ======
        // Sebelumnya aksi ini TIDAK pernah tercatat di approval_logs, jadi
        // modal Rekap Kuota Detail tidak bisa menampilkan siapa yang
        // menangguhkan. level_approval memakai angka 5 sebagai PENANDA
        // KHUSUS (bukan bagian dari rantai L1-L4 biasa), supaya tidak
        // bentrok dengan log approval L4 yang sudah ada sebelumnya
        // (kasus cuti yang sudah disetujui penuh lalu baru ditangguhkan).
        ApprovalLog::create([
            'pengajuan_id' => $pengajuan->id,
            'approver_id' => $user->id,
            'level_approval' => 5,
            'keputusan' => 'tangguh',
            'catatan' => $request->alasan,
            'tanggal_keputusan' => now(),
        ]);

        return back()->with('success', 'Cuti berhasil ditangguhkan/dibatalkan.');
    }
}
