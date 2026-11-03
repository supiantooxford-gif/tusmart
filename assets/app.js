/* TUSmart — skrip antarmuka (tanpa pustaka luar).
   Semua fitur di sini bersifat PELENGKAP: halaman tetap bekerja tanpa JavaScript. */
(function () {
  'use strict';

  function $(sel, akar) { return (akar || document).querySelector(sel); }
  function $$(sel, akar) { return Array.prototype.slice.call((akar || document).querySelectorAll(sel)); }

  // ---------- 1. Menu HP ----------
  function initMenu() {
    var tombol = $('[data-menu]');
    var sidebar = $('#sidebar');
    if (!tombol || !sidebar) { return; }
    var lapisan = null;
    function tutup() {
      sidebar.classList.remove('on');
      tombol.setAttribute('aria-expanded', 'false');
      if (lapisan) { lapisan.remove(); lapisan = null; }
    }
    tombol.addEventListener('click', function () {
      var buka = !sidebar.classList.contains('on');
      sidebar.classList.toggle('on', buka);
      tombol.setAttribute('aria-expanded', buka ? 'true' : 'false');
      if (buka) {
        lapisan = document.createElement('div');
        lapisan.className = 'lapisan-menu';
        lapisan.addEventListener('click', tutup);
        document.body.appendChild(lapisan);
      } else { tutup(); }
    });
    document.addEventListener('keydown', function (ev) { if (ev.key === 'Escape') { tutup(); } });
  }

  // ---------- 2. Dialog konfirmasi ----------
  function initKonfirmasi() {
    $$('[data-konfirmasi]').forEach(function (el) {
      if (el.tagName === 'FORM') {
        el.addEventListener('submit', function (ev) {
          if (!window.confirm(el.getAttribute('data-konfirmasi'))) { ev.preventDefault(); }
        });
      } else {
        el.addEventListener('click', function (ev) {
          if (!window.confirm(el.getAttribute('data-konfirmasi'))) { ev.preventDefault(); }
        });
      }
    });
  }

  // ---------- 3. Saringan otomatis ----------
  function initSaring() {
    $$('[data-auto]').forEach(function (el) {
      el.addEventListener('change', function () {
        var f = el.form;
        if (f) { if (f.requestSubmit) { f.requestSubmit(); } else { f.submit(); } }
      });
    });
  }

  // ---------- 4. Cetak otomatis (?auto=1) ----------
  function initCetak() {
    if (document.body && document.body.getAttribute('data-auto-cetak') === '1') {
      setTimeout(function () { window.print(); }, 500);
    }
  }

  // ---------- 5. Pratinjau berkas ----------
  function initPratinjau() {
    $$('input[type=file][data-pratinjau]').forEach(function (inp) {
      var target = $(inp.getAttribute('data-pratinjau'));
      if (!target) { return; }
      inp.addEventListener('change', function () {
        target.innerHTML = '';
        var f = inp.files && inp.files[0];
        if (!f) { return; }
        var info = document.createElement('div');
        info.textContent = f.name + ' (' + Math.round(f.size / 1024) + ' KB)';
        target.appendChild(info);
        if (/^image\//.test(f.type)) {
          var img = document.createElement('img');
          img.alt = 'Pratinjau berkas';
          img.src = URL.createObjectURL(f);
          target.appendChild(img);
        }
      });
    });
  }

  // ---------- 6. Tombol salin ----------
  function initSalin() {
    $$('[data-salin]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var sel = btn.getAttribute('data-salin');
        var el = $(sel);
        var teks = el ? (el.textContent || '') : (btn.getAttribute('data-salin-teks') || '');
        teks = teks.trim();
        if (!teks) { return; }
        function beres() {
          var asli = btn.getAttribute('data-asli') || btn.textContent;
          btn.setAttribute('data-asli', asli);
          btn.textContent = 'Tersalin';
          setTimeout(function () { btn.textContent = asli; }, 1500);
        }
        if (navigator.clipboard && navigator.clipboard.writeText) {
          navigator.clipboard.writeText(teks).then(beres, function () {});
        } else {
          var t = document.createElement('textarea');
          t.value = teks; document.body.appendChild(t); t.select();
          try { document.execCommand('copy'); beres(); } catch (e) {}
          t.remove();
        }
      });
    });
  }

  // ---------- 7. Centang semua ----------
  function initPilihSemua() {
    $$('[data-pilih-semua]').forEach(function (cb) {
      cb.addEventListener('change', function () {
        $$(cb.getAttribute('data-pilih-semua')).forEach(function (k) {
          if (!k.disabled) { k.checked = cb.checked; }
        });
      });
    });
  }

  // ---------- 8. Ekspor Excel (SheetJS) ----------
  function initEkspor() {
    $$('[data-ekspor]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        if (typeof XLSX === 'undefined') {
          window.alert('Modul ekspor Excel belum termuat. Gunakan tombol "Unduh CSV" sebagai cadangan.');
          return;
        }
        var sumber = $(btn.getAttribute('data-ekspor'));
        if (!sumber) { return; }
        var data;
        try { data = JSON.parse(sumber.textContent || '{}'); } catch (e) { return; }
        var judul = data.judul || 'Data';
        var header = data.header || [];
        var baris = data.baris || [];
        var aoa = [header].concat(baris);
        var ws = XLSX.utils.aoa_to_sheet(aoa);
        ws['!cols'] = header.map(function (h, i) {
          var maks = String(h).length;
          baris.forEach(function (b) { var v = b[i] == null ? '' : String(b[i]); if (v.length > maks) { maks = v.length; } });
          return { wch: Math.min(48, Math.max(10, maks + 2)) };
        });
        var wb = XLSX.utils.book_new();
        XLSX.utils.book_append_sheet(wb, ws, (data.sheet || 'Data').substring(0, 30));
        var nama = (btn.getAttribute('data-nama') || judul).replace(/[^A-Za-z0-9._-]+/g, '-');
        XLSX.writeFile(wb, nama + '-' + new Date().toISOString().slice(0, 10) + '.xlsx');
      });
    });
  }

  // ---------- 9. Hitung centang pilihan (mis. pilih semua siswa) ----------
  function initHitungCentang() {
    $$('[data-hitung-centang]').forEach(function (kotak) {
      var target = $(kotak.getAttribute('data-hitung-centang'));
      if (!target) { return; }
      function perbarui() {
        var n = $$('input[type=checkbox]:checked', kotak).filter(function (k) { return !k.hasAttribute('data-pilih-semua'); }).length;
        target.textContent = n + ' dipilih';
      }
      kotak.addEventListener('change', perbarui);
      perbarui();
    });
  }

  // ---------- 10. Isi medan surat dari Data Siswa (pelengkap) ----------
  // Tanpa JavaScript halaman tetap bekerja: medan dapat diketik manual, dan server tetap
  // melengkapi medan kosong dari siswa yang dipilih saat surat disimpan.
  function initIsiSiswa() {
    var pilih = $('[data-isi-siswa]');
    var sumber = $('#dataSiswaSurat');
    if (!pilih || !sumber) { return; }
    var peta = {};
    try { peta = JSON.parse(sumber.textContent || '{}'); } catch (e) { return; }
    function isi() {
      var d = peta[pilih.value];
      if (!d) { return; }
      Object.keys(d).forEach(function (kunci) {
        var el = $('[data-medan="' + kunci + '"]');
        if (!el) { return; }
        if (el.tagName === 'SELECT') {
          var ada = Array.prototype.some.call(el.options, function (o) { return o.value === d[kunci]; });
          if (ada) { el.value = d[kunci]; }
        } else if (String(d[kunci] || '') !== '') {
          el.value = d[kunci];
        }
      });
    }
    pilih.addEventListener('change', isi);
  }

  // ---------- 11. Tanda tangan digital (hanya perangkat LAYAR SENTUH) ----------
  // Tombol "Tanda Tangan" pada lembar Daftar Hadir Rapat disembunyikan sejak HTML (`hidden`)
  // dan hanya dimunculkan bila perangkat benar-benar memiliki layar sentuh. Tanpa JavaScript
  // atau pada komputer biasa, tombol tidak pernah tampil dan lembar tetap dapat dicetak.
  function perangkatSentuh() {
    return (navigator.maxTouchPoints && navigator.maxTouchPoints > 0)
      || ('ontouchstart' in window);
  }

  function initTtdSentuh() {
    var dataEl = $('#ttdData');
    var dialog = $('#ttdDialog');
    if (!dataEl || !dialog) { return; }
    var kanvas = $('#ttdKanvas');
    var pesanEl = $('#ttdPesan');
    var judulEl = $('#ttdJudul');
    var subEl = $('#ttdSub');
    var pihakEl = $('#ttdPihak');
    if (!kanvas || !pihakEl) { return; }
    var data;
    try { data = JSON.parse(dataEl.textContent || '{}'); } catch (e) { return; }
    if (!data || !data.endpoint) { return; }

    var sentuh = perangkatSentuh();
    var tombolSemua = $$('[data-ttd-tombol]');
    var catatan = $('#ttdCatatan');
    if (sentuh) {
      tombolSemua.forEach(function (b) { b.hidden = false; });
      if (catatan) { catatan.hidden = false; }
    } else {
      tombolSemua.forEach(function (b) { b.hidden = true; });
      if (catatan) { catatan.hidden = true; }
    }

    var ctx = kanvas.getContext('2d');
    var menggambar = false;
    var adaCoretan = false;
    var pihakAktif = '';
    var terakhir = null;

    function ukuranKanvas() {
      var rasio = window.devicePixelRatio || 1;
      var kotak = kanvas.getBoundingClientRect();
      var lebar = Math.max(280, Math.round(kotak.width || kanvas.clientWidth || 600));
      var tinggi = Math.max(140, Math.round(kotak.height || kanvas.clientHeight || 220));
      kanvas.width = Math.round(lebar * rasio);
      kanvas.height = Math.round(tinggi * rasio);
      ctx.setTransform(rasio, 0, 0, rasio, 0, 0);
      bersihkan();
      function bersihkan() {
        ctx.clearRect(0, 0, lebar, tinggi);
        ctx.lineWidth = 2.4;
        ctx.lineCap = 'round';
        ctx.lineJoin = 'round';
        ctx.strokeStyle = '#12356b';
        adaCoretan = false;
      }
      kanvas._bersihkan = bersihkan;
      kanvas._lebar = lebar;
      kanvas._tinggi = tinggi;
    }

    function titik(ev) {
      var kotak = kanvas.getBoundingClientRect();
      return { x: ev.clientX - kotak.left, y: ev.clientY - kotak.top };
    }

    function mulai(ev) {
      ev.preventDefault();
      menggambar = true;
      terakhir = titik(ev);
      if (kanvas.setPointerCapture && ev.pointerId !== undefined) {
        try { kanvas.setPointerCapture(ev.pointerId); } catch (e) {}
      }
    }

    function gerak(ev) {
      if (!menggambar) { return; }
      ev.preventDefault();
      var p = titik(ev);
      ctx.beginPath();
      ctx.moveTo(terakhir.x, terakhir.y);
      ctx.lineTo(p.x, p.y);
      ctx.stroke();
      terakhir = p;
      adaCoretan = true;
    }

    function selesai(ev) {
      if (ev) { ev.preventDefault(); }
      menggambar = false;
      terakhir = null;
    }

    kanvas.addEventListener('pointerdown', mulai);
    kanvas.addEventListener('pointermove', gerak);
    kanvas.addEventListener('pointerup', selesai);
    kanvas.addEventListener('pointercancel', selesai);
    kanvas.addEventListener('pointerleave', selesai);
    // Peramban lama tanpa Pointer Events: pakai sentuhan langsung.
    kanvas.addEventListener('touchstart', function (ev) {
      if (window.PointerEvent) { return; }
      var t = ev.touches[0];
      if (!t) { return; }
      mulai({ preventDefault: function () { ev.preventDefault(); }, clientX: t.clientX, clientY: t.clientY });
    }, { passive: false });
    kanvas.addEventListener('touchmove', function (ev) {
      if (window.PointerEvent) { return; }
      var t = ev.touches[0];
      if (!t) { return; }
      gerak({ preventDefault: function () { ev.preventDefault(); }, clientX: t.clientX, clientY: t.clientY });
    }, { passive: false });
    kanvas.addEventListener('touchend', function (ev) {
      if (window.PointerEvent) { return; }
      selesai({ preventDefault: function () { ev.preventDefault(); } });
    }, { passive: false });

    function tampilkanPesan(teks, jenis) {
      if (!pesanEl) { return; }
      pesanEl.textContent = teks || '';
      pesanEl.className = 'ttd-pesan' + (jenis ? ' ' + jenis : '');
    }

    function buka(pihak) {
      pihakAktif = pihak;
      pihakEl.value = pihak;
      var info = (data.pihak || {})[pihak] || {};
      if (judulEl) { judulEl.textContent = 'Tanda Tangan — ' + (info.label || 'Peserta'); }
      if (subEl) {
        subEl.textContent = (info.nama || '') !== ''
          ? 'Nama: ' + info.nama + ' · tanda tangan langsung pada layar (jari atau stylus).'
          : 'Tanda tangan langsung pada layar (jari atau stylus).';
      }
      tampilkanPesan('', '');
      if (typeof dialog.showModal === 'function') { dialog.showModal(); }
      else { dialog.setAttribute('open', 'open'); }
      window.setTimeout(function () { ukuranKanvas(); }, 30);
    }

    function tutup() {
      if (typeof dialog.close === 'function') { dialog.close(); }
      else { dialog.removeAttribute('open'); }
      pihakAktif = '';
    }

    tombolSemua.forEach(function (b) {
      b.addEventListener('click', function () { buka(b.getAttribute('data-ttd-tombol') || ''); });
    });

    var tombolTutup = $('#ttdTutup');
    if (tombolTutup) { tombolTutup.addEventListener('click', tutup); }
    var tombolHapus = $('#ttdHapus');
    if (tombolHapus) {
      tombolHapus.addEventListener('click', function () {
        if (kanvas._bersihkan) { kanvas._bersihkan(); }
        tampilkanPesan('', '');
      });
    }

    function kirim(aksi) {
      var csrf = $('input[name=csrf]', dialog);
      var fd = new FormData();
      fd.append('aksi', aksi);
      fd.append('id', String(data.id || 0));
      fd.append('pihak', pihakAktif);
      fd.append('format', 'json');
      if (csrf) { fd.append('csrf', csrf.value); }
      if (aksi === 'simpan') {
        fd.append('gambar', eksporPng());
      }
      return fetch(data.endpoint, {
        method: 'POST',
        body: fd,
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
      }).then(function (r) { return r.json(); });
    }

    // Menghasilkan PNG yang SUDAH DIPANGKAS ke area coretan (margin transparan dibuang).
    // Tanpa ini tanda tangan tampil kecil di lembar cetak karena sebagian besar gambar kosong.
    function eksporPng() {
      var rasio = kanvas.width / (kanvas._lebar || kanvas.width);
      var data;
      try {
        data = ctx.getImageData(0, 0, kanvas.width, kanvas.height);
      } catch (e) {
        return kanvas.toDataURL('image/png');
      }
      var w = kanvas.width;
      var h = kanvas.height;
      var minX = w, minY = h, maxX = -1, maxY = -1;
      for (var y = 0; y < h; y++) {
        for (var x = 0; x < w; x++) {
          if (data.data[(y * w + x) * 4 + 3] > 8) {
            if (x < minX) { minX = x; }
            if (x > maxX) { maxX = x; }
            if (y < minY) { minY = y; }
            if (y > maxY) { maxY = y; }
          }
        }
      }
      if (maxX < 0) { return kanvas.toDataURL('image/png'); }
      var pad = Math.round(6 * (rasio || 1));
      minX = Math.max(0, minX - pad); minY = Math.max(0, minY - pad);
      maxX = Math.min(w - 1, maxX + pad); maxY = Math.min(h - 1, maxY + pad);
      var cw = maxX - minX + 1;
      var ch = maxY - minY + 1;
      var keluar = document.createElement('canvas');
      keluar.width = cw;
      keluar.height = ch;
      var ktx = keluar.getContext('2d');
      ktx.drawImage(kanvas, minX, minY, cw, ch, 0, 0, cw, ch);
      return keluar.toDataURL('image/png');
    }

    function perbaruiSel(pihak, url) {
      var sel = document.querySelector('[data-ttd-sel="' + pihak + '"]');
      if (!sel) { return; }
      var img = sel.querySelector('img.ttd-img');
      if (url) {
        if (!img) {
          img = document.createElement('img');
          img.className = 'ttd-img';
          img.alt = 'Tanda tangan';
          sel.insertBefore(img, sel.firstChild);
        }
        img.src = url;
      } else if (img) {
        img.parentNode.removeChild(img);
      }
      var tombol = sel.querySelector('[data-ttd-tombol]');
      if (tombol) {
        tombol.hidden = !perangkatSentuh();
        tombol.textContent = url ? 'Ulangi' : 'Tanda Tangan';
      }
      var jumlah = document.querySelectorAll('img.ttd-img').length;
      $$('[data-ttd-jumlah]').forEach(function (el) { el.textContent = String(jumlah); });
    }

    var tombolSimpan = $('#ttdSimpan');
    if (tombolSimpan) {
      tombolSimpan.addEventListener('click', function () {
        if (!adaCoretan) {
          tampilkanPesan('Tanda tangan masih kosong — coretkan tanda tangan terlebih dahulu.', 'err');
          return;
        }
        tampilkanPesan('Menyimpan tanda tangan…', '');
        tombolSimpan.disabled = true;
        kirim('simpan').then(function (r) {
          tombolSimpan.disabled = false;
          if (!r || !r.ok) {
            tampilkanPesan((r && r.pesan) || 'Tanda tangan gagal disimpan.', 'err');
            return;
          }
          perbaruiSel(pihakAktif, r.url || '');
          data.pihak[pihakAktif] = data.pihak[pihakAktif] || {};
          data.pihak[pihakAktif].ada = true;
          tutup();
        }).catch(function () {
          tombolSimpan.disabled = false;
          tampilkanPesan('Gagal menghubungi server. Periksa koneksi lalu coba lagi.', 'err');
        });
      });
    }

    var tombolHapusTersimpan = $('#ttdHapusTersimpan');
    if (tombolHapusTersimpan) {
      tombolHapusTersimpan.addEventListener('click', function () {
        tampilkanPesan('Menghapus tanda tangan tersimpan…', '');
        kirim('hapus').then(function (r) {
          if (!r || !r.ok) {
            tampilkanPesan((r && r.pesan) || 'Tanda tangan tidak dapat dihapus.', 'err');
            return;
          }
          perbaruiSel(pihakAktif, '');
          if (kanvas._bersihkan) { kanvas._bersihkan(); }
          tampilkanPesan('Tanda tangan tersimpan sudah dihapus.', 'ok');
        }).catch(function () {
          tampilkanPesan('Gagal menghubungi server. Periksa koneksi lalu coba lagi.', 'err');
        });
      });
    }

    window.addEventListener('resize', function () {
      if (dialog.open && !adaCoretan && kanvas._bersihkan) { ukuranKanvas(); }
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    initMenu(); initKonfirmasi(); initSaring(); initCetak(); initPratinjau();
    initSalin(); initPilihSemua(); initEkspor(); initHitungCentang(); initIsiSiswa();
    initTtdSentuh();
  });
})();
