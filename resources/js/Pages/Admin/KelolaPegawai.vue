<script setup>
import { ref, onErrorCaptured } from "vue";
import { useForm, Head, router } from "@inertiajs/vue3";
import MainLayout from "@/Layouts/MainLayout.vue";

// Definisikan props dengan nilai default agar tidak error jika data dari backend kosong
const props = defineProps({
    pegawai: {
        type: Array,
        default: () => [],
    },
    // Tahun berjalan (dikirim dari AdminController@kelolaPegawai), dipakai
    // hanya untuk label "Saldo Cuti Tahun {{ tahunBerjalan }}" di modal.
    tahunBerjalan: {
        type: [Number, String],
        default: () => new Date().getFullYear(),
    },
});

// ---> SISTEM PENDETEKSI ERROR OTOMATIS <---
const vueError = ref(null);
onErrorCaptured((err) => {
    vueError.value = err.toString();
    console.error("VUE ERROR TERTANGKAP:", err);
    return false; // Mencegah error merusak seluruh halaman
});

const formatRole = (roleId) => {
    const roles = {
        1: "Karyawan",
        2: "Atasan L1",
        3: "Atasan L2",
        4: "Atasan L3",
        5: "Admin HR",
        6: "Atasan L4",
    };
    return roles[roleId] || "Tidak Diketahui";
};

// State reaktivitas modal
const isModalOpen = ref(false);
const isEditMode = ref(false);
const currentEditId = ref(null);

// Default saldo cuti untuk pegawai baru (dipakai saat openAddModal)
const DEFAULT_KUOTA_TAHUNAN = 12;

const form = useForm({
    nip: "",
    nama: "",
    departemen: "", // Divisi/Departemen
    divisi: "", // Tim Kerja
    jabatan: "",
    kelompok_substansi: "", // Field baru ditambahkan
    role_id: 1,
    no_telp: "",
    alamat: "",
    kuota_tahunan: DEFAULT_KUOTA_TAHUNAN,
    sisa: DEFAULT_KUOTA_TAHUNAN,
    carry_forward_normal: 0,
});

// Fungsi buka modal Tambah
const openAddModal = () => {
    isEditMode.value = false;
    currentEditId.value = null;
    form.reset();
    isModalOpen.value = true;
};

// Fungsi buka modal Edit
const openEditModal = (item) => {
    isEditMode.value = true;
    currentEditId.value = item.id;
    form.nip = item.nip || "";
    form.nama = item.nama || "";
    form.departemen = item.departemen || "";
    form.divisi = item.divisi || "";
    form.jabatan = item.jabatan || "";
    form.kelompok_substansi = item.kelompok_substansi || ""; // Mapping data baru
    form.role_id = item.role_id || 1;
    form.no_telp = item.no_telp || "";
    form.alamat = item.alamat || "";

    // Ambil saldo cuti tahun berjalan pegawai ini
    const saldoTahunIni =
        item.saldo_cuti && item.saldo_cuti.length > 0
            ? item.saldo_cuti[0]
            : null;

    form.kuota_tahunan = saldoTahunIni?.kuota_tahunan ?? DEFAULT_KUOTA_TAHUNAN;
    form.sisa = saldoTahunIni?.sisa ?? DEFAULT_KUOTA_TAHUNAN;
    form.carry_forward_normal = saldoTahunIni?.carry_forward_normal ?? 0;

    isModalOpen.value = true;
};

// Fungsi simpan (Store / Update)
const submitForm = () => {
    if (isEditMode.value) {
        form.put(route("admin.pegawai.update", currentEditId.value), {
            onSuccess: () => {
                isModalOpen.value = false;
                form.reset();
            },
        });
    } else {
        form.post(route("admin.pegawai.store"), {
            onSuccess: () => {
                isModalOpen.value = false;
                form.reset();
            },
        });
    }
};

// ---> STATE UNTUK TOAST NOTIFIKASI SUKSES RESET PASSWORD <---
const showResetToast = ref(false);
const resetToastNama = ref("");
let resetToastTimer = null;

const resetPassword = (item) => {
    const konfirmasi = window.confirm(
        `Reset password untuk "${item.nama}" ke default (password123)?\n\nPegawai akan wajib mengganti passwordnya sendiri saat login berikutnya.`,
    );

    if (!konfirmasi) return;

    router.post(
        route("admin.pegawai.reset-password", item.id),
        {},
        {
            preserveScroll: true,
            onSuccess: () => {
                resetToastNama.value = item.nama;
                showResetToast.value = true;
                clearTimeout(resetToastTimer);
                resetToastTimer = setTimeout(() => {
                    showResetToast.value = false;
                }, 3500);
            },
        },
    );
};
</script>

