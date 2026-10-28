/*
 notify.js - the notification bell
   * asks notifications.php?api=poll every 15 seconds (AJAX)
   * when a NEW notification arrives: bell rings (CSS shake), badge updates,
     and on a desktop screen the sound plays (browsers need one click on the page first)
   * click a notification -> it is marked read
*/
(function () {
  'use strict';
  var btn = document.getElementById('notifBtn'); if (!btn) return;
  var dot = document.getElementById('notifDot'), list = document.getElementById('notifList'), cnt = document.getElementById('notifCount');
  var snd = document.getElementById('notifSound'), all = document.getElementById('notifReadAll');
  var csrf = (document.querySelector('meta[name=crm-csrf]') || {}).content || '';
  var last = parseInt(btn.getAttribute('data-last-id'), 10) || 0, wait = false, audio = null, unlocked = false, pending = false;
  var get = function (k) { try { return localStorage.getItem(k); } catch (e) { return null; } }, put = function (k, v) { try { localStorage.setItem(k, v); } catch (e) { } };
  var desktop = function () { return matchMedia('(min-width: 992px)').matches && matchMedia('(pointer: fine)').matches; };
  var soundOn = function () { return get('crm_sound') !== '0'; };

  function icon() { snd.innerHTML = '<i class="bi bi-volume-' + (soundOn() ? 'up' : 'mute') + '"></i>'; }
  icon();
  snd.addEventListener('click', function () { put('crm_sound', soundOn() ? '0' : '1'); icon(); if (soundOn()) play(); });

  function play() {
    if (!soundOn() || !desktop()) return;
    audio = audio || new Audio('assets/sound/notify.wav');
    audio.currentTime = 0;
    var p = audio.play(); if (p && p.catch) p.catch(function () { pending = true; });   // blocked until the user clicks once
  }
  ['click', 'keydown', 'touchstart'].forEach(function (ev) {
    document.addEventListener(ev, function () { unlocked = true; if (pending) { pending = false; play(); } }, { once: false, passive: true });
  });

  function ring() { btn.classList.remove('ringing'); void btn.offsetWidth; btn.classList.add('ringing'); setTimeout(function () { btn.classList.remove('ringing'); }, 2500); }

  function show(n) {
    dot.textContent = n > 99 ? '99+' : n; dot.classList.toggle('d-none', !n);
    cnt.textContent = n ? n + ' new' : 'All caught up';
    document.title = (n ? '(' + n + ') ' : '') + document.title.replace(/^\(\d+\)\s*/, '');
  }

  function toast(items) {
    var box = document.getElementById('toasts'); if (!box || !window.bootstrap) return;
    items.slice(0, 2).forEach(function (n) {
      var t = document.createElement('div'); t.className = 'toast crm-toast';
      t.innerHTML = '<div class="d-flex"><div class="toast-body"><i class="bi bi-bell me-2"></i></div><button class="btn-close btn-close-white m-auto me-2" data-bs-dismiss="toast" aria-label="Close"></button></div>';
      t.querySelector('.toast-body').appendChild(document.createTextNode(n.title));
      box.appendChild(t); new bootstrap.Toast(t, { delay: 5000 }).show(); t.addEventListener('hidden.bs.toast', function () { t.remove(); });
    });
  }

  function poll() {
    if (wait) return; wait = true;
    fetch('notifications.php?api=poll&after=' + last, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (r) { if (r.status === 401) { location.href = 'login.php'; throw 0; } return r.json(); })
      .then(function (j) {
        if (!j || !j.success) return;
        list.innerHTML = j.html; show(j.unread);
        if (j.new && j.new.length) { ring(); play(); toast(j.new); }
        if (j.max_id > last) last = j.max_id;
      }).catch(function () { }).then(function () { wait = false; });
  }
  setInterval(poll, 15000);
  document.addEventListener('visibilitychange', function () { if (!document.hidden) poll(); });

  function post(data) {
    return fetch('notifications.php?api=read', { method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF-Token': csrf, Accept: 'application/json' }, body: new URLSearchParams(data) }).then(function (r) { return r.json(); });
  }
  all.addEventListener('click', function () { post({ all: 1 }).then(function (j) { if (j.success) { list.querySelectorAll('.unread').forEach(function (x) { x.classList.remove('unread'); }); show(0); } }); });
  document.addEventListener('click', function (e) { var a = e.target.closest('[data-nid]'); if (a) { if (navigator.sendBeacon) { var f = new FormData(); f.append('id', a.dataset.nid); f.append('_csrf', csrf); navigator.sendBeacon('notifications.php?api=read', f); } else post({ id: a.dataset.nid }); } });
})();
