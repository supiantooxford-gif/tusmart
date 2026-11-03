<?php
/**
 * Impor Data Guru & Tenaga Kependidikan dari Excel/CSV.
 * Berkas dibaca DI PERAMBAN (SheetJS) lalu dikirim sebagai JSON — server tidak menerima berkasnya.
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/init.php';
require_login();

$ringkas = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_wajib();
    $payload = json_decode((string) ($_POST['payload'] ?? ''), true);
    if (!is_array($payload) || empty($payload['baris'])) {
        flash('Tidak ada baris yang dapat diimpor. Pastikan berkas sudah dipilih dan kolom Nama terisi.', 'err');
        redirect('guru_impor.php');
    }
    $perbarui = f('perbarui') === '1';
    $baru = 0; $ubah = 0; $lewat = 0; $galat = 0;
    $catatan = [];
    $pdo->exec('BEGIN IMMEDIATE');
    try {
        foreach ($payload['baris'] as $r) {
            if (!is_array($r)) { $galat++; continue; }
            $nama = trim((string) ($r['nama'] ?? ''));
            if ($nama === '') { $lewat++; continue; }
            $jk = strtoupper(trim((string) ($r['jenis_kelamin'] ?? '')));
            $jk = in_array($jk, ['P', 'PEREMPUAN', 'WANITA'], true) ? 'P' : 'L';
            $aktifTeks = strtolower(trim((string) ($r['status'] ?? 'aktif')));
            $aktif = in_array($aktifTeks, ['nonaktif', 'non aktif', '0', 'tidak aktif'], true) ? 0 : 1;
            $tmt = trim((string) ($r['tmt'] ?? ''));
            if ($tmt !== '' && !tanggal_sah($tmt)) {
                $ts = strtotime($tmt);
                $tmt = $ts ? date('Y-m-d', $ts) : '';
            }
            $kol = [
                'nama' => $nama,
                'nip' => trim((string) ($r['nip'] ?? '')),
                'nuptk' => trim((string) ($r['nuptk'] ?? '')),
                'jenis_kelamin' => $jk,
                'jabatan' => trim((string) ($r['jabatan'] ?? 'Guru')),
                'mapel' => trim((string) ($r['mapel'] ?? '')),
                'email' => trim((string) ($r['email'] ?? '')),
                'telepon' => trim((string) ($r['telepon'] ?? '')),
                'alamat' => trim((string) ($r['alamat'] ?? '')),
                'tmt' => $tmt,
                'keterangan' => trim((string) ($r['keterangan'] ?? '')),
                'status' => $aktif === 1 ? 'aktif' : 'nonaktif',
                'aktif' => $aktif,
            ];
            $ada = satu('SELECT id FROM guru WHERE lower(nama) = lower(?)', [$nama]);
            if ($ada) {
                if (!$perbarui) { $lewat++; continue; }
                $set = implode(', ', array_map(static fn($k) => "$k = ?", array_keys($kol)));
                $arg = array_values($kol);
                $arg[] = date('Y-m-d H:i:s');
                $arg[] = (int) $ada['id'];
                q("UPDATE guru SET $set, diubah = ? WHERE id = ?", $arg);
                $ubah++;
            } else {
                $kol['urutan'] = (int) kolom('SELECT COALESCE(MAX(urutan),0) + 10 FROM guru');
                $kol['dibuat'] = date('Y-m-d H:i:s');
                $nama2 = array_keys($kol);
                q('INSERT INTO guru (' . implode(',', $nama2) . ') VALUES (' . implode(',', array_fill(0, count($nama2), '?')) . ')', array_values($kol));
                $baru++;
            }
        }
        $pdo->exec('COMMIT');
    } catch (Throwable $ex) {
        $pdo->exec('ROLLBACK');
        flash('Impor gagal: ' . $ex->getMessage(), 'err');
        redirect('guru_impor.php');
    }
    $ringkas = ['baru' => $baru, 'ubah' => $ubah, 'lewat' => $lewat, 'galat' => $galat, 'baris' => count($payload['baris'])];
    log_catat('impor_guru', $baru . ' baru, ' . $ubah . ' diperbarui');
    flash('Impor selesai: ' . $baru . ' data baru, ' . $ubah . ' diperbarui, ' . $lewat . ' dilewati.');
    redirect('guru.php');
}

$GLOBALS['__butuh_xlsx'] = true;
page_top('Impor Data Guru & Tendik', 'Administrasi kepegawaian');
?>
<div class="card">
  <div class="card-kepala">
    <div><h2>Impor dari Excel / CSV</h2>
      <p class="card-ket">Berkas dibaca di peramban Anda; hanya barisnya yang dikirim ke server. Urutan kolom bebas — nama kolom dikenali otomatis.</p></div>
    <div class="card-aksi"><a class="btn btn-garis" href="guru.php">Kembali</a></div>
  </div>

  <div class="alert alert-info">
    <div><b>Kolom yang dikenali:</b> Nama <i>(wajib)</i>, NIP, NUPTK, Jenis Kelamin, Jabatan, Mata Pelajaran, Email, Telepon, Alamat, TMT, Keterangan, Status.
      <br>Nama yang sudah ada diperbarui hanya bila opsi di bawah dicentang; kalau tidak, baris itu dilewati.</div>
  </div>

  <form method="post" id="formImpor">
    <?= csrf_field() ?>
    <input type="hidden" name="payload" id="payload" value="">
    <div class="form-grid f2">
      <div class="medan">
        <label for="berkas">Berkas Excel (.xlsx/.xls) atau CSV</label>
        <input type="file" id="berkas" accept=".xlsx,.xls,.csv">
        <span class="petunjuk">Baris pertama harus berisi nama kolom.</span>
      </div>
      <div class="medan">
        <label>Pilihan impor</label>
        <label class="centang"><input type="checkbox" name="perbarui" value="1"> Perbarui data yang namanya sudah ada</label>
      </div>
    </div>
    <div id="pratinjauImpor" class="mt16"></div>
    <div class="tombol-baris mt16">
      <button class="btn" type="submit" id="tombolImpor" disabled><?= ikon('unggah') ?> Impor Sekarang</button>
      <a class="btn btn-garis" href="guru.php">Batal</a>
      <button class="btn btn-garis" type="button" id="unduhContoh"><?= ikon('unduh') ?> Unduh Contoh CSV</button>
    </div>
  </form>
</div>

<script>
(function () {
  var input = document.getElementById('berkas');
  var kotak = document.getElementById('pratinjauImpor');
  var payload = document.getElementById('payload');
  var tombol = document.getElementById('tombolImpor');
  if (!input) { return; }

  var kolom = [
    ['nama', ['nama', 'nama lengkap', 'nama guru']],
    ['nip', ['nip']],
    ['nuptk', ['nuptk']],
    ['jenis_kelamin', ['jenis kelamin', 'jk', 'gender']],
    ['jabatan', ['jabatan']],
    ['mapel', ['mata pelajaran', 'mapel', 'bidang']],
    ['email', ['email', 'e-mail']],
    ['telepon', ['telepon', 'hp', 'no hp', 'whatsapp']],
    ['alamat', ['alamat']],
    ['tmt', ['tmt', 'terhitung mulai']],
    ['keterangan', ['keterangan', 'status kepegawaian']],
    ['status', ['status', 'aktif']]
  ];

  function normal(s) { return String(s == null ? '' : s).toLowerCase().replace(/\s+/g, ' ').trim(); }

  function petakan(header) {
    var peta = {};
    var dipakai = {};
    // tahap 1: cocok persis
    kolom.forEach(function (k) {
      header.forEach(function (h, i) {
        if (dipakai[i]) { return; }
        if (k[1].indexOf(normal(h)) >= 0) { peta[k[0]] = i; dipakai[i] = true; }
      });
    });
    // tahap 2: cocok awalan
    kolom.forEach(function (k) {
      if (peta[k[0]] != null) { return; }
      for (var i = 0; i < header.length; i++) {
        if (dipakai[i]) { continue; }
        var h = normal(header[i]);
        if (!h) { continue; }
        var cocok = k[1].some(function (nama) { return h.indexOf(nama) === 0; });
        if (cocok) { peta[k[0]] = i; dipakai[i] = true; break; }
      }
    });
    return peta;
  }

  input.addEventListener('change', function () {
    kotak.innerHTML = '';
    payload.value = '';
    tombol.disabled = true;
    var f = input.files && input.files[0];
    if (!f) { return; }
    if (typeof XLSX === 'undefined') {
      kotak.innerHTML = '<div class="alert alert-err">Modul pembaca Excel belum termuat. Muat ulang halaman.</div>';
      return;
    }
    var reader = new FileReader();
    reader.onload = function (ev) {
      var wb;
      try {
        wb = XLSX.read(new Uint8Array(ev.target.result), { type: 'array', cellDates: false, raw: false });
      } catch (e) {
        kotak.innerHTML = '<div class="alert alert-err">Berkas tidak dapat dibaca. Pastikan formatnya Excel/CSV yang sah.</div>';
        return;
      }
      var ws = wb.Sheets[wb.SheetNames[0]];
      var aoa = XLSX.utils.sheet_to_json(ws, { header: 1, blankrows: false, defval: '' });
      if (!aoa.length) {
        kotak.innerHTML = '<div class="alert alert-err">Berkas kosong.</div>';
        return;
      }
      var header = aoa[0];
      var peta = petakan(header);
      if (peta.nama == null) {
        kotak.innerHTML = '<div class="alert alert-err">Kolom <b>Nama</b> tidak ditemukan pada baris pertama. Kolom terbaca: ' +
          header.map(function (h) { return '<i>' + String(h) + '</i>'; }).join(', ') + '</div>';
        return;
      }
      var baris = [];
      for (var i = 1; i < aoa.length; i++) {
        var r = aoa[i];
        if (!r || !String(r[peta.nama] || '').trim()) { continue; }
        var o = {};
        kolom.forEach(function (k) {
          o[k[0]] = peta[k[0]] != null ? String(r[peta[k[0]]] == null ? '' : r[peta[k[0]]]).trim() : '';
        });
        baris.push(o);
      }
      if (!baris.length) {
        kotak.innerHTML = '<div class="alert alert-err">Tidak ada baris berisi nama.</div>';
        return;
      }
      payload.value = JSON.stringify({ baris: baris });
      tombol.disabled = false;
      var html = '<div class="tabel-ringkas"><span class="chip hijau">' + baris.length + ' baris siap diimpor</span>' +
        '<span class="chip">Kolom terpetakan: ' + Object.keys(peta).length + '</span></div>';
      html += '<div class="tabel-bungkus" style="max-height:340px;overflow:auto"><table class="tbl"><thead><tr>' +
        '<th>No</th><th>Nama</th><th>NIP</th><th>JK</th><th>Jabatan</th><th>Mapel</th><th>Kontak</th></tr></thead><tbody>';
      baris.slice(0, 40).forEach(function (b, i) {
        html += '<tr><td>' + (i + 1) + '</td><td>' + b.nama + '</td><td>' + (b.nip || '-') + '</td><td>' + (b.jenis_kelamin || '-') +
          '</td><td>' + (b.jabatan || '-') + '</td><td>' + (b.mapel || '-') + '</td><td>' + (b.telepon || b.email || '-') + '</td></tr>';
      });
      html += '</tbody></table></div>';
      if (baris.length > 40) { html += '<p class="kecil mut mt8">Pratinjau 40 baris pertama dari ' + baris.length + ' baris.</p>'; }
      kotak.innerHTML = html;
    };
    reader.readAsArrayBuffer(f);
  });

  var contoh = document.getElementById('unduhContoh');
  if (contoh) {
    contoh.addEventListener('click', function () {
      var isi = 'Nama,NIP,NUPTK,Jenis Kelamin,Jabatan,Mata Pelajaran,Email,Telepon,Alamat,TMT,Keterangan,Status\n' +
        'Contoh Guru, S.Pd,-,-,P,Guru,Matematika,guru@example.com,081200000000,Dusun Contoh,2019-07-01,Satmikal,aktif\n';
      var blob = new Blob([isi], { type: 'text/csv;charset=utf-8' });
      var a = document.createElement('a');
      a.href = URL.createObjectURL(blob);
      a.download = 'contoh-impor-guru.csv';
      a.click();
      setTimeout(function () { URL.revokeObjectURL(a.href); }, 1000);
    });
  }
})();
</script>
<?php page_bottom(); ?>
