<?php
/**
 * IMPOR KEHADIRAN GURU dari berkas PDF laporan absensi bulanan aplikasi lain
 * (mis. https://ma.stm.sch.id/absensi/sw-admin/laporan-hari → cetak "Laporan Absensi Bulan").
 *
 * Alur: pdf_teks() (inc/pdf_teks.php) membaca potongan teks + koordinatnya, lalu modul ini
 * menyusunnya kembali menjadi tabel kehadiran:
 *   - kolom tanggal ditentukan dari baris judul berisi angka 1..31,
 *   - setiap guru punya dua baris: "Masuk" dan "Pulang",
 *   - sel berisi jam (HH:MM[:SS]) + keterangan (Telat / Tepat Waktu / Pulang Cepat) atau "X".
 *
 * Hasilnya dipakai halaman `absensi_impor.php` untuk pratinjau yang dapat disunting, lalu
 * disimpan ke tabel `absensi_guru` — sehingga otomatis ikut pada seluruh laporan absensi.
 */
declare(strict_types=1);

require_once __DIR__ . '/pdf_teks.php';
require_once __DIR__ . '/../inc/helpers.php';

/** Menormalkan teks hasil ekstraksi: ligatur, spasi ganda, tanda kutip khusus. */
function ai_bersih_teks(string $t): string
{
    $peta = [
        "\u{FB01}" => 'fi', "\u{FB02}" => 'fl', "\u{FB00}" => 'ff', "\u{FB03}" => 'ffi', "\u{FB04}" => 'ffl',
        "\u{2018}" => "'", "\u{2019}" => "'", "\u{201C}" => '"', "\u{201D}" => '"',
        "\u{2013}" => '-', "\u{2014}" => '-', "\u{00A0}" => ' ',
    ];
    $t = strtr($t, $peta);
    $t = preg_replace('/\s+/u', ' ', $t);
    return trim((string) $t);
}

/**
 * Menggabungkan potongan teks menjadi KATA per baris (y sama).
 * PDF hasil cetak peramban memecah teks menjadi satu glyph per operator, sehingga perlu
 * digabung: glyph yang berdekatan (< 1,8 pt) dianggap satu kata.
 * @return array<float, array<int, array{x: float, x2: float, teks: string}>>
 */
function ai_runs(array $items, float $f = 1.0): array
{
    // $f = faktor ukuran huruf halaman (1,0 = huruf ±5 pt seperti PDF laporan asli).
    // Toleransi jarak memakai satuan poin, jadi harus ikut mengecil bila PDF diperkecil.
    $tolBaris = max(0.3, 1.2 * $f);
    $tolKata = max(0.45, 1.8 * $f);
    $tolUkuran = max(0.3, 0.6 * $f);
    // Kelompokkan menurut y. Kunci array PHP tidak boleh float (dibulatkan ke int),
    // jadi pakai indeks daftar + pembanding toleransi.
    $kelompok = [];   // [ ['y' => float, 'items' => []] ]
    foreach ($items as $it) {
        $ketemu = null;
        foreach ($kelompok as $i => $k) {
            if (abs($k['y'] - $it['y']) <= $tolBaris) { $ketemu = $i; break; }
        }
        if ($ketemu === null) {
            $kelompok[] = ['y' => (float) $it['y'], 'items' => [$it]];
        } else {
            $kelompok[$ketemu]['items'][] = $it;
        }
    }
    $keluar = [];
    foreach ($kelompok as $k) {
        $y = $k['y'];
        $daftar = $k['items'];
        usort($daftar, static fn($a, $b) => $a['x'] <=> $b['x']);
        $runs = [];
        $sebelum = null;
        $teks = '';
        $x1 = 0.0;
        $x2 = 0.0;
        $ukuranSebelum = null;
        $isiRun = [];       // potongan ASLI pembentuk run (dipakai memisah kolom Nama/Jabatan)
        foreach ($daftar as $it) {
            $lebar = 0.0;
            if (isset($it['ukuran'])) { $lebar = (float) $it['ukuran'] * 0.55 * max(1, strlen((string) $it['teks'])); }
            $mulai = (float) $it['x'];
            $akhir = $mulai + $lebar;
            $uk = isset($it['ukuran']) ? (float) $it['ukuran'] : 0.0;
            $bedaUkuran = $ukuranSebelum !== null && $uk > 0 && $ukuranSebelum > 0 && abs($uk - $ukuranSebelum) > $tolUkuran;
            if ($sebelum !== null && $mulai - $sebelum <= $tolKata && !$bedaUkuran) {
                $teks .= (string) $it['teks'];
                $x2 = max($x2, $akhir);
            } else {
                if ($sebelum !== null && trim($teks) !== '') {
                    $runs[] = ['x' => $x1, 'x2' => $x2, 'teks' => $teks, 'bag' => $isiRun];
                }
                $teks = (string) $it['teks'];
                $x1 = $mulai;
                $x2 = $akhir;
                $isiRun = [];
            }
            $isiRun[] = ['x' => $mulai, 'x2' => $akhir, 'u' => $uk, 'teks' => (string) $it['teks']];
            $sebelum = $akhir;
            $ukuranSebelum = $uk > 0 ? $uk : $ukuranSebelum;
        }
        if (trim($teks) !== '') { $runs[] = ['x' => $x1, 'x2' => $x2, 'teks' => $teks, 'bag' => $isiRun]; }
        $keluar[] = ['y' => (float) $y, 'runs' => $runs];
    }
    usort($keluar, static fn($a, $b) => $b['y'] <=> $a['y']);  // atas → bawah
    return $keluar;
}

