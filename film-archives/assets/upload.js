// Shows upload progress for large film files, creates thumbnails and watermarked previews in the
// browser (the server has no ffmpeg), and confirms destructive actions.
document.querySelectorAll('form[data-confirm]').forEach(form => {
  form.addEventListener('submit', e => { if (!confirm(form.dataset.confirm)) e.preventDefault(); });
});

const PREVIEW_SECONDS = 15;
const PREVIEW_WIDTH = 640;
const THUMB_WIDTH = 960;
const WATERMARK = 'FILM-ARCHIVES.COM · PREVIEW';

const once = (el, event, ms) => new Promise((resolve, reject) => {
  const timer = setTimeout(() => reject(new Error('timeout waiting for ' + event)), ms);
  el.addEventListener(event, () => { clearTimeout(timer); resolve(); }, { once: true });
  el.addEventListener('error', () => { clearTimeout(timer); reject(new Error('video cannot be decoded')); }, { once: true });
});

const canvasToBlob = (canvas, type, quality) => new Promise(r => canvas.toBlob(r, type, quality));

function drawWatermark(ctx, w, h) {
  const size = Math.max(12, Math.round(w / 28));
  ctx.font = `600 ${size}px system-ui, sans-serif`;
  ctx.textAlign = 'right';
  ctx.textBaseline = 'bottom';
  ctx.fillStyle = 'rgba(0,0,0,0.45)';
  ctx.fillText(WATERMARK, w - size * 0.6 + 1, h - size * 0.5 + 1);
  ctx.fillStyle = 'rgba(255,255,255,0.75)';
  ctx.fillText(WATERMARK, w - size * 0.6, h - size * 0.5);
  // A faint large mark across the middle makes cropping the corner text pointless.
  ctx.save();
  ctx.translate(w / 2, h / 2);
  ctx.rotate(-Math.PI / 12);
  ctx.textAlign = 'center';
  ctx.textBaseline = 'middle';
  ctx.font = `700 ${Math.round(w / 11)}px system-ui, sans-serif`;
  ctx.fillStyle = 'rgba(255,255,255,0.13)';
  ctx.fillText('PREVIEW', 0, 0);
  ctx.restore();
}

function pickRecorderType() {
  if (!window.MediaRecorder) return null;
  for (const t of ['video/mp4;codecs=avc1', 'video/mp4', 'video/webm;codecs=vp9', 'video/webm;codecs=vp8', 'video/webm']) {
    if (MediaRecorder.isTypeSupported(t)) return t;
  }
  return null;
}

// Grabs a still frame and records a short, small, watermarked preview from the chosen video file.
async function makePreviewAssets(file, video, { wantThumb, wantPreview, onProgress }) {
  const url = URL.createObjectURL(file);
  try {
    video.muted = true;
    video.playsInline = true;
    video.preload = 'auto';
    video.src = url;
    await once(video, 'loadeddata', 15000);
    if (!video.videoWidth) throw new Error('no video track');
    const duration = isFinite(video.duration) ? video.duration : 0;
    const out = { thumb: null, preview: null, previewExt: null, duration };

    if (wantThumb) {
      video.currentTime = Math.min(1, duration / 3 || 0);
      await once(video, 'seeked', 10000);
      const w = Math.min(THUMB_WIDTH, video.videoWidth);
      const h = Math.round(video.videoHeight * w / video.videoWidth);
      const c = Object.assign(document.createElement('canvas'), { width: w, height: h });
      c.getContext('2d').drawImage(video, 0, 0, w, h);
      out.thumb = await canvasToBlob(c, 'image/jpeg', 0.85);
    }

    const type = wantPreview ? pickRecorderType() : null;
    const canvas = document.createElement('canvas');
    if (type && canvas.captureStream) {
      const w = Math.min(PREVIEW_WIDTH, video.videoWidth);
      const h = Math.round(video.videoHeight * w / video.videoWidth / 2) * 2;
      canvas.width = w;
      canvas.height = h;
      const ctx = canvas.getContext('2d');
      const length = Math.min(PREVIEW_SECONDS, duration || PREVIEW_SECONDS);
      const chunks = [];
      const recorder = new MediaRecorder(canvas.captureStream(25), { mimeType: type, videoBitsPerSecond: 900000 });
      recorder.ondataavailable = ev => ev.data.size && chunks.push(ev.data);
      const stopped = new Promise(r => { recorder.onstop = r; });

      video.currentTime = 0;
      await once(video, 'seeked', 10000);
      const draw = () => { ctx.drawImage(video, 0, 0, w, h); drawWatermark(ctx, w, h); };
      draw();
      recorder.start(1000);
      await video.play();
      const started = performance.now();
      await new Promise(resolve => {
        const timer = setInterval(() => {
          draw();
          const elapsed = video.currentTime;
          onProgress && onProgress(Math.min(elapsed, length), length);
          if (elapsed >= length || video.ended || performance.now() - started > (length + 20) * 1000) {
            clearInterval(timer);
            resolve();
          }
        }, 40);
      });
      video.pause();
      recorder.stop();
      await stopped;
      const blob = new Blob(chunks, { type: type.split(';')[0] });
      if (blob.size > 1000) {
        out.preview = blob;
        out.previewExt = type.startsWith('video/mp4') ? 'mp4' : 'webm';
      }
    }
    return out;
  } finally {
    video.removeAttribute('src');
    video.load();
    URL.revokeObjectURL(url);
  }
}

