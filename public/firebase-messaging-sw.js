/* eslint-disable no-undef */
// File ini HARUS berada di folder public/ agar bisa diakses di:
// http://localhost/firebase-messaging-sw.js  (root domain)

importScripts(
    "https://www.gstatic.com/firebasejs/10.12.2/firebase-app-compat.js",
);
importScripts(
    "https://www.gstatic.com/firebasejs/10.12.2/firebase-messaging-compat.js",
);

// Konfigurasi Firebase WEB. Nilai ini memang publik (terlihat oleh browser),
// BUKAN rahasia. Batasi API key lewat Google Cloud Console (HTTP referrers).
const firebaseConfig = {
    apiKey: "AIzaSyArjCA2NdzrfClLiG-z0A7rT3vRxUy1ECc",
    authDomain: "sistem-cuti-kementan-cffc2.firebaseapp.com",
    projectId: "sistem-cuti-kementan-cffc2",
    storageBucket: "sistem-cuti-kementan-cffc2.firebasestorage.app",
    messagingSenderId: "675368181810",
    appId: "1:675368181810:web:4740574be02e82a348dc2b",
};

// Inisialisasi Firebase di background
firebase.initializeApp(firebaseConfig);
const messaging = firebase.messaging();


// Versi service worker baru langsung aktif, tanpa menunggu tab lama ditutup.
self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (event) => event.waitUntil(clients.claim()));


// Pesan dari server dikirim sebagai data-only (title, body, url).
// Service worker ini yang menampilkan notifikasinya.
messaging.onBackgroundMessage(function (payload) {
    const d = payload.data || {};


    self.registration.showNotification(d.title || 'SICA', {
        body: d.body || '',
        icon: '/favicon.ico',
        data: { url: d.url || '/dashboard' },
    });
});


// KEAMANAN: ubah tautan menjadi URL yang SELALU berada di origin aplikasi ini.
// "//evil.com/x", "https://evil.com", dan "javascript:..." semuanya ditolak
// dan diganti ke /dashboard. Server sudah membersihkan path, ini lapisan kedua.
function urlAman(path) {
    const fallback = self.location.origin + '/dashboard';


    try {
        const u = new URL(String(path || ''), self.location.origin);


        if (u.origin !== self.location.origin) {
            return fallback;
        }


        return u.href;
    } catch (e) {
        return fallback;
    }
}


// Klik notifikasi membuka halaman tujuan (memakai tab yang sudah ada jika ada).
self.addEventListener('notificationclick', function (event) {
    event.notification.close();


    const target = urlAman(event.notification.data && event.notification.data.url);


    event.waitUntil(
        clients.matchAll({ type: 'window', includeUncontrolled: true }).then((list) => {
            for (const c of list) {
                // Bandingkan ORIGIN sebenarnya, bukan awalan teks.
                let sama = false;
                try {
                    sama = new URL(c.url).origin === self.location.origin;
                } catch (e) { /* abaikan */ }


                if (sama && 'navigate' in c) {
                    return c.navigate(target)
                        .then((w) => (w ? w.focus() : null))
                        .catch(() => clients.openWindow(target));
                }
            }
            return clients.openWindow(target);
        })
    );
});

