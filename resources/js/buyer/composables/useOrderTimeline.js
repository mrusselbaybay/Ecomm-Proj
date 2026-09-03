import { useBuyer } from './useBuyer';

/*
|--------------------------------------------------------------------------
| Order Timeline
|--------------------------------------------------------------------------
|
| The buyer-facing order progress tracker, shared between OrderDetails.vue
| and OrderTracking.vue so the step labels/icons/timestamp lookup can't
| drift between the two pages showing the same underlying data two
| different ways.
|
| The five steps here are DISPLAY-ONLY groupings — Order::STATUSES has
| more granular values than this (Confirmed/Processing/Packed/Ready for
| Pickup all read to the buyer as one "being prepared" step), and one
| status ('In Transit') actually covers two different real states once
| logistics gets involved:
|
|   - PREPARING covers every seller-side status from the moment the
|     seller accepts the order (Confirmed) up to dispatch — 'New' alone
|     stays at ORDERED, matching Order::ALLOWED_TRANSITIONS: a seller
|     must accept before an order counts as "being prepared".
|   - SORTING / OUT_FOR_DELIVERY split 'In Transit' the same way the
|     seller's own order timeline does (see SellerOrderController::
|     timelineRows) — driven by the order's ParcelAssignment
|     (order.parcel from Buyer\OrderController::transform), not by
|     Order::status, since status alone can't tell "still waiting at the
|     sorting center" apart from "a rider has it now". SORTING is always
|     shown as soon as the order ships; OUT_FOR_DELIVERY only replaces it
|     once a rider is actually assigned — never both at once, and never
|     out of order.
|
| Every timestamp comes from order.statusHistory (real order_status_history
| rows) or order.parcel (real parcel_assignments columns) — see
| OrderController::transform(). A step with no real data yet returns null
| rather than a fabricated date; the one exception is the first step,
| which falls back to the order's own placed-at time — an equally real
| alternative source for "when was this ordered".
|
*/

const { ORDER_STATUSES } = useBuyer();

// Virtual step identifiers — distinct from the raw Order::STATUSES values
// on purpose, so SORTING/OUT_FOR_DELIVERY (which share the single
// 'In Transit' status) and PREPARING (which several seller statuses all
// bucket into) can each get their own row without colliding.
export const STEP = {
    ORDERED: 'ORDERED',
    PREPARING: 'PREPARING',
    SORTING: 'SORTING',
    OUT_FOR_DELIVERY: 'OUT_FOR_DELIVERY',
    DELIVERED: 'DELIVERED'
};

export const trackingSteps = [
    STEP.ORDERED,
    STEP.PREPARING,
    STEP.SORTING,
    STEP.OUT_FOR_DELIVERY,
    STEP.DELIVERED
];

// Friendlier display labels for the timeline only — every computed value
// driving actual behavior runs off the real canonical status/parcel data
// via currentStepKey() below.
export const stepLabels = {
    [STEP.ORDERED]: 'Ordered',
    [STEP.PREPARING]: 'Being prepared by seller',
    [STEP.SORTING]: 'Parcel in Sorting Center',
    [STEP.OUT_FOR_DELIVERY]: 'Parcel is out for delivery',
    [STEP.DELIVERED]: 'Delivered'
};

export const stepDescriptions = {
    [STEP.ORDERED]: 'Order details received by NEXMART.',
    [STEP.PREPARING]: 'Seller accepted your order and is preparing it.',
    [STEP.SORTING]: 'Courier received the parcel and is sorting it for delivery.',
    [STEP.OUT_FOR_DELIVERY]: 'A rider has been assigned and is on the way.',
    [STEP.DELIVERED]: 'Delivered to your address.'
};

// One glyph per step so the timeline reads at a glance instead of as a
// plain 1-2-3-4-5.
export const stepIcons = {
    [STEP.ORDERED]: '<path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4Z"/><path d="M3 6h18"/><path d="M16 10a4 4 0 0 1-8 0"/>',
    [STEP.PREPARING]: '<path d="M20 6 9 17l-5-5"/>',
    [STEP.SORTING]: '<path d="M3 7h11v9H3z"/><path d="M14 10h4l3 3v3h-7z"/><circle cx="7" cy="18" r="1.6"/><circle cx="17.5" cy="18" r="1.6"/>',
    [STEP.OUT_FOR_DELIVERY]: '<path d="M14 18V6a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2v11a1 1 0 0 0 1 1h2"/><path d="M15 18H9"/><path d="M19 18h2a1 1 0 0 0 1-1v-3.65a1 1 0 0 0-.22-.624l-3.48-4.35A1 1 0 0 0 17.52 8H14"/><circle cx="17" cy="18" r="2"/><circle cx="7" cy="18" r="2"/>',
    [STEP.DELIVERED]: '<path d="M15 21v-8a1 1 0 0 0-1-1h-4a1 1 0 0 0-1 1v8"/><path d="M3 10a2 2 0 0 1 .709-1.528l7-6a2 2 0 0 1 2.582 0l7 6A2 2 0 0 1 21 10v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>'
};

