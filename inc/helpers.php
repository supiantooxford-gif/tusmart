<?php
/**
 * Fungsi bantu TUSmart: escape, query, sesi/CSRF, pengaturan lembaga, unggahan berkas,
 * tanggal Indonesia, penomoran surat, dan log aktivitas.
 */
declare(strict_types=1);

/* ============================== DASAR ============================== */

function e($teks): string
{
    return htmlspecialchars((string) $teks, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function q(string $sql, array $arg = []): PDOStatement
{
    global $pdo;
    $st = $pdo->prepare($sql);
    $st->execute($arg);
    return $st;
}

function satu(string $sql, array $arg = [])
{
    $r = q($sql, $arg)->fetch(PDO::FETCH_ASSOC);
    return $r === false ? null : $r;
}

function kolom(string $sql, array $arg = [])
{
    $v = q($sql, $arg)->fetchColumn();
    return $v === false ? null : $v;
}

function semua(string $sql, array $arg = []): array
{
    return q($sql, $arg)->fetchAll(PDO::FETCH_ASSOC);
}

function redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

function f(string $kunci, string $bawaan = ''): string
{
    $v = $_GET[$kunci] ?? $_POST[$kunci] ?? $bawaan;
    return is_string($v) ? trim($v) : $bawaan;
}

/**
 * Nilai aksi dari BODY POST saja. JANGAN memakai f('aksi') di penangan POST:
 * alamat seperti `?aksi=baru` akan menang atas isi formulir sehingga aksi simpan
 * tidak pernah dijalankan (jebakan yang pernah terjadi).
 */
function aksi_post(): string
{
    return trim((string) ($_POST['aksi'] ?? ''));
}

function idf(string $kunci): int
{
    return (int) ($_GET[$kunci] ?? $_POST[$kunci] ?? 0);
}

/* ============================== FLASH ============================== */

function flash(string $pesan, string $jenis = 'ok'): void
{
    $_SESSION['flash'][] = ['pesan' => $pesan, 'jenis' => $jenis];
}

function flash_ambil(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

function flash_render(): string
{
    $out = '';
    foreach (flash_ambil() as $f) {
        $kelas = ($f['jenis'] ?? 'ok') === 'err' ? 'alert alert-err' : (($f['jenis'] ?? '') === 'warning' ? 'alert alert-warn' : 'alert alert-ok');
        $out .= '<div class="' . $kelas . '">' . e($f['pesan']) . '</div>';
    }
    return $out;
}

/* ============================== CSRF ============================== */

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(24));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function csrf_valid(): bool
{
    $t = (string) ($_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
    return $t !== '' && !empty($_SESSION['csrf']) && hash_equals((string) $_SESSION['csrf'], $t);
}

function csrf_wajib(): void
{
    if (!csrf_valid()) {
        http_response_code(419);
        page_top('Sesi Kedaluwarsa');
        echo '<div class="card"><div class="alert alert-err">Sesi formulir sudah kedaluwarsa. Muat ulang halaman lalu coba lagi.</div>'
            . '<a class="btn" href="index.php">Kembali ke Dashboard</a></div>';
        page_bottom();
        exit;
    }
}

/* ============================== PENGGUNA ============================== */

function current_user(): ?array
{
    static $cache = false;
    if ($cache !== false) {
        return $cache;
    }
    $uid = (int) ($_SESSION['uid'] ?? 0);
    if ($uid === 0) {
        return $cache = null;
    }
    $u = satu('SELECT * FROM users WHERE id = ?', [$uid]);
    if (!$u || (int) $u['aktif'] !== 1) {
        return $cache = null;
    }
    return $cache = $u;
}

function is_login(): bool
{
    return current_user() !== null;
}

function is_super(): bool
{
    $u = current_user();
    return $u !== null && $u['role'] === 'super';
}

function is_kepala_tu(): bool
{
    $u = current_user();
    return $u !== null && $u['role'] === 'kepala_tu';
}

function role_label(?string $role = null): string
{
    $u = current_user();
    $role = $role ?? ($u['role'] ?? '');
    return match ($role) {
        'super'     => 'Super Admin',
        'kepala_tu' => 'Kepala Tata Usaha',
        default     => 'Pengguna',
    };
}

function require_login(): void
{
    if (!is_login()) {
        redirect('login.php');
    }
    log_sesi_ping();
}

function require_super(): void
{
    require_login();
    if (!is_super()) {
        http_response_code(403);
        page_top('Akses Ditolak');
        echo '<div class="card"><div class="alert alert-err">Halaman ini khusus <b>Super Admin</b>.</div>'
            . '<a class="btn" href="index.php">Kembali ke Dashboard</a></div>';
        page_bottom();
        exit;
    }
}

function require_kepala_atau_super(): void
{
    require_login();
}

/* ============================== PENGATURAN LEMBAGA ============================== */

function setting(string $kunci, string $bawaan = ''): string
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        foreach (semua('SELECT kunci, nilai FROM pengaturan') as $r) {
            $cache[$r['kunci']] = (string) $r['nilai'];
        }
    }
    if (array_key_exists($kunci, $cache) && $cache[$kunci] !== '') {
        return $cache[$kunci];
    }
    if ($kunci === 'sarpras_sumber') {
        return dirname(dirname(__DIR__)) . '/e-guru/data/eguru.sqlite';
    }
    if ($kunci === 'perpus_sumber') {
        return PERPUS_SUMBER_DEFAULT;
    }
    if ($kunci === 'absguru_sumber') {
        return ABSGURU_SUMBER_DEFAULT;
    }
    if (array_key_exists($kunci, LEMBAGA_BAWAAN) && (string) LEMBAGA_BAWAAN[$kunci] !== '') {
        return (string) LEMBAGA_BAWAAN[$kunci];
    }
    return $bawaan;
}

function set_setting(string $kunci, string $nilai): void
{
    q('INSERT INTO pengaturan (kunci, nilai) VALUES (?, ?) ON CONFLICT(kunci) DO UPDATE SET nilai = excluded.nilai', [$kunci, $nilai]);
}

function lembaga_nama(): string
{
    return setting('nama');
}

function is_madrasah(): bool
{
    return setting('jenis', 'madrasah') === 'madrasah';
}

/** Istilah lembaga yang dipakai pada judul/label: "Madrasah" atau "Sekolah". */
function istilah_lembaga(): string
{
    return is_madrasah() ? 'Madrasah' : 'Sekolah';
}

function kepala_lembaga(): string
{
    return setting('kepala', '-');
}

function nip_kepala(): string
{
    $nip = trim(setting('nip_kepala'));
    return ($nip === '' || $nip === '-') ? '' : $nip;
}

/* ============================== TANGGAL ============================== */

function bulan_id(): array
{
    return [1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
}

function nama_hari(): array
{
    return [1 => 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu'];
}

function tanggal_id(?string $ymd, bool $denganHari = false): string
{
    $ymd = trim((string) $ymd);
    if ($ymd === '' || str_starts_with($ymd, '0000')) {
        return '-';
    }
    $ts = strtotime($ymd);
    if ($ts === false) {
        return '-';
    }
    $b = bulan_id();
    $teks = date('j', $ts) . ' ' . $b[(int) date('n', $ts)] . ' ' . date('Y', $ts);
    if ($denganHari) {
        $h = nama_hari();
        $teks = $h[(int) date('N', $ts)] . ', ' . $teks;
    }
    return $teks;
}

function tanggal_pendek(?string $ymd): string
{
    $ymd = trim((string) $ymd);
    return $ymd === '' ? '-' : date('d/m/Y', strtotime($ymd));
}

function tanggal_sah(?string $ymd): bool
{
    $ymd = trim((string) $ymd);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ymd)) {
        return false;
    }
    [$y, $m, $d] = array_map('intval', explode('-', $ymd));
    return checkdate($m, $d, $y);
}

function tanggal_hari_ini(): string
{
    return date('Y-m-d');
}

/**
 * Membaca tanggal dari berkas impor secara LENIENT:
 * 2011-04-12, 2011/4/12, 20110412, 12/04/2011, 12-4-2011, 12.04.2011, "12 April 2011",
 * "12 Des 2010", dan angka serial Excel (40615). Mengembalikan '' bila tak dikenali.
 */
function tgl_impor($v): string
{
    $v = trim((string) $v);
    if ($v === '') { return ''; }
    if (is_numeric($v) && (float) $v > 1000 && (float) $v < 80000) {
        $ts = (int) round(((float) $v - 25569) * 86400);
        return gmdate('Y-m-d', $ts);
    }
    if (tanggal_sah($v)) { return $v; }
    if (preg_match('/^(\d{4})[\/.](\d{1,2})[\/.](\d{1,2})$/', $v, $m)) {
        $y = (int) $m[1]; $mo = (int) $m[2]; $d = (int) $m[3];
        return checkdate($mo, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : '';
    }
    if (preg_match('/^(\d{8})$/', $v, $m)) {
        $y = (int) substr($v, 0, 4); $mo = (int) substr($v, 4, 2); $d = (int) substr($v, 6, 2);
        return checkdate($mo, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : '';
    }
    if (preg_match('/^(\d{1,2})[\/.\-](\d{1,2})[\/.\-](\d{2,4})$/', $v, $m)) {
        $a = (int) $m[1]; $b = (int) $m[2]; $y = (int) $m[3];
        if ($y < 100) { $y += 2000; }
        // hari lebih dulu (kebiasaan Indonesia); tukar bila jelas bulan lebih dulu
        $d = $a; $mo = $b;
        if ($a > 12 && $b <= 12) { $d = $a; $mo = $b; }
        elseif ($b > 12 && $a <= 12) { $d = $b; $mo = $a; }
        return checkdate($mo, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : '';
    }
    $teks = strtolower(str_replace(',', ' ', $v));
    $bulan = ['januari' => 1, 'jan' => 1, 'februari' => 2, 'feb' => 2, 'maret' => 3, 'mar' => 3, 'april' => 4, 'apr' => 4,
        'mei' => 5, 'juni' => 6, 'jun' => 6, 'juli' => 7, 'jul' => 7, 'agustus' => 8, 'agu' => 8, 'agt' => 8, 'aug' => 8,
        'september' => 9, 'sep' => 9, 'sept' => 9, 'oktober' => 10, 'okt' => 10, 'oct' => 10, 'november' => 11, 'nov' => 11,
        'desember' => 12, 'des' => 12, 'dec' => 12];
    if (preg_match('/(\d{1,2})\s+([a-z]+)\s+(\d{4})/', $teks, $m) && isset($bulan[$m[2]])) {
        $d = (int) $m[1]; $mo = $bulan[$m[2]]; $y = (int) $m[3];
        return checkdate($mo, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : '';
    }
    if (preg_match('/([a-z]+)\s+(\d{1,2}),?\s+(\d{4})/', $teks, $m) && isset($bulan[$m[1]])) {
        $d = (int) $m[2]; $mo = $bulan[$m[1]]; $y = (int) $m[3];
        return checkdate($mo, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : '';
    }
    $ts = strtotime($v);
    return $ts !== false ? date('Y-m-d', $ts) : '';
}

function bulan_romawi(int $n): string
{
    $peta = [1 => 'I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X', 'XI', 'XII'];
    return $peta[$n] ?? (string) $n;
}

function waktu_id(?string $dt): string
{
    if (!$dt) { return '-'; }
    $ts = strtotime($dt);
    return $ts === false ? '-' : date('d/m/Y H:i', $ts);
}

/** Memotong teks (UTF-8 aman) tanpa bergantung pada ekstensi mbstring. */
function potong(?string $teks, int $maks = 90): string
{
    $teks = trim((string) $teks);
    if ($teks === '') { return ''; }
    if (function_exists('mb_substr')) {
        return mb_substr($teks, 0, $maks, 'UTF-8');
    }
    if (function_exists('mb_strlen') && strlen($teks) <= $maks) { return $teks; }
    $out = substr($teks, 0, $maks);
    // jangan memotong di tengah karakter multibyte
    while ($out !== '' && (ord(substr($out, -1)) & 0xC0) === 0x80) {
        $out = substr($out, 0, -1);
    }
    return $out;
}



/* ============================== NOTULEN RAPAT ============================== */

function notulen_by_id(int $id): ?array
{
    return $id > 0 ? satu('SELECT * FROM notulen WHERE id = ?', [$id]) : null;
}

/** Peserta rapat (daftar hadir) sebuah notulen; nama/NIP/jabatan adalah snapshot saat disimpan. */
function notulen_peserta(int $notulenId): array
{
    return $notulenId > 0 ? semua('SELECT * FROM notulen_hadir WHERE notulen_id = ? ORDER BY urutan, id', [$notulenId]) : [];
}

function notulen_jumlah_hadir(int $notulenId): int
{
    return $notulenId > 0 ? (int) kolom('SELECT COUNT(*) FROM notulen_hadir WHERE notulen_id = ?', [$notulenId]) : 0;
}

/** Foto bukti kegiatan sebuah notulen (maksimal NOTULEN_FOTO_MAKS), urut penyimpanan. */
function notulen_foto(int $notulenId): array
{
    return $notulenId > 0 ? semua('SELECT * FROM notulen_foto WHERE notulen_id = ? ORDER BY urutan, id', [$notulenId]) : [];
}

function notulen_jumlah_foto(int $notulenId): int
{
    return $notulenId > 0 ? (int) kolom('SELECT COUNT(*) FROM notulen_foto WHERE notulen_id = ?', [$notulenId]) : 0;
}

function notulen_sisa_slot_foto(int $notulenId): int
{
    return max(0, NOTULEN_FOTO_MAKS - notulen_jumlah_foto($notulenId));
}

/** Merapikan nomor urut foto menjadi 0,1,2,… setelah ada foto yang dihapus. */
function notulen_foto_urutkan_ulang(int $notulenId): void
{
    $urut = 0;
    foreach (notulen_foto($notulenId) as $f) {
        q('UPDATE notulen_foto SET urutan = ? WHERE id = ? AND notulen_id = ?', [$urut, (int) $f['id'], $notulenId]);
        $urut++;
    }
}

/**
 * Ukuran bingkai foto bukti kegiatan pada LEMBAR CETAK.
 * Seluruh dokumentasi harus muat SATU lembar A4 melintang, jadi rasio 3:2 dengan batas
 * lebar/tinggi (NOTULEN_FOTO_CETAK = 3R/4R hanya menjadi batas atas).
 */
function foto_cetak_ukuran(): array
{
    $daftar = ['4R' => [101.6, 152.4], '3R' => [88.9, 127.0]];
    $nama = strtoupper((string) (defined('NOTULEN_FOTO_CETAK') ? NOTULEN_FOTO_CETAK : '4R'));
    if (!isset($daftar[$nama])) { $nama = '4R'; }
    [$lebarAsli, $tinggiAsli] = $daftar[$nama];
    // Anggaran satu halaman A4 melintang (±277 × 190 mm): foto 2 sebaris + kop/judul/identitas/
    // keterangan/tanda tangan. Tinggi foto dibatasi 60 mm supaya SELURUH dokumentasi muat 1 lembar.
    $tinggiBatas = 60.0;
    $lebar = min(128.0, $lebarAsli);
    $tinggi = round($lebar * 2 / 3, 1);
    if ($tinggi > $tinggiBatas) { $tinggi = $tinggiBatas; $lebar = round($tinggi * 3 / 2, 1); }
    return ['nama' => $nama, 'lebar' => $lebar, 'tinggi' => $tinggi, 'kolom' => 2,
        'asli' => [$lebarAsli, $tinggiAsli], 'satu_lembar' => true];
}

/** NIP untuk daftar hadir: NIP bila ada, kalau tidak NUPTK. */
function notulen_nip_guru(array $g): string
{
    $nip = trim((string) ($g['nip'] ?? ''));
    if ($nip === '' || in_array($nip, ['-', '–', '—', '.'], true)) {
        $nip = trim((string) ($g['nuptk'] ?? ''));
        if (in_array($nip, ['-', '–', '—', '.'], true)) { $nip = ''; }
    }
    return $nip;
}

/* ============================== JAM KERJA & ABSENSI ============================== */

/** Batas jam kerja yang berlaku untuk SEMUA guru/tenaga kependidikan. */
function jam_kerja(): array
{
    $masuk = trim(setting('jam_masuk_batas', JAM_MASUK_BAWAAN));
    $pulang = trim(setting('jam_pulang_batas', JAM_PULANG_BAWAAN));
    if (!preg_match('/^\d{2}:\d{2}$/', $masuk)) { $masuk = JAM_MASUK_BAWAAN; }
    if (!preg_match('/^\d{2}:\d{2}$/', $pulang)) { $pulang = JAM_PULANG_BAWAAN; }
    return ['masuk' => $masuk, 'pulang' => $pulang];
}

/** Mengubah "HH:MM" / "HH:MM:SS" menjadi jumlah menit sejak 00:00 (null bila kosong/tidak sah). */
function menit_jam(?string $jam): ?int
{
    $jam = trim((string) $jam);
    if (!preg_match('/^(\d{1,2}):(\d{2})/', $jam, $m)) {
        return null;
    }
    $h = (int) $m[1];
    $i = (int) $m[2];
    if ($h > 23 || $i > 59) {
        return null;
    }
    return $h * 60 + $i;
}

/** Jam dibulatkan ke menit tanpa detik ("07:50:41" -> "07:50"). */
function jam_rapi(?string $jam): string
{
    $m = menit_jam($jam);
    if ($m === null) { return ''; }
    return sprintf('%02d:%02d', intdiv($m, 60), $m % 60);
}

/** Kategori jam MASUK: 'tepat' | 'telat' | '' (kosong). Batas mengikuti jam kerja HARI itu. */
function absensi_kategori_masuk(?string $jam, ?string $batas = null, ?string $tanggal = null): string
{
    $m = menit_jam($jam);
    if ($m === null) { return ''; }
    $batas = $batas ?? jam_kerja_hari($tanggal)['masuk'];
    $b = menit_jam($batas);
    if ($b === null) { return ''; }
    return $m > $b ? 'telat' : 'tepat';
}

/** Kategori jam PULANG: 'tepat' | 'cepat' | '' (kosong). Batas mengikuti jam kerja HARI itu. */
function absensi_kategori_pulang(?string $jam, ?string $batas = null, ?string $tanggal = null): string
{
    $m = menit_jam($jam);
    if ($m === null) { return ''; }
    $batas = $batas ?? jam_kerja_hari($tanggal)['pulang'];
    $b = menit_jam($batas);
    if ($b === null) { return ''; }
    return $m < $b ? 'cepat' : 'tepat';
}

/** Menit keterlambatan (0 bila tidak terlambat). */
function absensi_menit_terlambat(?string $jam, ?string $batas = null, ?string $tanggal = null): int
{
    $m = menit_jam($jam);
    $b = menit_jam($batas ?? jam_kerja_hari($tanggal)['masuk']);
    if ($m === null || $b === null) { return 0; }
    return max(0, $m - $b);
}

/** Label tampilan kategori ("Tepat Waktu", "Terlambat", "Pulang Cepat"). */
function absensi_label_kategori(?string $kategori): string
{
    return match ($kategori) {
        'telat' => 'Telat',
        'tepat' => 'Tepat Waktu',
        'cepat' => 'Pulang Cepat',
        default => '',
    };
}

/**
 * Kategori efektif satu baris absensi: memakai kolom tersimpan bila ada, kalau tidak
 * dihitung dari jam + jam kerja saat ini (baris lama sebelum skema v3).
 */
function absensi_kategori_baris(array $r): array
{
    $masuk = (string) ($r['kategori_masuk'] ?? '');
    $pulang = (string) ($r['kategori_pulang'] ?? '');
    $tgl = (string) ($r['tanggal'] ?? '');
    if ($masuk === '') { $masuk = absensi_kategori_masuk($r['jam_masuk'] ?? '', null, $tgl); }
    if ($pulang === '') { $pulang = absensi_kategori_pulang($r['jam_keluar'] ?? '', null, $tgl); }
    $menit = (int) ($r['menit_terlambat'] ?? 0);
    if ($menit === 0) { $menit = absensi_menit_terlambat($r['jam_masuk'] ?? '', null, $tgl); }
    return ['masuk' => $masuk, 'pulang' => $pulang, 'menit' => $menit];
}

/** Menyimpan pengaturan jam kerja (bawaan/umum, batas masuk & pulang). */
function jam_kerja_simpan(string $masuk, string $pulang): bool
{
    if (!preg_match('/^\d{2}:\d{2}$/', $masuk) || !preg_match('/^\d{2}:\d{2}$/', $pulang)) {
        return false;
    }
    if (menit_jam($masuk) === null || menit_jam($pulang) === null) {
        return false;
    }
    set_setting('jam_masuk_batas', $masuk);
    set_setting('jam_pulang_batas', $pulang);
    return true;
}

/** Peta jam kerja per hari (1=Senin … 7=Minggu) — dibaca sekali per permintaan. */
function jam_kerja_peta(): array
{
    static $peta = null;
    if ($peta !== null) {
        return $peta;
    }
    $dasar = jam_kerja();
    $peta = [];
    for ($h = 1; $h <= 7; $h++) {
        $peta[$h] = ['masuk' => $dasar['masuk'], 'pulang' => $dasar['pulang'], 'libur' => 0];
    }
    foreach (semua('SELECT * FROM jam_kerja') as $r) {
        $h = (int) $r['hari'];
        if ($h < 1 || $h > 7) { continue; }
        $m = jam_rapi($r['masuk']);
        $p = jam_rapi($r['pulang']);
        $peta[$h] = [
            'masuk'  => $m !== '' ? $m : $dasar['masuk'],
            'pulang' => $p !== '' ? $p : $dasar['pulang'],
            'libur'  => (int) $r['libur'] === 1 ? 1 : 0,
        ];
    }
    return $peta;
}

/** Daftar hari libur manual: [tanggal => keterangan] (dibaca sekali per permintaan). */
function hari_libur_peta(): array
{
    static $peta = null;
    if ($peta !== null) {
        return $peta;
    }
    $peta = [];
    foreach (semua('SELECT tanggal, keterangan FROM hari_libur ORDER BY tanggal') as $r) {
        $peta[(string) $r['tanggal']] = trim((string) $r['keterangan']);
    }
    return $peta;
}

/**
 * Jadwal satu tanggal: jam masuk/pulang yang berlaku + keadaan libur.
 * Libur dapat berasal dari LIBUR MINGGUAN (jam_kerja.libur) atau HARI LIBUR MANUAL.
 */
function jam_kerja_hari(?string $tanggal = null): array
{
    $tanggal = tanggal_sah((string) $tanggal) ? (string) $tanggal : tanggal_hari_ini();
    $hari = (int) date('N', strtotime($tanggal));
    $nama = nama_hari();
    $peta = jam_kerja_peta();
    $j = $peta[$hari] ?? ['masuk' => JAM_MASUK_BAWAAN, 'pulang' => JAM_PULANG_BAWAAN, 'libur' => 0];
    $hasil = [
        'tanggal' => $tanggal, 'hari' => $hari, 'nama' => $nama[$hari] ?? '',
        'masuk' => $j['masuk'], 'pulang' => $j['pulang'],
        'libur' => (int) $j['libur'] === 1, 'libur_mingguan' => (int) $j['libur'] === 1,
        'keterangan' => (int) $j['libur'] === 1 ? 'Libur mingguan (' . ($nama[$hari] ?? '') . ')' : '',
    ];
    $manual = hari_libur_peta();
    if (array_key_exists($tanggal, $manual)) {
        $hasil['libur'] = true;
        $hasil['libur_manual'] = true;
        $hasil['keterangan'] = 'Libur: ' . ($manual[$tanggal] !== '' ? $manual[$tanggal] : 'hari libur');
    }
    return $hasil;
}

/** Apakah tanggal itu hari libur (mingguan atau manual)? */
function absensi_libur_tanggal(?string $tanggal): bool
{
    return (bool) jam_kerja_hari($tanggal)['libur'];
}

/** Menyimpan jam kerja SATU hari. */
function jam_kerja_simpan_hari(int $hari, string $masuk, string $pulang, bool $libur): bool
{
    if ($hari < 1 || $hari > 7) { return false; }
    $masuk = jam_rapi($masuk);
    $pulang = jam_rapi($pulang);
    if ($masuk === '' || $pulang === '') { return false; }
    q('INSERT INTO jam_kerja (hari, masuk, pulang, libur, diubah) VALUES (?,?,?,?,?)
       ON CONFLICT(hari) DO UPDATE SET masuk = excluded.masuk, pulang = excluded.pulang,
         libur = excluded.libur, diubah = excluded.diubah',
        [$hari, $masuk, $pulang, $libur ? 1 : 0, date('Y-m-d H:i:s')]);
    return true;
}

/** Menambah hari libur manual (tanggal + keterangan). */
function hari_libur_tambah(string $tanggal, string $keterangan = ''): bool
{
    if (!tanggal_sah($tanggal)) { return false; }
    q('INSERT INTO hari_libur (tanggal, keterangan, oleh, dibuat) VALUES (?,?,?,?)
       ON CONFLICT(tanggal) DO UPDATE SET keterangan = excluded.keterangan, oleh = excluded.oleh',
        [$tanggal, substr(trim($keterangan), 0, 150), current_user()['nama'] ?? '', date('Y-m-d H:i:s')]);
    return true;
}

function hari_libur_hapus(int $id): bool
{
    $r = satu('SELECT id FROM hari_libur WHERE id = ?', [$id]);
    if (!$r) { return false; }
    q('DELETE FROM hari_libur WHERE id = ?', [$id]);
    return true;
}

/** Semua rekaman absensi satu bulan: [guru_id][tanggal(1..31)] = baris. */
function absensi_bulan_data(string $bulan): array
{
    $awal = $bulan . '-01';
    $akhir = date('Y-m-t', strtotime($awal));
    $peta = [];
    foreach (semua('SELECT * FROM absensi_guru WHERE tanggal BETWEEN ? AND ?', [$awal, $akhir]) as $r) {
        $peta[(int) $r['guru_id']][(int) substr((string) $r['tanggal'], 8, 2)] = $r;
    }
    return $peta;
}

/** Rekap per guru satu bulan: jumlah tiap status + jumlah terlambat + jumlah pulang cepat. */
function absensi_rekap_bulan(string $bulan, ?array $guru = null): array
{
    $guru = $guru ?? guru_list(true);
    $awal = $bulan . '-01';
    $akhir = date('Y-m-t', strtotime($awal));
    $rekap = [];
    foreach ($guru as $g) {
        $rekap[(int) $g['id']] = ['H' => 0, 'T' => 0, 'I' => 0, 'S' => 0, 'C' => 0, 'A' => 0,
            'n' => 0, 'telat' => 0, 'cepat' => 0, 'menit' => 0];
    }
    foreach (semua('SELECT * FROM absensi_guru WHERE tanggal BETWEEN ? AND ?', [$awal, $akhir]) as $r) {
        $gid = (int) $r['guru_id'];
        if (!isset($rekap[$gid])) { continue; }
        $k = absensi_kategori_baris($r);
        $st = (string) $r['status'];
        if (isset($rekap[$gid][$st])) { $rekap[$gid][$st]++; }
        if ($k['masuk'] === 'telat') { $rekap[$gid]['telat']++; $rekap[$gid]['menit'] += $k['menit']; }
        if ($k['pulang'] === 'cepat') { $rekap[$gid]['cepat']++; }
        $rekap[$gid]['n']++;
    }
    return $rekap;
}

/** Jam masuk/pulang hari ini dari rekaman; dipakai kartu dashboard. */
function absensi_hari_ini_ringkas(?string $tanggal = null): array
{
    $tanggal = $tanggal ?? tanggal_hari_ini();
    $r = ['direkam' => 0, 'hadir' => 0, 'telat' => 0, 'izin' => 0, 'sakit' => 0, 'cuti' => 0, 'alpha' => 0, 'cepat' => 0];
    foreach (semua('SELECT * FROM absensi_guru WHERE tanggal = ?', [$tanggal]) as $b) {
        $k = absensi_kategori_baris($b);
        $r['direkam']++;
        $st = (string) $b['status'];
        if ($st === 'H' || $st === 'T') { $r['hadir']++; } else { $r[strtolower($st)] = ($r[strtolower($st)] ?? 0) + 1; }
        if ($k['masuk'] === 'telat' || $st === 'T') { $r['telat']++; }
        if ($k['pulang'] === 'cepat') { $r['cepat']++; }
    }
    return $r;
}

/* ============================== BERKAS / UNGGAHAN ============================== */

function media_upload(string $bytes, string $filename): array
{
    if ($bytes === '') {
        return ['ok' => false, 'pesan' => 'Berkas kosong.'];
    }
    $tokenFile = dirname(__DIR__) . '/.vibecoder-media-token';
    if (!is_file($tokenFile) || !is_readable($tokenFile)) {
        return ['ok' => false, 'pesan' => 'Layanan penyimpanan berkas belum siap (token unggah belum tersedia).'];
    }
    $token = trim((string) @file_get_contents($tokenFile));
    if ($token === '') {
        return ['ok' => false, 'pesan' => 'Layanan penyimpanan berkas belum siap (token unggah kosong).'];
    }
    $endpoint = MEDIA_ENDPOINT . '?filename=' . rawurlencode($filename);
    $body = false;
    $code = 0;
    if (function_exists('curl_init')) {
        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $bytes,
            CURLOPT_HTTPHEADER     => ['X-App-Media-Token: ' . $token, 'Content-Type: application/octet-stream'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 180,
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
    } else {
        $ctx = stream_context_create(['http' => [
            'method'        => 'POST',
            'header'        => "X-App-Media-Token: " . $token . "\r\nContent-Type: application/octet-stream\r\n",
            'content'       => $bytes,
            'timeout'       => 180,
            'ignore_errors' => true,
        ]]);
        $body = @file_get_contents($endpoint, false, $ctx);
        if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
            $code = (int) $m[1];
        }
    }
    if ($body === false) {
        return ['ok' => false, 'pesan' => 'Gagal menghubungi layanan penyimpanan berkas.'];
    }
    $json = json_decode((string) $body, true);
    if ($code >= 200 && $code < 300 && is_array($json) && !empty($json['ok']) && !empty($json['url'])) {
        return ['ok' => true, 'url' => (string) $json['url']];
    }
    $pesan = is_array($json) && !empty($json['message']) ? (string) $json['message'] : 'Unggahan ditolak layanan penyimpanan (kode ' . $code . ').';
    return ['ok' => false, 'pesan' => $pesan];
}

function ekstensi_berkas(string $nama): string
{
    return strtolower((string) pathinfo($nama, PATHINFO_EXTENSION));
}

/** Format rupiah: 1500 → "Rp 1.500". */
function rupiah($n): string
{
    return 'Rp ' . number_format((float) $n, 0, ',', '.');
}

function ukuran_teks(?int $byte): string
{
    $byte = (int) $byte;
    if ($byte <= 0) { return '-'; }
    if ($byte < 1024) { return $byte . ' B'; }
    if ($byte < 1024 * 1024) { return number_format($byte / 1024, 0, ',', '.') . ' KB'; }
    return number_format($byte / (1024 * 1024), 1, ',', '.') . ' MB';
}

/**
 * Memeriksa ISI berkas unggahan (bukan hanya ekstensinya).
 * @return string pesan galat, atau '' bila sah.
 */
function periksa_isi_berkas(string $path, string $ext): string
{
    $ukuran = (int) @filesize($path);
    if ($ukuran <= 0) {
        return 'Berkas kosong.';
    }
    $awal = (string) @file_get_contents($path, false, null, 0, 512);
    if (in_array($ext, ['pdf'], true)) {
        if (!str_starts_with($awal, '%PDF')) {
            return 'Berkas PDF tidak sah (isi berkas bukan PDF).';
        }
    } elseif (in_array($ext, ['docx', 'xlsx', 'pptx', 'zip'], true)) {
        if (!str_starts_with($awal, 'PK') || $ukuran < 512) {
            return 'Berkas ' . strtoupper($ext) . ' tidak sah (berkas rusak atau hanya berganti nama).';
        }
    } elseif (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
        $info = @getimagesize($path);
        if ($info === false) {
            return 'Berkas gambar tidak sah.';
        }
    } elseif ($ext === 'svg') {
        if (stripos($awal, '<svg') === false) {
            return 'Berkas SVG tidak sah.';
        }
    } elseif (in_array($ext, ['csv', 'txt'], true)) {
        if (strpos($awal, "\0") !== false) {
            return 'Berkas teks mengandung data biner (bukan teks biasa).';
        }
    }
    return '';
}

/**
 * Mengunggah satu berkas dari $_FILES (lewat media proxy platform).
 * @param string $field nama input berkas
 * @param string[]|null $exts ekstensi yang diterima (null = BERKAS_EKSTENSI)
 * @return array{ok:bool,pesan?:string,url?:string,nama?:string,ukuran?:int}
 */
function unggah_berkas(string $field, ?array $exts = null, ?int $maks = null): array
{
    $exts = $exts ?? BERKAS_EKSTENSI;
    $maks = $maks ?? BERKAS_MAKS_BYTE;
    if (empty($_FILES[$field]) || ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['ok' => false, 'pesan' => '', 'kosong' => true];
    }
    $f = $_FILES[$field];
    if ((int) $f['error'] !== UPLOAD_ERR_OK) {
        $pesan = match ((int) $f['error']) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Ukuran berkas melebihi batas kirim server.',
            default => 'Berkas gagal diunggah (kode ' . (int) $f['error'] . ').',
        };
        return ['ok' => false, 'pesan' => $pesan];
    }
    $nama = (string) $f['name'];
    $ext = ekstensi_berkas($nama);
    if (!in_array($ext, $exts, true)) {
        return ['ok' => false, 'pesan' => 'Jenis berkas tidak diizinkan. Yang diterima: ' . strtoupper(implode(', ', $exts)) . '.'];
    }
    if ((int) $f['size'] > $maks) {
        return ['ok' => false, 'pesan' => 'Ukuran berkas melebihi ' . ukuran_teks($maks) . '.'];
    }
    $galat = periksa_isi_berkas((string) $f['tmp_name'], $ext);
    if ($galat !== '') {
        return ['ok' => false, 'pesan' => $galat];
    }
    $bytes = (string) @file_get_contents((string) $f['tmp_name']);
    $up = media_upload($bytes, $nama);
    if (empty($up['ok'])) {
        return ['ok' => false, 'pesan' => $up['pesan'] ?? 'Unggahan gagal.'];
    }
    return ['ok' => true, 'url' => $up['url'], 'nama' => $nama, 'ukuran' => (int) $f['size']];
}

/* ============================== LOG AKTIVITAS ============================== */

function log_ip(): string
{
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    $fwd = (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
    if ($fwd !== '') {
        $bagian = array_values(array_filter(array_map('trim', explode(',', $fwd))));
        if ($bagian) {
            $ip = (string) end($bagian);
        }
    }
    return substr($ip, 0, 60);
}

function log_peramban(): string
{
    $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
    $browser = 'Peramban';
    foreach (['Edg' => 'Edge', 'OPR' => 'Opera', 'Chrome' => 'Chrome', 'Safari' => 'Safari', 'Firefox' => 'Firefox'] as $kunci => $label) {
        if (str_contains($ua, $kunci)) { $browser = $label; break; }
    }
    $perangkat = 'Komputer';
    if (preg_match('/Android/i', $ua)) { $perangkat = 'Android'; }
    elseif (preg_match('/iPhone|iPad|iPod/i', $ua)) { $perangkat = 'iPhone/iPad'; }
    elseif (preg_match('/Mobile/i', $ua)) { $perangkat = 'HP'; }
    return $browser . ' - ' . $perangkat;
}

function log_catat(string $aktivitas, string $keterangan = ''): void
{
    try {
        $u = current_user();
        q(
            'INSERT INTO log_aktivitas (user_id, nama, role, aktivitas, keterangan, ip, peramban, waktu) VALUES (?,?,?,?,?,?,?,?)',
            [$u['id'] ?? null, $u['nama'] ?? 'Pengunjung', $u['role'] ?? '', $aktivitas, $keterangan, log_ip(), log_peramban(), date('Y-m-d H:i:s')]
        );
    } catch (Throwable $ex) {
        // pencatatan log tidak boleh menggagalkan permintaan pengguna
    }
}

function log_sesi_ping(): void
{
    if (!is_login()) { return; }
    $sekarang = time();
    $akhir = (int) ($_SESSION['__ping'] ?? 0);
    if ($sekarang - $akhir < 60) { return; }
    $_SESSION['__ping'] = $sekarang;
    try {
        q('UPDATE users SET terakhir = ? WHERE id = ?', [date('Y-m-d H:i:s'), (int) current_user()['id']]);
    } catch (Throwable $ex) {
    }
}

/* ============================== PENOMORAN SURAT ============================== */

/**
 * Nomor agenda berikutnya untuk tahun tertentu pada tabel surat (masuk/keluar).
 * Kolom tanggal ditentukan pemanggil: surat_masuk memakai tanggal_terima, surat_keluar tanggal_surat.
 */
function agenda_berikut(string $tabel, int $tahun, string $kolomTanggal = 'tanggal_terima'): string
{
    $tabel = $tabel === 'surat_keluar' ? 'surat_keluar' : 'surat_masuk';
    $kolomTanggal = $kolomTanggal === 'tanggal_surat' ? 'tanggal_surat' : 'tanggal_terima';
    $urut = (int) kolom("SELECT COUNT(*) FROM $tabel WHERE substr($kolomTanggal,1,4) = ?", [(string) $tahun]);
    return sprintf('%03d', $urut + 1) . '/' . $tahun;
}

/** Nomor surat keluar otomatis: 001/TU/IX/2026 (urut dihitung per bulan). */
function nomor_surat_berikut(?string $tanggal = null): string
{
    $tanggal = tanggal_sah($tanggal ?? '') ? (string) $tanggal : tanggal_hari_ini();
    $ts = strtotime($tanggal);
    $y = (int) date('Y', $ts);
    $m = (int) date('n', $ts);
    $kode = trim(setting('kode_surat', KODE_SURAT_DEFAULT));
    if ($kode === '') { $kode = KODE_SURAT_DEFAULT; }
    $urut = (int) kolom(
        "SELECT COUNT(*) FROM surat_keluar WHERE substr(tanggal_surat,1,4) = ? AND substr(tanggal_surat,6,2) = ?",
        [(string) $y, sprintf('%02d', $m)]
    );
    return sprintf('%03d', $urut + 1) . '/' . $kode . '/' . bulan_romawi($m) . '/' . $y;
}

/* ============================== DATA PENDUKUNG ============================== */

function kelas_list(bool $aktifSaja = false): array
{
    $sql = 'SELECT * FROM kelas';
    if ($aktifSaja) { $sql .= ' WHERE aktif = 1'; }
    $sql .= ' ORDER BY urutan, nama';
    return semua($sql);
}

function kelas_by_id(int $id): ?array
{
    return $id > 0 ? satu('SELECT * FROM kelas WHERE id = ?', [$id]) : null;
}

function kelas_nama(?int $id): string
{
    $k = $id ? kelas_by_id((int) $id) : null;
    return $k['nama'] ?? '-';
}

function guru_list(bool $aktifSaja = false): array
{
    $sql = 'SELECT * FROM guru';
    if ($aktifSaja) { $sql .= ' WHERE aktif = 1'; }
    $sql .= " ORDER BY urutan, nama";
    return semua($sql);
}

function status_siswa_list(): array
{
    return ['aktif' => 'Aktif', 'lulus' => 'Lulus', 'pindah' => 'Pindah', 'keluar' => 'Keluar'];
}

function status_siswa_label(?string $s): string
{
    return status_siswa_list()[$s ?? ''] ?? 'Aktif';
}

function sifat_surat_list(): array
{
    return ['biasa' => 'Biasa', 'penting' => 'Penting', 'segera' => 'Segera', 'rahasia' => 'Rahasia'];
}

function status_surat_keluar(): array
{
    return ['draf' => 'Draf', 'verifikasi' => 'Diverifikasi', 'terkirim' => 'Terkirim'];
}

/** Status kehadiran guru/tenaga kependidikan. */
function absensi_status(): array
{
    return [
        'H' => ['label' => 'Hadir', 'kelas' => 'st-h'],
        'T' => ['label' => 'Terlambat', 'kelas' => 'st-t'],
        'I' => ['label' => 'Izin', 'kelas' => 'st-i'],
        'S' => ['label' => 'Sakit', 'kelas' => 'st-s'],
        'C' => ['label' => 'Cuti', 'kelas' => 'st-c'],
        'A' => ['label' => 'Alpha', 'kelas' => 'st-a'],
    ];
}

/**
 * Menyalin berkas unggahan surat menjadi baris ARSIP DIGITAL.
 * Dipakai saat surat masuk/surat keluar disimpan dengan berkas.
 */
function arsip_otomatis(string $sumber, int $refId, string $judul, string $tanggal, string $url, string $nama, int $ukuran): void
{
    if ($url === '') { return; }
    $ada = satu('SELECT id FROM arsip WHERE sumber = ? AND ref_id = ?', [$sumber, $refId]);
    if ($ada) {
        q('UPDATE arsip SET judul = ?, tanggal = ?, berkas_url = ?, berkas_nama = ?, berkas_ukuran = ? WHERE id = ?',
            [$judul, $tanggal, $url, $nama, $ukuran, (int) $ada['id']]);
        return;
    }
    $urut = (int) kolom('SELECT COUNT(*) FROM arsip') + 1;
    $kode = 'ARS-' . date('Y') . '-' . sprintf('%04d', $urut);
    q('INSERT INTO arsip (sumber, ref_id, kode, judul, tanggal, status, berkas_url, berkas_nama, berkas_ukuran, keterangan, dibuat, oleh)
       VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
        [$sumber, $refId, $kode, $judul, $tanggal, 'aktif', $url, $nama, $ukuran, '', date('Y-m-d H:i:s'), current_user()['nama'] ?? '']);
}

/** Usia arsip dalam bulan (dipakai untuk saran arsip inaktif > 2 tahun). */
function arsip_usia_bulan(?string $tanggal): int
{
    if (!$tanggal) { return 0; }
    $ts = strtotime($tanggal);
    if ($ts === false) { return 0; }
    return (int) floor((time() - $ts) / (30 * 86400));
}
