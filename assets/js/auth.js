/* auth.js - login / forgot password pages: only the show-hide password button */
document.addEventListener('click', function (e) {
  var b = e.target.closest('[data-eye]'); if (!b) return;
  var i = b.parentElement.querySelector('input'); i.type = i.type === 'password' ? 'text' : 'password';
  b.innerHTML = '<i class="bi bi-eye' + (i.type === 'password' ? '' : '-slash') + '"></i>';
});
