<?php
/**
 * Pembaca teks PDF sederhana (tanpa pustaka luar / tanpa ZipArchive).
 *
 * Dipakai untuk mengimpor laporan absensi bulanan dari aplikasi lain (berkas PDF hasil cetak
 * peramban). Kemampuannya sengaja dibatasi pada kebutuhan itu:
 *   - objek PDF biasa (bukan object-stream terkompresi),
 *   - stream dengan filter FlateDecode / ASCIIHexDecode / ASCII85Decode,
 *   - operator teks BT/ET, Tf, Td, TD, Tm, T*, TL, Tj, TJ, ' dan ",
 *   - font sederhana (1 byte) DAN Type0/Identity-H (2 byte) + peta /ToUnicode.
 *
 * Keluaran: daftar halaman, tiap halaman berisi daftar potongan teks + koordinat (x, y) dari
 * matriks teks PDF (satuan poin, y makin besar = makin ATAS). Urutan tidak dijamin; pemanggil
 * mengelompokkan sendiri berdasarkan koordinat.
 */
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

/** Menyimpan hasil parsing objek PDF: [nomor => ['body' => string, 'pos' => int]]. */
function pdf_objek(string $bytes): array
{
    $isi = $bytes;
    $objek = [];
    $offset = 0;
    $panjang = strlen($isi);
    // Cari pola "N G obj" di seluruh berkas.
    while ($offset < $panjang && preg_match('/(\d+)\s+(\d+)\s+obj\b/', $isi, $m, PREG_OFFSET_CAPTURE, $offset)) {
        $nomor = (int) $m[1][0];
        $mulai = (int) $m[0][1] + strlen($m[0][0]);
        $akhir = strpos($isi, 'endobj', $mulai);
        if ($akhir === false) {
            break;
        }
        $body = substr($isi, $mulai, $akhir - $mulai);
        $objek[$nomor] = ['body' => $body, 'pos' => $mulai];
        $offset = $akhir + 6;
    }
    return $objek;
}

/** Mengambil data mentah stream dari badan objek (tanpa dekode). */
function pdf_stream_mentah(string $body): ?string
{
    $pos = strpos($body, 'stream');
    if ($pos === false) {
        return null;
    }
    $mulai = $pos + 6;
    // Lewati EOL setelah kata "stream".
    if (substr($body, $mulai, 2) === "\r\n") {
        $mulai += 2;
    } elseif (substr($body, $mulai, 1) === "\n" || substr($body, $mulai, 1) === "\r") {
        $mulai += 1;
    }
    $akhir = strrpos($body, 'endstream');
    if ($akhir === false || $akhir < $mulai) {
        return null;
    }
    $data = substr($body, $mulai, $akhir - $mulai);
    // Buang satu EOL sebelum endstream bila ada.
    $data = preg_replace("/\r?\n$/", '', $data);
    return $data;
}

/** Daftar filter stream dari badan objek (nama filter huruf besar semua). */
function pdf_filter(string $body): array
{
    if (!preg_match('/\/Filter\s*(\[[^\]]*\]|\/\w+)/', $body, $m)) {
        return [];
    }
    preg_match_all('/\/([A-Za-z0-9]+)/', $m[1], $f);
    return $f[1] ?? [];
}

