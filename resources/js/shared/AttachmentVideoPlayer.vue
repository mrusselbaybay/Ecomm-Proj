<!--
  resources/js/shared/AttachmentVideoPlayer.vue

  Self-styled (no Tailwind dependency) HTML5 video player used for message
  attachments across buyer/seller chat. Custom controls: play/pause, seek,
  rewind/forward 10s, playback speed, mute, and fullscreen enter/exit —
  native <video controls> doesn't expose a speed control consistently
  across browsers, so this is a from-scratch control bar over a plain
  <video> element.
-->
<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';

const { src, name = 'Video' } = defineProps({
    src: { type: String, required: true },
    name: { type: String, default: 'Video' },
});

const SPEEDS = [0.5, 0.75, 1, 1.25, 1.5, 2];
const SEEK_SECONDS = 10;

const containerEl = ref(null);
const videoEl = ref(null);

// A thread can carry several video attachments; giving every one of them a
// src up front means the browser opens that many connections at once and
// each one buffers slower — this is a likely cause of "the video keeps
// buffering" when more than one is on screen. Defer the actual <video src>
// until the player scrolls near the viewport (see hasEnteredView below), so
// only videos the user can actually see start fetching.
const hasEnteredView = ref(false);
let intersectionObserver = null;

const isPlaying = ref(false);
const isLoading = ref(false);
const isScrubbing = ref(false);
const isMuted = ref(false);
const isFullscreen = ref(false);
const showSpeedMenu = ref(false);
const currentTime = ref(0);
const duration = ref(0);
const playbackRate = ref(1);
const controlsVisible = ref(true);

let hideControlsTimer = null;

const progressPct = computed(() => (duration.value ? (currentTime.value / duration.value) * 100 : 0));

function formatTime(seconds) {
    if (!Number.isFinite(seconds) || seconds < 0) {
        return '0:00';
    }

    const mins = Math.floor(seconds / 60);
    const secs = Math.floor(seconds % 60);

    return `${mins}:${String(secs).padStart(2, '0')}`;
}

function togglePlay() {
    const el = videoEl.value;

    if (!el) {
        return;
    }

    if (el.paused || el.ended) {
        el.play();
    } else {
        el.pause();
    }
}

// The big-play overlay can be clicked before the IntersectionObserver has
// mounted the real <video> (e.g. clicked the instant the thread opens) —
// fall back to nudging it into view immediately rather than a dead click.
function onBigPlayClick() {
    if (!hasEnteredView.value) {
        hasEnteredView.value = true;
        nextTick(() => videoEl.value?.play());

        return;
    }

    togglePlay();
}

function onPlay() {
    isPlaying.value = true;
    scheduleHideControls();
}

function onPause() {
    isPlaying.value = false;
    showControls();
}

function onLoadStart() {
    isLoading.value = true;
}

function onLoadedMetadata() {
    duration.value = videoEl.value?.duration || 0;
    isLoading.value = false;
}

function onTimeUpdate() {
    if (!isScrubbing.value) {
        currentTime.value = videoEl.value?.currentTime || 0;
    }
}

function onWaiting() {
    isLoading.value = true;
}

function onPlaying() {
    isLoading.value = false;
}

function onEnded() {
    isPlaying.value = false;
    showControls();
}

function seekTo(value) {
    const el = videoEl.value;

    if (!el || !duration.value) {
        return;
    }

    el.currentTime = Math.min(Math.max(value, 0), duration.value);
    currentTime.value = el.currentTime;
}

function onScrubInput(event) {
    isScrubbing.value = true;
    seekTo(Number(event.target.value));
}

function onScrubEnd() {
    isScrubbing.value = false;
}

function skip(delta) {
    seekTo((videoEl.value?.currentTime || 0) + delta);
}

function toggleMute() {
    const el = videoEl.value;

    if (!el) {
        return;
    }

    el.muted = !el.muted;
    isMuted.value = el.muted;
}

function setSpeed(rate) {
    const el = videoEl.value;

    if (el) {
        el.playbackRate = rate;
    }

    playbackRate.value = rate;
    showSpeedMenu.value = false;
}

function toggleFullscreen() {
    const container = containerEl.value;

    if (!document.fullscreenElement) {
        (container?.requestFullscreen?.() || videoEl.value?.webkitEnterFullscreen?.())?.catch?.(() => {});
    } else {
        document.exitFullscreen?.();
    }
}

function onFullscreenChange() {
    isFullscreen.value = document.fullscreenElement === containerEl.value;
}

function showControls() {
    controlsVisible.value = true;
    scheduleHideControls();
}

function scheduleHideControls() {
    clearTimeout(hideControlsTimer);

    if (!isPlaying.value) {
        return;
    }

    hideControlsTimer = setTimeout(() => {
        if (isPlaying.value && !showSpeedMenu.value) {
            controlsVisible.value = false;
        }
    }, 2500);
}

