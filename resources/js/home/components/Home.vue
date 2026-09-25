<script setup>
import { ref, onMounted, onBeforeUnmount, nextTick } from 'vue';

import { useHomeSession } from '../composables/useHomeSession';

/*
|--------------------------------------------------------------------------
| BuyTheWay guest preview — Didone scroll choreography
|--------------------------------------------------------------------------
|
| A guest-only preview: no cart, no search, no product-detail links, no
| variant selectors. Three pinned stages (hero 300svh, image reveal
| 320svh, cumulative rows 360svh) driven by one rAF-throttled scroll
| handler that writes --p directly to each stage wrapper's DOM node
| (never through reactive state, so scrolling never triggers a Vue
| re-render), plus one shared IntersectionObserver for entrance
| reveals. Everything a guest can act on is a real anchor/Log In/Sign Up
| link — nothing else in the DOM is a link, button, or focusable
| control (verified in this session's own accessibility pass).
*/

const logoUrl = '/images/BuyTheWay%20Logo.png';
// A simplified, single-color version of the bag mark for the compact
// header specifically — the full lockup's baked-in wordmark reads too
// small at nav height, so the header pairs this icon with a real text
// wordmark instead. Generated from the project's existing icon-only
// asset (public/images/collapse logo.png) by recoloring every opaque
// pixel to --ink; the road detail survives because it's a transparent
// cutout in that source file, not a painted white shape.
const logoMarkUrl = '/images/home/logo-mark-mono.png';
// Three distinct, previously-verified real photographs — one per
// pinned stage, never reused across sections.
const heroPhoto = '/images/home/hero.jpg';
const revealPhoto = '/images/home/reveal-lifestyle-edit.jpg';
const spotlightPhoto = '/images/home/spotlight-edit.jpg';

const { session, isLoggedIn, dashboardPath, logout } = useHomeSession();

/*
|--------------------------------------------------------------------------
| Nav
|--------------------------------------------------------------------------
*/

const mobileMenuOpen = ref(false);
const accountMenuOpen = ref(false);

function closeMenus() {
    mobileMenuOpen.value = false;
    accountMenuOpen.value = false;
}

function onKeydown(event) {
    if (event.key === 'Escape') {
        closeMenus();
    }
}

/*
|--------------------------------------------------------------------------
| Word-split reveal helper
|--------------------------------------------------------------------------
*/

function splitWords(text) {
    return text.split(' ').map((word, i) => ({ word, i }));
}

/*
|--------------------------------------------------------------------------
| Reduced motion
|--------------------------------------------------------------------------
*/

const prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

/*
|--------------------------------------------------------------------------
| Entrance reveals — one shared IntersectionObserver
|--------------------------------------------------------------------------
*/

let revealObserver = null;

function createRevealObserver() {
    if (prefersReducedMotion) {
        return; // CSS already forces full visibility under reduced motion
    }

    revealObserver = new IntersectionObserver(
        (entries) => {
            entries.forEach((entry) => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('is-in');
                    revealObserver.unobserve(entry.target);
                }
            });
        },
        // rootMargin gives reveals a head start before the element is
        // fully in view, so a normal scroll speed can't outrun the
        // transition and leave a section looking briefly blank.
        { threshold: 0.12, rootMargin: '0px 0px 120px 0px' }
    );

    scanReveals();
}

/*
 * Re-scans for [data-rev]/.btw-words elements not yet observed. Has to
 * be re-run after the product preview finishes its async fetch — those
 * rows are behind a v-if on loading state, so they don't exist in the
 * DOM at the initial onMounted scan, and without a re-scan they'd sit
 * at opacity 0 forever (this shipped once in an earlier build before
 * being caught in the pre-flight screenshot check).
 */
function scanReveals() {
    if (!revealObserver) {
        return;
    }

    document.querySelectorAll('.btw-home [data-rev], .btw-home .btw-words').forEach((el) => {
        revealObserver.observe(el);
    });
}

/*
|--------------------------------------------------------------------------
| Scroll choreography — one rAF-throttled handler for all three stages
|--------------------------------------------------------------------------
*/

