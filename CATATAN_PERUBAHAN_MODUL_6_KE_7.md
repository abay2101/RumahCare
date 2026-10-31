# Catatan Perubahan Prototype Modul 6 ke Implementasi Modul 7

**Proyek:** Sistem Informasi Pengelolaan Pesanan dan Penjadwalan Jasa Perawatan Rumah (RumahCare)  
**Tanggal:** 31 Oktober 2026  
**Rujukan:** Use case Modul 4, rancangan basis data Modul 5, dan user flow/prototype Modul 6.

## Alur inti yang dibandingkan

Modul 6 menetapkan tiga alur prototype utama: UC-03 pencatatan pesanan oleh admin, UC-06 pengelolaan jadwal, dan UC-14 pembaruan status pekerjaan oleh teknisi. Implementasi mempertahankan alur tersebut dan menghubungkannya ke database RumahCare.

| Area | Prototype Modul 6 | Implementasi Modul 7 | Alasan perubahan |
|---|---|---|---|
| Pencatatan pesanan (UC-03) | Admin memilih pelanggan dan layanan, mengisi alamat serta waktu, lalu mengonfirmasi pesanan. | Form admin menyimpan pesanan dan rincian beberapa layanan; total biaya dihitung dari layanan yang dipilih. | Satu kunjungan dapat mencakup lebih dari satu layanan. Rincian layanan disimpan terpisah agar relasi dan biaya tetap dapat dikelola. |
| Status pesanan | Prototype menunjukkan pesanan tersimpan dan dapat diproses. | Pesanan aktif dipisahkan dari pesanan Selesai/Dibatalkan. Perubahan ke status akhir dikonfirmasi dan pesanan masuk arsip. | Status akhir perlu dibedakan dari pekerjaan yang masih berlangsung, dan data terkait jadwal/pembayaran perlu tetap terlacak. |
| Penjadwalan (UC-06) | Admin memilih pekerjaan, mengubah jadwal, mengonfirmasi, lalu melihat jadwal diperbarui. | Admin mengatur waktu mulai dan teknisi. Teknisi yang sudah memiliki pekerjaan aktif ditolak; status jadwal diselaraskan dengan status pesanan. | Estimasi selesai sulit ditentukan sebelum pekerjaan diperiksa. Pembatasan teknisi mencegah penugasan aktif ganda. |
| Pembaruan status (UC-14) | Teknisi membuka pekerjaan, memilih status terbaru, menyimpan, lalu melihat status yang diperbarui. | Teknisi hanya melihat pekerjaan aktif pada halaman utama; pekerjaan selesai/dibatalkan berada di Riwayat. Detail riwayat tidak dapat diubah. | Memisahkan pekerjaan yang perlu ditindaklanjuti dari pekerjaan yang sudah ditutup. |
| Hak akses teknisi | Prototype menyediakan dashboard dan detail pekerjaan teknisi. | Portal teknisi memakai autentikasi dan hanya menampilkan jadwal yang ditugaskan kepada akun teknisi tersebut. | Membatasi informasi pekerjaan sesuai aktor dan penugasan. |
| Portal pelanggan | Modul 6 berfokus pada alur admin dan teknisi; tidak ada alur portal pelanggan interaktif yang setara dengan tiga prototype inti. | Pelanggan yang akunnya dibuat admin dapat melihat pesanan, jadwal, teknisi, biaya, status pembayaran, dan riwayat miliknya. | Implementasi melanjutkan kebutuhan pelanggan pada Modul 4. Akses dibatasi berdasarkan tautan akun ke data pelanggan. |
| Pembayaran | Pembayaran tercantum pada kebutuhan dan rancangan data, tetapi bukan salah satu dari tiga alur prototype inti Modul 6. | Admin dapat mencatat metode Tunai, Transfer Bank, atau QRIS beserta jumlah dan tanggal. Portal menampilkan status dari catatan pembayaran. Tidak ada unggah bukti atau pemrosesan otomatis. | Menyediakan pencatatan pembayaran sesuai kebutuhan data tanpa integrasi penyedia pembayaran di luar ruang lingkup. |

## Ringkasan

Tiga alur inti prototype tetap menjadi dasar implementasi. Perubahan yang tampak terutama berupa penghubungan ke database, pembatasan akses per role, rincian banyak layanan dalam satu pesanan, pemisahan status aktif dan arsip, serta penambahan portal pelanggan dan pencatatan pembayaran. Perubahan tersebut memperluas prototype berdasarkan kebutuhan operasional dan data pada Modul 4–5, bukan mengganti alur inti Modul 6.

## Pemeriksaan lanjutan

Catatan ini mendokumentasikan perbandingan rancangan dengan implementasi. Bukti uji pengguna, screenshot hasil aplikasi, dan hasil evaluasi end-to-end dicatat terpisah sebagai bahan pengujian modul berikutnya.