/** Apakah teks berupa jam "7:50", "07:50:41"?  Mengembalikan "HH:MM" atau ''. */
function ai_jam(string $t): string
{
    $t = trim($t);
    if (!preg_match('/^(\d{1,2})[:.](\d{2})(?:[:.](\d{2}))?$/', $t, $m)) {
        return '';
    }
    $h = (int) $m[1];
    $i = (int) $m[2];
    if ($h > 23 || $i > 59) { return ''; }
    if ($h === 0 && $i === 0) { return ''; }   // 00:00:00 = tidak ada rekaman
    return sprintf('%02d:%02d', $h, $i);
}

/** Keterangan sel → 'telat' | 'tepat' | 'cepat' | ''. */
function ai_keterangan(string $t): string
{
    $t = strtolower(preg_replace('/[^a-z]/i', '', $t));
    if ($t === '') { return ''; }
    if (str_contains($t, 'telat') || str_contains($t, 'terlambat')) { return 'telat'; }
    if (str_contains($t, 'cepat')) { return 'cepat'; }
    if (str_contains($t, 'tepat')) { return 'tepat'; }
    return '';
}

/** Nama bulan Indonesia (termasuk singkatan) → nomor bulan. */
function ai_bulan_nomor(string $teks): int
{
    $teks = strtolower(trim($teks));
    $peta = ['januari' => 1, 'jan' => 1, 'februari' => 2, 'feb' => 2, 'maret' => 3, 'mar' => 3,
        'april' => 4, 'apr' => 4, 'mei' => 5, 'juni' => 6, 'jun' => 6, 'juli' => 7, 'jul' => 7,
        'agustus' => 8, 'agu' => 8, 'agt' => 8, 'september' => 9, 'sep' => 9, 'oktober' => 10,
        'okt' => 10, 'november' => 11, 'nov' => 11, 'desember' => 12, 'des' => 12];
    return $peta[$teks] ?? 0;
}

/**
 * Membaca PDF laporan absensi bulanan.
 *
 * @return array{
 *   bulan: string,        // "YYYY-MM" ('' bila tidak terbaca)
 *   guru: array<int, array{nama: string, jabatan: string, tanggal: array<string, array{masuk:string, keluar:string, k_masuk:string, k_pulang:string}>}>,
 *   peringatan: string[],
 *   galat: string
 * }
 */
