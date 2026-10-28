/*
 crm-table.js  -  ONE script that gives every table the same behaviour:
   - live search (results change while typing, no button)
   - filters (a drop-down for every <th data-filter>)
   - pagination 30 / 60 / 100 / All
   - click a header with data-sort-col to sort (optional)

 HOW TO USE (no JavaScript to write):
     <table data-crm-table> ... </table>
     <th data-filter>Category</th>          drop-down built from the values of that column
     <th data-filter="City">City</th>       same, with your own label
     <td data-value="VIP">...</td>          (optional) value used for filter / search instead of the cell text
     <td data-sort="2026-10-01">...</td>    (optional) value used when sorting
     data-search-init="text"                (on the table) starts with this search text
 Rows that are added later by other scripts (AJAX) are picked up automatically.
*/
(function () {
  'use strict';
  var SIZES = [30, 60, 100, 0];                       // 0 = All
  var KEY = 'crm_page_size';
  var uid = 0;

  function saved() { try { var v = parseInt(localStorage.getItem(KEY), 10); return SIZES.indexOf(v) > -1 ? v : 30; } catch (e) { return 30; } }
  function save(v) { try { localStorage.setItem(KEY, String(v)); } catch (e) { } }
  function norm(s) { return String(s == null ? '' : s).replace(/\s+/g, ' ').trim(); }
  function el(tag, cls, html) { var n = document.createElement(tag); if (cls) n.className = cls; if (html != null) n.innerHTML = html; return n; }

  function init(table) {
    if (table.__crm) return; table.__crm = true;
    var id = ++uid, tbody = table.tBodies[0]; if (!tbody) return;
    var heads = [].slice.call(table.querySelectorAll('thead th'));
    var state = { q: table.getAttribute('data-search-init') || '', size: saved(), page: 1, filters: {}, sortCol: -1, sortDir: 1 };
    var rows = [], obs = null;

    /* ---------- toolbar ---------- */
    var host = table.closest('.table-responsive') || table;
    var bar = el('div', 'crm-bar');
    var s = el('div', 'crm-search', '<i class="bi bi-search"></i><input type="search" placeholder="Search..." aria-label="Search this table">');
    var sIn = s.querySelector('input'); sIn.value = state.q; bar.appendChild(s);
    if (table.getAttribute('data-crm-search') === 'off') s.classList.add('d-none');
    var selects = [];
    heads.forEach(function (th, i) {
      if (!th.hasAttribute('data-filter')) return;
      var label = th.getAttribute('data-filter') || norm(th.textContent);
      var sel = el('select', 'crm-filter'); sel.setAttribute('aria-label', label);
      sel.innerHTML = '<option value="">' + label + ': All</option>';
      sel.addEventListener('change', function () { state.filters[i] = sel.value; state.page = 1; draw(); });
      selects.push({ col: i, sel: sel, label: label }); bar.appendChild(sel);
    });
    var clear = el('button', 'crm-clear d-none', '<i class="bi bi-x-circle"></i> Clear'); clear.type = 'button'; bar.appendChild(clear);
    var count = el('span', 'crm-count'); bar.appendChild(count);
    host.parentNode.insertBefore(bar, host);

    /* ---------- footer ---------- */
    var foot = el('div', 'crm-foot');
    var per = el('label', 'crm-per', 'Show <select aria-label="Rows per page">' + SIZES.map(function (n) { return '<option value="' + n + '">' + (n || 'All') + '</option>'; }).join('') + '</select> records');
    var perSel = per.querySelector('select'); perSel.value = String(state.size);
    var info = el('span', 'crm-info'), pager = el('ul', 'pagination pagination-sm mb-0');
    foot.appendChild(per); foot.appendChild(info); foot.appendChild(pager);
    host.parentNode.insertBefore(foot, host.nextSibling);
    var none = el('div', 'crm-none d-none', '<i class="bi bi-inbox"></i> Nothing matches your search or filters.'); host.parentNode.insertBefore(none, foot);

    /* ---------- data ---------- */
    function cellVal(td) { return td ? (td.hasAttribute('data-value') ? td.getAttribute('data-value') : norm(td.textContent)) : ''; }
    function collect() {
      rows = [].slice.call(tbody.rows).filter(function (r) { return !(r.cells.length === 1 && r.cells[0].colSpan > 1) && !r.classList.contains('crm-skip'); });
      rows.forEach(function (r) {
        if (!r.__t || r.__n !== r.cells.length || r.__h !== r.innerHTML.length) {
          r.__t = norm(r.textContent).toLowerCase() + ' ' + [].map.call(r.cells, function (c) { return c.getAttribute('data-value') || ''; }).join(' ').toLowerCase();
          r.__n = r.cells.length; r.__h = r.innerHTML.length;
        }
      });
      selects.forEach(function (o) {
        var vals = {}, cur = state.filters[o.col] || '';
        rows.forEach(function (r) { var v = cellVal(r.cells[o.col]); if (v && v !== '-') vals[v] = 1; });
        var keys = Object.keys(vals).sort(function (a, b) { return a.localeCompare(b, undefined, { numeric: true }); });
        var sig = keys.join('\u0001'); if (o.sig === sig) return; o.sig = sig;
        o.sel.innerHTML = '<option value="">' + o.label + ': All</option>' + keys.map(function (k) { return '<option>' + k.replace(/&/g, '&amp;').replace(/</g, '&lt;') + '</option>'; }).join('');
        o.sel.value = keys.indexOf(cur) > -1 ? cur : ''; if (o.sel.value !== cur) state.filters[o.col] = '';
      });
    }

    function sortVal(r, i) { var c = r.cells[i]; if (!c) return ''; var v = c.hasAttribute('data-sort') ? c.getAttribute('data-sort') : norm(c.textContent); var n = parseFloat(String(v).replace(/[^0-9.\-]/g, '')); return (!isNaN(n) && /^[\s\u20B9$Rs.,\-0-9]+$/.test(v)) ? n : String(v).toLowerCase(); }

    function draw() {
      if (obs) obs.disconnect(); collect();
      var terms = state.q.toLowerCase().split(/\s+/).filter(Boolean);
      var shown = rows.filter(function (r) {
        for (var i = 0; i < terms.length; i++) if (r.__t.indexOf(terms[i]) < 0) return false;
        for (var c in state.filters) if (state.filters[c] && cellVal(r.cells[c]) !== state.filters[c]) return false;
        return true;
      });
      if (state.sortCol > -1) {
        shown.sort(function (a, b) { var x = sortVal(a, state.sortCol), y = sortVal(b, state.sortCol); return (x < y ? -1 : x > y ? 1 : 0) * state.sortDir; });
        shown.forEach(function (r) { tbody.appendChild(r); });
      }
      var total = shown.length, size = state.size || total || 1, pages = Math.max(1, Math.ceil(total / size));
      if (state.page > pages) state.page = pages;
      var from = (state.page - 1) * size, to = from + size;
      rows.forEach(function (r) { r.hidden = true; });
      shown.forEach(function (r, i) { r.hidden = !(i >= from && i < to); });
      none.classList.toggle('d-none', total > 0 || rows.length === 0);
      var filtered = !!(terms.length || Object.keys(state.filters).some(function (k) { return state.filters[k]; }));
      clear.classList.toggle('d-none', !filtered);
      count.textContent = total + (filtered ? ' of ' + rows.length : '') + ' records';
      info.textContent = total ? 'Showing ' + (from + 1) + '-' + Math.min(to, total) + ' of ' + total : 'No records';
      pager.innerHTML = '';
      if (pages > 1) {
        var add = function (label, p, cls) { var li = el('li', 'page-item ' + (cls || '')); var a = el('a', 'page-link', label); a.href = '#'; a.dataset.p = p; li.appendChild(a); pager.appendChild(li); };
        add('&laquo;', state.page - 1, state.page === 1 ? 'disabled' : '');
        var list = [], w = 2; for (var p = 1; p <= pages; p++) if (p === 1 || p === pages || Math.abs(p - state.page) <= w) list.push(p);
        var last = 0; list.forEach(function (p) { if (p - last > 1) { var d = el('li', 'page-item disabled'); d.innerHTML = '<span class="page-link">...</span>'; pager.appendChild(d); } add(p, p, p === state.page ? 'active' : ''); last = p; });
        add('&raquo;', state.page + 1, state.page === pages ? 'disabled' : '');
      }
      if (obs) obs.observe(tbody, { childList: true });
    }

    /* ---------- events ---------- */
    sIn.addEventListener('input', function () { state.q = sIn.value; state.page = 1; draw(); });
    perSel.addEventListener('change', function () { state.size = parseInt(perSel.value, 10); state.page = 1; save(state.size); draw(); });
    pager.addEventListener('click', function (e) { var a = e.target.closest('a[data-p]'); if (!a) return; e.preventDefault(); state.page = parseInt(a.dataset.p, 10); draw(); host.scrollIntoView({ block: 'nearest' }); });
    clear.addEventListener('click', function () { state.q = ''; sIn.value = ''; state.filters = {}; selects.forEach(function (o) { o.sel.value = ''; }); state.page = 1; draw(); });
    heads.forEach(function (th, i) {
      if (!th.hasAttribute('data-sort-col')) return; th.classList.add('crm-sortable');
      th.addEventListener('click', function () { state.sortDir = state.sortCol === i ? -state.sortDir : 1; state.sortCol = i; draw(); });
    });
    /* rows added / removed by other scripts */
    obs = new MutationObserver(function () { rows.forEach(function (r) { r.__t = null; }); draw(); });
    draw();
  }

  function boot() { [].forEach.call(document.querySelectorAll('table[data-crm-table]'), init); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
  window.CRMTable = { init: init, boot: boot };
})();
