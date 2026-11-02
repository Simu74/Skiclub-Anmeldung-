// Kleine Helfer. Die App funktioniert auch ohne JavaScript, nur weniger bequem.
(function () {
  // Fahrer-Plätze nur zeigen, wenn "Ich kann fahren" angekreuzt ist
  document.querySelectorAll('[data-toggle]').forEach(function (cb) {
    cb.addEventListener('change', function () {
      var el = document.getElementById(cb.dataset.toggle);
      if (el) el.hidden = !cb.checked;
    });
  });

  // Auswahllisten speichern sofort (Gruppe wechseln, Leiter, Stufe)
  document.querySelectorAll('select[data-autosubmit]').forEach(function (s) {
    s.addEventListener('change', function () { s.form.submit(); });
  });

  // Anzahl gewählter Kinder auf dem Senden-Knopf zeigen
  document.querySelectorAll('button[data-count-label]').forEach(function (b) {
    var form = b.form;
    function upd() {
      var n = form.querySelectorAll('input[name="kids[]"]:checked').length;
      b.textContent = n ? b.dataset.countLabel + ' (' + n + (n === 1 ? ' Kind)' : ' Kinder)') : 'Niemand kommt: speichern';
    }
    form.addEventListener('change', upd);
    upd();
  });

  // Kopieren
  document.querySelectorAll('[data-copy]').forEach(function (b) {
    b.addEventListener('click', function () {
      var el = document.getElementById(b.dataset.copy);
      var done = function () { var t = b.textContent; b.textContent = 'Kopiert ✓'; setTimeout(function () { b.textContent = t; }, 1800); };
      var fallback = function () { el.focus(); el.select(); try { document.execCommand('copy'); done(); } catch (e) {} };
      if (navigator.clipboard) navigator.clipboard.writeText(el.value).then(done, fallback); else fallback();
    });
  });

  // Angepassten WhatsApp-Text in den Link übernehmen
  var src = document.querySelector('[data-wa-source]');
  var wa = document.getElementById('waOpen');
  if (src && wa) {
    src.addEventListener('input', function () { wa.href = 'https://wa.me/?text=' + encodeURIComponent(src.value); });
  }

  // Liste filtern (z.B. Familien suchen)
  document.querySelectorAll('input[data-filter]').forEach(function (inp) {
    var items = document.querySelectorAll(inp.dataset.filter);
    inp.addEventListener('input', function () {
      var q = inp.value.trim().toLowerCase();
      items.forEach(function (it) { it.hidden = q !== '' && (it.dataset.search || '').indexOf(q) === -1; });
    });
  });

  // Aufklapp-Eintrag aus dem Link (#f12) öffnen, z.B. nach dem Speichern
  if (location.hash) {
    var t = document.getElementById(location.hash.slice(1));
    if (t && t.tagName === 'DETAILS') { t.open = true; t.scrollIntoView({ block: 'start' }); }
  }

  // Drucken
  // Als App vom Home-Bildschirm oder im Browser von WhatsApp, Instagram usw. gibt es
  // kein Druckfenster. Dann statt des Knopfs einen Hinweis zeigen.
  var noPrint = typeof window.print !== 'function' ||
    window.navigator.standalone === true ||
    (window.matchMedia && (window.matchMedia('(display-mode: standalone)').matches ||
      window.matchMedia('(display-mode: fullscreen)').matches)) ||
    /WhatsApp|FBAN|FBAV|Instagram|Line\/|Snapchat|; wv\)/i.test(navigator.userAgent);
  document.querySelectorAll('[data-print]').forEach(function (b) {
    if (noPrint) { b.hidden = true; return; }
    b.addEventListener('click', function () { window.print(); });
  });
  document.querySelectorAll('[data-print-info]').forEach(function (el) {
    el.hidden = !noPrint;
  });

  // Meldung ausblenden
  var toast = document.querySelector('.toast');
  if (toast) setTimeout(function () { toast.classList.add('hide'); }, 2600);
})();

// Formatierungsknöpfe für Freitext (*fett*, _kursiv_, Aufzählung)
(function () {
  document.querySelectorAll('[data-format-for]').forEach(function (bar) {
    var ta = document.getElementById(bar.dataset.formatFor);
    if (!ta) return;
    bar.addEventListener('click', function (ev) {
      var b = ev.target.closest('button');
      if (!b) return;
      var s = ta.selectionStart, en = ta.selectionEnd, v = ta.value, sel = v.slice(s, en);
      if (b.dataset.wrap) {
        var w = b.dataset.wrap, txt = sel || 'Text';
        ta.value = v.slice(0, s) + w + txt + w + v.slice(en);
        ta.setSelectionRange(s + 1, s + 1 + txt.length);
      } else if (b.hasAttribute('data-list')) {
        var start = v.lastIndexOf('\n', s - 1) + 1;
        var block = v.slice(start, en) || '';
        var lines = block.split('\n').map(function (l) { return /^\s*[-•]\s/.test(l) ? l : '- ' + l; });
        ta.value = v.slice(0, start) + lines.join('\n') + v.slice(en);
      }
      ta.focus();
    });
  });

  // Rückmeldung: Stufen-Auswahl nur bei Art «Stufe»
  document.querySelectorAll('select[data-show-skill]').forEach(function (s) {
    var box = document.getElementById(s.dataset.showSkill);
    var upd = function () { if (box) box.hidden = s.value !== 'skill'; };
    s.addEventListener('change', upd);
    upd();
  });
})();