function absensi_impor_baca_pdf(string $bytes, string $bulanCadangan = ''): array
{
    $hasil = ['bulan' => '', 'guru' => [], 'peringatan' => [], 'galat' => ''];
    $pdf = pdf_teks($bytes);
    if ($pdf['galat'] !== '') {
        $hasil['galat'] = $pdf['galat'];
        return $hasil;
    }

    // ---------- 1. bulan & tahun ----------
    $semuaTeks = [];
    foreach ($pdf['halaman'] as $items) {
        foreach ($items as $it) { $semuaTeks[] = (string) $it['teks']; }
    }
    $gabung = ai_bersih_teks(implode(' ', $semuaTeks));
    // PDF hasil cetak peramban memecah teks per huruf ("O k t o b e r") → bentuk rapat dulu.
    $rapat = strtolower(preg_replace('/\s+/', '', $gabung));
    $tahun = 0;
    $bulan = 0;
    if (preg_match('/bulan:?([a-z]+?)(\d{4})/', $rapat, $m)) {
        $bulan = ai_bulan_nomor($m[1]);
        $tahun = (int) $m[2];
    }
    if ($bulan === 0 || $tahun === 0) {
        // cadangan: cari nama bulan diikuti tahun (mis. "Oktober 2026")
        if (preg_match('/(januari|februari|maret|april|mei|juni|juli|agustus|september|oktober|november|desember)(\d{4})/', $rapat, $m)) {
            $bulan = ai_bulan_nomor($m[1]);
            $tahun = (int) $m[2];
        }
    }
    if ($bulan > 0 && $tahun > 0) {
        $hasil['bulan'] = sprintf('%04d-%02d', $tahun, $bulan);
    } elseif ($bulanCadangan !== '') {
        $hasil['bulan'] = $bulanCadangan;
        $hasil['peringatan'][] = 'Bulan/tahun pada PDF tidak terbaca — memakai bulan yang Anda pilih (' . $bulanCadangan . ').';
    }
    if ($hasil['bulan'] === '') {
        $hasil['galat'] = 'Bulan & tahun laporan tidak dapat dikenali dari PDF. Pilih bulannya pada formulir impor lalu unggah ulang.';
        return $hasil;
    }

    // ---------- 2. susun baris, cari judul tanggal & kolom tanggal ----------
    foreach ($pdf['halaman'] as $no => $items) {
        // Faktor ukuran huruf halaman (acuan 4,6 pt = laporan absensi ukuran normal).
        $ukuran = [];
        foreach ($items as $it) {
            $u = (float) ($it['ukuran'] ?? 0);
            if ($u > 0) { $ukuran[] = $u; }
        }
        $median = 0.0;
        if ($ukuran) { sort($ukuran); $median = $ukuran[intdiv(count($ukuran), 2)]; }
        $f = $median > 0 ? max(0.35, min(2.5, $median / 4.6)) : 1.0;
        $baris = ai_runs($items, $f);

        // Baris judul tanggal: berisi banyak angka 1..31 berurutan.
        $kolomTanggal = [];
        $yHeader = null;    // y baris nomor tanggal (dipakai sebagai batas atas data)
        foreach ($baris as $ln) {
            $y = $ln['y'];
            $bag = $ln['runs'];
            $angka = [];
            foreach ($bag as $it) {
                $t = ai_bersih_teks((string) $it['teks']);
                if (preg_match('/^\d{1,2}$/', $t) && (int) $t >= 1 && (int) $t <= 31) {
                    $angka[] = ['n' => (int) $t, 'x' => ((float) $it['x'] + (float) ($it['x2'] ?? $it['x'])) / 2];
                }
            }
            // Butuh deretan menaik minimal 10 angka dalam satu baris.
            $urut = $angka;
            usort($urut, static fn($a, $b) => $a['x'] <=> $b['x']);
            $naik = 0;
            for ($i = 1; $i < count($urut); $i++) { if ($urut[$i]['n'] > $urut[$i - 1]['n']) { $naik++; } }
            if (count($urut) >= 10 && $naik >= count($urut) - 2) {
                $kolomTanggal = $urut;
                $yHeader = (float) $y;
                break;
            }
        }
        if (!$kolomTanggal) {
            $hasil['peringatan'][] = 'Halaman ' . $no . ': baris nomor tanggal (1–31) tidak ditemukan.';
            continue; // halaman tanpa tabel tanggal
        }

        // Batas kolom = titik tengah antar pusat angka.
        $pusat = array_map(static fn($k) => $k['x'], $kolomTanggal);
        $batas = [];
        for ($i = 0; $i < count($pusat); $i++) {
            $kiri = $i === 0 ? $pusat[0] - (($pusat[1] ?? $pusat[0] + 20) - $pusat[0]) / 2 : ($pusat[$i - 1] + $pusat[$i]) / 2;
            $kanan = $i === count($pusat) - 1 ? $pusat[$i] + (($pusat[$i] - ($pusat[$i - 1] ?? $pusat[$i] - 20)) / 2) : ($pusat[$i] + $pusat[$i + 1]) / 2;
            $batas[$i] = [$kiri, $kanan];
        }
        // Batas kiri area tanggal = tepi kiri kolom tanggal PERTAMA, tetapi bila kolom
        // "Status" (label Masuk/Pulang) diketahui dari isinya, batas itu dimundurkan sampai
        // di kiri label. Dulu dipakai tetap "- 12 pt" sehingga pusat tulisan "Masuk"/"Pulang"
        // kerap berada di kanan batas itu: labelnya dianggap isi tabel tanggal, halaman
        // dilewati, dan impor gagal dengan pesan "Tidak ada data kehadiran guru…".
        $kiriTabel = $batas[0][0] - 1.5 * $f;
        $kandidatLabel = [];   // x (tepi kiri) tulisan "Masuk"/"Pulang" di kiri tabel tanggal
        foreach ($baris as $ln) {
            foreach ($ln['runs'] as $it) {
                $t = ai_bersih_teks((string) $it['teks']);
                if (!preg_match('/^(masuk|pulang)$/i', $t)) { continue; }
                $xl = (float) $it['x'];
                if ($xl > $batas[0][0] - 90 * $f && $xl < $batas[0][0] + 4 * $f) { $kandidatLabel[] = $xl; }
            }
        }
        if ($kandidatLabel) { $kiriTabel = min($kiriTabel, min($kandidatLabel) - 0.8 * $f); }

        $labelY = [];   // daftar [y, 'masuk'|'pulang'] urut atas→bawah
        $xLabel = null; // posisi horisontal label "Masuk"/"Pulang" (kolom Status)
        foreach ($baris as $ln) {
            $y = $ln['y'];
            foreach ($ln['runs'] as $it) {
                $t = ai_bersih_teks((string) $it['teks']);
                $xc = ((float) $it['x'] + (float) ($it['x2'] ?? $it['x'])) / 2;
                // Label dicari di kiri kolom tanggal pertama (tidak memakai $kiriTabel yang
                // justru diturunkan dari posisi label itu sendiri).
                if ((float) $it['x'] < $batas[0][0] - 0.5 * $f) {
                    if (preg_match('/^masuk$/i', $t)) { $labelY[] = ['y' => $y, 'jenis' => 'masuk']; $xLabel = $xLabel === null ? $xc : min($xLabel, $xc); }
                    elseif (preg_match('/^pulang$/i', $t)) { $labelY[] = ['y' => $y, 'jenis' => 'pulang']; $xLabel = $xLabel === null ? $xc : min($xLabel, $xc); }
                }
            }
        }
        usort($labelY, static fn($a, $b) => $b['y'] <=> $a['y']); // atas → bawah
        if (count($labelY) < 2) {
            $hasil['peringatan'][] = 'Halaman ' . $no . ': baris "Masuk"/"Pulang" tidak ditemukan.';
            continue;
        }

        // ---------- 3. blok tiap guru ----------
        $bulanAngka = (int) substr($hasil['bulan'], 5, 2);
        $tahunAngka = (int) substr($hasil['bulan'], 0, 4);
        $hariMaks = (int) date('t', strtotime($hasil['bulan'] . '-01'));

        // Batas kolom Nama & Jabatan dari baris judul kolom ("No Nama Jabatan Status").
        $xNo = null;
        $xNama = null;
        $xJab = null;
        foreach ($baris as $ln) {
            foreach ($ln['runs'] as $r) {
                $t = strtolower(ai_bersih_teks((string) $r['teks']));
                if ($t === 'no' && $xNo === null) { $xNo = (float) $r['x']; }
                if ($t === 'nama' && $xNama === null) { $xNama = (float) $r['x']; }
                if ($t === 'jabatan' && $xJab === null) { $xJab = (float) $r['x']; }
            }
        }
        if ($xNama === null) { $xNama = $kiriTabel - 70; }
        if ($xJab === null) { $xJab = $kiriTabel - 35; }
        if ($xNo === null) { $xNo = max(0, $xNama - 30); }
        $batasNo = ($xNo + $xNama) / 2;          // kolom "No" berakhir di tengah No–Nama
        $batasNama = ($xNama + $xJab) / 2;       // kolom Nama berakhir di tengah Nama–Jabatan
        // Kolom Jabatan berakhir di tengah antara judul "Jabatan" dan label "Masuk"/"Pulang"
        // (dulu dipakai tepi kolom tanggal, sehingga tulisan label "Masuk"/"Pulang" bisa
        //  dianggap bagian kolom Jabatan).
        $batasJab = $xLabel !== null ? ($xJab + $xLabel) / 2 : $kiriTabel;
        $batasJab = max($xJab, min($batasJab, $kiriTabel));

        // Pasangkan label Masuk & Pulang tiap guru (urut atas → bawah).
        $guruLabel = [];
        for ($i = 0; $i < count($labelY); $i++) {
            if ($labelY[$i]['jenis'] !== 'masuk') { continue; }
            $yMasuk = $labelY[$i]['y'];
            $yPulang = null;
            for ($j = $i + 1; $j < count($labelY); $j++) {
                if ($labelY[$j]['jenis'] === 'pulang' && $labelY[$j]['y'] < $yMasuk) { $yPulang = $labelY[$j]['y']; break; }
                if ($labelY[$j]['jenis'] === 'masuk') { break; }
            }
            $guruLabel[] = ['masuk' => $yMasuk, 'pulang' => $yPulang, 'sel' => []];
        }
        if (!$guruLabel) { continue; }

        // SUSUN PETA BARIS: setiap sub-baris (Masuk/Pulang tiap guru) dipakai sebagai "jangkar";
        // batas antara dua jangkar = titik tengahnya. Cara ini menggantikan pita tetap
        // (±7 / −4,5 pt) yang membuat baris KEDUA nama guru (mis. "S.Pd.I") ikut terbaca
        // sebagai milik guru BERIKUTNYA — nama guru jadi tertukar/bercampur.
        $jangkar = [];
        foreach ($guruLabel as $gi => $gl) {
            $jangkar[] = ['gi' => $gi, 'sub' => 'masuk', 'y' => $gl['masuk']];
            if ($gl['pulang'] !== null) { $jangkar[] = ['gi' => $gi, 'sub' => 'pulang', 'y' => $gl['pulang']]; }
        }
        usort($jangkar, static fn($a, $b) => $b['y'] <=> $a['y']);   // atas → bawah
        // Batas atas data = baris nomor tanggal (header) — isi header tidak boleh ikut terbaca.
        $atasData = ($yHeader !== null ? $yHeader : $jangkar[0]['y'] + 8 * $f) - 0.8 * $f;
        // Batas bawah data = sedikit di bawah label Pulang terakhir (jangan sampai baris
        // keterangan/kaki halaman seperti "H: Hadir T: Terlambat …" ikut terbaca sebagai
        // nama/jabatan guru terakhir).
        $bawahData = $jangkar[count($jangkar) - 1]['y'] - 10.0 * $f;
        $n = count($jangkar);
        for ($i = 0; $i < $n; $i++) {
            $jangkar[$i]['atas'] = $i === 0 ? $atasData : ($jangkar[$i - 1]['y'] + $jangkar[$i]['y']) / 2;
            $jangkar[$i]['bawah'] = $i === $n - 1 ? $bawahData : ($jangkar[$i]['y'] + $jangkar[$i + 1]['y']) / 2;
        }

        // Kumpulkan isi sel tanggal & kolom kiri untuk SETIAP guru.
        foreach ($baris as $ln) {
            $y = $ln['y'];
            if ($y > $atasData || $y < $bawahData) { continue; }
            // pemilik baris: jangkar yang memuat y
            $giPemilik = null;
            $subPemilik = 'masuk';
            foreach ($jangkar as $jk) {
                if ($y <= $jk['atas'] && $y >= $jk['bawah']) {
                    $giPemilik = $jk['gi'];
                    $subPemilik = $jk['sub'];
                    break;
                }
            }
            if ($giPemilik === null) { continue; }

            foreach ($ln['runs'] as $it) {
                $tRun = ai_bersih_teks((string) $it['teks']);
                if ($tRun === '') { continue; }

                if ((float) $it['x'] < $kiriTabel) {
                    // Label sub-baris "Masuk"/"Pulang" bukan isi kolom Nama/Jabatan.
                    if (preg_match('/^(masuk|pulang)$/i', $tRun)) { continue; }
                    // Kolom Nama & Jabatan ditentukan PER KATA (bukan per run gabungan, juga
                    // bukan per huruf): run bisa menggabungkan ujung nama dengan jabatan
                    // ("DjamaludinGuru") sehingga jabatan ikut masuk nama guru, sedangkan
                    // pemotongan per huruf memecah nama panjang yang melewati batas kolom.
                    // Kata = potongan asli yang bersambung (jarak ≤ 1,8 pt); kolomnya
                    // ditentukan dari posisi MULAI kata.
                    $potongan = $it['bag'] ?? [['x' => $it['x'], 'x2' => $it['x2'] ?? $it['x'], 'u' => 0.0, 'teks' => $tRun]];
                    // Toleransi jarak "masih satu kata" mengikuti ukuran huruf kolom ini
                    // (kolom Nama/Jabatan biasanya berhuruf lebih besar daripada sel tanggal).
                    $ukMax = 0.0;
                    foreach ($potongan as $g) { $ukMax = max($ukMax, (float) ($g['u'] ?? 0)); }
                    $tolKataLokal = $ukMax > 0 ? max(0.45, 0.42 * $ukMax) : max(0.45, 1.8 * $f);
                    $kata = [];
                    $sebelum = null;
                    foreach ($potongan as $g) {
                        $tg = ai_bersih_teks((string) $g['teks']);
                        if ($tg === '') { continue; }
                        $gx = (float) $g['x'];
                        $gx2 = (float) ($g['x2'] ?? $gx);
                        if ($sebelum !== null && $gx - $sebelum <= $tolKataLokal && $kata) {
                            $akhir = count($kata) - 1;
                            $kata[$akhir]['teks'] .= $tg;
                            $kata[$akhir]['x2'] = max($kata[$akhir]['x2'], $gx2);
                            $kata[$akhir]['u'] = max($kata[$akhir]['u'], (float) ($g['u'] ?? 0));
                        } else {
                            $kata[] = ['x' => $gx, 'x2' => $gx2, 'u' => (float) ($g['u'] ?? 0), 'teks' => $tg];
                        }
                        $sebelum = $gx2;
                    }
                    foreach ($kata as $g) {
                        if ($g['x'] < $batasNo) { continue; }       // kolom "No"
                        $kolom = $g['x'] < $batasNama ? 'nama' : ($g['x'] < $batasJab ? 'jabatan' : '');
                        if ($kolom === '') { continue; }
                        $guruLabel[$giPemilik]['kiri'][$kolom][] = ['y' => $y, 'x' => $g['x'], 'x2' => $g['x2'], 'u' => (float) ($g['u'] ?? 0), 'teks' => $g['teks']];
                    }
                    continue;
                }

                $x = ((float) $it['x'] + (float) ($it['x2'] ?? $it['x'])) / 2;
                // kolom tanggal
                $idx = null;
                foreach ($batas as $k => [$a, $b]) {
                    if ($x >= $a && $x < $b) { $idx = $k; break; }
                }
                if ($idx === null) { continue; }
                $jam = ai_jam($tRun);
                if ($jam === '' && preg_match('/(\d{1,2}[:.]\d{2}(?:[:.]\d{2})?)/', $tRun, $jm)) { $jam = ai_jam($jm[1]); }
                $ket = ai_keterangan($tRun);
                if ($jam !== '') { $guruLabel[$giPemilik]['sel'][$idx][$subPemilik]['jam'] = $jam; }
                if ($ket !== '') { $guruLabel[$giPemilik]['sel'][$idx][$subPemilik]['ket'] = $ket; }
            }
        }

        // Nama & jabatan: susun ulang per baris (potongan digabung menurut jarak huruf),
        // lalu baris-barisnya digabung urut atas → bawah.
        $susun = static function (array $potongan) use ($f): string {
                if (!$potongan) { return ''; }
                $barisK = [];
                foreach ($potongan as $p) {
                    $ketemu = null;
                    foreach ($barisK as $i => $b) {
                        if (abs($b['y'] - (float) $p['y']) <= 1.2 * $f) { $ketemu = $i; break; }
                    }
                    if ($ketemu === null) { $barisK[] = ['y' => (float) $p['y'], 'isi' => [$p]]; }
                    else { $barisK[$ketemu]['isi'][] = $p; }
                }
                usort($barisK, static fn($a, $b) => $b['y'] <=> $a['y']);
                $teks = [];
                foreach ($barisK as $b) {
                    $isi = $b['isi'];
                    usort($isi, static fn($a, $c) => $a['x'] <=> $c['x']);
                    $ukBaris = 0.0;
                    foreach ($isi as $p) { $ukBaris = max($ukBaris, (float) ($p['u'] ?? 0)); }
                    $tolKataBaris = $ukBaris > 0 ? max(0.45, 0.42 * $ukBaris) : max(0.45, 1.8 * $f);
                    $satu = '';
                    $akhirSebelum = null;
                    foreach ($isi as $p) {
                        $t = ai_bersih_teks((string) $p['teks']);
                        if ($t === '') { continue; }
                        $x = (float) $p['x'];
                        if ($satu !== '') {
                            // jarak antar potongan kecil = masih satu kata
                            $satu .= ($akhirSebelum !== null && $x - $akhirSebelum > $tolKataBaris) ? ' ' . $t : $t;
                        } else {
                            $satu = $t;
                        }
                        $akhirSebelum = (float) ($p['x2'] ?? $x);
                    }
                    $satu = trim($satu);
                    if ($satu !== '') { $teks[] = $satu; }
                }
                $gab = implode(' ', $teks);
                // Pisahkan kata yang menempel: "AstiaA." → "Astia A.", "Djufri,M.Pd" → "Djufri, M.Pd"
                $gab = preg_replace('/([a-z])([A-Z])/', '$1 $2', $gab);
                $gab = preg_replace('/,(\S)/', ', $1', $gab);
                $gab = preg_replace('/\s+/', ' ', $gab);
                return trim((string) $gab);
        };

        // Tahap 1: susun nama & jabatan tiap guru, lalu kumpulkan daftar JABATAN yang dikenal
        // pada halaman ini (mis. "Guru", "Kepala Madrasah").
        $kenalJabatan = [];   // huruf kecil => teks asli
        foreach ($guruLabel as $gi => $gl) {
            $guruLabel[$gi]['nama'] = $susun($gl['kiri']['nama'] ?? []);
            $guruLabel[$gi]['jabatan'] = $susun($gl['kiri']['jabatan'] ?? []);
            $jab = ai_bersih_teks((string) $guruLabel[$gi]['jabatan']);
            if ($jab !== '') {
                $k = strtolower($jab);
                if (!isset($kenalJabatan[$k])) { $kenalJabatan[$k] = $jab; }
            }
        }
        // Tahap 2: rapikan nama. Bila kolom Nama & Jabatan tercetak berdempetan, kata jabatan
        // ikut terbaca sebagai bagian nama ("Yelfis DjamaludinGuru") — buang kata itu dari nama
        // bila memang jabatan yang dikenal pada halaman ini.
        foreach ($guruLabel as $gi => $gl) {
            $nama = (string) ($gl['nama'] ?? '');
            if ($nama === '') { continue; }
            foreach ($kenalJabatan as $kunci => $jabAsli) {
                $pola = '/(?<![A-Za-z])' . preg_quote($kunci, '/') . '(?![A-Za-z])/i';
                if (!preg_match($pola, $nama)) { continue; }
                $sisa = trim((string) preg_replace('/\s+/', ' ', (string) preg_replace($pola, ' ', $nama)));
                if ($sisa === '' || preg_match('/^[,;.\s]*$/', $sisa)) { continue; }   // jangan sampai nama hilang
                $nama = $sisa;
                if (trim((string) ($gl['jabatan'] ?? '')) === '') { $guruLabel[$gi]['jabatan'] = $jabAsli; }
            }
            $guruLabel[$gi]['nama'] = ai_bersih_teks($nama);
        }

        foreach ($guruLabel as $gl) {
            if (empty($gl['sel'])) { continue; }
            $nama = (string) ($gl['nama'] ?? '');
            $jabatan = ai_bersih_teks((string) ($gl['jabatan'] ?? ''));
            if ($nama === '') { continue; }

            $tanggal = [];
            foreach ($gl['sel'] as $idx => $isi) {
                $hari = (int) $kolomTanggal[$idx]['n'];
                if ($hari < 1 || $hari > $hariMaks) { continue; }
                $tgl = sprintf('%04d-%02d-%02d', $tahunAngka, $bulanAngka, $hari);
                $masuk = $isi['masuk']['jam'] ?? '';
                $keluar = $isi['pulang']['jam'] ?? '';
                if ($masuk === '' && $keluar === '') { continue; }
                $tanggal[$tgl] = [
                    'masuk'    => $masuk,
                    'keluar'   => $keluar,
                    'k_masuk'  => $isi['masuk']['ket'] ?? '',
                    'k_pulang' => $isi['pulang']['ket'] ?? '',
                ];
            }
            if (!$tanggal) { continue; }

            // Gabungkan bila guru yang sama muncul lagi (halaman berikutnya / nama ganda).
            $kunci = ai_kunci_nama($nama);
            $ada = null;
            foreach ($hasil['guru'] as $gi => $g) {
                if (ai_kunci_nama((string) $g['nama']) === $kunci) { $ada = $gi; break; }
            }
            if ($ada === null) {
                $hasil['guru'][] = ['nama' => $nama, 'jabatan' => $jabatan, 'tanggal' => $tanggal];
            } else {
                $hasil['guru'][$ada]['tanggal'] = $tanggal + $hasil['guru'][$ada]['tanggal'];
                if ($hasil['guru'][$ada]['jabatan'] === '' && $jabatan !== '') {
                    $hasil['guru'][$ada]['jabatan'] = $jabatan;
                }
            }
        }
    }

    if (!$hasil['guru']) {
        $hasil['galat'] = 'Tidak ada data kehadiran guru yang dapat dibaca. Pastikan berkasnya PDF laporan absensi bulanan (bukan hasil pindai/gambar).';
        if ($hasil['peringatan']) {
            $hasil['galat'] .= ' Rincian: ' . implode(' ', array_slice($hasil['peringatan'], 0, 3));
        }
    }
    return $hasil;
}

