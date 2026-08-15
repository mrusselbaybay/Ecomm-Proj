<!-- resources/js/admin/layouts/AdminLayout.vue -->
<template>
  <div class="min-h-screen flex" style="height:100vh;overflow:hidden;">
    <!-- SIDEBAR -->
    <aside class="side-panel w-64 flex-shrink-0 text-white flex flex-col justify-between px-4 py-6" style="height:100vh;overflow-y:auto;">
      <div>
        <div class="flex items-center gap-3 mb-8 px-2">
          <div class="w-9 h-9 rounded-xl bg-gradient-to-br from-orange-500 to-amber-500 flex items-center justify-center">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2">
              <path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4Z"/>
              <path d="M3 6h18"/><path d="M16 10a4 4 0 0 1-8 0"/>
            </svg>
          </div>
          <div>
            <p class="text-sm font-bold tracking-wide leading-tight">NEXMART</p>
            <p class="text-[0.65rem] text-orange-400 font-bold tracking-widest uppercase leading-tight">Admin Panel</p>
          </div>
        </div>

        <nav class="space-y-1">
          <div v-for="item in navItems" :key="item.id" 
               @click="$router.push(item.path)"
               class="sidebar-link" 
               :class="{ active: $route.path === item.path }">
            <span class="icon-wrap" v-html="getIcon(item.icon)"></span>
            <span>{{ item.label }}</span>
            <span v-if="item.badge" class="badge badge-amber ml-auto text-xs">{{ item.badge }}</span>
          </div>
        </nav>
      </div>

      <div>
        <div class="border-t border-white/10 pt-3 mb-2">
          <div class="sidebar-link" @click="$emit('logout')">
            <span class="icon-wrap">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
                <polyline points="16 17 21 12 16 7"/>
                <line x1="21" y1="12" x2="9" y2="12"/>
              </svg>
            </span>
            <span>Logout</span>
          </div>
        </div>
        <div class="flex items-center gap-2 px-2">
          <div class="w-8 h-8 rounded-full bg-orange-500/20 text-orange-400 flex items-center justify-center text-xs font-bold">
            {{ adminInitials }}
          </div>
          <div class="leading-tight">
            <p class="text-xs font-semibold text-white">{{ adminProfile.name }}</p>
            <p class="text-[0.65rem] text-slate-400">Platform Admin</p>
          </div>
        </div>
      </div>
    </aside>

    <!-- MAIN CONTENT -->
    <main class="flex-1 overflow-y-auto" style="height:100vh;">
      <div class="max-w-6xl mx-auto px-8 py-8">
        <div class="flex items-center justify-between mb-6">
          <div>
            <p class="text-orange-500 text-xs font-bold tracking-widest uppercase mb-1">Admin Panel</p>
            <h1 class="section-title text-3xl text-slate-900">{{ $route.meta.title || 'Dashboard' }}</h1>
          </div>
          <div class="flex items-center gap-2">
            <span v-if="pendingCount > 0" class="badge badge-amber">{{ pendingCount }} pending</span>
          </div>
        </div>
        
        <slot />
      </div>
    </main>
  </div>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
  adminProfile: Object,
  pendingCount: Number,
  navItems: Array,
});

const adminInitials = computed(() => {
  if (!props.adminProfile?.name) return 'AU';
  const parts = props.adminProfile.name.split(' ');
  if (parts.length >= 2) {
    return (parts[0][0] + parts[1][0]).toUpperCase();
  }
  return parts[0].substring(0, 2).toUpperCase();
});

const getIcon = (iconName) => {
  const icons = {
    grid: `<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>`,
    userCheck: `<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="m17 11 2 2 4-4"/></svg>`,
    users: `<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>`,
    shield: `<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z"/><path d="m9 12 2 2 4-4"/></svg>`,
    alert: `<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0Z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>`,
    percent: `<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="19" y1="5" x2="5" y2="19"/><circle cx="6.5" cy="6.5" r="2.5"/><circle cx="17.5" cy="17.5" r="2.5"/></svg>`,
    file: `<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z"/><path d="M14 2v6h6"/><line x1="9" y1="13" x2="15" y2="13"/><line x1="9" y1="17" x2="15" y2="17"/></svg>`,
    settings: `<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1Z"/></svg>`,
    chat: `<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5Z"/></svg>`,
    userCog: `<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="9" cy="7" r="4"/><path d="M2 21v-2a4 4 0 0 1 4-4h3"/><circle cx="18" cy="17" r="3"/><path d="M18 14.5v0M18 19.5v0M20.6 15.5l0 0M15.4 18.5l0 0M20.6 18.5l0 0M15.4 15.5l0 0"/></svg>`,
  };
  return icons[iconName] || '';
};

defineEmits(['logout']);
</script>

<style scoped>
.sidebar-link {
  display: flex;
  align-items: center;
  gap: 0.65rem;
  padding: 0.55rem 0.75rem;
  border-radius: 0.5rem;
  font-size: 0.82rem;
  font-weight: 600;
  color: #94a3b8;
  transition: background 0.15s ease, color 0.15s ease;
  cursor: pointer;
  border: 1px solid transparent;
}
.sidebar-link:hover { background: rgba(255,255,255,0.05); color: #e2e8f0; }
.sidebar-link.active {
  background: rgba(234,88,12,0.14);
  border-color: rgba(234,88,12,0.35);
  color: #fb923c;
}
.sidebar-link .icon-wrap {
  width: 18px; height: 18px; flex-shrink: 0;
  display: flex; align-items: center; justify-content: center;
}
.side-panel {
  background-color: #0f1420;
  background-image:
    radial-gradient(circle at 15% 25%, rgba(234,88,12,0.12) 0, transparent 45%),
    radial-gradient(circle at 85% 80%, rgba(234,88,12,0.10) 0, transparent 45%),
    radial-gradient(circle, rgba(255,255,255,0.05) 1px, transparent 1px);
  background-size: auto, auto, 22px 22px;
}
.badge {
  display: inline-flex;
  align-items: center;
  padding: 0.15rem 0.55rem;
  border-radius: 9999px;
  font-size: 0.68rem;
  font-weight: 700;
  letter-spacing: 0.02em;
}
.badge-amber { background: #fef3c7; color: #b45309; }
.section-title { font-family: 'Playfair Display', serif; font-weight: 800; }
</style>