document.addEventListener('fullscreenchange', onFullscreenChange);

onMounted(() => {
    if (typeof IntersectionObserver === 'undefined' || !containerEl.value) {
        hasEnteredView.value = true;

        return;
    }

    intersectionObserver = new IntersectionObserver(
        (entries) => {
            if (entries.some((entry) => entry.isIntersecting)) {
                hasEnteredView.value = true;
                intersectionObserver?.disconnect();
                intersectionObserver = null;
            }
        },
        { rootMargin: '200px' },
    );
    intersectionObserver.observe(containerEl.value);
});

onBeforeUnmount(() => {
    clearTimeout(hideControlsTimer);
    document.removeEventListener('fullscreenchange', onFullscreenChange);
    intersectionObserver?.disconnect();
});
</script>

<template>
    <div
        ref="containerEl"
        class="avp-root"
        :class="{ 'avp-fullscreen': isFullscreen }"
        @mousemove="showControls"
        @mouseleave="isPlaying && (controlsVisible = false)"
    >
        <video
            v-if="hasEnteredView"
            ref="videoEl"
            class="avp-video"
            :src="src"
            preload="metadata"
            playsinline
            :aria-label="name"
            @click="togglePlay"
            @loadstart="onLoadStart"
            @play="onPlay"
            @pause="onPause"
            @loadedmetadata="onLoadedMetadata"
            @timeupdate="onTimeUpdate"
            @waiting="onWaiting"
            @playing="onPlaying"
            @ended="onEnded"
        ></video>
        <div v-else class="avp-video avp-video-placeholder" @click="onBigPlayClick"></div>

        <div v-if="isLoading" class="avp-spinner-overlay" aria-hidden="true">
            <span class="avp-spinner"></span>
        </div>

        <button
            v-if="!isPlaying && !isLoading"
            type="button"
            class="avp-big-play"
            aria-label="Play video"
            @click="onBigPlayClick"
        >
            <svg viewBox="0 0 24 24" width="26" height="26" fill="currentColor"><path d="M8 5v14l11-7z" /></svg>
        </button>

        <div class="avp-controls" :class="{ 'avp-controls--hidden': !controlsVisible }">
            <input
                class="avp-seek"
                type="range"
                min="0"
                :max="duration || 0"
                step="0.05"
                :value="currentTime"
                :style="{ '--avp-progress': progressPct + '%' }"
                aria-label="Seek"
                @input="onScrubInput"
                @change="onScrubEnd"
                @mousedown="isScrubbing = true"
                @touchstart="isScrubbing = true"
            >

            <div class="avp-controls-row">
                <button type="button" class="avp-btn" :aria-label="isPlaying ? 'Pause' : 'Play'" @click="togglePlay">
                    <svg v-if="isPlaying" viewBox="0 0 24 24" width="16" height="16" fill="currentColor"><path d="M6 5h4v14H6zM14 5h4v14h-4z" /></svg>
                    <svg v-else viewBox="0 0 24 24" width="16" height="16" fill="currentColor"><path d="M8 5v14l11-7z" /></svg>
                </button>

                <button type="button" class="avp-btn" aria-label="Rewind 10 seconds" @click="skip(-SEEK_SECONDS)">
                    <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 8v8M12.5 19a7 7 0 1 0-6.5-9.5L3 12" /><path d="M3 6v4h4" /></svg>
                </button>

                <button type="button" class="avp-btn" aria-label="Forward 10 seconds" @click="skip(SEEK_SECONDS)">
                    <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 8v8M11.5 19a7 7 0 1 1 6.5-9.5L21 12" /><path d="M21 6v4h-4" /></svg>
                </button>

                <span class="avp-time">{{ formatTime(currentTime) }} / {{ formatTime(duration) }}</span>

                <span class="avp-spacer"></span>

                <button type="button" class="avp-btn" :aria-label="isMuted ? 'Unmute' : 'Mute'" @click="toggleMute">
                    <svg v-if="isMuted" viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 5 6 9H2v6h4l5 4z" /><path d="M23 9l-6 6M17 9l6 6" /></svg>
                    <svg v-else viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 5 6 9H2v6h4l5 4z" /><path d="M15.5 8.5a5 5 0 0 1 0 7M19 5a9 9 0 0 1 0 14" /></svg>
                </button>

                <div class="avp-speed">
                    <button type="button" class="avp-btn avp-speed-btn" aria-label="Playback speed" @click="showSpeedMenu = !showSpeedMenu">
                        {{ playbackRate }}x
                    </button>
                    <div v-if="showSpeedMenu" class="avp-speed-menu">
                        <button
                            v-for="speed in SPEEDS"
                            :key="speed"
                            type="button"
                            class="avp-speed-item"
                            :class="{ active: speed === playbackRate }"
                            @click="setSpeed(speed)"
                        >
                            {{ speed }}x
                        </button>
                    </div>
                </div>

                <button type="button" class="avp-btn" :aria-label="isFullscreen ? 'Exit full screen' : 'Full screen'" @click="toggleFullscreen">
                    <svg v-if="isFullscreen" viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 3v4a1 1 0 0 1-1 1H4M15 3v4a1 1 0 0 0 1 1h4M9 21v-4a1 1 0 0 0-1-1H4M15 21v-4a1 1 0 0 1 1-1h4" /></svg>
                    <svg v-else viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 3H4v4M16 3h4v4M8 21H4v-4M16 21h4v-4" /></svg>
                </button>
            </div>
        </div>
    </div>