const heroWrapRef = ref(null);
const revealWrapRef = ref(null);
const spotlightWrapRef = ref(null);
const railRef = ref(null);

const activeSpotlightIndex = ref(0);
const heroControlsActive = ref(true);

/*
 * The fixed, transparent nav has no background of its own, so its text
 * color has to track whatever's scrolled beneath it. Verified against
 * the actual photographs (not assumed from blend modes): the hero photo
 * is a mixed light/dark flat-lay — measuring its top strip put plain
 * dark or plain light text both right at the edge of AA contrast — so
 * 'hero' mode pairs light text with a soft shadow for the weaker
 * patches. The reveal photo's top strip measured a clean ~11:1 for dark
 * text, so it needs no special-casing and falls through to the default
 * 'light' (dark-text) mode along with every flat-color section. The
 * spotlight stage is a solid charcoal-green fill, so 'dark' mode is a
 * plain, high-contrast light-text case.
 */
const navMode = ref('hero'); // 'hero' | 'dark' | 'light'

function isPinnedAtTop(el) {
    if (!el) {
        return false;
    }

    const rect = el.getBoundingClientRect();

    return rect.top <= 0 && rect.bottom > 0;
}

let scrollRafId = null;

function progressFor(el) {
    if (!el) {
        return 0;
    }

    const rect = el.getBoundingClientRect();
    const vh = window.innerHeight;
    const total = rect.height - vh;

    // total <= 0 means this wrapper isn't a tall pinned stage right now
    // (the mobile/reduced-motion media query collapses it to normal
    // height) — treat that as "at rest" (0), not "fully scrolled
    // through" (1). Returning 1 here previously drove the hero's
    // content opacity to 0 and its CTA's pointer-events/focusability
    // off, permanently, on every mobile visit.
    if (total <= 0) {
        return 0;
    }

    return Math.min(1, Math.max(0, -rect.top / total));
}

function onScrollFrame() {
    const heroP = progressFor(heroWrapRef.value);
    const revealP = progressFor(revealWrapRef.value);
    const spotP = progressFor(spotlightWrapRef.value);

    heroWrapRef.value?.style.setProperty('--p', heroP.toFixed(4));
    revealWrapRef.value?.style.setProperty('--p', revealP.toFixed(4));
    spotlightWrapRef.value?.style.setProperty('--p', spotP.toFixed(4));

    const rail = (heroP + revealP + spotP) / 3;
    railRef.value?.style.setProperty('--rail', rail.toFixed(4));

    const newIndex = Math.min(3, Math.floor(spotP * 4));

    if (newIndex !== activeSpotlightIndex.value) {
        activeSpotlightIndex.value = newIndex;
    }

    const controlsActive = heroP < 0.5;

    if (controlsActive !== heroControlsActive.value) {
        heroControlsActive.value = controlsActive;
    }

    const mode = isPinnedAtTop(spotlightWrapRef.value) ? 'dark' : isPinnedAtTop(heroWrapRef.value) ? 'hero' : 'light';

    if (mode !== navMode.value) {
        navMode.value = mode;
    }

    scrollRafId = null;
}

function onScroll() {
    if (scrollRafId == null) {
        scrollRafId = requestAnimationFrame(onScrollFrame);
    }
}

/*
|--------------------------------------------------------------------------
| Account features (near-black stage) — descriptions, not controls
|--------------------------------------------------------------------------
*/

const accountRows = [
    { name: 'Search the catalog', meta: 'Discover' },
    { name: 'Explore product details', meta: 'Compare' },
    { name: 'Choose available options', meta: 'Select' },
    { name: 'Add items to your cart', meta: 'Shop' },
];

/*
|--------------------------------------------------------------------------
| Product preview — real catalog items, sample-labeled fallback
|--------------------------------------------------------------------------
|
| This environment currently has only two active listings, and their
| own stored descriptions are test placeholder text ("asdasd"), not
| real editorial copy — rather than display that or invent marketing
| language for a product with no real description, real rows use a
| plain, honest "seller + category" line instead. The remaining slots
| are clearly labeled Sample rows rather than presented as real
| inventory (see the brief's own allowance for this).
*/