/** Dekode data stream sesuai filternya. */
function pdf_dekode(string $data, array $filter): string
{
    foreach ($filter as $f) {
        if ($f === 'FlateDecode' || $f === 'Fl') {
            $out = @zlib_decode($data);
            if ($out === false) { $out = @gzuncompress($data); }
            if ($out === false) { $out = @gzinflate($data); }
            if ($out === false) { return ''; }
            $data = $out;
        } elseif ($f === 'ASCIIHexDecode' || $f === 'AHx') {
            $hex = preg_replace('/[^0-9A-Fa-f]/', '', $data);
            if (strlen($hex) % 2 === 1) { $hex .= '0'; }
            $data = (string) hex2bin($hex);
        } elseif ($f === 'ASCII85Decode' || $f === 'A85') {
            $t = preg_replace('/\s+/', '', $data);
            $t = preg_replace('/^<~/', '', $t);
            $t = preg_replace('/~>$/', '', $t);
            $out = '';
            $buf = '';
            $len = strlen($t);
            for ($i = 0; $i < $len; $i++) {
                $c = $t[$i];
                if ($c === 'z' && $buf === '') { $out .= "\0\0\0\0"; continue; }
                $buf .= $c;
                if (strlen($buf) === 5) {
                    $n = 0;
                    for ($k = 0; $k < 5; $k++) { $n = $n * 85 + (ord($buf[$k]) - 33); }
                    $out .= chr(($n >> 24) & 0xFF) . chr(($n >> 16) & 0xFF) . chr(($n >> 8) & 0xFF) . chr($n & 0xFF);
                    $buf = '';
                }
            }
            if ($buf !== '') {
                $pad = 5 - strlen($buf);
                $buf .= str_repeat('u', $pad);
                $n = 0;
                for ($k = 0; $k < 5; $k++) { $n = $n * 85 + (ord($buf[$k]) - 33); }
                $empat = chr(($n >> 24) & 0xFF) . chr(($n >> 16) & 0xFF) . chr(($n >> 8) & 0xFF) . chr($n & 0xFF);
                $out .= substr($empat, 0, 4 - $pad);
            }
            $data = $out;
        } else {
            // Filter tak dikenal (mis. DCTDecode untuk gambar) → bukan teks.
            return '';
        }
    }
    return $data;
}

/**
 * Mencari nilai sebuah kunci kamus PDF: bila nilainya kamus (<< … >>) kembalikan ISI kamus
 * (berimbang, termasuk kamus bersarang), bila rujukan (N 0 R) kembalikan nomor objeknya.
 * Dibutuhkan karena regex non-greedy berhenti di ">>" pertama (kamus bersarang).
 */
function pdf_kamus(string $isi, string $kunci): ?string
{
    $pos = 0;
    $panjang = strlen($isi);
    while (($p = strpos($isi, $kunci, $pos)) !== false) {
        // Pastikan batas token (bukan bagian dari nama lain).
        $sebelum = $p > 0 ? $isi[$p - 1] : ' ';
        $setelah = $p + strlen($kunci);
        $j = $setelah;
        while ($j < $panjang && strpos(" \t\r\n", $isi[$j]) !== false) { $j++; }
        $lanjut = $setelah + 1;
        if (!in_array($sebelum, ['/', '-', '_', '0', '1', '2', '3', '4', '5', '6', '7', '8', '9'], true)) {
            if (substr($isi, $j, 2) === '<<') {
                $depth = 0;
                $k = $j;
                while ($k < $panjang - 1) {
                    if (substr($isi, $k, 2) === '<<') { $depth++; $k += 2; continue; }
                    if (substr($isi, $k, 2) === '>>') {
                        $depth--;
                        $k += 2;
                        if ($depth === 0) { return substr($isi, $j + 2, $k - $j - 4); }
                        continue;
                    }
                    $k++;
                }
            }
            if (preg_match('/^(\d+)\s+\d+\s+R/', substr($isi, $j, 32), $m)) {
                return 'ref:' . $m[1];
            }
        }
        $pos = $lanjut;
    }
    return null;
}

/** Mencari nomor objek pertama yang dirujuk setelah kata kunci tertentu (mis. /ToUnicode 7 0 R). */
function pdf_rujukan(string $body, string $kunci): ?int
{
    if (preg_match('/' . preg_quote($kunci, '/') . '\s+(\d+)\s+\d+\s+R/', $body, $m)) {
        return (int) $m[1];
    }
    return null;
}