</template>

<style scoped>
.avp-root {
    position: relative;
    width: 100%;
    aspect-ratio: 16 / 9;
    background: #0f172a;
    border-radius: 0.75rem;
    overflow: hidden;
    color: #fff;
    font-family: inherit;
}

.avp-fullscreen {
    border-radius: 0;
}

.avp-video {
    width: 100%;
    height: 100%;
    display: block;
    object-fit: contain;
    background: #000;
    cursor: pointer;
}

.avp-spinner-overlay {
    position: absolute;
    inset: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    background: rgba(15, 23, 42, 0.25);
    pointer-events: none;
}

.avp-spinner {
    width: 2rem;
    height: 2rem;
    border-radius: 9999px;
    border: 3px solid rgba(255, 255, 255, 0.35);
    border-top-color: #fff;
    animation: avp-spin 0.75s linear infinite;
}

@keyframes avp-spin {
    to { transform: rotate(360deg); }
}

.avp-big-play {
    position: absolute;
    inset: 0;
    margin: auto;
    width: 3.25rem;
    height: 3.25rem;
    border-radius: 9999px;
    background: rgba(15, 23, 42, 0.55);
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    border: none;
    cursor: pointer;
    transition: background 0.15s ease, transform 0.15s ease;
}

.avp-big-play:hover {
    background: rgba(15, 23, 42, 0.75);
    transform: scale(1.06);
}

.avp-controls {
    position: absolute;
    left: 0;
    right: 0;
    bottom: 0;
    padding: 0.4rem 0.6rem 0.55rem;
    background: linear-gradient(to top, rgba(0, 0, 0, 0.75), rgba(0, 0, 0, 0));
    transition: opacity 0.2s ease;
}

.avp-controls--hidden {
    opacity: 0;
    pointer-events: none;
}

.avp-seek {
    -webkit-appearance: none;
    appearance: none;
    width: 100%;
    height: 4px;
    border-radius: 9999px;
    background: linear-gradient(to right, #14b8a6 var(--avp-progress, 0%), rgba(255, 255, 255, 0.3) var(--avp-progress, 0%));
    cursor: pointer;
    margin: 0 0 0.4rem;
    display: block;
}

.avp-seek::-webkit-slider-thumb {
    -webkit-appearance: none;
    width: 11px;
    height: 11px;
    border-radius: 9999px;
    background: #fff;
    cursor: pointer;
}

.avp-seek::-moz-range-thumb {
    width: 11px;
    height: 11px;
    border: none;
    border-radius: 9999px;
    background: #fff;
    cursor: pointer;
}

.avp-controls-row {
    display: flex;
    align-items: center;
    gap: 0.35rem;
}

.avp-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 1.75rem;
    height: 1.75rem;
    border: none;
    background: transparent;
    color: #fff;
    border-radius: 0.4rem;
    cursor: pointer;
    padding: 0;
    flex-shrink: 0;
}

.avp-btn:hover {
    background: rgba(255, 255, 255, 0.15);
}

.avp-time {
    font-size: 0.65rem;
    font-variant-numeric: tabular-nums;
    color: rgba(255, 255, 255, 0.9);
    white-space: nowrap;
}

.avp-spacer {
    flex: 1;
}

.avp-speed {
    position: relative;
}

.avp-speed-btn {
    width: auto;
    padding: 0 0.35rem;
    font-size: 0.65rem;
    font-weight: 700;
}

.avp-speed-menu {
    position: absolute;
    bottom: calc(100% + 0.35rem);
    right: 0;
    background: #1e293b;
    border-radius: 0.5rem;
    padding: 0.25rem;
    display: flex;
    flex-direction: column;
    box-shadow: 0 4px 16px rgba(0, 0, 0, 0.35);
    z-index: 1;
}

.avp-speed-item {
    border: none;
    background: transparent;
    color: #fff;
    font-size: 0.7rem;
    padding: 0.3rem 0.6rem;
    border-radius: 0.35rem;
    cursor: pointer;
    text-align: left;
    white-space: nowrap;
}

.avp-speed-item:hover {
    background: rgba(255, 255, 255, 0.12);
}

.avp-speed-item.active {
    color: #2dd4bf;
    font-weight: 700;
}
</style>
