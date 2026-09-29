# 🏛️ Hermes-API & Athena-API

## Filosofi Proyek

### Hermes-API (Lokal)

> _"Seperti Hermes yang bertugas menyampaikan pesan antar dunia, Hermes-API bertindak sebagai jembatan yang mengirimkan data dari sistem lokal ke dunia luar (VPS). Dengan kecepatan dan ketepatan, ia memastikan setiap informasi sampai ke tujuannya tanpa mengganggu sistem inti yang tidak bisa diubah."_

**Peran**: Pengirim data dari sistem lokal ke VPS.

### Athena-API (VPS)

> _"Athena-API adalah pusat kebijaksanaan yang menerima, memproses, dan menyimpan data dengan cermat. Layaknya dewi yang melindungi pengetahuan, API ini memastikan setiap data yang diterima dikelola dengan bijak, terstruktur, dan siap digunakan untuk pengambilan keputusan di masa depan."_

**Peran**: Penerima, pengolah, dan penyimpan data dari sistem lokal.

---

## 🔗 Hubungan Antar Proyek

Sinkronisasi RPS mengirim baris angsuran untuk rekening pada snapshot dengan
rentang `tglangsuran` dari awal bulan sebelumnya sampai awal bulan setelah
bulan depan (batas akhir tidak termasuk). Dengan demikian, yang dikirim adalah
jadwal bulan lalu, bulan ini, dan bulan depan.