/** Mengurai /ToUnicode CMap menjadi peta kode (string hex) => teks. */
function pdf_cmap(string $cmap): array
{
    $peta = [];
    // bfchar: <0001> <0041>  atau  <01> <0061>
    if (preg_match_all('/beginbfchar(.*?)endbfchar/s', $cmap, $blok)) {
        foreach ($blok[1] as $b) {
            if (preg_match_all('/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>/', $b, $pasang, PREG_SET_ORDER)) {
                foreach ($pasang as $p) {
                    $peta[strtoupper($p[1])] = pdf_unicode_hex($p[2]);
                }
            }
        }
    }
    // bfrange: <lo> <hi> <dst>  atau  <lo> <hi> [<d1> <d2> …]
    if (preg_match_all('/beginbfrange(.*?)endbfrange/s', $cmap, $blok)) {
        foreach ($blok[1] as $b) {
            if (preg_match_all('/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>\s*(<[0-9A-Fa-f]+>|\[[^\]]*\])/', $b, $baris, PREG_SET_ORDER)) {
                foreach ($baris as $r) {
                    $lo = hexdec($r[1]);
                    $hi = hexdec($r[2]);
                    $lebar = max(2, strlen($r[1]));
                    if (str_starts_with($r[3], '[')) {
                        preg_match_all('/<([0-9A-Fa-f]+)>/', $r[3], $daftar);
                        foreach ($daftar[1] as $i => $dst) {
                            $kode = $lo + $i;
                            if ($kode > $hi) { break; }
                            $peta[strtoupper(str_pad(dechex($kode), $lebar, '0', STR_PAD_LEFT))] = pdf_unicode_hex($dst);
                        }
                    } else {
                        $dstHex = trim($r[3], '<>');
                        $lebarDst = max(4, strlen($dstHex));
                        $dst = hexdec($dstHex);
                        for ($kode = $lo; $kode <= $hi; $kode++) {
                            $tujuan = str_pad(dechex($dst + ($kode - $lo)), $lebarDst, '0', STR_PAD_LEFT);
                            $peta[strtoupper(str_pad(dechex($kode), $lebar, '0', STR_PAD_LEFT))] = pdf_unicode_hex($tujuan);
                        }
                    }
                }
            }
        }
    }
    return $peta;
}

/** Hex UTF-16BE (atau byte tunggal) → teks UTF-8. */
function pdf_unicode_hex(string $hex): string
{
    $hex = strtoupper(preg_replace('/[^0-9A-Fa-f]/', '', $hex));
    if ($hex === '') { return ''; }
    if (strlen($hex) % 2 === 1) { $hex = '0' . $hex; }
    // Satu code unit (≤ 4 digit hex) = satu karakter BMP.
    if (strlen($hex) <= 4) {
        $cp = hexdec($hex);
        return $cp === 0 ? ' ' : pdf_utf8($cp);
    }
    $out = '';
    for ($i = 0; $i < strlen($hex); $i += 4) {
        $potong = substr($hex, $i, 4);
        if (strlen($potong) < 4) { $potong = str_pad($potong, 4, '0', STR_PAD_RIGHT); }
        $cp = hexdec($potong);
        if ($cp === 0) { $out .= ' '; continue; }
        $out .= pdf_utf8($cp);
    }
    return $out;
}

function pdf_utf8(int $cp): string
{
    if ($cp < 0x80) { return chr($cp); }
    if ($cp < 0x800) { return chr(0xC0 | ($cp >> 6)) . chr(0x80 | ($cp & 0x3F)); }
    if ($cp < 0x10000) { return chr(0xE0 | ($cp >> 12)) . chr(0x80 | (($cp >> 6) & 0x3F)) . chr(0x80 | ($cp & 0x3F)); }
    return chr(0xF0 | ($cp >> 18)) . chr(0x80 | (($cp >> 12) & 0x3F)) . chr(0x80 | (($cp >> 6) & 0x3F)) . chr(0x80 | ($cp & 0x3F));
}

/** Membaca string PDF literal "( … )" menjadi byte asli (escape didahului backslash). */
function pdf_string_literal(string $s): string
{
    $out = '';
    $len = strlen($s);
    for ($i = 0; $i < $len; $i++) {
        $c = $s[$i];
        if ($c !== '\\') { $out .= $c; continue; }
        $i++;
        if ($i >= $len) { break; }
        $n = $s[$i];
        if ($n === 'n') { $out .= "\n"; }
        elseif ($n === 'r') { $out .= "\r"; }
        elseif ($n === 't') { $out .= "\t"; }
        elseif ($n === 'b') { $out .= "\x08"; }
        elseif ($n === 'f') { $out .= "\x0C"; }
        elseif ($n === '(' || $n === ')' || $n === '\\') { $out .= $n; }
        elseif ($n >= '0' && $n <= '7') {
            $okt = $n;
            for ($k = 0; $k < 2 && $i + 1 < $len && $s[$i + 1] >= '0' && $s[$i + 1] <= '7'; $k++) {
                $i++;
                $okt .= $s[$i];
            }
            $out .= chr(octdec($okt) & 0xFF);
        } elseif ($n === "\n") { /* baris lanjutan: tidak menghasilkan apa pun */ }
        elseif ($n === "\r") { if ($i + 1 < $len && $s[$i + 1] === "\n") { $i++; } }
        else { $out .= $n; }
    }
    return $out;
}

