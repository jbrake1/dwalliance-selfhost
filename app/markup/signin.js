// Sign-in page for the "Suggest an edit" button. Uses the existing emailed-code flow (/session/).
(function () {
  var box = document.getElementById('mk-signin');
  if (!box) return;
  var brand = box.getAttribute('data-brand');
  var ret = box.getAttribute('data-return') || '/';
  var msg = document.getElementById('mk-msg');
  function say(t) { msg.textContent = t; }
  document.getElementById('mk-email').addEventListener('submit', function (e) {
    e.preventDefault();
    var email = e.target.email.value.trim();
    say('Sending...');
    fetch('/session/', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ email: email, brand: brand }) })
      .then(function (r) { return r.json(); })
      .then(function (j) { say(j && j.ok ? 'Check your email for a code, then paste it below.' : 'We could not send a code to that address.'); })
      .catch(function () { say('Network error, try again.'); });
  });
  document.getElementById('mk-code').addEventListener('submit', function (e) {
    e.preventDefault();
    say('Checking...');
    fetch('/markup/?a=signin', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-DW-Markup': '1' }, body: JSON.stringify({ a: 'signin', code: e.target.code.value.trim(), brand: brand }) })
      .then(function (r) { return r.json(); })
      .then(function (j) { if (j && j.ok) { say('Signed in.'); location.href = ret; } else { say((j && j.error) || 'Sign-in failed.'); } })
      .catch(function () { say('Network error, try again.'); });
  });
})();
