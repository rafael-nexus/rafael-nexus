// Shows upload progress for large film files and confirms destructive actions.
document.querySelectorAll('form[data-confirm]').forEach(form => {
  form.addEventListener('submit', e => { if (!confirm(form.dataset.confirm)) e.preventDefault(); });
});

const form = document.getElementById('clip-form');
if (form) {
  form.addEventListener('submit', e => {
    if (!window.FormData || !window.XMLHttpRequest) return;
    e.preventDefault();
    const box = form.querySelector('.upload-progress');
    const bar = box.querySelector('.bar span');
    const pct = box.querySelector('b');
    const button = form.querySelector('button[type=submit]');
    box.hidden = false;
    button.disabled = true;

    const xhr = new XMLHttpRequest();
    xhr.open('POST', form.action);
    xhr.upload.onprogress = ev => {
      if (!ev.lengthComputable) return;
      const p = Math.round((ev.loaded / ev.total) * 100);
      bar.style.width = p + '%';
      pct.textContent = p === 100 ? '100% — processing…' : p + '%';
    };
    xhr.onload = () => {
      if (xhr.status < 400 && xhr.responseURL && xhr.responseURL !== form.action) {
        location.href = xhr.responseURL;
      } else {
        document.open(); document.write(xhr.responseText); document.close();
      }
    };
    xhr.onerror = () => {
      button.disabled = false;
      pct.textContent = 'failed — check your connection and try again';
    };
    xhr.send(new FormData(form));
  });
}
