<?php
/**
 * Kerangka tampilan TUSmart: sidebar bertingkat, bilah atas, flash pesan, dan kaki halaman.
 * Tema: biru profesional tata usaha.
 */
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

/** Ikon SVG sederhana (garis) berdasarkan nama. */
function ikon(string $nama, string $kelas = ''): string
{
    $p = [
        'dashboard'   => '<rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/>',
        'guru'        => '<circle cx="9" cy="8" r="3.2"/><path d="M3 20c0-3.3 2.7-5.5 6-5.5s6 2.2 6 5.5"/><path d="M16 8h5M18.5 5.5v5"/>',
        'absen'       => '<rect x="3" y="4" width="18" height="17" rx="2"/><path d="M8 2v4M16 2v4M3 10h18"/><path d="M8 15l2 2 4-4"/>',
        'siswa'       => '<path d="M12 3L2 8l10 5 10-5-10-5z"/><path d="M6 11v5c0 1.7 2.7 3 6 3s6-1.3 6-3v-5"/>',
        'buku'        => '<path d="M4 4h7a2 2 0 012 2v14a2 2 0 00-2-2H4z"/><path d="M20 4h-7a2 2 0 00-2 2v14a2 2 0 012-2h7z"/>',
        'surat_masuk' => '<path d="M3 6h18v12H3z"/><path d="M3 7l9 6 9-6"/>',
        'surat_keluar'=> '<path d="M4 4h11l5 5v11H4z"/><path d="M15 4v5h5"/><path d="M8 13h8M8 17h5"/>',
        'arsip'       => '<path d="M3 7h18v13H3z"/><path d="M2 3h20v4H2z"/><path d="M10 12h4"/>',
        'sarpras'     => '<path d="M3 21h18"/><path d="M5 21V8l7-5 7 5v13"/><path d="M9 21v-6h6v6"/>',
        'notulen'     => '<rect x="4" y="3" width="16" height="18" rx="2"/><path d="M8 8h8M8 12h8M8 16h5"/>',
        'akun'        => '<circle cx="12" cy="8" r="3.5"/><path d="M4.5 21c0-4 3.3-6.5 7.5-6.5s7.5 2.5 7.5 6.5"/>',
        'pengguna'    => '<circle cx="9" cy="8" r="3"/><path d="M2.5 20c0-3.3 2.9-5.5 6.5-5.5s6.5 2.2 6.5 5.5"/><path d="M17 11l2 2 3-3"/>',
        'lembaga'     => '<path d="M3 21h18"/><path d="M5 21V9l7-5 7 5v12"/><path d="M9 21v-5h6v5"/>',
        'cetak'       => '<path d="M6 9V3h12v6"/><rect x="3" y="9" width="18" height="8" rx="2"/><path d="M6 17h12v4H6z"/>',
        'unduh'       => '<path d="M12 3v12"/><path d="M7 11l5 5 5-5"/><path d="M4 21h16"/>',
        'unggah'      => '<path d="M12 21V9"/><path d="M7 13l5-5 5 5"/><path d="M4 4h16"/>',
        'tambah'      => '<path d="M12 5v14M5 12h14"/>',
        'keluar'      => '<path d="M15 4h4v16h-4"/><path d="M11 8l-4 4 4 4"/><path d="M3 12h8"/>',
        'menu'        => '<path d="M4 7h16M4 12h16M4 17h16"/>',
        'cek'         => '<path d="M4 12l5 5L20 6"/>',
        'kunci'       => '<rect x="4" y="10" width="16" height="11" rx="2"/><path d="M8 10V7a4 4 0 018 0v3"/><path d="M12 15v3"/>',
        'ubah'        => '<path d="M4 20h4l10-10-4-4L4 16z"/><path d="M14 6l4 4"/>',
        'salin'       => '<rect x="8" y="8" width="12" height="12" rx="2"/><path d="M16 8V5a1 1 0 00-1-1H5a1 1 0 00-1 1v10a1 1 0 001 1h3"/>',
        'hapus'       => '<path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="M6 6l1 15h10l1-15"/><path d="M10 11v6M14 11v6"/>',
        'saring'      => '<path d="M3 5h18l-7 8v6l-4 2v-8z"/>',
        'tutup'       => '<path d="M6 6l12 12M18 6L6 18"/>',
    ];
    $isi = $p[$nama] ?? $p['cek'];
    return '<svg class="ico' . ($kelas !== '' ? ' ' . $kelas : '') . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $isi . '</svg>';
}

