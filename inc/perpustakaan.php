<?php
/**
 * Inventaris Perpustakaan — dibaca LANGSUNG (hanya-baca) dari basis data aplikasi e-PERPUS.
 *
 * Sama seperti modul Sarpras: data TIDAK disalin. Setiap halaman dibuka, isinya mengikuti
 * e-PERPUS (termasuk ketika e-PERPUS diperbarui/diisi). Koneksi dibuka mode baca-saja +
 * query_only sehingga TUSmart mustahil mengubah data perpustakaan.
 *
 * Laporan yang disediakan (sama dengan laporan e-PERPUS):
 *   pengunjung, buku (data buku), peminjaman, pengembalian.
 */
declare(strict_types=1);

/**
 * Kandidat jalur basis data e-PERPUS. Yang tersimpan di pengaturan dicoba lebih dulu, lalu
 * lokasi standar di sebelah folder aplikasi (berguna bila folder dipindah / dipublikasikan ulang).
 */
function perpus_kandidat(): array
{
    $akar = dirname(__DIR__);
    $daftar = [
        trim(setting('perpus_sumber')),
        $akar . '/../perpustakaan/data/perpustakaan.sqlite',
        dirname($akar) . '/perpustakaan/data/perpustakaan.sqlite',
    ];
    $keluar = [];
    foreach ($daftar as $p) {
        if ($p !== '' && is_file($p) && is_readable($p) && !in_array($p, $keluar, true)) {
            $keluar[] = $p;
        }
    }
    return $keluar;
}

function perpus_db(): ?PDO
{
    static $pdo = false;
    if ($pdo !== false) {
        return $pdo;
    }
    $kandidat = perpus_kandidat();
    if (!$kandidat) {
        return $pdo = null;
    }
    $path = $kandidat[0];
    try {
        $p = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $p->exec('PRAGMA query_only = 1');
        $p->exec('PRAGMA busy_timeout = 3000');
        return $pdo = $p;
    } catch (Throwable $ex) {
        return $pdo = null;
    }
}

