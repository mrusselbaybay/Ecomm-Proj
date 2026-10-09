<script setup>
/*
|--------------------------------------------------------------------------
| HeroBanner — storefront hero
|--------------------------------------------------------------------------
|
| Editorial split: type set straight on the page (no card), one photograph
| of a real small shop beside it. The headline hangs from the photo's top
| edge and the supporting copy + actions sit on its bottom edge, so the two
| columns share one frame instead of competing as separate boxes.
|
| Photo: "Ceramic bowls and vases on a wooden display table in a sunlit
| shop" by tommao wang, Unsplash License (images.unsplash.com/
| photo-1789811878526-ff057243a782). Cropped below the window lettering and
| re-encoded to WebP in public/images/hero/: a 16:10 crop for desktop and
| tablet, a 4:3 crop for phones.
|
| Motion is CSS-only (see "HERO" in layout.css) and skipped entirely under
| prefers-reduced-motion.
|
*/
// Deals are linked from the shortcut row directly below the hero (with the
// real count), so the hero's secondary action points at the full catalog
// instead of repeating that intent.
const emit = defineEmits([
    'shop-categories',
    'browse-all'
]);

// Served from public/, bound rather than static attributes so Vite doesn't
// try to resolve them as module imports.
const HERO_DIR = '/images/hero';

const wideImage = {
    src: `${HERO_DIR}/hero-shop-wide-1600.webp`,
    srcset: `${HERO_DIR}/hero-shop-wide-960.webp 960w, ${HERO_DIR}/hero-shop-wide-1600.webp 1600w`
};

const tallImage = {
    srcset: `${HERO_DIR}/hero-shop-tall-640.webp 640w, ${HERO_DIR}/hero-shop-tall-1080.webp 1080w`
};
</script>

<template>

    <section
        class="hero"
        aria-labelledby="hero-title"
    >

        <div class="hero-copy">
            <h1
                id="hero-title"
                class="hero-title"
            >
                <span class="hero-line">Local shops.</span>
                <span class="hero-line is-accent">One cart.</span>
            </h1>

            <div class="hero-foot">
                <p class="hero-sub">
                    Gadgets, clothes, pet supplies and home basics from independent sellers across the Philippines, with every order tracked in one place.
                </p>

                <div class="hero-actions">
                    <button
                        type="button"
                        class="hero-cta"
                        @click="emit('shop-categories')"
                    >
                        Shop by category
                        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6" /></svg>
                    </button>

                    <button
                        type="button"
                        class="hero-link"
                        @click="emit('browse-all')"
                    >
                        Browse all products
                    </button>
                </div>
            </div>
        </div>

        <picture class="hero-media">
            <source
                media="(max-width: 767px)"
                :srcset="tallImage.srcset"
                sizes="100vw"
                width="1080"
                height="810"
            >
            <img
                :src="wideImage.src"
                :srcset="wideImage.srcset"
                sizes="(max-width: 1023px) 50vw, 58vw"
                width="1600"
                height="1000"
                alt="Ceramics, plants and books arranged on a wooden counter in a small independent shop"
                decoding="async"
                fetchpriority="high"
            >
        </picture>

    </section>

</template>