/** Daftar menu sidebar: [judul seksi, [[berkas, label, ikon, khususSuper]]. */
function menu_sidebar(): array
{
    return [
        ['Utama', [
            ['index.php', 'Dashboard', 'dashboard', false],
        ]],
        ['Administrasi Kepegawaian', [
            ['guru.php', 'Data Guru & Tenaga Kependidikan', 'guru', false],
            ['absensi_guru.php', 'Absensi Guru & Tendik', 'absen', false],
            ['laporan_absensi.php', 'Laporan Absensi', 'cetak', false],
            ['laporan_absensi_guru.php', 'Laporan Absensi-Guru', 'absen', false],
            ['absensi_impor.php', 'Impor Kehadiran (PDF)', 'unggah', false],
        ]],
        ['Administrasi Kesiswaan', [
            ['siswa.php', 'Data Siswa', 'siswa', false],
            ['buku_induk.php', 'Buku Induk & Mutasi', 'buku', false],
            ['kenaikan.php', 'Kenaikan & Kelulusan', 'buku', false],
            ['alumni.php', 'Alumni', 'pengguna', false],
        ]],
        ['Administrasi Persuratan', [
            ['surat_masuk.php', 'Surat Masuk', 'surat_masuk', false],
            ['surat_keluar.php', 'Surat Keluar', 'surat_keluar', false],
            ['arsip.php', 'Arsip Digital', 'arsip', false],
        ]],
        ['Administrasi Sarana Prasarana', [
            ['sarpras.php', 'Inventaris Sarpras', 'sarpras', false],
            ['perpustakaan.php', 'Inventaris Perpustakaan', 'buku', false],
        ]],
        ['Administrasi Rapat', [
            ['notulen.php', 'Notulen Rapat', 'notulen', false],
        ]],
        ['Pengaturan', [
            ['pengaturan_akun.php', 'Pengaturan Akun', 'akun', false],
            ['pengguna.php', 'Manajemen Pengguna', 'pengguna', true],
            ['pengaturan_lembaga.php', 'Pengaturan Data Lembaga', 'lembaga', false],
        ]],
    ];
}

function halaman_aktif(): string
{
    return basename((string) ($_SERVER['SCRIPT_NAME'] ?? 'index.php'));
}

