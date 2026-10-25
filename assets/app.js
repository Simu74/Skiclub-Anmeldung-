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

  // Meldung ausblenden
  var toast = document.querySelector('.toast');
  if (toast) setTimeout(function () { toast.classList.add('hide'); }, 2600);
})();