/** Informasi perpustakaan (nama perpustakaan & sekolah) dari e-PERPUS. */
function perpus_info(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $p = perpus_db();
    if (!$p) {
        return $cache = [];
    }
    try {
        $r = $p->query('SELECT * FROM sekolah ORDER BY id LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        $cache = $r ?: [];
    } catch (Throwable $ex) {
        $cache = [];
    }
    return $cache;
}

function perpus_ada(): bool
{
    return perpus_db() !== null;
}

/** Daftar jenis laporan (urutan sesuai permintaan pemilik). */
function perpus_jenis(): array
{
    return [
        'pengunjung'   => 'Laporan Pengunjung',
        'buku'         => 'Laporan Data Buku',
        'peminjaman'   => 'Laporan Peminjaman',
        'pengembalian' => 'Laporan Pengembalian',
    ];
}

function perpus_jenis_sah(string $j): bool
{
    return array_key_exists($j, perpus_jenis());
}

/** Penyaring dari query string (GET); tanggal terbalik ditukar. */
function perpus_filter(): array
{
    $dari = tanggal_sah(f('dari')) ? f('dari') : '';
    $sampai = tanggal_sah(f('sampai')) ? f('sampai') : '';
    if ($dari !== '' && $sampai !== '' && $dari > $sampai) {
        [$dari, $sampai] = [$sampai, $dari];
    }
    return [
        'dari'      => $dari,
        'sampai'    => $sampai,
        'cari'      => f('cari'),
        'keperluan' => f('keperluan'),
        'kategori'  => f('kategori'),
        'status'    => in_array(f('status'), ['dipinjam', 'kembali', 'terlambat'], true) ? f('status') : '',
        'kelas'     => f('kelas'),
    ];
}

function perpus_qs(array $f, array $tambah = []): string
{
    return http_build_query(array_filter(array_merge([
        'dari'      => $f['dari'] ?? '',
        'sampai'    => $f['sampai'] ?? '',
        'cari'      => $f['cari'] ?? '',
        'keperluan' => $f['keperluan'] ?? '',
        'kategori'  => $f['kategori'] ?? '',
        'status'    => $f['status'] ?? '',
        'kelas'     => $f['kelas'] ?? '',
    ], $tambah), static fn($v) => (string) $v !== ''));
}

/** Nama hari Indonesia dari tanggal Y-m-d (untuk laporan pengunjung). */
function perpus_hari(?string $ymd): string
{
    $ts = strtotime((string) $ymd);
    if ($ts === false) {
        return '-';
    }
    $h = nama_hari();
    return $h[(int) date('N', $ts)] ?? '-';
}

/** Lama keterlambatan (hari) — 0 bila tidak terlambat. */
function perpus_terlambat(?string $jatuhTempo, ?string $kembali = null): int
{
    if (!$jatuhTempo) {
        return 0;
    }
    $akhir = $kembali && $kembali !== '' ? $kembali : tanggal_hari_ini();
    $a = strtotime($jatuhTempo);
    $b = strtotime($akhir);
    if ($a === false || $b === false) {
        return 0;
    }
    return max(0, (int) floor(($b - $a) / 86400));
}

/**
 * Menyusun satu laporan (judul, kolom, baris, ringkasan, meta).
 * Struktur seragam supaya halaman layar, lembar cetak, dan ekspor memakai data yang sama.
 */
function perpus_laporan(string $jenis, array $f): array
{
    $p = perpus_db();
    $kosong = ['judul' => 'Laporan', 'berkas' => 'laporan', 'kolom' => [], 'align' => [], 'rows' => [],
        'ringkas' => [], 'meta' => [], 'kosong' => 'Data belum tersedia.'];
    if (!$p) {
        return $kosong + ['kosong' => 'Basis data e-PERPUS belum terhubung.'];
    }
    $w = [];
    $arg = [];

    try {
        if ($jenis === 'pengunjung') {
            if ($f['dari'] !== '') { $w[] = 'waktu >= ?'; $arg[] = $f['dari'] . ' 00:00:00'; }
            if ($f['sampai'] !== '') { $w[] = 'waktu <= ?'; $arg[] = $f['sampai'] . ' 23:59:59'; }
            if ($f['keperluan'] !== '') { $w[] = 'IFNULL(keperluan,"") = ?'; $arg[] = $f['keperluan']; }
            if ($f['kelas'] !== '') { $w[] = 'IFNULL(kelas,"") = ?'; $arg[] = $f['kelas']; }
            if ($f['cari'] !== '') {
                $k = '%' . strtolower($f['cari']) . '%';
                $w[] = '(lower(nama) LIKE ? OR lower(IFNULL(kelas,"")) LIKE ? OR lower(IFNULL(keperluan,"")) LIKE ?)';
                array_push($arg, $k, $k, $k);
            }
            $sql = 'SELECT * FROM pengunjung' . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . ' ORDER BY waktu DESC, id DESC';
            $st = $p->prepare($sql);
            $st->execute($arg);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);

            $out = [];
            $nama = [];
            $perKeperluan = [];
            $hari = [];
            foreach ($rows as $i => $r) {
                $nama[strtolower(trim((string) $r['nama']))] = true;
                $kep = (string) ($r['keperluan'] ?: '-');
                $perKeperluan[$kep] = ($perKeperluan[$kep] ?? 0) + 1;
                $tgl = substr((string) $r['waktu'], 0, 10);
                $hari[$tgl] = true;
                $ts = strtotime((string) $r['waktu']);
                $out[] = [
                    $i + 1, perpus_hari($tgl), tanggal_id($tgl), $ts ? date('H:i', $ts) : '-',
                    (string) $r['nama'], (string) ($r['jenis_kelamin'] ?: '-'), (string) ($r['kelas'] ?: '-'),
                    (string) ($r['keperluan'] ?: '-'), (string) ($r['saran'] ?: '-'),
                ];
            }
            arsort($perKeperluan);
            return [
                'judul' => 'Laporan Pengunjung Perpustakaan',
                'berkas' => 'laporan-pengunjung',
                'kolom' => ['No', 'Hari', 'Tanggal', 'Jam', 'Nama Pengunjung', 'JK', 'Kelas', 'Keperluan', 'Saran'],
                'align' => ['c', 'l', 'l', 'c', 'l', 'c', 'c', 'l', 'l'],
                'rows' => $out,
                'ringkas' => [
                    'Jumlah kunjungan' => count($rows),
                    'Pengunjung (nama unik)' => count($nama),
                    'Hari tercatat' => count($hari),
                    'Rata-rata per hari' => count($hari) > 0 ? (string) round(count($rows) / count($hari), 1) : '0',
                    'Keperluan terbanyak' => $perKeperluan ? (string) array_key_first($perKeperluan) : '-',
                ],
                'meta' => ['Jenis Laporan' => 'Pengunjung'],
                'kosong' => 'Belum ada kunjungan pada saringan ini.',
            ];
        }

        if ($jenis === 'buku') {
            if ($f['cari'] !== '') {
                $k = '%' . strtolower($f['cari']) . '%';
                $w[] = '(lower(b.judul) LIKE ? OR lower(IFNULL(b.kode,"")) LIKE ? OR lower(IFNULL(b.pengarang,"")) LIKE ? OR lower(IFNULL(b.penerbit,"")) LIKE ?)';
                array_push($arg, $k, $k, $k, $k);
            }
            if ($f['kategori'] !== '') { $w[] = 'IFNULL(b.kategori,"") = ?'; $arg[] = $f['kategori']; }
            $sql = 'SELECT b.*, (SELECT COUNT(*) FROM peminjaman p WHERE p.buku_id = b.id AND p.status = \'dipinjam\') AS dipinjam
                    FROM buku b' . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . ' ORDER BY b.judul COLLATE NOCASE';
            $st = $p->prepare($sql);
            $st->execute($arg);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);

            $out = [];
            $eksemplar = 0;
            $dipinjam = 0;
            foreach ($rows as $i => $r) {
                $tot = (int) $r['total_buku'];
                $dip = (int) $r['dipinjam'];
                $eksemplar += $tot;
                $dipinjam += $dip;
                $out[] = [
                    $i + 1, (string) ($r['kode'] ?: '-'), (string) $r['judul'], (string) ($r['pengarang'] ?: '-'),
                    (string) ($r['penerbit'] ?: '-'), (string) ($r['tahun_terbit'] ?: '-'), (string) ($r['kategori'] ?: '-'),
                    (string) $tot, (string) $dip, (string) max(0, $tot - $dip), (string) ($r['lokasi'] ?: '-'),
                    (int) $r['aktif'] === 1 ? 'Aktif' : 'Nonaktif',
                ];
            }
            return [
                'judul' => 'Laporan Data Buku Perpustakaan',
                'berkas' => 'laporan-buku',
                'kolom' => ['No', 'Kode', 'Judul Buku', 'Pengarang', 'Penerbit', 'Tahun', 'Kategori', 'Total', 'Dipinjam', 'Tersedia', 'Lokasi', 'Status'],
                'align' => ['c', 'l', 'l', 'l', 'l', 'c', 'c', 'c', 'c', 'c', 'l', 'c'],
                'rows' => $out,
                'ringkas' => [
                    'Jumlah judul' => count($rows),
                    'Total eksemplar' => $eksemplar,
                    'Sedang dipinjam' => $dipinjam,
                    'Tersedia di rak' => max(0, $eksemplar - $dipinjam),
                ],
                'meta' => ['Jenis Laporan' => 'Data Buku'],
                'kosong' => 'Belum ada buku yang cocok dengan saringan ini.',
            ];
        }

        if ($jenis === 'peminjaman' || $jenis === 'pengembalian') {
            $kolomTanggal = $jenis === 'peminjaman' ? 'p.tanggal_pinjam' : 'p.tanggal_kembali';
            if ($jenis === 'pengembalian') { $w[] = 'p.tanggal_kembali IS NOT NULL AND p.tanggal_kembali <> ""'; }
            if ($f['dari'] !== '') { $w[] = $kolomTanggal . ' >= ?'; $arg[] = $f['dari']; }
            if ($f['sampai'] !== '') { $w[] = $kolomTanggal . ' <= ?'; $arg[] = $f['sampai']; }
            if ($f['status'] === 'dipinjam') { $w[] = "p.status = 'dipinjam'"; }
            elseif ($f['status'] === 'kembali') { $w[] = "p.status = 'kembali'"; }
            elseif ($f['status'] === 'terlambat') { $w[] = "p.status = 'dipinjam' AND date(p.jatuh_tempo) < date('now','localtime')"; }
            if ($f['cari'] !== '') {
                $k = '%' . strtolower($f['cari']) . '%';
                $w[] = '(lower(IFNULL(p.kode,"")) LIKE ? OR lower(g.nama) LIKE ? OR lower(b.judul) LIKE ?)';
                array_push($arg, $k, $k, $k);
            }
            $sql = 'SELECT p.*, g.nama AS anggota_nama, g.kelas AS anggota_kelas, b.judul AS buku_judul
                    FROM peminjaman p
                    JOIN anggota g ON g.id = p.anggota_id
                    JOIN buku b ON b.id = p.buku_id'
                . ($w ? ' WHERE ' . implode(' AND ', $w) : '')
                . ' ORDER BY ' . $kolomTanggal . ' DESC, p.id DESC';
            $st = $p->prepare($sql);
            $st->execute($arg);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);

            $out = [];
            $aktif = 0;
            $kembali = 0;
            $telat = 0;
            $totalDenda = 0;
            $nDenda = 0;
            foreach ($rows as $i => $r) {
                $ht = perpus_terlambat($r['jatuh_tempo'] ?? null, $r['status'] === 'kembali' ? ($r['tanggal_kembali'] ?? null) : null);
                $denda = (int) ($r['denda'] ?? 0);
                $totalDenda += $denda;
                if ($denda > 0) { $nDenda++; }
                if (($r['status'] ?? '') === 'dipinjam') { $aktif++; if ($ht > 0) { $telat++; } } else { $kembali++; }
                if ($jenis === 'pengembalian') {
                    $out[] = [
                        $i + 1, (string) ($r['kode'] ?: '-'), (string) $r['anggota_nama'], (string) ($r['anggota_kelas'] ?: '-'),
                        (string) $r['buku_judul'], tanggal_id($r['tanggal_pinjam']), tanggal_id($r['tanggal_kembali']),
                        $ht > 0 ? $ht . ' hari' : '-', rupiah($denda), (string) ($r['kondisi'] ?: 'Baik'), (string) ($r['petugas'] ?: '-'),
                    ];
                } else {
                    $out[] = [
                        $i + 1, (string) ($r['kode'] ?: '-'), tanggal_id($r['tanggal_pinjam']), (string) $r['anggota_nama'],
                        (string) ($r['anggota_kelas'] ?: '-'), (string) $r['buku_judul'], tanggal_id($r['jatuh_tempo']),
                        ($r['status'] ?? '') === 'dipinjam' ? 'Dipinjam' : 'Kembali', $ht > 0 ? $ht . ' hari' : '-', (string) ($r['petugas'] ?: '-'),
                    ];
                }
            }
            if ($jenis === 'pengembalian') {
                return [
                    'judul' => 'Laporan Pengembalian Buku',
                    'berkas' => 'laporan-pengembalian',
                    'kolom' => ['No', 'Kode', 'Anggota', 'Kelas', 'Judul Buku', 'Tgl Pinjam', 'Tgl Kembali', 'Terlambat', 'Denda', 'Kondisi', 'Petugas'],
                    'align' => ['c', 'l', 'l', 'c', 'l', 'c', 'c', 'c', 'r', 'c', 'l'],
                    'rows' => $out,
                    'ringkas' => [
                        'Jumlah pengembalian' => count($rows),
                        'Tepat waktu' => max(0, count($rows) - $telat),
                        'Terlambat' => $telat,
                        'Total denda' => rupiah($totalDenda),
                    ],
                    'meta' => ['Jenis Laporan' => 'Pengembalian Buku'],
                    'kosong' => 'Belum ada pengembalian buku pada saringan ini.',
                ];
            }
            return [
                'judul' => 'Laporan Peminjaman Buku',
                'berkas' => 'laporan-peminjaman',
                'kolom' => ['No', 'Kode', 'Tgl Pinjam', 'Anggota', 'Kelas', 'Judul Buku', 'Jatuh Tempo', 'Status', 'Terlambat', 'Petugas'],
                'align' => ['c', 'l', 'c', 'l', 'c', 'l', 'c', 'c', 'c', 'l'],
                'rows' => $out,
                'ringkas' => [
                    'Jumlah transaksi' => count($rows),
                    'Masih dipinjam' => $aktif,
                    'Sudah kembali' => $kembali,
                    'Terlambat' => $telat,
                    'Transaksi berdenda' => $nDenda,
                ],
                'meta' => ['Jenis Laporan' => 'Peminjaman Buku'],
                'kosong' => 'Belum ada transaksi peminjaman pada saringan ini.',
            ];
        }
    } catch (Throwable $ex) {
        return $kosong + ['kosong' => 'Gagal membaca data e-PERPUS: ' . $ex->getMessage()];
    }
    return $kosong;
}

