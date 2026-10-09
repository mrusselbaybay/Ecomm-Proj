<script setup>
/*
|--------------------------------------------------------------------------
| AccountNav — the account area's navigation
|--------------------------------------------------------------------------
|
| Two groups (Account settings / Shopping & support). A compact sidebar on
| desktop; below 1024px a horizontally scrolling strip that keeps the
| active page in view. Navigation goes through useAccountNav, and
| Dashboard asks before leaving unsaved changes.
|
*/
import { nextTick, onMounted, ref, watch } from 'vue';
import { ACCOUNT_NAV_GROUPS, goToAccount } from '../composables/useAccountNav';

const props = defineProps({
    active: {
        type: String,
        required: true
    }
});

const strip = ref(null);

function revealActive() {
    const el = strip.value?.querySelector('.acc-nav-link.is-active');

    if (el && strip.value.scrollWidth > strip.value.clientWidth) {
        strip.value.scrollLeft = el.offsetLeft - 16;
    }
}

onMounted(() => nextTick(revealActive));
watch(() => props.active, () => nextTick(revealActive));
</script>

<template>

    <nav
        class="acc-nav"
        aria-label="Account"
    >
        <div
            ref="strip"
            class="acc-nav-inner"
        >
            <div
                v-for="group in ACCOUNT_NAV_GROUPS"
                :key="group.id"
                class="acc-nav-group"
            >
                <p
                    :id="`acc-nav-${group.id}`"
                    class="acc-nav-heading"
                >
                    {{ group.label }}
                </p>
                <ul :aria-labelledby="`acc-nav-${group.id}`">
                    <li
                        v-for="item in group.items"
                        :key="item.id"
                    >
                        <a
                            :href="`?account=${item.id}`"
                            class="acc-nav-link"
                            :class="{ 'is-active': item.id === active }"
                            :aria-current="item.id === active ? 'page' : undefined"
                            @click.prevent="goToAccount(item.id)"
                        >
                            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path :d="item.icon" /></svg>
                            {{ item.label }}
                        </a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

</template>
