const crypto = require('node:crypto');
const config = require('./config');

const COOKIE = 'fa_admin';
const SESSION_HOURS = 12;

const sign = value => crypto.createHmac('sha256', config.sessionSecret).update(value).digest('base64url');

function safeEqual(a, b) {
  const ab = Buffer.from(String(a)), bb = Buffer.from(String(b));
  return ab.length === bb.length && crypto.timingSafeEqual(ab, bb);
}

function parseCookies(header = '') {
  const out = {};
  for (const part of header.split(';')) {
    const i = part.indexOf('=');
    if (i > 0) out[part.slice(0, i).trim()] = decodeURIComponent(part.slice(i + 1).trim());
  }
  return out;
}

function isAdmin(req) {
  const raw = parseCookies(req.headers.cookie)[COOKIE];
  if (!raw) return false;
  const [expires, sig] = raw.split('.');
  return safeEqual(sig, sign(expires)) && Number(expires) > Date.now();
}

// Marked Secure whenever the request arrived over HTTPS, so login still works on a plain-HTTP setup.
function login(req, res) {
  const expires = String(Date.now() + SESSION_HOURS * 3600e3);
  res.cookie(COOKIE, `${expires}.${sign(expires)}`, {
    httpOnly: true, sameSite: 'strict', secure: req.secure, maxAge: SESSION_HOURS * 3600e3, path: '/',
  });
}

function logout(res) {
  res.clearCookie(COOKIE, { path: '/' });
}

// Simple in-memory brute-force protection for the login form.
const attempts = new Map();
function loginAllowed(ip) {
  const now = Date.now();
  const recent = (attempts.get(ip) || []).filter(t => now - t < 15 * 60e3);
  attempts.set(ip, recent);
  return recent.length < 10;
}
function recordFailure(ip) {
  attempts.set(ip, [...(attempts.get(ip) || []), Date.now()]);
}

function checkPassword(password) {
  return !!config.adminPassword && safeEqual(password || '', config.adminPassword);
}

function requireAdmin(req, res, next) {
  if (isAdmin(req)) return next();
  res.redirect(`/admin/login?next=${encodeURIComponent(req.originalUrl)}`);
}

module.exports = { isAdmin, login, logout, loginAllowed, recordFailure, checkPassword, requireAdmin, safeEqual };