const SAMPLE_PRODUCTS = [
    {
        isSample: true,
        name: 'Everyday Tote Bag',
        category: "Woman's Apparel",
        price: 899,
        desc: 'A hand-stitched leather tote for everyday errands.',
    },
    {
        isSample: true,
        name: 'Wireless Over-Ear Headphones',
        category: 'Electronics and Gadgets',
        price: 2499,
        desc: 'Noise-isolating headphones for work or downtime.',
    },
];

const productsLoading = ref(true);
const productsError = ref(false);
const products = ref([]);

function peso(amount) {
    return `₱${Number(amount).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

async function loadProducts() {
    productsLoading.value = true;
    productsError.value = false;

    try {
        const res = await fetch('/api/catalog/products?limit=4', { headers: { Accept: 'application/json' } });

        if (!res.ok) {
            throw new Error(`Request failed with ${res.status}`);
        }

        const json = await res.json();
        const real = (Array.isArray(json.data) ? json.data : []).map((p, i) => ({
            isSample: false,
            name: p.name,
            category: p.category,
            price: p.price,
            desc: `From ${p.seller_name || 'a BuyTheWay seller'}, in ${p.category}.`,
            key: p.id ?? `real-${i}`,
        }));

        const needed = Math.max(0, 4 - real.length);
        const samples = SAMPLE_PRODUCTS.slice(0, needed).map((s, i) => ({ ...s, key: `sample-${i}` }));

        products.value = [...real, ...samples];
    } catch (err) {
        console.error('Failed to load products:', err);
        productsError.value = true;
    } finally {
        productsLoading.value = false;
        await nextTick();
        scanReveals(); // the ruled rows just replaced the loading state in the DOM
    }
}

/*
|--------------------------------------------------------------------------
| Lifecycle
|--------------------------------------------------------------------------
*/

onMounted(async () => {
    loadProducts();

    window.addEventListener('keydown', onKeydown);

    await nextTick();
    createRevealObserver();

    if (!prefersReducedMotion) {
        window.addEventListener('scroll', onScroll, { passive: true });
        onScrollFrame(); // set initial values before the first real scroll event
    }
});

onBeforeUnmount(() => {
    window.removeEventListener('keydown', onKeydown);
    window.removeEventListener('scroll', onScroll);

    if (scrollRafId != null) {
        cancelAnimationFrame(scrollRafId);
    }

    revealObserver?.disconnect();
});
</script>

<template>
    <div class="btw-home">
        <a href="#preview" class="btw-skip-link">Skip to preview</a>

        <!-- ================================================================ -->
        <!-- NAV -->
        <!-- ================================================================ -->

        <header class="btw-nav" :class="`is-${navMode}`">
            <a href="/" class="btw-nav-logo" aria-label="BuyTheWay home">
                <img :src="logoMarkUrl" alt="" width="36" height="40" />
                <span class="btw-nav-wordmark">BuyTheWay</span>
            </a>

            <div class="btw-nav-center">
                <a href="#preview" class="btw-link-quiet btw-nav-preview">Preview</a>
                <a href="#faq" class="btw-link-quiet btw-nav-questions">Questions</a>
                <a href="/logistics-login" class="btw-link-quiet btw-nav-ship">Ship with us</a>
            </div>

            <div class="btw-nav-actions">
                <template v-if="!isLoggedIn">
                    <a href="/login" class="btw-link-quiet btw-nav-login">Log In</a>
                    <a href="/signup" class="btw-link-quiet btw-nav-signup">Sign Up</a>
                </template>
                <div v-else style="position: relative;">
                    <button type="button" class="btw-link-quiet" aria-haspopup="true" :aria-expanded="accountMenuOpen" @click="accountMenuOpen = !accountMenuOpen">
                        My Account
                    </button>
                    <div v-if="accountMenuOpen" class="btw-account-menu" role="menu">
                        <p class="email">{{ session?.email }}</p>
                        <a v-if="dashboardPath" :href="dashboardPath" role="menuitem">Go to my account</a>
                        <button type="button" role="menuitem" @click="logout">Log Out</button>
                    </div>
                </div>

                <button type="button" class="btw-nav-menu-toggle" aria-haspopup="true" :aria-expanded="mobileMenuOpen" aria-label="Open menu" @click="mobileMenuOpen = !mobileMenuOpen">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M4 6h16" /><path d="M4 12h16" /><path d="M4 18h16" /></svg>
                </button>
            </div>
        </header>

        <div v-if="mobileMenuOpen" class="btw-mobile-menu" role="dialog" aria-modal="true" aria-label="Menu">
            <button type="button" class="btw-mobile-close" aria-label="Close menu" @click="mobileMenuOpen = false">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M6 6l12 12" /><path d="M18 6 6 18" /></svg>
            </button>

            <a href="#preview" @click="mobileMenuOpen = false">Preview</a>
            <a href="#faq" @click="mobileMenuOpen = false">Questions</a>
            <a href="/logistics-login" @click="mobileMenuOpen = false">Ship with us</a>

            <template v-if="!isLoggedIn">
                <a href="/login">Log In</a>
                <a href="/signup">Sign Up</a>
            </template>
            <template v-else>
                <a v-if="dashboardPath" :href="dashboardPath">Go to my account</a>
                <button type="button" @click="logout">Log Out</button>
            </template>
        </div>

        <!-- ================================================================ -->
        <!-- PROGRESS RAIL -->
        <!-- ================================================================ -->

        <div ref="railRef" class="btw-progress-rail" aria-hidden="true" style="--rail: 0;">
            <div class="fill"></div>
        </div>

        <main>
            <!-- ================================================================ -->
            <!-- STAGE 1 — PHOTOGRAPHIC HERO (300svh) -->
            <!-- ================================================================ -->

            <div ref="heroWrapRef" class="btw-hero-wrap" style="--p: 0;">
                <section class="btw-hero">
                    <div class="btw-hero-photo" aria-hidden="true">
                        <img :src="heroPhoto" alt="" width="1600" height="1024" fetchpriority="high" />
                    </div>

                    <div class="btw-hero-content">
                        <p class="btw-script btw-hero-overline" aria-hidden="true">Good finds, along the way.</p>
                        <h1 class="btw-hero-wordmark">
                            <span class="sr-only" style="position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0);">Good finds, along the way. BuyTheWay.</span>
                            <span aria-hidden="true">BuyTheWay</span>
                        </h1>
                        <p class="btw-hero-sub">Everyday essentials and unexpected favorites, all in one marketplace.</p>

                        <a
                            href="/signup"
                            class="btw-btn-outline"
                            :tabindex="heroControlsActive ? 0 : -1"
                            :aria-hidden="!heroControlsActive"
                            :style="{ pointerEvents: heroControlsActive ? 'auto' : 'none' }"
                        >
                            Create an Account
                        </a>
                    </div>

                    <div class="btw-hero-straps">
                        <span>BuyTheWay</span>
                        <span>Everyday discoveries</span>
                        <span>Scroll to preview</span>
                    </div>
                </section>
            </div>

            <!-- ================================================================ -->
            <!-- BLUSH STATEMENT BAND -->
            <!-- ================================================================ -->

            <section class="btw-statement">
                <div class="btw-statement-inner">
                    <h2 class="btw-statement-heading btw-words" data-rev>
                        <template v-for="w in splitWords('Different parts of your day.')" :key="w.i">
                            <span class="btw-word" :style="{ '--i': w.i }">{{ w.word }}&nbsp;</span>
                        </template>
                        <em v-for="w in splitWords('One place to discover them.')" :key="`e${w.i}`" class="btw-word" :style="{ '--i': w.i + 5 }">{{ w.word }}&nbsp;</em>
                    </h2>

                    <p class="btw-script btw-statement-script" data-rev style="--d: 120ms;">A little discovery.</p>

                    <div class="btw-statement-cols">
                        <div class="btw-statement-col" data-rev style="--d: 0ms;">
                            <h3>For yourself</h3>
                            <p>Clothing, accessories, and personal essentials.</p>
                        </div>
                        <div class="btw-statement-col" data-rev style="--d: 80ms;">
                            <h3>For your home</h3>
                            <p>Practical household finds and everyday supplies.</p>
                        </div>
                        <div class="btw-statement-col" data-rev style="--d: 160ms;">
                            <h3>For your routine</h3>
                            <p>Useful tech and accessories for work, study, and downtime.</p>
                        </div>
                    </div>
                </div>
            </section>

            <!-- ================================================================ -->
            <!-- STAGE 2 — BOTTOM-UP IMAGE REVEAL (320svh) -->
            <!-- ================================================================ -->

            <div ref="revealWrapRef" class="btw-reveal-wrap" style="--p: 0;">
                <section class="btw-reveal">
                    <div class="btw-reveal-photo" aria-hidden="true">
                        <img :src="revealPhoto" alt="A knit cardigan, wireless headphones, and a leather bag arranged together on a warm neutral surface." width="1600" height="1022" loading="lazy" />
                    </div>

                    <div class="btw-reveal-heading">
                        <h2>A glimpse of<br />what's inside.</h2>
                    </div>

                    <div class="btw-reveal-caption">
                        <p>Meet a few of the products you can explore after signing in.</p>
                    </div>
                </section>
            </div>

            <!-- ================================================================ -->
            <!-- RULED PRODUCT PREVIEW -->
            <!-- ================================================================ -->

            <section id="preview" class="btw-preview">
                <div class="btw-preview-inner">
                    <p class="btw-label btw-preview-eyebrow">A few things you'll find</p>

                    <div class="btw-preview-list">
                        <template v-if="productsLoading">
                            <div v-for="n in 4" :key="n" class="btw-preview-row" aria-hidden="true" style="opacity: .4;">
                                <div style="height: 14px; width: 60%; background: rgba(20,37,33,.08);"></div>
                                <div style="height: 12px; width: 80%; background: rgba(20,37,33,.06);"></div>
                                <div style="height: 14px; width: 40%; background: rgba(20,37,33,.08); justify-self: end;"></div>
                            </div>
                        </template>

                        <p v-else-if="productsError" class="btw-preview-empty">
                            Products couldn't be loaded right now.
                        </p>

                        <p v-else-if="products.length === 0" class="btw-preview-empty">
                            No products are listed yet. Check back soon.
                        </p>

                        <div v-for="(p, idx) in products" v-else :key="p.key" class="btw-preview-row" data-rev :style="{ '--d': `${idx * 70}ms` }">
                            <div class="btw-preview-name-wrap">
                                <p class="btw-preview-name">{{ p.name }}</p>
                                <span v-if="p.isSample" class="btw-sample-tag">Sample</span>
                            </div>
                            <p class="btw-preview-desc">{{ p.desc }}</p>
                            <span class="btw-preview-price">{{ peso(p.price) }}</span>
                        </div>
                    </div>
                </div>
            </section>

            <!-- ================================================================ -->
            <!-- STAGE 3 — NEAR-BLACK CUMULATIVE ROWS (360svh) -->
            <!-- ================================================================ -->

            <div ref="spotlightWrapRef" class="btw-spotlight-wrap" style="--p: 0;">
                <section class="btw-spotlight" aria-label="Account benefits">
                    <div>
                        <h2 class="btw-spotlight-heading">Your next find <em>starts with an account.</em></h2>

                        <div class="btw-spotlight-rows">
                            <div
                                v-for="(row, idx) in accountRows"
                                :key="row.name"
                                class="btw-spotlight-row"
                                :class="{ 'is-muted': idx > activeSpotlightIndex }"
                            >
                                <span class="idx">{{ String(idx + 1).padStart(2, '0') }}</span>
                                <span class="name">{{ row.name }}</span>
                                <span class="meta">{{ row.meta }}</span>
                            </div>
                        </div>

                        <div class="btw-spotlight-actions">
                            <a href="/signup" class="btw-btn-outline">Create an Account</a>
                            <a href="/login" class="btw-link-quiet">Log In</a>
                        </div>
                    </div>

                    <div class="btw-spotlight-still">
                        <img :src="spotlightPhoto" alt="A pair of suede boots, a wristwatch, and eyeglasses arranged on a dark surface" width="900" height="1125" loading="lazy" />
                        <span class="btw-spotlight-tag">Member Access</span>
                    </div>
                </section>
            </div>

            <!-- ================================================================ -->
            <!-- FAQ -->
            <!-- ================================================================ -->

            <section id="faq" class="btw-faq">
                <div class="btw-faq-inner">
                    <h2 class="btw-statement-heading" style="font-size: clamp(1.7rem, 3.6vw, 2.3rem);" data-rev>Shopping with BuyTheWay.</h2>

                    <div class="btw-faq-list">
                        <details class="btw-faq-item" data-rev style="--d: 0ms;">
                            <summary>
                                Do I need an account to shop?
                                <span class="btw-faq-indicator" aria-hidden="true"></span>
                            </summary>
                            <p class="btw-faq-answer">Yes. This page offers a preview. Create an account or log in to search the catalog and start shopping.</p>
                        </details>

                        <details class="btw-faq-item" data-rev style="--d: 70ms;">
                            <summary>
                                Where can I find product information?
                                <span class="btw-faq-indicator" aria-hidden="true"></span>
                            </summary>
                            <p class="btw-faq-answer">After signing in, open a product to see its price and category details before you buy.</p>
                        </details>

                        <details class="btw-faq-item" data-rev style="--d: 140ms;">
                            <summary>
                                Can I choose a size, color, or other option?
                                <span class="btw-faq-indicator" aria-hidden="true"></span>
                            </summary>
                            <p class="btw-faq-answer">Some products offer options like size or color. Select the option you need before adding the item to your cart.</p>
                        </details>

                        <details class="btw-faq-item" data-rev style="--d: 210ms;">
                            <summary>
                                Can I buy from different sellers?
                                <span class="btw-faq-indicator" aria-hidden="true"></span>
                            </summary>
                            <p class="btw-faq-answer">Yes. BuyTheWay brings together listings from many independent sellers. Your cart groups items by seller, so you can check out from more than one seller together.</p>
                        </details>

                        <details class="btw-faq-item" data-rev style="--d: 280ms;">
                            <summary>
                                Where can I check my order status?
                                <span class="btw-faq-indicator" aria-hidden="true"></span>
                            </summary>
                            <p class="btw-faq-answer">Order tracking is still being built. Once it's ready, you'll be able to view your purchases and their status from your account.</p>
                        </details>

                        <details class="btw-faq-item" data-rev style="--d: 350ms;">
                            <summary>
                                What kinds of products can I find on BuyTheWay?
                                <span class="btw-faq-indicator" aria-hidden="true"></span>
                            </summary>
                            <p class="btw-faq-answer">Browse by category — Electronics, Fashion, Home &amp; Living, Beauty, Sports, and Groceries — or use search to find something specific.</p>
                        </details>
                    </div>
                </div>
            </section>
        </main>

        <!-- ================================================================ -->
        <!-- FOOTER -->
        <!-- ================================================================ -->

        <footer class="btw-footer">
            <div class="btw-footer-inner">
                <div class="btw-footer-brand" data-rev style="--d: 0ms;">
                    <img :src="logoUrl" alt="BuyTheWay" width="90" height="75" />
                </div>

                <!--
                    Only "About Us" is linked. The brief asks for
                    "Available About and Contact links" and "Privacy
                    Policy and Terms links" — of those, only an About
                    page actually exists in this project; Contact,
                    Privacy Policy, and Terms & Conditions pages don't,
                    so they're left out rather than pointed at pages
                    that don't exist.
                -->
                <nav class="btw-footer-links" aria-label="Company" data-rev style="--d: 60ms;">
                    <a href="/about">About Us</a>
                </nav>

                <div class="btw-footer-bottom" data-rev style="--d: 120ms;">
                    <p class="btw-footer-copy">&copy; {{ new Date().getFullYear() }} BuyTheWay. All rights reserved.</p>
                    <p class="btw-script" style="display: inline-block;">See you inside.</p>
                </div>
            </div>
        </footer>
    </div>
</template>
