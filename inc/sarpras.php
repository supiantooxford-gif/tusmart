<?php
/**
 * Inventaris Sarana & Prasarana — dibaca LANGSUNG (hanya-baca) dari basis data aplikasi e-GURU.
 *
 * Data TIDAK disalin ke TUSmart: setiap kali halaman dibuka, isinya mengikuti e-GURU
 * (menu Tugas Tambahan -> Sarana & Prasarana). Koneksi dibuka mode baca-saja + query_only
 * sehingga TUSmart mustahil mengubah data e-GURU.
 */
declare(strict_types=1);

/** Kandidat jalur basis data e-GURU (yang tersimpan lebih dulu, lalu lokasi standar). */
function sarpras_kandidat(): array
{
    $akar = dirname(__DIR__);
    $daftar = [
        trim(setting('sarpras_sumber')),
        $akar . '/../e-guru/data/eguru.sqlite',
        dirname($akar) . '/e-guru/data/eguru.sqlite',
    ];
    $keluar = [];
    foreach ($daftar as $p) {
        if ($p !== '' && is_file($p) && is_readable($p) && !in_array($p, $keluar, true)) {
            $keluar[] = $p;
        }
    }
    return $keluar;
}

function sarpras_db(): ?PDO
{
    static $pdo = false;
    if ($pdo !== false) {
        return $pdo;
    }
    $kandidat = sarpras_kandidat();
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

/** Sekolah di e-GURU yang datanya dipakai (dicocokkan dari NAMA lembaga TUSmart). */
function sarpras_sekolah(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $p = sarpras_db();
    if (!$p) {
        return $cache = ['id' => 0, 'nama' => ''];
    }
    $nama = lembaga_nama();
    try {
        $st = $p->prepare('SELECT id, nama FROM sekolah WHERE nama = ? LIMIT 1');
        $st->execute([$nama]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) {
            // cadangan: cocokkan "Sirojuth Tholibiin Mootilango" tanpa "Sidomukti".
            $st = $p->prepare("SELECT id, nama FROM sekolah WHERE nama LIKE ? AND nama NOT LIKE '%Sidomukti%' ORDER BY id LIMIT 1");
            $st->execute(['%Sirojuth Tholibiin Mootilango%']);
            $r = $st->fetch(PDO::FETCH_ASSOC);
        }
    } catch (Throwable $ex) {
        $r = null;
    }
    return $cache = ['id' => (int) ($r['id'] ?? 0), 'nama' => (string) ($r['nama'] ?? '')];
}

function sarpras_ada(): bool
{
    $s = sarpras_sekolah();
    return sarpras_db() !== null && $s['id'] > 0;
}

/** Daftar barang inventaris (hanya-baca dari e-GURU). */
function sarpras_list(string $cari = '', string $kondisi = '', string $jenis = ''): array
{
    $p = sarpras_db();
    $s = sarpras_sekolah();
    if (!$p || $s['id'] <= 0) {
        return [];
    }
    $sql = 'SELECT * FROM sarpras_barang WHERE sekolah_id = ?';
    $arg = [$s['id']];
    if ($cari !== '') {
        $sql .= ' AND (nama LIKE ? OR spesifikasi LIKE ? OR lokasi LIKE ? OR keterangan LIKE ? OR sumber_dana LIKE ?)';
        $k = '%' . $cari . '%';
        array_push($arg, $k, $k, $k, $k, $k);
    }
    if ($kondisi !== '') { $sql .= ' AND kondisi = ?'; $arg[] = $kondisi; }
    if ($jenis !== '') { $sql .= ' AND jenis = ?'; $arg[] = $jenis; }
    $sql .= ' ORDER BY urutan, nama';
    try {
        $st = $p->prepare($sql);
        $st->execute($arg);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $ex) {
        return [];
    }
}

function sarpras_ringkas(array $baris): array
{
    $unit = 0;
    $kondisi = [];
    $jenis = [];
    $lokasi = [];
    foreach ($baris as $b) {
        $unit += (int) $b['jumlah'];
        $k = trim((string) $b['kondisi']) ?: 'Belum diisi';
        $kondisi[$k] = ($kondisi[$k] ?? 0) + (int) $b['jumlah'];
        $j = trim((string) $b['jenis']) ?: 'Belum diisi';
        $jenis[$j] = ($jenis[$j] ?? 0) + 1;
        $l = trim((string) $b['lokasi']) ?: 'Belum diisi';
        $lokasi[$l] = ($lokasi[$l] ?? 0) + 1;
    }
    arsort($kondisi);
    arsort($jenis);
    arsort($lokasi);
    return [
        'baris'   => count($baris),
        'unit'    => $unit,
        'kondisi' => $kondisi,
        'jenis'   => $jenis,
        'lokasi'  => $lokasi,
        'baik'    => (int) ($kondisi['Baik'] ?? 0),
        'rusak'   => (int) (($kondisi['Rusak Ringan'] ?? 0) + ($kondisi['Rusak Berat'] ?? 0)),
    ];
}

function sarpras_pilihan_kondisi(): array
{
    return ['Baik', 'Rusak Ringan', 'Rusak Berat'];
}

function sarpras_pilihan_jenis(): array
{
    return ['Tetap', 'Tidak Tetap', 'Bergerak'];
}