/** Kunci pencocokan nama guru (huruf kecil, tanpa tanda baca & gelar depan). */
function ai_kunci_nama(string $nama): string
{
    $nama = ai_bersih_teks($nama);
    $nama = strtolower($nama);
    $nama = preg_replace('/[^a-z0-9 ]+/', ' ', $nama);
    $nama = trim(preg_replace('/\s+/', ' ', (string) $nama));
    // Buang gelar/sapaan di depan (h, hj, drs, dra, dr, prof, ir, kh, ust, ustadz, pak, bu).
    $kata = explode(' ', $nama);
    $gelar = ['h', 'hj', 'drs', 'dra', 'dr', 'prof', 'ir', 'kh', 'ust', 'ustadz', 'ustadzah', 'pak', 'bu', 'ibu', 'bapak'];
    while (count($kata) > 2 && in_array($kata[0], $gelar, true)) { array_shift($kata); }
    return implode(' ', $kata);
}

/**
 * Mencocokkan nama guru hasil PDF dengan data guru aplikasi.
 * @return array<int, int|null> indeks hasil => guru_id (null bila tidak cocok)
 */
function ai_cocok_guru(array $hasilGuru, array $daftarGuru): array
{
    $petaKunci = [];
    $petaAwal = [];
    foreach ($daftarGuru as $g) {
        $k = ai_kunci_nama((string) $g['nama']);
        $petaKunci[$k] = (int) $g['id'];
        $awal = explode(' ', $k)[0] ?? '';
        if ($awal !== '' && !isset($petaAwal[$awal])) { $petaAwal[$awal] = (int) $g['id']; }
    }
    $cocok = [];
    foreach ($hasilGuru as $i => $h) {
        $k = ai_kunci_nama((string) $h['nama']);
        if (isset($petaKunci[$k])) { $cocok[$i] = $petaKunci[$k]; continue; }
        // cadangan: nama PDF memuat seluruh nama guru, atau kata awalnya sama & unik
        $ketemu = null;
        foreach ($petaKunci as $kk => $id) {
            if ($kk !== '' && (str_contains($k, $kk) || str_contains($kk, $k))) { $ketemu = $id; break; }
        }
        if ($ketemu === null) {
            $awal = explode(' ', $k)[0] ?? '';
            if ($awal !== '' && isset($petaAwal[$awal])) { $ketemu = $petaAwal[$awal]; }
        }
        $cocok[$i] = $ketemu;
    }
    return $cocok;
}