/** Perkalian matriks PDF [a b c d e f] (terapkan $m1 lebih dulu, lalu $m2). */
function pdf_mkali(array $m1, array $m2): array
{
    return [
        $m1[0] * $m2[0] + $m1[1] * $m2[2],
        $m1[0] * $m2[1] + $m1[1] * $m2[3],
        $m1[2] * $m2[0] + $m1[3] * $m2[2],
        $m1[2] * $m2[1] + $m1[3] * $m2[3],
        $m1[4] * $m2[0] + $m1[5] * $m2[2] + $m2[4],
        $m1[4] * $m2[1] + $m1[5] * $m2[3] + $m2[5],
    ];
}

/** Memecah content stream menjadi token + operator, lalu mengumpulkan potongan teks berkoordinat. */
function pdf_teks_content(string $content, array $fontPeta, int $halaman): array
{
    $items = [];
    $len = strlen($content);
    $i = 0;
    $operand = [];
    $stack = [];
    // matriks teks: [a,b,c,d,e,f]
    $tm = [1, 0, 0, 1, 0, 0];
    $tlm = $tm;
    $ctm = [1, 0, 0, 1, 0, 0];   // matriks ruang perangkat (penting: banyak PDF memakai cm terbalik)
    $leading = 0.0;
    $font = '';
    $ukuran = 10.0;

    $gabung = static function (string $bytes, string $font) use ($fontPeta): string {
        $peta = $fontPeta[$font] ?? ['peta' => [], 'dua' => false];
        $dua = (bool) ($peta['dua'] ?? false);
        $map = $peta['peta'] ?? [];
        $out = '';
        if ($dua) {
            for ($k = 0; $k + 1 < strlen($bytes); $k += 2) {
                $kode = strtoupper(bin2hex(substr($bytes, $k, 2)));
                $out .= $map[$kode] ?? '';
            }
            return $out;
        }
        for ($k = 0; $k < strlen($bytes); $k++) {
            $kode = strtoupper(bin2hex($bytes[$k]));
            if (isset($map[$kode])) { $out .= $map[$kode]; continue; }
            if (isset($map['0' . $kode])) { $out .= $map['0' . $kode]; continue; }
            $o = ord($bytes[$k]);
            $out .= $o < 32 ? ' ' : pdf_utf8($o);
        }
        return $out;
    };

    $tambah = static function (string $teks) use (&$items, &$tm, &$ctm, &$ukuran, $halaman): void {
        $teks = str_replace("\0", '', $teks);
        if (trim($teks) === '') { return; }
        $dev = pdf_mkali($tm, $ctm);
        // Ukuran huruf EFEKTIF di halaman (poin perangkat): ukuran Tf × skala matriks teks
        // dan matriks halaman. Penting karena PDF hasil cetak bisa diperkecil (mis. laporan
        // lebar dicetak pada kertas portrait) — tanpa ini jarak antar baris/huruf tidak
        // sebanding dengan ukuran huruf dan pengelompokan baris bisa salah.
        $skala = sqrt(abs($dev[0] * $dev[3] - $dev[1] * $dev[2]));
        if (!is_finite($skala) || $skala <= 0) { $skala = 1.0; }
        $items[] = [
            'h' => $halaman,
            'x' => round($dev[4], 2),
            'y' => round($dev[5], 2),
            'teks' => $teks,
            'ukuran' => $ukuran * $skala,
        ];
    };

    while ($i < $len) {
        $c = $content[$i];
        if ($c === '%' ) { // komentar
            $nl = strpos($content, "\n", $i);
            $i = $nl === false ? $len : $nl + 1;
            continue;
        }
        if ($c === ' ' || $c === "\r" || $c === "\n" || $c === "\t" || $c === "\f" || $c === "\0") { $i++; continue; }
        if ($c === '(') { // string literal
            $depth = 1; $mulai = $i + 1; $j = $i + 1; $raw = '';
            while ($j < $len && $depth > 0) {
                $ch = $content[$j];
                if ($ch === '\\') { $raw .= substr($content, $j, 2); $j += 2; continue; }
                if ($ch === '(') { $depth++; }
                elseif ($ch === ')') { $depth--; if ($depth === 0) { break; } }
                $raw .= $ch;
                $j++;
            }
            $operand[] = ['t' => 'str', 'v' => pdf_string_literal($raw)];
            $i = $j + 1;
            continue;
        }
        if ($c === '<' && substr($content, $i, 2) !== '<<') { // string hex
            $akhir = strpos($content, '>', $i);
            if ($akhir === false) { break; }
            $hex = preg_replace('/[^0-9A-Fa-f]/', '', substr($content, $i + 1, $akhir - $i - 1));
            if (strlen($hex) % 2 === 1) { $hex .= '0'; }
            $operand[] = ['t' => 'str', 'v' => (string) hex2bin($hex)];
            $i = $akhir + 1;
            continue;
        }
        if ($c === '<' && substr($content, $i, 2) === '<<') { // kamus: lewati berimbang
            $depth = 0; $j = $i;
            while ($j < $len) {
                if (substr($content, $j, 2) === '<<') { $depth++; $j += 2; continue; }
                if (substr($content, $j, 2) === '>>') { $depth--; $j += 2; if ($depth === 0) { break; } continue; }
                $j++;
            }
            $operand[] = ['t' => 'lain', 'v' => substr($content, $i, $j - $i)];
            $i = $j;
            continue;
        }
        if ($c === '[') {
            $depth = 0; $j = $i;
            while ($j < $len) {
                $ch = $content[$j];
                if ($ch === '[') { $depth++; }
                elseif ($ch === ']') { $depth--; if ($depth === 0) { break; } }
                elseif ($ch === '(') { // lompati string di dalam array
                    $d2 = 1; $j++;
                    while ($j < $len && $d2 > 0) {
                        if ($content[$j] === '\\') { $j += 2; continue; }
                        if ($content[$j] === '(') { $d2++; }
                        elseif ($content[$j] === ')') { $d2--; }
                        $j++;
                    }
                }
                $j++;
            }
            $operand[] = ['t' => 'arr', 'v' => substr($content, $i, $j - $i + 1)];
            $i = $j + 1;
            continue;
        }
        if ($c === '/') { // nama
            $j = $i + 1;
            while ($j < $len && !in_array($content[$j], [' ', "\r", "\n", "\t", '/', '[', ']', '(', ')', '<', '>'], true)) { $j++; }
            $operand[] = ['t' => 'name', 'v' => substr($content, $i, $j - $i)];
            $i = $j;
            continue;
        }
        if (preg_match('/^[A-Za-z\'"*]+[0-9]?\*?/', substr($content, $i, 8), $m)) {
            $op = $m[0];
            $i += strlen($op);
            // operator
            switch ($op) {
                case 'BT':
                    $tm = [1, 0, 0, 1, 0, 0];
                    $tlm = $tm;
                    break;
                case 'cm2':
                    break;
                case 'Tf':
                    $namaFont = '';
                    $sz = $ukuran;
                    foreach ($operand as $o) {
                        if ($o['t'] === 'name') { $namaFont = ltrim($o['v'], '/'); }
                        if ($o['t'] === 'num') { $sz = (float) $o['v']; }
                    }
                    $font = $namaFont;
                    $ukuran = $sz;
                    break;
                case 'TL':
                    foreach ($operand as $o) { if ($o['t'] === 'num') { $leading = (float) $o['v']; } }
                    break;
                case 'Td':
                case 'TD':
                    $angka = [];
                    foreach ($operand as $o) { if ($o['t'] === 'num') { $angka[] = (float) $o['v']; } }
                    if (count($angka) >= 2) {
                        $tx = $angka[0]; $ty = $angka[1];
                        $tlm = [$tlm[0], $tlm[1], $tlm[2], $tlm[3],
                            $tlm[4] + $tx * $tlm[0] + $ty * $tlm[2],
                            $tlm[5] + $tx * $tlm[1] + $ty * $tlm[3]];
                        $tm = $tlm;
                        if ($op === 'TD') { $leading = -$ty; }
                    }
                    break;
                case 'Tm':
                    $angka = [];
                    foreach ($operand as $o) { if ($o['t'] === 'num') { $angka[] = (float) $o['v']; } }
                    if (count($angka) >= 6) {
                        $tm = [$angka[0], $angka[1], $angka[2], $angka[3], $angka[4], $angka[5]];
                        $tlm = $tm;
                    }
                    break;
                case 'T*':
                    $tlm = [$tlm[0], $tlm[1], $tlm[2], $tlm[3], $tlm[4] - $leading * $tlm[2], $tlm[5] - $leading * $tlm[3]];
                    $tm = $tlm;
                    break;
                case 'Tj':
                    foreach ($operand as $o) {
                        if ($o['t'] === 'str') { $tambah($gabung($o['v'], $font)); }
                    }
                    break;
                case 'TJ':
                    foreach ($operand as $o) {
                        if ($o['t'] !== 'arr') { continue; }
                        if (preg_match_all('/\(((?:\\\\.|[^\\\\()])*)\)|<([0-9A-Fa-f\s]+)>|(-?[\d.]+)/s', $o['v'], $bag, PREG_SET_ORDER)) {
                            $teks = '';
                            foreach ($bag as $b) {
                                if (($b[1] ?? '') !== '' || (isset($b[0][0]) && $b[0][0] === '(')) {
                                    $teks .= $gabung(pdf_string_literal($b[1] ?? ''), $font);
                                } elseif (($b[2] ?? '') !== '') {
                                    $hex = preg_replace('/[^0-9A-Fa-f]/', '', $b[2]);
                                    if (strlen($hex) % 2 === 1) { $hex .= '0'; }
                                    $teks .= $gabung((string) hex2bin($hex), $font);
                                } elseif (($b[3] ?? '') !== '') {
                                    $geser = (float) $b[3];
                                    if ($geser < -120) { $teks .= ' '; }
                                }
                            }
                            $tambah($teks);
                        }
                    }
                    break;
                case "'":
                    $tlm = [$tlm[0], $tlm[1], $tlm[2], $tlm[3], $tlm[4] - $leading * $tlm[2], $tlm[5] - $leading * $tlm[3]];
                    $tm = $tlm;
                    foreach ($operand as $o) { if ($o['t'] === 'str') { $tambah($gabung($o['v'], $font)); } }
                    break;
                case '"':
                    $tlm = [$tlm[0], $tlm[1], $tlm[2], $tlm[3], $tlm[4] - $leading * $tlm[2], $tlm[5] - $leading * $tlm[3]];
                    $tm = $tlm;
                    foreach ($operand as $o) { if ($o['t'] === 'str') { $tambah($gabung($o['v'], $font)); } }
                    break;
                case 'q':
                    $stack[] = [$tm, $tlm, $leading, $font, $ukuran, $ctm];
                    break;
                case 'Q':
                    if ($stack) { [$tm, $tlm, $leading, $font, $ukuran, $ctm] = array_pop($stack); }
                    break;
                case 'cm':
                    $angka = [];
                    foreach ($operand as $o) { if ($o['t'] === 'num') { $angka[] = (float) $o['v']; } }
                    if (count($angka) >= 6) { $ctm = pdf_mkali($angka, $ctm); }
                    break;
                default:
                    break;
            }
            $operand = [];
            continue;
        }
        // angka atau kata lain
        if (preg_match('/^-?\d*\.?\d+/', substr($content, $i, 40), $m)) {
            $operand[] = ['t' => 'num', 'v' => $m[0]];
            $i += strlen($m[0]);
            continue;
        }
        $i++;
    }
    return $items;
}