const form = document.getElementById('clip-form');
if (form) {
  const master = form.querySelector('input[name=master]');
  const previewInput = form.querySelector('input[name=preview]');
  const thumbInput = form.querySelector('input[name=thumbnail]');
  const auto = form.querySelector('.auto-preview');
  let generated = null;   // { thumb, preview, previewExt }
  let generating = null;  // Promise while a preview is being made

  const looksLikeVideo = f => f && (f.type.startsWith('video/') || /\.(mov|mp4|m4v|webm)$/i.test(f.name));

  async function generate() {
    generated = null;
    const file = master && master.files[0];
    const enabled = auto && auto.querySelector('input[type=checkbox]').checked;
    if (!auto || !enabled || !looksLikeVideo(file)) { if (auto) auto.querySelector('.auto-status').textContent = ''; return; }
    const status = auto.querySelector('.auto-status');
    const video = auto.querySelector('video');
    const img = auto.querySelector('img');
    video.controls = false;
    video.hidden = false;
    img.hidden = true;
    status.textContent = 'Creating thumbnail and preview from your video…';
    try {
      generated = await makePreviewAssets(file, video, {
        wantThumb: !thumbInput.files.length,
        wantPreview: !previewInput.files.length,
        onProgress: (s, total) => { status.textContent = `Creating ${Math.round(total)}-second preview… ${Math.round(s)}s`; },
      });
      const parts = [];
      if (generated.thumb) { img.src = URL.createObjectURL(generated.thumb); img.hidden = false; parts.push('thumbnail'); }
      if (generated.preview) {
        const secs = Math.round(Math.min(PREVIEW_SECONDS, generated.duration || PREVIEW_SECONDS));
        parts.push(`${secs}-second watermarked preview`);
      }
      status.textContent = parts.length ? '✓ Created ' + parts.join(' and ') + '. They upload together with the master.'
        : 'This browser could not create a preview. You can upload one yourself below.';
    } catch (err) {
      generated = null;
      status.textContent = 'Could not read this video in the browser (' + err.message + '). You can upload a preview and thumbnail yourself.';
    }
    // Let the seller watch the generated preview before uploading.
    if (generated && generated.preview) {
      video.src = URL.createObjectURL(generated.preview);
      video.controls = true;
      video.hidden = false;
    } else {
      video.hidden = true;
    }
  }

  if (master && auto) {
    const start = () => { generating = generate(); };
    master.addEventListener('change', start);
    auto.querySelector('input[type=checkbox]').addEventListener('change', start);
  }

  form.addEventListener('submit', async e => {
    // Catch files over the server's upload limit before spending minutes uploading them.
    const max = Number(form.dataset.maxBytes) || Infinity;
    let total = 0;
    for (const input of form.querySelectorAll('input[type=file]')) {
      for (const f of input.files) {
        total += f.size;
        if (f.size > max) {
          e.preventDefault();
          alert('"' + f.name + '" is larger than this server allows (' + form.dataset.maxLabel + ').\n\n' +
            'Upload it with your FTP app into the data/inbox folder, reload this page and pick it from the list.');
          return;
        }
      }
    }
    if (total > max) {
      e.preventDefault();
      alert('Together these files are larger than this server allows (' + form.dataset.maxLabel + '). Upload the master by FTP instead.');
      return;
    }
    if (!window.FormData || !window.XMLHttpRequest) return;
    e.preventDefault();
    const box = form.querySelector('.upload-progress');
    const bar = box.querySelector('.bar span');
    const pct = box.querySelector('b');
    const button = form.querySelector('button[type=submit]');
    box.hidden = false;
    button.disabled = true;

    if (generating) {
      pct.textContent = 'waiting for the preview to finish…';
      await generating;
    }
    const data = new FormData(form);
    if (generated) {
      if (generated.thumb && !thumbInput.files.length) data.set('thumbnail', generated.thumb, 'thumbnail.jpg');
      if (generated.preview && !previewInput.files.length) data.set('preview', generated.preview, 'preview.' + generated.previewExt);
    }

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
    xhr.send(data);
  });
}
