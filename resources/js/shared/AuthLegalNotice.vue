<script setup>
defineProps({
    mode: { type: String, required: true },
});

const termsUrl = window.CONFIG?.LEGAL_TERMS_URL || '/terms';
const privacyUrl = window.CONFIG?.LEGAL_PRIVACY_URL || '/privacy';
</script>

<template>
    <p class="auth-legal-notice">
        By {{ mode === 'signup' ? 'signing up' : 'logging in' }}, you agree to BuyTheWay's
        <a :href="termsUrl" target="_blank" rel="noopener noreferrer">Terms of Service</a>
        &amp;
        <a :href="privacyUrl" target="_blank" rel="noopener noreferrer">Privacy Policy</a>.
    </p>
</template>

<style scoped>
.auth-legal-notice {
    flex-shrink: 0;
    max-width: 340px;
    margin: 1.5rem auto 0;
    padding-inline: .5rem;
    color: #64748b;
    font-size: .75rem;
    line-height: 1.65;
    text-align: center;
}

.auth-legal-notice a {
    color: #0f766e;
    font-weight: 600;
    text-decoration: none;
}

.auth-legal-notice a:hover {
    text-decoration: underline;
}

.auth-legal-notice a:focus-visible {
    border-radius: 2px;
    outline: 2px solid #0f766e;
    outline-offset: 3px;
}
</style>
