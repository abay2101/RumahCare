-- Jalankan satu kali untuk mendukung jadwal tanpa estimasi waktu selesai
-- dan menyimpan waktu aktual saat pesanan selesai atau dibatalkan.
ALTER TABLE jadwal
    MODIFY COLUMN waktu_selesai DATETIME NULL DEFAULT NULL;

ALTER TABLE pesanan
    ADD COLUMN waktu_status_akhir DATETIME NULL DEFAULT NULL;
