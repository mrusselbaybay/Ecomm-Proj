// resources/js/seller/lib/deliveryDetailsPrint.js
//
// Builds and prints the "delivery details" sheet — shop, items, shipping
// configuration, tracking number and the parcel confirmation QR — for an
// order in the transformDetail() shape (see SellerOrderController). Shared
// by Prepare Orders (pre/post dispatch) and Order Details, so a seller can
// reprint it after the order has left the Prepare Orders list.

import { QrCode } from '../vendor/qrcodegen.js';

function escapeHtml(value) {
    const map = {
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#39;',
    };

    return String(value ?? '').replace(/[&<>"']/g, (ch) => map[ch]);
}

// Encode `value` as a standalone SVG string (no <img>, no network), the
// same way resources/js/seller/components/ParcelQrCode.vue renders it.
export function qrSvgMarkup(value, { size = 210, border = 4 } = {}) {
    if (!value) {
        return '';
    }

    let qr;

    try {
        qr = QrCode.encodeText(value, QrCode.Ecc.MEDIUM);
    } catch (err) {
        console.error('QR encode failed:', err);

        return '';
    }

    const dim = qr.size + border * 2;
    const parts = [];

    for (let y = 0; y < qr.size; y++) {
        for (let x = 0; x < qr.size; x++) {
            if (qr.getModule(x, y)) {
                parts.push(`M${x + border} ${y + border}h1v1h-1z`);
            }
        }
    }

    return (
        `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${dim} ${dim}" ` +
        `width="${size}" height="${size}" shape-rendering="crispEdges">` +
        `<rect width="100%" height="100%" fill="#ffffff"/>` +
        `<path d="${parts.join('')}" fill="#0f172a"/></svg>`
    );
}

// Assemble the full printable document for `order`. `extras` may carry a
// `weight` and `dims` ({ l, w, h }) for callers (Prepare Orders) that hold
// those locally — they aren't persisted on the order.
export function buildDeliveryDetailsHtml(order, extras = {}) {
    if (!order) {
        return '';
    }

    const shop = escapeHtml(order.seller?.name || 'Store');
    const loc = escapeHtml(
        [order.seller?.city, order.seller?.province].filter(Boolean).join(', '),
    );

    const itemRows = (order.items || [])
        .map((item) => {
            const name = escapeHtml(item.name);
            const variant = item.variant
                ? ` &middot; ${escapeHtml(item.variant)}`
                : '';

            return `<tr><td>${name}${variant}</td><td class="qty">&times;&nbsp;${escapeHtml(item.qty)}</td></tr>`;
        })
        .join('');

    const dims = extras.dims || {};
    const dimsLabel =
        dims.l || dims.w || dims.h
            ? `${escapeHtml(dims.l || '?')} &times; ${escapeHtml(dims.w || '?')} &times; ${escapeHtml(dims.h || '?')} cm`
            : '—';

    const shipping = order.shipping || {};
    const rows = [
        ['Weight', extras.weight ? `${escapeHtml(extras.weight)} kg` : '—'],
        ['Dimensions', dimsLabel],
        ['Courier', escapeHtml(shipping.carrier || '—')],
        ['Service tier', escapeHtml(shipping.service || '—')],
        ['Tracking number', escapeHtml(shipping.trackingNumber || '—')],
    ]
        .map(([label, val]) => `<div><dt>${label}</dt><dd>${val}</dd></div>`)
        .join('');

    const svg = qrSvgMarkup(order.dispatch?.qrPayload);
    const orderId = escapeHtml(order.id || '');

    return (
        `<!doctype html><html><head><meta charset="utf-8">` +
        `<title>Delivery details ${orderId}</title><style>` +
        `*{box-sizing:border-box}` +
        `body{font-family:system-ui,-apple-system,'Segoe UI',sans-serif;margin:0;padding:32px;color:#0f172a;max-width:640px}` +
        `h1{font-size:1.25rem;margin:0}` +
        `.loc{color:#475569;margin:2px 0 0;font-size:.9rem}` +
        `.ordno{margin:4px 0 20px;font-weight:700;color:#0f172a}` +
        `section{border-top:1px solid #e2e8f0;padding:14px 0}` +
        `h2{font-size:.72rem;letter-spacing:.06em;text-transform:uppercase;color:#64748b;margin:0 0 8px}` +
        `table{width:100%;border-collapse:collapse;font-size:.9rem}` +
        `td{padding:4px 0;border-bottom:1px solid #f1f5f9;vertical-align:top}` +
        `td.qty{text-align:right;white-space:nowrap;font-weight:700;width:70px}` +
        `dl{margin:0;display:grid;grid-template-columns:1fr 1fr;gap:8px 20px}` +
        `dt{font-size:.72rem;color:#64748b}` +
        `dd{margin:0;font-size:.9rem;font-weight:600}` +
        `.qr{text-align:center}` +
        `.qr svg{width:210px;height:210px}` +
        `.qr .hint{color:#475569;font-size:.8rem;margin:8px 0 0}` +
        `@media print{body{padding:0}}` +
        `</style></head><body>` +
        `<h1>${shop}</h1>` +
        (loc ? `<p class="loc">${loc}</p>` : '') +
        `<p class="ordno">Order ${orderId}</p>` +
        (itemRows
            ? `<section><h2>Items</h2><table>${itemRows}</table></section>`
            : '') +
        `<section><h2>Shipping configuration</h2><dl>${rows}</dl></section>` +
        (svg
            ? `<section class="qr"><h2>Parcel confirmation code</h2>${svg}` +
              `<p class="hint">The courier scans this at pickup and again on delivery.</p></section>`
            : '') +
        `</body></html>`
    );
}

// Render `html` in a throwaway hidden iframe and open the print dialog —
// no popup window, so nothing to be blocked. The frame cleans itself up
// after printing (or after a minute, as a fallback).
export function printDocument(html) {
    if (!html) {
        return;
    }

    const frame = document.createElement('iframe');
    frame.setAttribute('aria-hidden', 'true');
    frame.style.cssText =
        'position:fixed;right:0;bottom:0;width:0;height:0;border:0;';
    document.body.appendChild(frame);

    const cleanup = () => frame.remove();

    frame.onload = () => {
        const win = frame.contentWindow;

        win.onafterprint = cleanup;
        win.focus();
        win.print();
        setTimeout(cleanup, 60000);
    };

    const doc = frame.contentWindow.document;

    doc.open();
    doc.write(html);
    doc.close();
}

export function printDeliveryDetails(order, extras = {}) {
    printDocument(buildDeliveryDetailsHtml(order, extras));
}
