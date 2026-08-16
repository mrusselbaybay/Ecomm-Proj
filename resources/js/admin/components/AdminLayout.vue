<!-- resources/js/admin/components/AdminLayout.vue -->
<template>
  <div class="admin-app">
    <!-- Loading State -->
    <div v-if="isLoading" class="min-h-screen flex items-center justify-center">
      <div class="text-center">
        <div class="loading-spinner mx-auto mb-4"></div>
        <p class="text-slate-500">Loading admin panel...</p>
      </div>
    </div>
    
    <!-- Admin Panel -->
    <div v-else-if="isAuthenticated && isAdmin" class="min-h-screen flex" style="height:100vh;overflow:hidden;">
      <!-- SIDEBAR -->
      <aside class="side-panel">
        <div class="sidebar-top">
          <!-- Logo -->
          <div class="sidebar-logo">
            <div class="logo-icon">
              <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2">
                <path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4Z"/>
                <path d="M3 6h18"/>
                <path d="M16 10a4 4 0 0 1-8 0"/>
              </svg>
            </div>
            <div>
              <p class="logo-text">NEXMART</p>
              <p class="logo-sub">Admin Panel</p>
            </div>
          </div>

          <!-- Navigation -->
          <nav class="sidebar-nav">
            <div 
              v-for="item in navItems" 
              :key="item.id" 
              @click="navigateTo(item.id)"
              class="sidebar-link" 
              :class="{ active: currentSection === item.id }"
            >
              <span class="icon-wrap" v-html="getIcon(item.icon)"></span>
              <span class="nav-label">{{ item.label }}</span>
              <span v-if="item.badge" class="nav-badge">{{ item.badge }}</span>
            </div>
          </nav>
        </div>

        <!-- Bottom Section -->
        <div class="sidebar-bottom">
          <div class="sidebar-divider"></div>
          
          <!-- Logout -->
          <div class="sidebar-link logout" @click="showLogoutConfirm = true">
            <span class="icon-wrap">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
                <polyline points="16 17 21 12 16 7"/>
                <line x1="21" y1="12" x2="9" y2="12"/>
              </svg>
            </span>
            <span class="nav-label">Logout</span>
          </div>
          
          <!-- Admin Profile -->
          <div class="admin-profile">
            <div class="profile-avatar">{{ adminInitials }}</div>
            <div class="profile-info">
              <p class="profile-name">{{ adminProfile.name }}</p>
              <p class="profile-role">Platform Admin</p>
            </div>
          </div>
        </div>
      </aside>

      <!-- MAIN CONTENT -->
      <main class="main-content">
        <div class="content-wrapper">
          <!-- Header -->
          <div class="content-header">
            <div>
              <p class="header-subtitle">Admin Panel</p>
              <h1 class="header-title">{{ sectionLabel }}</h1>
            </div>
            <div class="header-actions">
              <span v-if="pendingCount > 0" class="badge-pending">{{ pendingCount }} pending</span>
            </div>
          </div>
          
          <!-- Dynamic Component -->
          <component :is="currentComponent" />
        </div>
      </main>

      <!-- LOGOUT CONFIRM MODAL -->
      <div v-if="showLogoutConfirm" class="modal-overlay" @click.self="showLogoutConfirm = false">
        <div class="modal-panel modal-sm">
          <div class="modal-header">
            <h3>Log out?</h3>
            <button class="modal-close" @click="showLogoutConfirm = false">
              <svg class="icon" viewBox="0 0 20 20" fill="none"><path d="M5 5l10 10M15 5L5 15" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
            </button>
          </div>
          <p class="modal-desc text-center">You'll need to sign in again to access the admin panel.</p>
          <div class="modal-actions">
            <button @click="showLogoutConfirm = false" class="btn-outline flex-1 py-2">Cancel</button>
            <button @click="confirmLogout" class="btn-danger flex-1 py-2">Log Out</button>
          </div>
        </div>
      </div>
    </div>
    
    <!-- Not Authorized -->
    <div v-else class="min-h-screen flex items-center justify-center">
      <div class="text-center">
        <h1 class="text-2xl font-bold text-red-600 mb-2">Access Denied</h1>
        <p class="text-slate-500 mb-4">You do not have permission to view this page.</p>
        <a href="/" class="text-orange-600 hover:underline">Return to Home</a>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, watch, onBeforeUnmount } from 'vue';
import { useAdmin } from '../composables/useAdmin';

// Regular imports
import Dashboard from './Dashboard.vue';
import Users from './Users.vue';
import Compliance from './Compliance.vue';
import Complaints from './Complaints.vue';
import Commission from './Commission.vue';
import Reports from './Reports.vue';
import Settings from './Settings.vue';
import Chat from './Chat.vue';
import Profile from './Profile.vue';

// State
const currentSection = ref('dashboard');
const showLogoutConfirm = ref(false);

// Use admin composable
const {
  isLoading,
  isAuthenticated,
  isAdmin,
  adminProfile,
  pendingCount,
  loadStats,
  loadNotifications,
  checkAuth,
  confirmLogout,
} = useAdmin();