<template>
    <Head title="Kelola Pegawai" />

    <MainLayout>
        <div>
            <!-- KOTAK MERAH PENDETEKSI ERROR -->
            <div
                v-if="vueError"
                class="bg-red-600 text-white p-6 rounded-lg mb-6 shadow-xl font-mono text-sm border-4 border-red-800"
            >
                <h2 class="text-xl font-black mb-2">
                    🚨 VUE RENDER ERROR TERTANGKAP!
                </h2>
                <p>
                    Pesan Error: <strong>{{ vueError }}</strong>
                </p>
                <p class="mt-2 text-xs opacity-80">
                    Silakan kirimkan tulisan error di atas kepada saya agar kita
                    perbaiki akarnya!
                </p>
            </div>

            <!-- KONTEN UTAMA -->
            <div
                v-else
                class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6 relative z-10"
            >
                <div
                    class="flex justify-between items-center mb-6 border-b pb-4"
                >
                    <h2 class="text-2xl font-bold text-gray-800">
                        Master Data Pegawai
                    </h2>
                    <button
                        type="button"
                        @click="openAddModal"
                        class="px-5 py-2.5 bg-indigo-600 text-white rounded-md text-sm font-extrabold hover:bg-indigo-700 cursor-pointer shadow-lg hover:scale-105 transition-all"
                    >
                        + Tambah Pegawai
                    </button>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th
                                    class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase"
                                >
                                    NIP
                                </th>
                                <th
                                    class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase"
                                >
                                    Nama Pegawai
                                </th>
                                <th
                                    class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase"
                                >
                                    Divisi/Dept.
                                </th>
                                <th
                                    class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase"
                                >
                                    Tim Kerja
                                </th>
                                <th
                                    class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase"
                                >
                                    Jabatan
                                </th>
                                <th
                                    class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase"
                                >
                                    Hak Akses
                                </th>
                                <th
                                    class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase"
                                >
                                    Aksi
                                </th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <tr v-if="!pegawai || pegawai.length === 0">
                                <td
                                    colspan="7"
                                    class="px-6 py-12 text-center text-gray-500 font-bold bg-gray-50/50"
                                >
                                    <span class="block text-xl mb-2">📭</span>
                                    Data Pegawai Kosong atau Belum Dimasukkan
                                </td>
                            </tr>
                            <tr
                                v-else
                                v-for="item in pegawai"
                                :key="item.id"
                                class="hover:bg-gray-50"
                            >
                                <td
                                    class="px-4 py-3 whitespace-nowrap text-sm text-gray-900"
                                >
                                    {{ item?.nip || "-" }}
                                </td>
                                <td
                                    class="px-4 py-3 whitespace-nowrap text-sm text-gray-900 font-medium"
                                >
                                    {{ item?.nama || "-" }}
                                </td>
                                <td
                                    class="px-4 py-3 whitespace-nowrap text-sm text-gray-500"
                                >
                                    {{ item?.departemen || "-" }}
                                </td>
                                <td
                                    class="px-4 py-3 whitespace-nowrap text-sm text-gray-500"
                                >
                                    {{ item?.divisi || "-" }}
                                </td>
                                <td
                                    class="px-4 py-3 whitespace-nowrap text-sm text-gray-500"
                                >
                                    {{ item?.jabatan || "-" }}
                                </td>
                                <td
                                    class="px-4 py-3 whitespace-nowrap text-sm text-gray-500"
                                >
                                    {{ formatRole(item?.role_id) }}
                                </td>
                                <td
                                    class="px-4 py-3 whitespace-nowrap text-sm font-medium text-center space-x-1"
                                >
                                    <button
                                        type="button"
                                        @click="openEditModal(item)"
                                        class="text-indigo-600 hover:text-indigo-900 cursor-pointer font-bold px-3 py-1 bg-indigo-50 hover:bg-indigo-100 rounded transition-colors"
                                    >
                                        Edit
                                    </button>
                                    <button
                                        type="button"
                                        @click="resetPassword(item)"
                                        class="text-amber-600 hover:text-amber-900 cursor-pointer font-bold px-3 py-1 bg-amber-50 hover:bg-amber-100 rounded transition-colors"
                                    >
                                        Reset Password
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- ========================================== -->
            <!-- MODAL FORM EDIT / TAMBAH PEGWAWAI          -->
            <!-- ========================================== -->
            <Teleport to="body">
                <div
                    v-if="isModalOpen"
                    class="fixed inset-0 flex items-center justify-center p-4"
                    style="z-index: 999999"
                >
                    <!-- Background Transparan -->
                    <div
                        class="absolute inset-0 bg-black/60 backdrop-blur-sm"
                        @click="isModalOpen = false"
                    ></div>

                    <!-- Kotak Form Utama -->
                    <div
                        class="relative bg-white p-6 rounded-xl w-full max-w-2xl shadow-2xl max-h-[90vh] overflow-y-auto z-10"
                    >
                        <div
                            class="flex justify-between items-center mb-5 border-b pb-3"
                        >
                            <h3 class="text-lg font-bold text-gray-800">
                                {{
                                    isEditMode
                                        ? "Edit Data Pegawai"
                                        : "Tambah Data Pegawai Baru"
                                }}
                            </h3>
                            <button
                                type="button"
                                @click="isModalOpen = false"
                                class="text-gray-400 hover:text-red-500 font-bold text-2xl cursor-pointer outline-none"
                            >
                                &times;
                            </button>
                        </div>

                        <form @submit.prevent="submitForm">
                            <!-- BAGIAN 1: IDENTITAS & KONTAK -->
                            <p
                                class="text-xs font-bold uppercase tracking-wide text-gray-400 mb-3"
                            >
                                Identitas & Kontak
                            </p>

                            <!-- Baris 1: NIP & Nama -->
                            <div class="grid grid-cols-2 gap-x-4 mb-3">
                                <div>
                                    <label
                                        class="block text-sm font-medium text-gray-700"
                                        >NIP</label
                                    >
                                    <input
                                        type="text"
                                        v-model="form.nip"
                                        required
                                        class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                    />
                                </div>
                                <div>
                                    <label
                                        class="block text-sm font-medium text-gray-700"
                                        >Nama Lengkap & Gelar</label
                                    >
                                    <input
                                        type="text"
                                        v-model="form.nama"
                                        required
                                        class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                    />
                                </div>
                            </div>

                            <!-- Baris 2: No Telp & Hak Akses -->
                            <div class="grid grid-cols-2 gap-x-4 mb-3">
                                <div>
                                    <label
                                        class="block text-sm font-medium text-gray-700"
                                        >No Telepon</label
                                    >
                                    <input
                                        type="text"
                                        v-model="form.no_telp"
                                        placeholder="08xxxxxxxxxx"
                                        class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                    />
                                </div>
                                <div>
                                    <label
                                        class="block text-sm font-medium text-gray-700"
                                        >Hak Akses (Role)</label
                                    >
                                    <select
                                        v-model="form.role_id"
                                        required
                                        class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                    >
                                        <option value="1">
                                            Karyawan / Staf
                                        </option>
                                        <option value="2">
                                            Atasan L1 (Ketua Tim Kerja)
                                        </option>
                                        <option value="3">
                                            Atasan L2 (Ketua Kelompok)
                                        </option>
                                        <option value="4">
                                            Atasan L3 (Kasubag TU)
                                        </option>
                                        <option value="5">Admin HR</option>
                                        <option value="6">
                                            Atasan L4 (Kepala Biro)
                                        </option>
                                    </select>
                                </div>
                            </div>

                            <!-- Baris 3: Alamat (Membentang penuh/full-width) -->
                            <div class="mb-5">
                                <label
                                    class="block text-sm font-medium text-gray-700"
                                    >Alamat</label
                                >
                                <textarea
                                    v-model="form.alamat"
                                    rows="2"
                                    class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                ></textarea>
                            </div>

                            <!-- BAGIAN 2: STRUKTURAL / POSISI -->
                            <div class="border-t border-gray-100 pt-4 mb-5">
                                <p
                                    class="text-xs font-bold uppercase tracking-wide text-gray-400 mb-3"
                                >
                                    Posisi & Jabatan
                                </p>

                                <!-- Baris 4: Divisi & Kelompok Substansi -->
                                <div class="grid grid-cols-2 gap-x-4 mb-3">
                                    <div>
                                        <label
                                            class="block text-sm font-medium text-gray-700"
                                            >Divisi/Departemen</label
                                        >
                                        <input
                                            type="text"
                                            v-model="form.departemen"
                                            required
                                            class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                        />
                                    </div>
                                    <div>
                                        <label
                                            class="block text-sm font-medium text-gray-700"
                                            >Kelompok Substansi</label
                                        >
                                        <input
                                            type="text"
                                            v-model="form.kelompok_substansi"
                                            class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                        />
                                    </div>
                                </div>

                                <!-- Baris 5: Jabatan & Tim Kerja -->
                                <div class="grid grid-cols-2 gap-x-4">
                                    <div>
                                        <label
                                            class="block text-sm font-medium text-gray-700"
                                            >Jabatan</label
                                        >
                                        <input
                                            type="text"
                                            v-model="form.jabatan"
                                            required
                                            class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                        />
                                    </div>
                                    <div>
                                        <label
                                            class="block text-sm font-medium text-gray-700"
                                            >Tim Kerja</label
                                        >
                                        <input
                                            type="text"
                                            v-model="form.divisi"
                                            class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                        />
                                    </div>
                                </div>
                            </div>

                            <!-- BAGIAN 3: SALDO CUTI -->
                            <div class="border-t border-gray-100 pt-4 mb-4">
                                <p
                                    class="text-xs font-bold uppercase tracking-wide text-gray-400 mb-3"
                                >
                                    Saldo Cuti Tahun {{ tahunBerjalan }}
                                </p>

                                <div class="grid grid-cols-3 gap-x-3">
                                    <div>
                                        <label
                                            class="block text-sm font-medium text-gray-700"
                                            >Jatah Cuti</label
                                        >
                                        <input
                                            type="number"
                                            min="0"
                                            v-model="form.kuota_tahunan"
                                            class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                        />
                                    </div>
                                    <div>
                                        <label
                                            class="block text-sm font-medium text-gray-700"
                                            >Sisa Tahun Ini</label
                                        >
                                        <input
                                            type="number"
                                            min="0"
                                            v-model="form.sisa"
                                            class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                        />
                                    </div>
                                    <div>
                                        <label
                                            class="block text-sm font-medium text-gray-700"
                                            >Sisa Tahun Kemarin</label
                                        >
                                        <input
                                            type="number"
                                            min="0"
                                            v-model="form.carry_forward_normal"
                                            class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                        />
                                    </div>
                                </div>
                            </div>

                            <!-- TOMBOL SIMPAN -->
                            <div
                                class="flex justify-end space-x-2 mt-6 pt-4 border-t border-gray-100"
                            >
                                <button
                                    type="button"
                                    @click="isModalOpen = false"
                                    class="px-4 py-2 bg-gray-200 text-gray-700 rounded-md hover:bg-gray-300 cursor-pointer transition"
                                >
                                    Batal
                                </button>
                                <button
                                    type="submit"
                                    class="px-4 py-2 bg-indigo-600 text-white rounded-md hover:bg-indigo-700 cursor-pointer transition"
                                    :disabled="form.processing"
                                >
                                    {{
                                        isEditMode
                                            ? "Simpan Perubahan"
                                            : "Simpan Data"
                                    }}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </Teleport>

            <!-- TOAST NOTIFIKASI RESET PASSWORD -->
            <Teleport to="body">
                <Transition
                    enter-active-class="transition ease-out duration-200"
                    enter-from-class="opacity-0 translate-y-2"
                    enter-to-class="opacity-100 translate-y-0"
                    leave-active-class="transition ease-in duration-150"
                    leave-from-class="opacity-100 translate-y-0"
                    leave-to-class="opacity-0 translate-y-2"
                >
                    <div
                        v-if="showResetToast"
                        class="fixed bottom-6 right-6 flex items-center gap-3 bg-white border border-green-200 shadow-2xl rounded-lg px-4 py-3 max-w-sm"
                        style="z-index: 999999"
                    >
                        <span
                            class="flex-shrink-0 flex items-center justify-center w-8 h-8 rounded-full bg-green-100"
                        >
                            <svg
                                xmlns="http://www.w3.org/2000/svg"
                                class="h-5 w-5 text-green-600"
                                viewBox="0 0 20 20"
                                fill="currentColor"
                            >
                                <path
                                    fill-rule="evenodd"
                                    d="M16.704 5.29a1 1 0 010 1.415l-7.5 7.5a1 1 0 01-1.415 0l-3.5-3.5a1 1 0 111.415-1.415L8.5 12.086l6.793-6.793a1 1 0 011.415 0z"
                                    clip-rule="evenodd"
                                />
                            </svg>
                        </span>
                        <div class="text-sm">
                            <p class="font-semibold text-gray-800">
                                Password berhasil direset
                            </p>
                            <p class="text-gray-500">
                                Akun
                                <span class="font-medium">{{
                                    resetToastNama
                                }}</span>
                                sekarang menggunakan password default.
                            </p>
                        </div>
                        <button
                            type="button"
                            @click="showResetToast = false"
                            class="ml-2 text-gray-400 hover:text-gray-600 cursor-pointer"
                        >
                            &times;
                        </button>
                    </div>
                </Transition>
            </Teleport>
        </div>
    </MainLayout>
</template>
