// Minimal Stripe integration over the REST API (no SDK needed).
const crypto = require('node:crypto');
const config = require('./config');
const { safeEqual } = require('./auth');

const enabled = () => !!config.stripeSecretKey;

async function stripeRequest(method, path, params) {
  const res = await fetch(`https://api.stripe.com/v1${path}`, {
    method,
    headers: {
      Authorization: `Bearer ${config.stripeSecretKey}`,
      'Content-Type': 'application/x-www-form-urlencoded',
    },
    body: params ? new URLSearchParams(params).toString() : undefined,
  });
  const body = await res.json();
  if (!res.ok) throw new Error(`Stripe ${res.status}: ${body.error?.message || 'request failed'}`);
  return body;
}

function createCheckoutSession({ order, clip }) {
  return stripeRequest('POST', '/checkout/sessions', {
    mode: 'payment',
    customer_email: order.email,
    client_reference_id: order.id,
    'metadata[order_id]': order.id,
    'line_items[0][quantity]': '1',
    'line_items[0][price_data][currency]': order.currency,
    'line_items[0][price_data][unit_amount]': String(order.amount_cents),
    'line_items[0][price_data][product_data][name]': clip.title,
    'line_items[0][price_data][product_data][description]': `Footage licence — ${clip.master_name}`,
    success_url: `${config.baseUrl}/orders/${order.id}?session_id={CHECKOUT_SESSION_ID}`,
    cancel_url: `${config.baseUrl}/clips/${clip.slug}?cancelled=1`,
  });
}

const retrieveCheckoutSession = sessionId =>
  stripeRequest('GET', `/checkout/sessions/${encodeURIComponent(sessionId)}`);

// Verifies the Stripe-Signature header (https://docs.stripe.com/webhooks#verify-manually).
function verifyWebhook(rawBody, header, toleranceSec = 300) {
  if (!config.stripeWebhookSecret || !header) return null;
  const parts = Object.groupBy(header.split(','), p => p.split('=')[0]);
  const t = parts.t?.[0]?.split('=')[1];
  const sigs = (parts.v1 || []).map(p => p.split('=')[1]);
  if (!t || !sigs.length || Math.abs(Date.now() / 1000 - Number(t)) > toleranceSec) return null;
  const expected = crypto.createHmac('sha256', config.stripeWebhookSecret)
    .update(`${t}.${rawBody.toString('utf8')}`).digest('hex');
  if (!sigs.some(s => safeEqual(s, expected))) return null;
  return JSON.parse(rawBody.toString('utf8'));
}

module.exports = { enabled, createCheckoutSession, retrieveCheckoutSession, verifyWebhook };
