<?php
/**
 * Titik masuk tunggal TUSmart.
 */
declare(strict_types=1);

require_once __DIR__ . '/config.php';

if (PHP_SAPI !== 'cli' && !headers_sent()) {
    header("Content-Security-Policy: frame-ancestors 'self'; object-src 'none'; base-uri 'self'; form-action 'self'");
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=()');
}

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/seed_data.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/siswa_induk.php';   // Buku Induk Siswa: medan, penyimpanan, kafer
require_once __DIR__ . '/sarpras.php';
require_once __DIR__ . '/perpustakaan.php';   // Inventaris Perpustakaan (baca-saja dari e-PERPUS)
require_once __DIR__ . '/absguru.php';       // Laporan Absensi-Guru (baca-saja dari aplikasi Absensi Guru)
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/cetak.php';
require_once __DIR__ . '/surat_jenis.php';   // jenis & medan template surat keluar

db();
ensure_schema();
