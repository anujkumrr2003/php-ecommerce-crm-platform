/* site.js - small helpers for the storefront: AJAX cart + wishlist (no page reload), drawers, search suggestions, slider, product options.
   All prices and totals are calculated on the server; this file only shows what the server returns. */
(function () {
  'use strict';
  var $ = function (s, r) { return (r || document).querySelector(s); }, $$ = function (s, r) { return [].slice.call((r || document).querySelectorAll(s)); };
  var BASE = ($('meta[name=base]') || {}).content || '', CSRF = ($('meta[name=csrf]') || {}).content || '';
  var url = function (p) { return BASE + '/' + p.replace(/^\//, ''); };

  function api(action, data) {
    var body = new URLSearchParams(data || {}); body.set('action', action);
    return fetch(url('api.php'), { method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF-Token': CSRF, Accept: 'application/json' }, body: body })
      .then(function (r) { return r.json().catch(function () { return { success: false, message: 'Something went wrong. Please try again.' }; }); })
      .catch(function () { return { success: false, message: 'Network problem. Please check your internet.' }; });
  }
  function toast(msg, bad) {
    var t = document.createElement('div'); t.className = 'toast' + (bad ? ' bad' : ''); t.innerHTML = '<i class="bi bi-' + (bad ? 'exclamation-circle' : 'check2-circle') + '"></i>'; t.appendChild(document.createTextNode(msg));
    $('#toasts').appendChild(t); setTimeout(function () { t.remove(); }, 3600);
  }
  function badge(sel, n) { $$(sel).forEach(function (b) { b.textContent = n; b.hidden = !n; }); }
  window.Shop = { api: api, toast: toast, url: url };

  /* ---------- drawers / overlay ---------- */
  var ov = $('.overlay');
  function closeAll() { $$('.drawer.on,.search-panel.on').forEach(function (p) { p.classList.remove('on'); }); ov.classList.remove('on'); document.body.style.overflow = ''; }
  function open(name) {
    closeAll(); var p = $('#panel-' + name); if (!p) return; p.hidden = false; void p.offsetWidth; p.classList.add('on'); ov.classList.add('on'); document.body.style.overflow = 'hidden';
    if (name === 'search') setTimeout(function () { p.querySelector('input').focus(); }, 200);
    if (name === 'cart') loadDrawer();
  }
  function loadDrawer() { api('cart_drawer').then(function (j) { if (j.html !== undefined) $('#cart-drawer-body').innerHTML = j.html; counts(j); }); }
  function counts(j) { if (j.cart_count !== undefined) badge('[data-cart-count]', j.cart_count); if (j.wish_count !== undefined) badge('[data-wish-count]', j.wish_count); }
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeAll(); });

  document.addEventListener('click', function (e) {
    var t = e.target;
    var o = t.closest('[data-open]'); if (o) { open(o.dataset.open); return; }
    if (t.closest('[data-close]') || t === ov) { closeAll(); return; }

    var add = t.closest('[data-add]');                       // Add to cart
    if (add) {
      var vid = add.dataset.add; if (add.dataset.add === 'picker') { vid = (window.PDP && PDP.variantId()) || ''; if (!vid) { toast('Please choose an option first.', true); return; } }
      var qty = (window.PDP && add.dataset.add === 'picker') ? PDP.qty() : 1; add.disabled = true;
      api('cart_add', { variant_id: vid, qty: qty }).then(function (j) {
        add.disabled = false; counts(j); toast(j.message || (j.success ? 'Added to your bag' : 'Could not add'), !j.success);
        if (j.success) { if (add.hasAttribute('data-buynow')) location.href = url('checkout'); else open('cart'); }
      }); return;
    }
    var wish = t.closest('[data-wish]');                     // Wishlist heart
    if (wish) {
      api('wishlist_toggle', { product_id: wish.dataset.wish }).then(function (j) {
        if (j.login) { toast('Please log in to save favourites.', true); setTimeout(function () { location.href = url('login'); }, 900); return; }
        if (!j.success) { toast(j.message || 'Could not update wishlist', true); return; }
        $$('[data-wish="' + wish.dataset.wish + '"]').forEach(function (b) { b.classList.toggle('on', j.on); b.setAttribute('aria-pressed', j.on); b.innerHTML = '<i class="bi bi-heart' + (j.on ? '-fill' : '') + '"></i>' + (b.dataset.label ? ' ' + (j.on ? 'Saved' : 'Add to wishlist') : ''); });
        counts(j); toast(j.on ? 'Saved to your wishlist' : 'Removed from wishlist');
        if (wish.hasAttribute('data-remove-card')) { var c = wish.closest('.card-p'); if (c) c.remove(); }
      }); return;
    }
    var q = t.closest('[data-qty]');                         // cart quantity / remove (drawer + cart page)
    if (q) { api('cart_qty', { item_id: q.dataset.qty, qty: q.dataset.to }).then(function (j) { cartRefresh(j); }); return; }
    if (t.closest('[data-coupon-remove]')) { api('coupon_remove').then(cartRefresh); return; }
    if (t.closest('[data-filter-toggle]')) { $('.filters').classList.toggle('open'); }
  });
  function cartRefresh(j) {
    counts(j); if (!j.success && j.message) toast(j.message, true);
    var d = $('#cart-drawer-body'); if (d && $('#panel-cart').classList.contains('on') && j.html !== undefined) d.innerHTML = j.html;
    var pg = $('#cart-page'); if (pg && j.page_html !== undefined) { pg.innerHTML = j.page_html; var s = $('#cart-summary'); if (s) s.innerHTML = j.summary_html; }
  }

  /* coupon form on cart page */
  document.addEventListener('submit', function (e) {
    var f = e.target;
    if (f.matches('[data-coupon]')) { e.preventDefault(); api('coupon_apply', { code: f.code.value }).then(function (j) { toast(j.message || (j.success ? 'Coupon applied' : 'Invalid coupon'), !j.success); if (j.success) location.reload(); }); }
    if (f.matches('[data-newsletter]')) { e.preventDefault(); api('newsletter', { email: f.email.value }).then(function (j) { toast(j.message || 'Thank you', !j.success); if (j.success) f.reset(); }); }
  });

  /* ---------- search suggestions ---------- */
  var sg = $('[data-suggest]'), timer;
  if (sg) sg.addEventListener('input', function () {
    clearTimeout(timer); var v = sg.value.trim(), box = $('#suggest');
    if (v.length < 2) { box.innerHTML = ''; return; }
    timer = setTimeout(function () { api('suggest', { q: v }).then(function (j) { box.innerHTML = j.html || ''; }); }, 220);
  });

  /* ---------- hero slider ---------- */
  var hero = $('.hero');
  if (hero && $$('.slide', hero).length > 1) {
    var i = 0, n = $$('.slide', hero).length, track = $('.slides', hero), dots = $$('.dots button', hero), auto;
    var go = function (k) { i = (k + n) % n; track.style.transform = 'translateX(-' + i * 100 + '%)'; dots.forEach(function (d, x) { d.classList.toggle('on', x === i); }); };
    var run = function () { clearInterval(auto); auto = setInterval(function () { go(i + 1); }, 6000); };
    dots.forEach(function (d, x) { d.addEventListener('click', function () { go(x); run(); }); });
    $('.prev', hero).addEventListener('click', function () { go(i - 1); run(); }); $('.next', hero).addEventListener('click', function () { go(i + 1); run(); });
    var sx = 0; hero.addEventListener('touchstart', function (e) { sx = e.touches[0].clientX; }, { passive: true });
    hero.addEventListener('touchend', function (e) { var dx = e.changedTouches[0].clientX - sx; if (Math.abs(dx) > 40) { go(i + (dx < 0 ? 1 : -1)); run(); } });
    go(0); run();
  }

  /* ---------- scroll reveal ---------- */
  if ('IntersectionObserver' in window) {
    var io = new IntersectionObserver(function (es) { es.forEach(function (x) { if (x.isIntersecting) { x.target.classList.add('in'); io.unobserve(x.target); } }); }, { threshold: .08 });
    $$('.reveal').forEach(function (r) { io.observe(r); });
  } else $$('.reveal').forEach(function (r) { r.classList.add('in'); });

  /* ---------- product page: gallery + variant picker ---------- */
  var data = $('#pdp-data');
  if (data) {
    var V = JSON.parse(data.textContent), sel = { size: null, color: null }, qv = 1;
    var sizes = [], colors = []; V.variants.forEach(function (v) { if (v.size && sizes.indexOf(v.size) < 0) sizes.push(v.size); if (v.color && colors.indexOf(v.color) < 0) colors.push(v.color); });
    var money = function (n) { n = Math.round(n * 100) / 100; var s = String(Math.floor(n)), d = Math.round((n - Math.floor(n)) * 100); if (s.length > 3) s = s.slice(0, -3).replace(/\B(?=(\d{2})+(?!\d))/g, ',') + ',' + s.slice(-3); return '\u20B9' + s + (d ? '.' + String(d).padStart(2, '0') : ''); };
    var find = function () { return V.variants.filter(function (v) { return (!sizes.length || v.size === sel.size) && (!colors.length || v.color === sel.color); })[0]; };
    var ok = function (kind, val) { return V.variants.some(function (v) { return v[kind] === val && v.stock > 0 && (kind === 'size' ? (!sel.color || v.color === sel.color) : (!sel.size || v.size === sel.size)); }); };
    var paint = function () {
      [['size', sizes], ['color', colors]].forEach(function (g) { $$('[data-opt="' + g[0] + '"] button').forEach(function (b) { b.classList.toggle('on', sel[g[0]] === b.dataset.v); b.classList.toggle('off', !ok(g[0], b.dataset.v)); }); });
      var v = find(), st = $('#stock'), btn = $$('[data-add="picker"]'), sku = $('#sku');
      if (!v) { st.className = 'stock'; st.textContent = (sizes.length && !sel.size) || (colors.length && !sel.color) ? 'Please choose your options' : 'This combination is not available'; btn.forEach(function (b) { b.disabled = true; }); return; }
      var pr = $('#pdp-price'); pr.innerHTML = '<b>' + money(v.price) + '</b>' + (V.mrp > v.price ? ' <s>' + money(V.mrp) + '</s> <span class="off">' + Math.round((V.mrp - v.price) * 100 / V.mrp) + '% OFF</span>' : '');
      if (sku) sku.textContent = v.sku || V.sku;
      st.className = 'stock ' + (v.stock <= 0 ? 'out' : v.stock <= 5 ? 'low' : 'in');
      st.textContent = v.stock <= 0 ? 'Out of stock' : v.stock <= 5 ? 'Hurry! Only ' + v.stock + ' left' : 'In stock';
      btn.forEach(function (b) { b.disabled = v.stock <= 0; }); if (qv > v.stock) { qv = Math.max(1, v.stock); $('#q-val').textContent = qv; }
    };
    $$('[data-opt] button').forEach(function (b) { b.addEventListener('click', function () { var k = b.parentNode.dataset.opt; sel[k] = sel[k] === b.dataset.v ? null : b.dataset.v; paint(); }); });
    var first = V.variants.filter(function (v) { return v.stock > 0; })[0] || V.variants[0];
    if (first) { sel.size = sizes.length ? first.size : null; sel.color = colors.length ? first.color : null; }
    $('#q-minus').addEventListener('click', function () { qv = Math.max(1, qv - 1); $('#q-val').textContent = qv; });
    $('#q-plus').addEventListener('click', function () { var v = find(); qv = Math.min(10, v ? Math.min(v.stock, qv + 1) : qv + 1); $('#q-val').textContent = qv; });
    window.PDP = { variantId: function () { var v = find(); return v && v.stock > 0 ? v.id : 0; }, qty: function () { return qv; } };
    paint();
    $$('.thumbs button').forEach(function (t) { t.addEventListener('click', function () { $('#main-img').src = t.dataset.src; $$('.thumbs button').forEach(function (x) { x.classList.remove('on'); }); t.classList.add('on'); }); });
  }

  /* ---------- checkout: recalculate when address / payment changes ---------- */
  $$('[data-recalc]').forEach(function (el) { el.addEventListener('change', function () { var p = new URLSearchParams(location.search); p.set(el.name === 'address_id' ? 'addr' : 'pay', el.value); location.search = p.toString(); }); });
  $$('[data-auto-submit]').forEach(function (el) { el.addEventListener('change', function () { el.form.submit(); }); });
})();