/** Pilihan saringan yang tersedia (keperluan & kategori) dari data asli. */
function perpus_pilihan(string $kolom, string $tabel): array
{
    $p = perpus_db();
    if (!$p || !in_array($kolom, ['keperluan', 'kategori', 'kelas'], true) || !in_array($tabel, ['pengunjung', 'buku', 'anggota'], true)) {
        return [];
    }
    try {
        $rows = $p->query("SELECT DISTINCT $kolom AS v FROM $tabel WHERE IFNULL($kolom,'') <> '' ORDER BY $kolom")->fetchAll(PDO::FETCH_COLUMN);
        return array_values(array_filter(array_map('strval', $rows), static fn($v) => trim($v) !== ''));
    } catch (Throwable $ex) {
        return [];
    }
}

/** Angka ringkas untuk kartu dashboard perpustakaan. */
function perpus_ringkas(): array
{
    $p = perpus_db();
    if (!$p) {
        return ['buku' => 0, 'eksemplar' => 0, 'anggota' => 0, 'dipinjam' => 0, 'kunjungan' => 0];
    }
    $q = static function (string $sql) use ($p): int {
        try { return (int) $p->query($sql)->fetchColumn(); } catch (Throwable $ex) { return 0; }
    };
    return [
        'buku'      => $q('SELECT COUNT(*) FROM buku'),
        'eksemplar' => $q('SELECT COALESCE(SUM(total_buku),0) FROM buku'),
        'anggota'   => $q('SELECT COUNT(*) FROM anggota WHERE aktif = 1'),
        'dipinjam'  => $q("SELECT COUNT(*) FROM peminjaman WHERE status = 'dipinjam'"),
        'kunjungan' => $q('SELECT COUNT(*) FROM pengunjung'),
    ];
}
