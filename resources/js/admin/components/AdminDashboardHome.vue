<!-- resources/js/admin/components/AdminDashboardHome.vue -->
<!--
  Minimal, real content for the "Dashboard" nav section — replaces the
  old Dashboard.vue, which was actually a full duplicate copy of
  AdminLayout.vue's own shell (sidebar, nav, everything) mistakenly
  saved under the wrong name. AdminLayout's componentMap.dashboard
  pointed at that duplicate, so the real admin Dashboard page was
  rendering one full app shell nested inside another.

  Deliberately small: only pendingCount and adminProfile.name are real,
  already-loaded data (see useAdmin.js — both come from an actual
  Supabase query). useAdmin.js's `stats`/`notifications` refs exist but
  hold placeholder/hardcoded values (an always-"0" Open Complaints
  count, a static "Welcome to the admin panel!" message) — not used
  here, since presenting those as real data would be dishonest.

  Takes both as PROPS rather than calling useAdmin() itself: that
  composable hands each caller its OWN fresh refs (no shared/singleton
  state across calls, unlike the seller portal's composables), so a
  second useAdmin() call here would show default placeholder values,
  never the real data AdminLayout.vue already fetched on mount — see
  its own currentComponentProps computed for where these come from.
-->
<template>
    <div class="dash-home">
        <h2 class="dash-home-title">
            Welcome back, {{ adminProfile?.name || 'Admin' }}
        </h2>

        <div class="dash-home-card">
            <p v-if="pendingCount > 0" class="dash-home-text">
                <strong>{{ pendingCount }}</strong>
                registration{{ pendingCount === 1 ? '' : 's' }} awaiting
                review.
            </p>
            <p v-else class="dash-home-text">
                No registrations are waiting on review right now.
            </p>

            <a
                v-if="pendingCount > 0"
                href="/admin/accounts"
                class="dash-home-cta"
            >
                Review registrations
            </a>
        </div>

        <div class="dash-home-card dash-home-actions">
            <p class="dash-home-text"><strong>Quick actions</strong></p>
            <button type="button" class="dash-home-cta" @click="emit('create-voucher')">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 9a3 3 0 0 1 0 6v2a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-2a3 3 0 0 1 0-6V7a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2Z" /><path d="M13 5v2M13 17v2M13 11v2" /></svg>
                Create Voucher
            </button>
        </div>
    </div>
</template>

<script setup>
const emit = defineEmits(['create-voucher']);

defineProps({
    adminProfile: { type: Object, default: () => ({}) },
    pendingCount: { type: Number, default: 0 },
});
</script>

<style scoped>
.dash-home {
    max-width: 32rem;
}
.dash-home-title {
    font-size: 1.5rem;
    font-weight: 700;
    color: #0f172a;
    margin-bottom: 1.25rem;
}
.dash-home-card {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 0.75rem;
    padding: 1.5rem;
}
.dash-home-text {
    color: #334155;
    font-size: 0.95rem;
    margin-bottom: 1rem;
}
.dash-home-cta {
    display: inline-block;
    background: #ea580c;
    color: #fff;
    font-size: 0.85rem;
    font-weight: 600;
    padding: 0.55rem 1rem;
    border-radius: 0.5rem;
    text-decoration: none;
}
.dash-home-cta:hover {
    background: #c2410c;
}
.dash-home-actions { margin-top: 1rem; }
.dash-home-actions .dash-home-cta {
    display: inline-flex; align-items: center; gap: 0.4rem; min-height: 40px;
    border: 0; background: #0d9488; cursor: pointer;
}
.dash-home-actions .dash-home-cta:hover { background: #0f766e; }
.dash-home-actions .dash-home-cta:focus-visible { outline: 3px solid #93c5fd; outline-offset: 2px; }
</style>