/**
 * Membaca seluruh teks PDF.
 *
 * @return array{halaman: array<int, array<int, array{x: float, y: float, teks: string}>>, galat: string}
 */
function pdf_teks(string $bytes): array
{
    $hasil = ['halaman' => [], 'galat' => ''];
    if (strncmp($bytes, '%PDF', 4) !== 0) {
        $hasil['galat'] = 'Berkas bukan PDF yang sah.';
        return $hasil;
    }
    $objek = pdf_objek($bytes);

    // Peta font per nomor objek: ['peta' => [...], 'dua' => bool]
    $fontCache = [];
    $fontPeta = static function (int $nomor) use ($objek, &$fontCache): array {
        if (isset($fontCache[$nomor])) { return $fontCache[$nomor]; }
        $hasil = ['peta' => [], 'dua' => false];
        $body = $objek[$nomor]['body'] ?? '';
        if ($body === '') { return $fontCache[$nomor] = $hasil; }
        if (preg_match('/\/Subtype\s*\/Type0/', $body)) { $hasil['dua'] = true; }
        $tu = pdf_rujukan($body, '/ToUnicode');
        if ($tu !== null && isset($objek[$tu])) {
            $data = pdf_stream_mentah($objek[$tu]['body']);
            if ($data !== null) {
                $data = pdf_dekode($data, pdf_filter($objek[$tu]['body']));
                if ($data !== '') { $hasil['peta'] = pdf_cmap($data); }
            }
        }
        return $fontCache[$nomor] = $hasil;
    };

    $halamanKe = 0;
    foreach ($objek as $nomor => $o) {
        $body = $o['body'];
        if (!preg_match('/\/Type\s*\/Page\b/', $body)) { continue; }
        $halamanKe++;

        // Font pada halaman ini (Resources bisa kamus bersarang / rujukan objek).
        $petaHalaman = [];
        $resBody = '';
        $res = pdf_kamus($body, '/Resources');
        if ($res !== null && str_starts_with($res, 'ref:')) {
            $resBody = $objek[(int) substr($res, 4)]['body'] ?? '';
        } elseif ($res !== null) {
            $resBody = $res;
        }
        if ($resBody !== '') {
            $fontBlok = pdf_kamus($resBody, '/Font');
            if ($fontBlok !== null && str_starts_with($fontBlok, 'ref:')) {
                $fontBlok = $objek[(int) substr($fontBlok, 4)]['body'] ?? '';
            }
            if ($fontBlok !== null && preg_match_all('/\/([A-Za-z0-9]+)\s+(\d+)\s+\d+\s+R/', $fontBlok, $fmap, PREG_SET_ORDER)) {
                foreach ($fmap as $f) {
                    $petaHalaman[$f[1]] = (int) $f[2];
                }
            }
        }
        $petaFontHalaman = [];
        foreach ($petaHalaman as $nama => $fnum) {
            $petaFontHalaman[$nama] = $fontPeta($fnum);
        }

        // Content stream (bisa berupa array rujukan)
        $isi = '';
        $refs = [];
        if (preg_match('/\/Contents\s*\[\s*([^\]]*)\]/s', $body, $cm)) {
            if (preg_match_all('/(\d+)\s+\d+\s+R/', $cm[1], $cr)) { $refs = array_map('intval', $cr[1]); }
        } elseif (($cref = pdf_rujukan($body, '/Contents')) !== null) {
            $refs = [$cref];
        }
        foreach ($refs as $r) {
            $cd = pdf_stream_mentah($objek[$r]['body'] ?? '');
            if ($cd === null) { continue; }
            $isi .= pdf_dekode($cd, pdf_filter($objek[$r]['body'])) . "\n";
        }
        if ($isi === '') { continue; }
        $items = pdf_teks_content($isi, $petaFontHalaman, $halamanKe);
        if ($items) { $hasil['halaman'][$halamanKe] = $items; }
    }
    if (!$hasil['halaman']) {
        $hasil['galat'] = 'Tidak ada teks yang dapat dibaca dari PDF ini (kemungkinan hasil pemindaian/gambar).';
    }
    return $hasil;
}
