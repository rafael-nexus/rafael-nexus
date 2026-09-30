// Optional ffmpeg integration: when ffmpeg/ffprobe are installed, uploads get
// automatic duration/resolution detection, a poster frame and a low-res preview.
const { execFile } = require('node:child_process');
const { promisify } = require('node:util');
const run = promisify(execFile);

let available = null;
async function ffmpegAvailable() {
  if (available === null) {
    try { await run('ffmpeg', ['-version']); await run('ffprobe', ['-version']); available = true; }
    catch { available = false; }
  }
  return available;
}

async function probe(file) {
  const { stdout } = await run('ffprobe', ['-v', 'error', '-select_streams', 'v:0',
    '-show_entries', 'stream=width,height:format=duration', '-of', 'json', file]);
  const info = JSON.parse(stdout);
  const s = info.streams?.[0] || {};
  return {
    duration_sec: info.format?.duration ? Math.round(Number(info.format.duration) * 10) / 10 : null,
    resolution: s.width && s.height ? `${s.width}×${s.height}` : '',
  };
}

async function makeThumbnail(input, output, durationSec) {
  const at = durationSec ? Math.min(durationSec / 3, 5) : 1;
  await run('ffmpeg', ['-y', '-v', 'error', '-ss', String(at), '-i', input, '-frames:v', '1',
    '-vf', 'scale=960:-2', '-q:v', '3', output]);
}

// A short, small, low-bitrate preview so the master never has to be exposed publicly.
async function makePreview(input, output, maxSeconds = 30) {
  await run('ffmpeg', ['-y', '-v', 'error', '-i', input, '-t', String(maxSeconds),
    '-vf', 'scale=640:-2', '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '32',
    '-an', '-movflags', '+faststart', output], { timeout: 10 * 60e3 });
}

module.exports = { ffmpegAvailable, probe, makeThumbnail, makePreview };
