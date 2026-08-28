import { useBuyer } from './useBuyer';

/*
|--------------------------------------------------------------------------
| Order Timeline
|--------------------------------------------------------------------------
|
| The real four-step order progress (Ordered/Confirmed/Shipped/Delivered),
| shared between OrderDetails.vue and OrderTracking.vue so the step
| labels/icons/timestamp lookup can't drift between the two pages showing
| the same underlying data two different ways.
|
| Every timestamp comes from order.statusHistory (real order_status_history
| rows — see OrderController::transform()). A step with no history entry
| yet returns null rather than a fabricated date; the one exception is the
| first step, which falls back to the order's own placed-at time — an
| equally real alternative source for "when was this ordered".
|
*/

const { ORDER_STATUSES } = useBuyer();

// OUT_FOR_DELIVERY/RETURNED are aliases of IN_TRANSIT/CANCELLED in
// ORDER_STATUSES (see useBuyer.js — no separate DB state for them yet),
// left out here to avoid duplicate steps.
export const trackingSteps = [
    ORDER_STATUSES.TO_SHIP,
    ORDER_STATUSES.PROCESSING,
    ORDER_STATUSES.IN_TRANSIT,
    ORDER_STATUSES.DELIVERED
];

// Friendlier display labels for the timeline only — every computed value
// driving actual behavior runs on the real canonical status strings above.
export const stepLabels = {
    [ORDER_STATUSES.TO_SHIP]: 'Ordered',
    [ORDER_STATUSES.PROCESSING]: 'Confirmed',
    [ORDER_STATUSES.IN_TRANSIT]: 'Shipped',
    [ORDER_STATUSES.DELIVERED]: 'Delivered'
};

export const stepDescriptions = {
    [ORDER_STATUSES.TO_SHIP]: 'Order details received by NEXMART.',
    [ORDER_STATUSES.PROCESSING]: 'Seller is preparing your order.',
    [ORDER_STATUSES.IN_TRANSIT]: 'Package picked up by courier.',
    [ORDER_STATUSES.DELIVERED]: 'Delivered to your address.'
};

// One glyph per step so the timeline reads at a glance instead of as a
// plain 1-2-3-4.
export const stepIcons = {
    [ORDER_STATUSES.TO_SHIP]: '<path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4Z"/><path d="M3 6h18"/><path d="M16 10a4 4 0 0 1-8 0"/>',
    [ORDER_STATUSES.PROCESSING]: '<path d="M20 6 9 17l-5-5"/>',
    [ORDER_STATUSES.IN_TRANSIT]: '<path d="M14 18V6a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2v11a1 1 0 0 0 1 1h2"/><path d="M15 18H9"/><path d="M19 18h2a1 1 0 0 0 1-1v-3.65a1 1 0 0 0-.22-.624l-3.48-4.35A1 1 0 0 0 17.52 8H14"/><circle cx="17" cy="18" r="2"/><circle cx="7" cy="18" r="2"/>',
    [ORDER_STATUSES.DELIVERED]: '<path d="M15 21v-8a1 1 0 0 0-1-1h-4a1 1 0 0 0-1 1v8"/><path d="M3 10a2 2 0 0 1 .709-1.528l7-6a2 2 0 0 1 2.582 0l7 6A2 2 0 0 1 21 10v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>'
};

export function currentTrackingIndex(order) {
    if (!order) {
        return -1;
    }

    return trackingSteps.indexOf(order.status);
}

export function isTrackingStepCompleted(order, index) {
    return currentTrackingIndex(order) >= index;
}

function statusHistoryMap(order) {
    const map = {};

    for (const entry of order?.statusHistory || []) {
        map[entry.status] = entry;
    }

    return map;
}

export function timelineTimestamp(order, step) {
    const entry = statusHistoryMap(order)[step];

    if (entry?.createdAt) {
        return entry.createdAt;
    }

    if (step === ORDER_STATUSES.TO_SHIP) {
        return order?.createdAt || null;
    }

    return null;
}

// The most recent real status change — used for "Last updated" displays.
export function lastUpdated(order) {
    const history = order?.statusHistory || [];

    if (history.length === 0) {
        return order?.createdAt || null;
    }

    return history.reduce((latest, entry) => {
        if (!entry.createdAt) {
            return latest;
        }

        return !latest || new Date(entry.createdAt) > new Date(latest)
            ? entry.createdAt
            : latest;
    }, null);
}