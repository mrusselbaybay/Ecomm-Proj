<!-- Same heuristic and markup as PasswordStrength in app.js / app-logistics.js
     (those use the runtime-template Vue build, so they can't import an SFC).
     Styles: .pw-strength* in resources/css/app.css. -->
<template>
    <div v-if="password" class="pw-strength" :class="'pw-strength--' + meta.key">
        <div class="pw-strength-track">
            <span v-for="i in 4" :key="i" class="pw-strength-seg" :class="{ filled: i <= level }"></span>
        </div>
        <span class="pw-strength-label">{{ meta.label }}</span>
    </div>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({ password: { type: String, default: '' } });

const LEVELS = {
    1: { key: 'weak', label: 'Weak' },
    2: { key: 'fair', label: 'Fair' },
    3: { key: 'good', label: 'Good' },
    4: { key: 'strong', label: 'Strong' },
};

const level = computed(() => {
    const pw = props.password || '';
    let s = 0;
    if (pw.length >= 8) s++;
    if (pw.length >= 12) s++;
    if (/[a-z]/.test(pw) && /[A-Z]/.test(pw)) s++;
    if (/\d/.test(pw)) s++;
    if (/[^A-Za-z0-9]/.test(pw)) s++;

    return s <= 1 ? 1 : Math.min(s, 4);
});

const meta = computed(() => LEVELS[level.value]);
</script>
