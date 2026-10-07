<script setup>
/* One claimable voucher ticket (product page row + "View all" panel). */
import { formatPrice } from '../composables/useCategoryMeta';

defineProps({
    card: { type: Object, required: true },
    price: { type: Number, required: true },
    claiming: { type: Boolean, default: false },
});

const emit = defineEmits(['claim']);
</script>

<template>
    <article class="vt" :class="{ shipping: card.isShipping, claimed: card.claimed }">
        <div class="vt-stub" aria-hidden="true">
            <strong>{{ card.headline }}</strong>
            <span>{{ card.subhead }}</span>
        </div>

        <div class="vt-body">
            <p class="vt-tags">
                <span class="sr-only">{{ card.label }} — </span>
                <span class="vt-scope">{{ card.scopeLabel }}</span>
                <span v-if="card.runningLow" class="vt-low">{{ card.remaining }} left</span>
            </p>
            <p class="vt-conditions">{{ card.conditions }}</p>
            <p v-if="card.after !== null" class="vt-price">
                Pay <b>{{ formatPrice(card.after) }}</b> <s>{{ formatPrice(price) }}</s>
            </p>
            <p class="vt-expiry" :class="{ soon: card.endingSoon }">{{ card.expiry }}</p>

            <button
                type="button"
                class="vt-claim"
                :class="{ done: card.claimed }"
                :disabled="card.claimed || claiming"
                :aria-label="card.claimed ? `${card.label} claimed` : `Claim ${card.label}`"
                @click="emit('claim', card)"
            >
                <template v-if="card.claimed">
                    <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="3" aria-hidden="true"><path d="m5 12 5 5 9-10" /></svg>
                    Claimed
                </template>
                <template v-else>{{ claiming ? 'Claiming…' : 'Claim' }}</template>
            </button>
        </div>
    </article>
</template>

<style scoped>
.vt {
    --accent: #dc2626; --accent-bg: #fef2f2; --accent-border: #fecaca;
    position: relative; display: grid; grid-template-columns: 68px 1fr; height: 100%;
    border: 1px solid var(--accent-border); border-radius: 12px; background: #fff; overflow: hidden;
    transition: box-shadow .15s ease;
}
.vt:hover { box-shadow: 0 4px 14px rgba(15, 23, 42, .07); }
.vt.shipping { --accent: #0f766e; --accent-bg: #f0fdfa; --accent-border: #99f6e4; }
/* Punched notches on the seam. */
.vt::before, .vt::after {
    content: ''; position: absolute; left: 62px; width: 12px; height: 12px; border-radius: 50%;
    background: #fff; border: 1px solid var(--accent-border);
}
.vt::before { top: -7px; }
.vt::after { bottom: -7px; }

.vt-stub {
    display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 2px; padding: 10px 4px;
    background: var(--accent-bg); color: var(--accent); border-right: 1px dashed var(--accent-border); text-align: center;
}
.vt-stub strong { font-size: 18px; font-weight: 800; line-height: 1.1; letter-spacing: -.01em; word-break: break-word; }
.vt-stub span { font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: .06em; }

.vt-body { display: flex; flex-direction: column; gap: 3px; min-width: 0; padding: 10px 12px; }
.vt-body p { margin: 0; }
.vt-tags { display: flex; flex-wrap: wrap; gap: 4px; }
.vt-scope { padding: 1px 8px; border-radius: 999px; background: #f1f5f9; color: #334155; font-size: 11px; font-weight: 600; }
.vt-low { padding: 1px 8px; border-radius: 999px; background: #fff7ed; color: #c2410c; font-size: 11px; font-weight: 700; }
.vt-conditions { font-size: 12.5px; color: #1e293b; line-height: 1.35; }
.vt-price { font-size: 12.5px; color: #475569; }
.vt-price b { color: #15803d; }
.vt-price s { margin-left: 2px; color: #94a3b8; }
.vt-expiry { font-size: 11.5px; color: #64748b; }
.vt-expiry.soon { color: #c2410c; font-weight: 600; }

.vt-claim {
    display: inline-flex; align-items: center; justify-content: center; gap: 4px; align-self: flex-start;
    min-height: 36px; min-width: 88px; margin-top: auto; padding: 0 14px; border: 0; border-radius: 9px;
    background: var(--accent); color: #fff; font-size: 13px; font-weight: 700; cursor: pointer;
    transition: filter .15s ease, transform .15s ease;
}
.vt-body > .vt-claim { margin-top: 6px; }
.vt-claim:hover:not(:disabled) { filter: brightness(1.08); }
.vt-claim:active:not(:disabled) { transform: scale(.97); }
.vt-claim:disabled { cursor: default; }
.vt-claim.done { background: #f0fdf4; color: #15803d; border: 1px solid #bbf7d0; }
.vt-claim:focus-visible { outline: 3px solid #93c5fd; outline-offset: 2px; }

.sr-only { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0, 0, 0, 0); white-space: nowrap; border: 0; }
</style>
