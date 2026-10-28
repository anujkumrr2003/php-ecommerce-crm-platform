/* app.js - small shared helpers used by several pages:
   toast / confirm / info popups, JSON helper (api), form validation, custom-field popup.
   No demo data, no menu code (the menu is built by PHP: includes/layout.php). */
(function () {
  'use strict';
  const $ = (s, r = document) => r.querySelector(s), $$ = (s, r = document) => [...r.querySelectorAll(s)];
  const esc = s => String(s ?? '').replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
  const CSRF = (document.querySelector('meta[name="csrf-token"]') || document.querySelector('meta[name="crm-csrf"]') || {}).content || '';
  const BRAND = (document.querySelector('meta[name="crm-brand"]') || {}).content || 'Our store';
  const todayStr = () => { const d = new Date(); return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); };
  const tone = { Paid: 'green', Done: 'green', Converted: 'green', Sent: 'green', Pending: 'amber', Partial: 'amber', New: 'blue', High: 'red', Required: 'gold', Active: 'green', Inactive: 'gray' };
  const badge = s => `<span class="badge-s b-${tone[s] || 'gray'}">${esc(s)}</span>`;

  /* JSON call to a PHP page. Always returns {success, message, ...} */
  const api = async (url, data) => {
    try {
      const opt = { credentials: 'same-origin', headers: { Accept: 'application/json', 'X-CSRF-Token': CSRF } };
      if (data) { opt.method = 'POST'; opt.body = new URLSearchParams(data); }
      const res = await fetch(url, opt);
      if (res.status === 401) { location.href = 'login.php'; return { success: false }; }
      return (await res.json().catch(() => null)) || { success: false, message: 'Server returned an invalid response (HTTP ' + res.status + ').' };
    } catch (e) { return { success: false, message: 'Network error. Please check your connection.' }; }
  };

  /* ---------- shared popups ---------- */
  document.body.insertAdjacentHTML('beforeend', `<div class="modal fade" id="confirmModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered modal-sm"><div class="modal-content"><div class="modal-body text-center p-4"><div class="ic-warn"><i class="bi bi-exclamation-triangle"></i></div><h5 class="mt-3">Are you sure?</h5><p class="text-muted mb-4" id="confirmText"></p><button class="btn btn-light me-2" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" id="confirmOk">Yes, continue</button></div></div></div></div>
<div class="modal fade" id="infoModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg"><div class="modal-content"><div class="modal-header"><h5 class="modal-title"></h5><button class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div><div class="modal-body"></div><div class="modal-footer"></div></div></div></div>
<div class="toast-container position-fixed top-0 end-0 p-3" id="toasts" style="z-index:2000"></div>`);
  const cm = new bootstrap.Modal('#confirmModal'), im = new bootstrap.Modal('#infoModal'); let okFn;
  const ask = (t, f) => { $('#confirmText').textContent = t; okFn = f; cm.show(); };
  $('#confirmOk').onclick = () => { cm.hide(); okFn && okFn(); };
  const info = (t, h, foot) => { $('#infoModal .modal-title').textContent = t; $('#infoModal .modal-body').innerHTML = h; $('#infoModal .modal-footer').innerHTML = foot || '<button class="btn btn-light" data-bs-dismiss="modal">Close</button>'; im.show(); };
  const toast = (m, t = 'success') => {
    const el = document.createElement('div'); el.className = 'toast crm-toast ' + t;
    el.innerHTML = `<div class="d-flex"><div class="toast-body"><i class="bi bi-${t === 'error' ? 'x-circle' : 'check-circle'} me-2"></i>${m}</div><button class="btn-close btn-close-white m-auto me-2" data-bs-dismiss="toast" aria-label="Close"></button></div>`;
    $('#toasts').append(el); new bootstrap.Toast(el, { delay: 3200 }).show(); el.addEventListener('hidden.bs.toast', () => el.remove());
  };
  window.CRMApp = { toast, ask, info, api, setBrand: () => { } };

  /* ---------- forms ---------- */
  $$('form.needs-validation').forEach(f => {
    f.addEventListener('submit', e => {
      f.classList.add('was-validated');
      if (!f.checkValidity()) { e.preventDefault(); toast('Please fill the highlighted fields.', 'error'); const x = f.querySelector(':invalid'); if (x) x.focus(); return; }
      if (f.hasAttribute('data-custom')) { e.preventDefault(); f.dispatchEvent(new Event('valid')); return; }   // page handles it
      if (f.method.toLowerCase() === 'post' && f.action) {
        const b = f.querySelector('[type="submit"]');
        if (b) { b.dataset.saving = b.textContent; b.disabled = true; b.textContent = 'Saving...'; }
      }
    });
  });
  addEventListener('pageshow', () => $$('form.needs-validation [type="submit"][data-saving]').forEach(b => { b.disabled = false; b.textContent = b.dataset.saving; b.removeAttribute('data-saving'); }));
  $$('[data-today]').forEach(x => { if (!x.value) x.value = todayStr(); });
  $$('[data-toast]').forEach(x => x.addEventListener('click', () => toast(x.dataset.toast)));
  $$('[data-tpl]').forEach(ta => { const pv = $('#' + ta.dataset.tpl), up = () => pv.textContent = ta.value.replaceAll('{customer_name}', 'Ananya').replaceAll('{store_name}', BRAND).replaceAll('{offer}', '15% off on your next purchase'); ta.addEventListener('input', up); up(); });

  /* ---------- custom registration fields (custom-fields.php) ---------- */
  const CF = [], cfBody = $('#cfRows'), cfm = $('#cfModal') && new bootstrap.Modal('#cfModal'); let cfEdit = null;
  const drawCF = () => {
    if (!cfBody) return;
    cfBody.innerHTML = CF.map(r => `<tr data-id="${r.id}"><td class="fw-semibold">${esc(r.label)}</td><td>${esc(r.type)}</td><td>${esc(r.ph) || '-'}</td><td data-value="${r.req ? 'Required' : 'Optional'}">${badge(r.req ? 'Required' : 'Optional')}</td>
      <td class="act text-nowrap"><button class="btn btn-sm btn-icon" data-act="edit" title="Edit"><i class="bi bi-pencil"></i></button><button class="btn btn-sm btn-icon text-danger" data-act="del" title="Delete"><i class="bi bi-trash"></i></button></td></tr>`).join('')
      || '<tr><td colspan="5" class="text-center text-muted py-4">No custom fields yet. Click "Add field" to create one.</td></tr>';
  };
  const loadCF = async () => {
    const j = await api('custom-fields.php?api=list');
    if (!j.success) { toast(j.message || 'Could not load custom fields.', 'error'); return; }
    CF.splice(0, CF.length, ...j.fields); drawCF();
  };
  const toggleOpts = () => { const f = $('#cfForm'); if (!f) return; const dd = f.type.value === 'dropdown'; f.opts.closest('.col-12').classList.toggle('d-none', !dd); f.opts.required = dd; };
  const openCF = r => {
    cfEdit = r || null; const f = $('#cfForm'); f.reset(); f.classList.remove('was-validated'); $('#cfTitle').textContent = r ? 'Edit field' : 'Add field';
    if (r) { f.label.value = r.label; f.type.value = r.type; f.ph.value = r.ph || ''; f.opts.value = r.opts || ''; f.req.checked = !!r.req; }
    toggleOpts(); cfm.show();
  };
  const cfForm = $('#cfForm');
  if (cfForm) {
    cfForm.type.addEventListener('change', toggleOpts);
    cfForm.addEventListener('valid', async () => {
      const btn = cfForm.querySelector('[type=submit]'); btn.disabled = true;
      const j = await api('custom-fields.php?api=save', { id: cfEdit ? cfEdit.id : '', label: cfForm.label.value.trim(), type: cfForm.type.value, ph: cfForm.ph.value.trim(), opts: cfForm.opts.value.trim(), req: cfForm.req.checked ? 1 : 0 });
      btn.disabled = false;
      if (j.success) { cfm.hide(); await loadCF(); toast(cfEdit ? 'Field updated' : 'Field added'); } else toast(j.message || 'Could not save the field.', 'error');
    });
  }
  if (cfBody) loadCF();

  /* ---------- click actions ---------- */
  document.addEventListener('click', e => {
    const b = e.target.closest('[data-act]'); if (!b) return;
    const a = b.dataset.act;
    if (a === 'cf-new') openCF();
    else if (cfBody && cfBody.contains(b)) {
      const r = CF.find(x => String(x.id) === b.closest('tr').dataset.id); if (!r) return;
      if (a === 'edit') openCF(r);
      if (a === 'del') ask(`Delete the field "${r.label}"? Values already saved for customers are kept.`, async () => {
        const j = await api('custom-fields.php?api=delete', { id: r.id });
        if (j.success) { await loadCF(); toast(j.message || 'Field deleted'); } else toast(j.message || 'Could not delete field.', 'error');
      });
    }
    else if (a === 'secret') { const i = b.previousElementSibling; i.type = i.type === 'password' ? 'text' : 'password'; }
    else if (a === 'chip') { const t = $(b.dataset.t); t.setRangeText(b.dataset.v, t.selectionStart, t.selectionEnd, 'end'); t.focus(); t.dispatchEvent(new Event('input')); }
  });
})();