function page_top(string $judul, string $subjudul = ''): void
{
    $u = current_user();
    $aktif = halaman_aktif();
    $lembaga = lembaga_nama();
    $logo = setting('logo');
    ?><!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($judul) ?> · <?= e(APP_NAME) ?></title>
<link rel="stylesheet" href="assets/style.css?v=<?= e(APP_VERSION) ?>">
<script src="assets/vendor/chart.umd.min.js" defer></script>
<?php if (!empty($GLOBALS['__butuh_xlsx'])): ?>
<script src="assets/vendor/xlsx.full.min.js" defer></script>
<?php endif; ?>
<script src="assets/app.js?v=<?= e(APP_VERSION) ?>" defer></script>
</head>
<body>
<a class="lewati" href="#konten">Lewati ke konten</a>
<div class="kerangka">
  <aside class="sidebar" id="sidebar">
    <div class="merek">
      <?php if ($logo !== ''): ?>
        <img class="merek-logo" src="<?= e($logo) ?>" alt="Logo <?= e($lembaga) ?>">
      <?php else: ?>
        <span class="merek-logo merek-huruf">TU</span>
      <?php endif; ?>
      <div class="merek-teks">
        <strong><?= e(APP_NAME) ?></strong>
        <small>Tata Usaha Smart</small>
      </div>
    </div>
    <div class="merek-lembaga" title="<?= e($lembaga) ?>"><?= e($lembaga) ?></div>
    <nav class="nav" aria-label="Menu aplikasi">
      <?php foreach (menu_sidebar() as [$seksi, $item]): ?>
        <?php
        $tampil = [];
        foreach ($item as $it) {
            if (!empty($it[3]) && !is_super()) { continue; }
            $tampil[] = $it;
        }
        if (!$tampil) { continue; }
        ?>
        <div class="nav-seksi"><?= e($seksi) ?></div>
        <?php foreach ($tampil as [$berkas, $label, $ic, ]): ?>
          <a class="nav-item<?= $aktif === $berkas ? ' on' : '' ?>" href="<?= e($berkas) ?>">
            <?= ikon($ic) ?><span><?= e($label) ?></span>
          </a>
        <?php endforeach; ?>
      <?php endforeach; ?>
    </nav>
    <div class="sidebar-kaki">
      <div class="versi"><?= e(APP_NAME) ?> v<?= e(APP_VERSION) ?></div>
      <div class="versi">Dikembangkan oleh <?= e(APP_AUTHOR) ?></div>
    </div>
  </aside>

  <div class="utama">
    <header class="topbar">
      <button class="tombol-menu" type="button" data-menu aria-label="Buka menu" aria-expanded="false"><?= ikon('menu') ?></button>
      <div class="topbar-judul">
        <h1><?= e($judul) ?></h1>
        <?php if ($subjudul !== ''): ?><p><?= e($subjudul) ?></p><?php endif; ?>
      </div>
      <div class="topbar-kanan">
        <div class="pengguna">
          <?php $foto = (string) ($u['foto'] ?? ''); ?>
          <?php if ($foto !== ''): ?>
            <img class="avatar" src="<?= e($foto) ?>" alt="Foto <?= e($u['nama'] ?? '') ?>">
          <?php else: ?>
            <span class="avatar avatar-huruf"><?= e(strtoupper(substr((string) ($u['nama'] ?? 'U'), 0, 1))) ?></span>
          <?php endif; ?>
          <div class="pengguna-teks">
            <strong><?= e($u['nama'] ?? '') ?></strong>
            <small><?= e(role_label()) ?></small>
          </div>
        </div>
        <a class="btn btn-garis btn-kecil" href="logout.php" data-konfirmasi="Keluar dari aplikasi sekarang?"><?= ikon('keluar') ?> Keluar</a>
      </div>
    </header>

    <main class="konten" id="konten">
      <?= flash_render() ?>
<?php
}

function page_bottom(): void
{
    ?>
    </main>
    <footer class="kaki">
      <span><?= e(APP_NAME) ?> v<?= e(APP_VERSION) ?> · Sistem Tata Usaha Digital</span>
      <span><?= e(lembaga_nama()) ?></span>
    </footer>
  </div>
</div>
</body>
</html>
<?php
}

/** Kartu statistik dashboard. */
function kartu(string $label, string $nilai, string $ket = '', string $ikonNama = 'dashboard', string $kelas = ''): string
{
    return '<div class="kartu-stat ' . e($kelas) . '">'
        . '<div class="kartu-stat-ikon">' . ikon($ikonNama) . '</div>'
        . '<div class="kartu-stat-teks"><div class="kartu-stat-label">' . e($label) . '</div>'
        . '<div class="kartu-stat-nilai">' . e($nilai) . '</div>'
        . ($ket !== '' ? '<div class="kartu-stat-ket">' . e($ket) . '</div>' : '')
        . '</div></div>';
}

/** Kepala kartu dengan judul + aksi (tombol) di kanan. */
function kartu_buka(string $judul, string $aksi = '', string $ket = ''): void
{
    echo '<section class="card"><div class="card-kepala"><div><h2>' . e($judul) . '</h2>'
        . ($ket !== '' ? '<p class="card-ket">' . e($ket) . '</p>' : '') . '</div>'
        . ($aksi !== '' ? '<div class="card-aksi">' . $aksi . '</div>' : '') . '</div>';
}

function kartu_tutup(): void
{
    echo '</section>';
}

/** Pilihan <option> dari array nilai => label. */
function opsi(array $daftar, ?string $pilih, string $kosong = ''): string
{
    $out = $kosong !== '' ? '<option value="">' . e($kosong) . '</option>' : '';
    foreach ($daftar as $nilai => $label) {
        $out .= '<option value="' . e((string) $nilai) . '"' . ($pilih !== null && (string) $pilih === (string) $nilai ? ' selected' : '') . '>' . e($label) . '</option>';
    }
    return $out;
}
