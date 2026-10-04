// resources/js/buyer/composables/useReveal.js
//
// v-reveal — subtle fade/rise as a section scrolls into view.
//
// Progressive by design: content is only ever hidden once this module has
// confirmed IntersectionObserver exists and the buyer hasn't asked for
// reduced motion (it adds `reveal-ready` to <html>; the CSS hide rule is
// scoped to that class). A safety timer reveals anything still pending, so
// a section can never stay invisible if the observer misbehaves.

const SAFETY_TIMEOUT = 2500;

const canAnimate = typeof window !== 'undefined'
    && 'IntersectionObserver' in window
    && !window.matchMedia('(prefers-reduced-motion: reduce)').matches;

let observer = null;

function reveal(el) {
    el.classList.add('is-revealed');
    observer?.unobserve(el);
}

function getObserver() {
    if (!observer) {
        document.documentElement.classList.add('reveal-ready');

        observer = new IntersectionObserver((entries) => {
            for (const entry of entries) {
                if (entry.isIntersecting) {
                    reveal(entry.target);
                }
            }
        }, { rootMargin: '0px 0px -8% 0px', threshold: 0.05 });
    }

    return observer;
}

export const vReveal = {
    mounted(el) {
        if (!(el instanceof Element)) {
            return;
        }

        el.classList.add('js-reveal');

        if (!canAnimate) {
            el.classList.add('is-revealed');

            return;
        }

        getObserver().observe(el);
        // Anything already on screen that the observer somehow hasn't
        // revealed yet gets shown anyway; below-the-fold sections keep
        // waiting for a real scroll.
        el._revealTimer = setTimeout(() => {
            if (el.getBoundingClientRect().top < window.innerHeight) {
                reveal(el);
            }
        }, SAFETY_TIMEOUT);
    },
    unmounted(el) {
        clearTimeout(el._revealTimer);
        observer?.unobserve(el);
    },
};