// URL to section mapping
const pathToSection = {
  '/admin/dashboard': 'dashboard',
  '/admin/accounts': 'accounts',
  '/admin/compliance': 'compliance',
  '/admin/complaints': 'complaints',
  '/admin/commission': 'commission',
  '/admin/reports': 'reports',
  '/admin/settings': 'settings',
  '/admin/chat': 'chat',
  '/admin/profile': 'profile'
};

// Section to URL mapping
const sectionToPath = {
  dashboard: '/admin/dashboard',
  accounts: '/admin/accounts',
  compliance: '/admin/compliance',
  complaints: '/admin/complaints',
  commission: '/admin/commission',
  reports: '/admin/reports',
  settings: '/admin/settings',
  chat: '/admin/chat',
  profile: '/admin/profile'
};

// Component map
const componentMap = {
  dashboard: Dashboard,
  accounts: Users,
  compliance: Compliance,
  complaints: Complaints,
  commission: Commission,
  reports: Reports,
  settings: Settings,
  chat: Chat,
  profile: Profile,
};

const currentComponent = computed(() => componentMap[currentSection.value] || Dashboard);

const sectionLabel = computed(() => {
  const labels = {
    dashboard: 'Dashboard',
    accounts: 'User Accounts',
    compliance: 'Seller Compliance',
    complaints: 'Complaints & Disputes',
    commission: 'Commission (10%)',
    reports: 'Generate Reports',
    settings: 'Platform Settings',
    chat: 'Chat / Messaging',
    profile: 'Account Management',
  };
  return labels[currentSection.value] || 'Dashboard';
});

const navItems = computed(() => [
  { id: 'dashboard', label: 'Dashboard', icon: 'grid' },
  { id: 'accounts', label: 'User Accounts', icon: 'users' },
  { id: 'compliance', label: 'Seller Compliance', icon: 'shield' },
  { id: 'complaints', label: 'Complaints & Disputes', icon: 'alert' },
  { id: 'commission', label: 'Commission (10%)', icon: 'percent' },
  { id: 'reports', label: 'Generate Reports', icon: 'file' },
  { id: 'settings', label: 'Platform Settings', icon: 'settings' },
  { id: 'chat', label: 'Chat / Messaging', icon: 'chat' },
  { id: 'profile', label: 'Account Management', icon: 'userCog' },
]);

const adminInitials = computed(() => {
  if (!adminProfile.value?.name) return 'AU';
  const parts = adminProfile.value.name.split(' ');
  if (parts.length >= 2) {
    return (parts[0][0] + parts[1][0]).toUpperCase();
  }
  return parts[0].substring(0, 2).toUpperCase();
});

function getIcon(iconName) {
  const icons = {
    grid: `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>`,
    users: `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>`,
    shield: `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z"/><path d="m9 12 2 2 4-4"/></svg>`,
    alert: `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0Z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>`,
    percent: `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="19" y1="5" x2="5" y2="19"/><circle cx="6.5" cy="6.5" r="2.5"/><circle cx="17.5" cy="17.5" r="2.5"/></svg>`,
    file: `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z"/><path d="M14 2v6h6"/><line x1="9" y1="13" x2="15" y2="13"/><line x1="9" y1="17" x2="15" y2="17"/></svg>`,
    settings: `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1Z"/></svg>`,
    chat: `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5Z"/></svg>`,
    userCog: `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="9" cy="7" r="4"/><path d="M2 21v-2a4 4 0 0 1 4-4h3"/><circle cx="18" cy="17" r="3"/><path d="M18 14.5v0M18 19.5v0M20.6 15.5l0 0M15.4 18.5l0 0M20.6 18.5l0 0M15.4 15.5l0 0"/></svg>`,
  };
  return icons[iconName] || '';
}

// Navigation with URL sync
function navigateTo(sectionId) {
  const path = sectionToPath[sectionId];
  if (!path) return;
  
  currentSection.value = sectionId;
  
  if (window.location.pathname !== path) {
    window.history.pushState({ section: sectionId }, '', path);
  }
}

function handlePopState(event) {
  const path = window.location.pathname;
  const section = pathToSection[path] || 'dashboard';
  
  if (currentSection.value !== section) {
    currentSection.value = section;
  }
}

watch(
  () => window.location.pathname,
  (newPath) => {
    const section = pathToSection[newPath] || 'dashboard';
    if (currentSection.value !== section) {
      currentSection.value = section;
    }
  }
);

onMounted(() => {
  const initialPath = window.location.pathname;
  const initialSection = pathToSection[initialPath] || 'dashboard';
  currentSection.value = initialSection;
  
  checkAuth();
  loadStats();
  loadNotifications();
  
  window.addEventListener('popstate', handlePopState);
});

onBeforeUnmount(() => {
  window.removeEventListener('popstate', handlePopState);
});
</script>

<style scoped>
/* ✅ Import all styles from the external CSS file */
@import '../../../css/admin/layout.css';

/* Only keep component-specific overrides here if needed */
</style>