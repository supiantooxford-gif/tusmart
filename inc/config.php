<?php
/**
 * Konfigurasi dasar aplikasi TUSmart (Tata Usaha Smart).
 *
 * Aplikasi SATU LEMBAGA (MA. Sirojuth Tholibiin Mootilango): identitas lembaga disimpan
 * pada tabel `pengaturan` (kunci/nilai) dan dapat diubah pada menu
 * Pengaturan -> Pengaturan Data Lembaga.
 *
 * Unggahan berkas (berkas surat, foto profil, logo) lewat media proxy platform:
 * token dibaca SERVER-SIDE dari `.vibecoder-media-token` (tidak pernah dikirim ke peramban).
 */
declare(strict_types=1);

date_default_timezone_set('Asia/Makassar'); // WITA (Gorontalo)

define('APP_NAME', 'TUSmart');
define('APP_SUB', 'Tata Usaha Smart');
define('APP_VERSION', '1.9.0');
define('APP_AUTHOR', 'Supianto');
define('APP_AUTHOR_JABATAN', 'Pengembang Aplikasi');

define('DB_FILE', dirname(__DIR__) . '/data/tusmart.sqlite');

// Sesi disimpan di folder aplikasi (data/sessions) — folder data/ harus dapat ditulis.
if (PHP_SAPI !== 'cli') {
    $sesiDir = dirname(__DIR__) . '/data/sessions';
    if (!is_dir($sesiDir)) { @mkdir($sesiDir, 0777, true); }
    if (is_dir($sesiDir) && is_writable($sesiDir)) {
        session_save_path($sesiDir);
    }
}

// Media proxy platform untuk unggahan berkas.
define('MEDIA_ENDPOINT', 'http://127.0.0.1:4310/api/app-media/upload');

// Batas unggahan.
define('BERKAS_MAKS_BYTE', 15 * 1024 * 1024);      // surat/arsip: gambar & dokumen 15 MB
define('FOTO_MAKS_BYTE', 15 * 1024 * 1024);
define('LOGO_MAKS_BYTE', 15 * 1024 * 1024);

// Ekstensi yang diterima.
const BERKAS_EKSTENSI = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'csv', 'txt', 'zip', 'jpg', 'jpeg', 'png', 'webp', 'gif', 'svg'];
const FOTO_EKSTENSI   = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'svg'];

// Tahun ajaran / semester bawaan (dapat diubah di Pengaturan Data Lembaga).
define('TA_DEFAULT', '2025/2026');
define('SEMESTER_DEFAULT', 'Ganjil');

// Kode klasifikasi surat keluar bawaan (dipakai penomoran otomatis).
define('KODE_SURAT_DEFAULT', 'TU');

// Kop SURAT RESMI (template Surat Rekomendasi Pindah / Surat Pindah / Lolos Butuh).
// Dapat diubah pada Pengaturan -> Pengaturan Data Lembaga.
define('YAYASAN_BAWAAN', 'YAYASAN SIROJUTH THOLIBIIN SIDOMUKTI');
define('AKREDITASI_BAWAAN', 'B');

// Jalur berkas basis data e-PERPUS (dipakai Inventaris Perpustakaan).
define('PERPUS_SUMBER_DEFAULT', dirname(dirname(__DIR__)) . '/perpustakaan/data/perpustakaan.sqlite');

// Jalur berkas basis data aplikasi ABSENSI GURU (dipakai menu Laporan Absensi-Guru).
// Dibaca HANYA-BACA: guru mengisi absensi di aplikasi Absensi Guru, laporannya langsung tampil di sini.
define('ABSGURU_SUMBER_DEFAULT', dirname(dirname(__DIR__)) . '/absensi-guru/data/absensi.sqlite');

// Batas jumlah baris cetak per halaman (hanya informasi pada lembar).
define('CETAK_BARIS_MAKS', 500);

// NOTULEN RAPAT — foto bukti kegiatan (mengikuti modul Notulen Rapat e-GURU).
define('NOTULEN_FOTO_MAKS', 2);        // maksimal 2 foto per notulen
define('NOTULEN_FOTO_CETAK', '4R');    // batas atas ukuran foto pada lembar cetak

// JAM KERJA (berlaku untuk SEMUA guru/tenaga kependidikan).
// Jam masuk melewati batas = TERLAMBAT; jam pulang sebelum batas = PULANG CEPAT.
// Nilai bawaan dapat diubah pada menu Absensi Guru & Tendik -> kartu "Jam Kerja".
define('JAM_MASUK_BAWAAN', '07:30');
define('JAM_PULANG_BAWAAN', '14:00');

// Nilai bawaan identitas lembaga (dipakai saat pemasangan pertama).
// Catatan: jalur sumber sarpras diisi setelah konstanta dibuat (tidak boleh memanggil
// fungsi di dalam const), lihat inc/init.php -> db_seed().
const LEMBAGA_BAWAAN = [
    'nama'            => 'MA. Sirojuth Tholibiin Mootilango',
    'npsn'            => '70027902',
    'nsm'             => '',
    'jenis'           => 'madrasah',
    'alamat'          => 'Jl. Dusun Gandaria, Desa Sidomukti, Kec. Mootilango',
    'desa'            => 'Sidomukti',
    'kecamatan'       => 'Mootilango',
    'kabupaten'       => 'Kabupaten Gorontalo',
    'provinsi'        => 'Gorontalo',
    'telepon'         => '085394726089',
    'email'           => 'ma.sirojuththolibiinmootilngo@gmail.com',
    'website'         => 'www.ma.stm.sch.id',
    'kepala'          => 'Supianto, S.Pd.I',
    'nip_kepala'      => '-',
    'tahun_ajaran'    => TA_DEFAULT,
    'semester'        => SEMESTER_DEFAULT,
    'kode_surat'      => KODE_SURAT_DEFAULT,
    'yayasan'         => YAYASAN_BAWAAN,
    'akreditasi'      => AKREDITASI_BAWAAN,
    'logo'            => '',
];
