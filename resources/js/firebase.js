// resources/js/firebase.js

import { initializeApp } from "firebase/app";
import { getMessaging, isSupported } from "firebase/messaging";

const firebaseConfig = {
    apiKey: "AIzaSyArjCA2NdzrfClLiG-z0A7rT3vRxUy1ECc",
    authDomain: "sistem-cuti-kementan-cffc2.firebaseapp.com",
    projectId: "sistem-cuti-kementan-cffc2",
    storageBucket: "sistem-cuti-kementan-cffc2.firebasestorage.app",
    messagingSenderId: "675368181810",
    appId: "1:675368181810:web:4740574be02e82a348dc2b",
};

// Initialize Firebase
const app = initializeApp(firebaseConfig);

// Messaging hanya diinisialisasi jika browser mendukung
let messaging = null;

if (typeof window !== "undefined") {
    isSupported().then((supported) => {
        if (supported) {
            messaging = getMessaging(app);
        }
    });
}

export { app, messaging };