// Seller statuses that read to the buyer as "being prepared" — everything
// from acceptance up to (not including) dispatch. 'New' is deliberately
// excluded: an order only counts as being prepared once the seller has
// actually accepted it (Order::ALLOWED_TRANSITIONS no longer allows
// New -> Processing directly, for the same reason).
const PREPARING_STATUSES = ['Confirmed', 'Processing', 'Packed', 'Ready for Pickup'];

/**
 * Maps an order onto exactly one of the five display steps above. This is
 * the single source of truth every other helper here builds on.
 */
export function currentStepKey(order) {
    if (!order) {
        return null;
    }

    const status = order.status;

    if (status === ORDER_STATUSES.DELIVERED) {
        return STEP.DELIVERED;
    }

    if (status === ORDER_STATUSES.IN_TRANSIT) {
        return order.parcel?.riderAssigned ? STEP.OUT_FOR_DELIVERY : STEP.SORTING;
    }

    if (PREPARING_STATUSES.includes(status)) {
        return STEP.PREPARING;
    }

    // 'New', Cancelled/Rejected (callers hide the tracker for those via
    // isCancelled), or anything unrecognized.
    return STEP.ORDERED;
}

export function currentTrackingIndex(order) {
    return trackingSteps.indexOf(currentStepKey(order));
}

export function isTrackingStepCompleted(order, index) {
    return currentTrackingIndex(order) >= index;
}

export function isCurrentStep(order, step) {
    return !!order && currentStepKey(order) === step;
}

function statusHistoryMap(order) {
    const map = {};

    for (const entry of order?.statusHistory || []) {
        // First (earliest) entry per status wins — statusHistory is
        // typically already chronological, but this is resilient either
        // way since a status is only ever "reached" once.
        if (!map[entry.status]) {
            map[entry.status] = entry;
        }
    }

    return map;
}

export function timelineTimestamp(order, step, history = statusHistoryMap(order)) {
    switch (step) {
        case STEP.ORDERED:
            return history[ORDER_STATUSES.TO_SHIP]?.createdAt || order?.createdAt || null;

        case STEP.PREPARING:
            return PREPARING_STATUSES.reduce(
                (found, status) => found || history[status]?.createdAt || null,
                null
            );

        case STEP.SORTING:
            // Prefer the real sorting-center receipt time; fall back to
            // when the order was first marked In Transit (the seller's own
            // dispatch handover) if logistics hasn't scanned it in yet.
            return order?.parcel?.receivedAt || history[ORDER_STATUSES.IN_TRANSIT]?.createdAt || null;

        case STEP.OUT_FOR_DELIVERY:
            return order?.parcel?.assignedAt || null;

        case STEP.DELIVERED:
            return history[ORDER_STATUSES.DELIVERED]?.createdAt || null;

        default:
            return null;
    }
}

/**
 * Precomputes every per-row value the Order Timeline UI needs in one pass
 * over `order.statusHistory`, for OrderDetails.vue and OrderTracking.vue
 * (both render this same 5-row timeline).
 *
 * Before this existed, both templates called
 * isTrackingStepCompleted()/timelineTimestamp()/isCurrentStep() straight
 * from `:class`/`{{ }}` bindings — 2-4 calls per row, each independently
 * re-deriving currentStepKey()/statusHistoryMap() from scratch, on every
 * re-render of the component (not just when `order` actually changes,
 * since none of it was wrapped in `computed()`). Call this once via a
 * `computed()` in the component instead and read the precomputed row —
 * one pass over statusHistory total instead of up to ten.
 */
export function buildTimeline(order) {
    const currentIndex = currentTrackingIndex(order);
    const history = statusHistoryMap(order);

    return trackingSteps.map((step, index) => ({
        step,
        index,
        label: stepLabels[step] || step,
        description: stepDescriptions[step],
        icon: stepIcons[step],
        completed: currentIndex >= index,
        // Equivalent to isTrackingStepCompleted(order, index + 1) — whether
        // the connector line leading into the *next* row paints "done".
        lineToNextCompleted: currentIndex >= index + 1,
        isCurrent: currentIndex === index,
        timestamp: timelineTimestamp(order, step, history)
    }));
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
