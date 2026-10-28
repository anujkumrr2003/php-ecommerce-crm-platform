/* layout.js - sidebar open/close, confirm on delete forms, auto-submit selects. (no data, no demo code) */
(function () {
  'use strict';
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var body = document.body, sb = $('.sidebar'), ov = $('.overlay');
  var mob = function () { return innerWidth < 992; };
  var store = { get: function (k) { try { return localStorage.getItem(k); } catch (e) { return null; } }, set: function (k, v) { try { localStorage.setItem(k, v); } catch (e) { } } };

  function closeMenu() { if (sb) sb.classList.remove('open'); if (ov) ov.classList.remove('show'); }
  if (!mob() && store.get('crm_col') === '1') body.classList.add('collapsed');
  addEventListener('resize', function () { if (mob()) body.classList.remove('collapsed'); else closeMenu(); });
  addEventListener('keydown', function (e) { if (e.key === 'Escape') closeMenu(); });
  if (ov) ov.addEventListener('click', closeMenu);

  document.addEventListener('click', function (e) {
    if (e.target.closest('#menuToggle')) {
      if (mob()) { sb.classList.toggle('open'); ov.classList.toggle('show'); }
      else { body.classList.toggle('collapsed'); store.set('crm_col', body.classList.contains('collapsed') ? '1' : '0'); }
    }
    if (sb && sb.contains(e.target)) {
      if (!mob() && body.classList.contains('collapsed') && e.target.closest('[data-bs-toggle=collapse]')) body.classList.remove('collapsed');
      if (mob() && e.target.closest('a[href]:not([data-bs-toggle])')) closeMenu();
    }
    var eye = e.target.closest('[data-eye]');
    if (eye) { var i = eye.parentElement.querySelector('input'); i.type = i.type === 'password' ? 'text' : 'password'; eye.innerHTML = '<i class="bi bi-eye' + (i.type === 'password' ? '' : '-slash') + '"></i>'; }
  });

  /* <form data-confirm="Are you sure?"> asks before submitting */
  document.addEventListener('submit', function (e) {
    var m = e.target.getAttribute && e.target.getAttribute('data-confirm');
    if (m && !confirm(m)) e.preventDefault();
  });
  /* <select data-autosubmit> sends its form when changed */
  document.addEventListener('change', function (e) {
    if (e.target.hasAttribute && e.target.hasAttribute('data-autosubmit') && e.target.form) e.target.form.submit();
  });

  /* sidebar links that only differ by #hash (settings.php#billing) */
  function markHash() {
    if (!location.hash || !sb) return;
    var f = location.pathname.split('/').pop(), a = sb.querySelector('a[href="' + f + location.hash + '"]');
    if (!a) return;
    sb.querySelectorAll('.sub a.active').forEach(function (x) { x.classList.remove('active'); });
    a.classList.add('active');
    var g = a.closest('.collapse'); if (g) g.classList.add('show');
  }
  markHash(); addEventListener('hashchange', markHash);

  /* open the Bootstrap tab named in the URL hash */
  function showTab() {
    var b = location.hash && $('[data-bs-target="' + location.hash + '"]');
    if (b && window.bootstrap) bootstrap.Tab.getOrCreateInstance(b).show();
  }
  showTab(); addEventListener('hashchange', showTab);
})();

/* ---- generic add / edit popup (includes/crud.php): fills the form from the data-row JSON of the button ---- */
document.addEventListener('show.bs.modal', function (ev) {
  var m = ev.target; if (m.id !== 'crudModal' || !ev.relatedTarget) return;
  var row = JSON.parse(ev.relatedTarget.getAttribute('data-row') || '{}'), edit = !!row.id, f = m.querySelector('form');
  var defs = window.CRUD_DEFAULTS || {}; f.reset();
  m.querySelector('[data-fill-title]').textContent = ev.relatedTarget.getAttribute('data-title') || '';
  f.elements.id.value = row.id || 0;
  [].forEach.call(f.elements, function (el) {
    if (!el.name || ['_csrf', 'do', 'id'].indexOf(el.name) > -1 || el.type === 'file') return;
    var v = edit ? row[el.name] : defs[el.name];
    if (el.name.slice(-7) === '__clear') return;
    if (el.type === 'checkbox') el.checked = edit ? String(v) === '1' : (v === undefined ? false : String(v) === '1');
    else if (v !== undefined && v !== null) el.value = v;
    if (el.hasAttribute('data-readonly-edit')) el.readOnly = edit;
  });
  [].forEach.call(m.querySelectorAll('[data-fill-img]'), function (d) {
    var n = d.getAttribute('data-fill-img'); d.innerHTML = edit && row[n] ? 'Current: <a target="_blank" href="' + window.CRUD_UPLOAD + row[n] + '">' + row[n] + '</a>' : '';
  });
});
