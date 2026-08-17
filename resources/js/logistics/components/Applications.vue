<!-- resources/js/logistics/components/Applications.vue -->
<template>
  <div class="logistics-page">

    <div class="toast-stack">
      <transition-group name="toast">
        <div v-for="t in toasts" :key="t.id" class="toast" :class="t.type">
          <span>{{ t.message }}</span>
        </div>
      </transition-group>
    </div>

    <div class="page-header">
      <div>
        <h2 class="page-title">Courier Applications</h2>
        <p class="page-subtitle">Review couriers who applied to join {{ companyName }}.</p>
      </div>
    </div>

    <div class="grid grid-cols-4 gap-4 mb-6">
      <div class="stat-card">
        <p class="field-label">Total</p>
        <p class="text-2xl font-bold stat-total">{{ applications.length }}</p>
      </div>
      <div class="stat-card">
        <p class="field-label">Pending</p>
        <p class="text-2xl font-bold stat-pending">{{ pendingCount }}</p>
      </div>
      <div class="stat-card">
        <p class="field-label">Accepted</p>
        <p class="text-2xl font-bold stat-active">{{ acceptedCount }}</p>
      </div>
      <div class="stat-card">
        <p class="field-label">Rejected</p>
        <p class="text-2xl font-bold stat-deactivated">{{ rejectedCount }}</p>
      </div>
    </div>

    <div class="toolbar">
      <div class="search-input">
        <input type="text" v-model="search" placeholder="Search by courier name or email..." @input="debouncedLoad" />
      </div>
      <select v-model="statusFilter" class="field-input w-40" @change="load">
        <option value="">All Statuses</option>
        <option value="pending">Pending</option>
        <option value="accepted">Accepted</option>
        <option value="rejected">Rejected</option>
        <option value="withdrawn">Withdrawn</option>
      </select>
    </div>

    <div class="card overflow-hidden">
      <table class="admin-table">
        <thead>
          <tr>
            <th>Courier</th>
            <th>Vehicle</th>
            <th>Status</th>
            <th>Documents</th>
            <th class="text-right">Actions</th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="loading">
            <td colspan="5" class="text-center py-8"><div class="loading-spinner"></div></td>
          </tr>
          <tr v-else-if="applications.length === 0">
            <td colspan="5" class="empty-state"><p>No applications match these filters.</p></td>
          </tr>
          <tr v-for="app in applications" :key="app.id">
            <td>
              <div class="flex items-center gap-3">
                <div class="avatar">{{ initials(app) }}</div>
                <div>
                  <p class="font-medium text-slate-800">{{ app.courier?.first_name }} {{ app.courier?.last_name }}</p>
                  <p class="text-xs text-slate-500">{{ app.courier?.email }}</p>
                </div>
              </div>
            </td>
            <td>
              <p class="text-sm">{{ app.courier_details?.vehicle || '—' }}</p>
              <p class="text-xs text-slate-500">{{ app.courier_details?.plate_number || '' }}</p>
            </td>
            <td>
              <span class="badge" :class="badgeClass(app.status)">
                <span class="status-dot" :class="app.status"></span>{{ app.status }}
              </span>
            </td>
            <td>
              <button @click="openDocuments(app)" class="btn-doc">View</button>
            </td>
            <td>
              <div class="action-buttons">
                <template v-if="app.status === 'pending'">
                  <button @click="acceptApplication(app)" class="btn-sm-primary">Accept</button>
                  <button @click="openRejectModal(app)" class="btn-danger-outline">Reject</button>
                </template>
                <template v-else-if="app.status === 'rejected'">
                  <button
                    v-if="app.rejection_reason"
                    @click="showRejectionReason(app)"
                    class="btn-sm-outline"
                  >View Reason</button>
                </template>
                <template v-else-if="app.status === 'accepted'">
                  <span class="text-xs text-slate-500">Joined {{ formatDate(app.reviewed_at) }}</span>
                </template>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- REJECT MODAL -->
    <transition name="modal">
      <div v-if="showRejectModal" class="modal-overlay" @click.self="closeRejectModal">
        <div class="modal-panel modal-lg">
          <div class="modal-header">
            <h3>Reject Application</h3>
            <button class="modal-close" @click="closeRejectModal">✕</button>
          </div>
          <p class="modal-desc">
            Select a reason for rejecting <strong>{{ rejectApp?.courier?.first_name }} {{ rejectApp?.courier?.last_name }}</strong>'s application:
          </p>
          <div class="space-y-3">
            <div
              v-for="reason in rejectionReasons"
              :key="reason.value"
              class="reason-option"
              :class="{ active: selectedReason === reason.value }"
              @click="selectedReason = reason.value"
            >
              <input type="radio" :value="reason.value" v-model="selectedReason" />
              <div>
                <p class="reason-label">{{ reason.label }}</p>
                <p class="reason-desc">{{ reason.description }}</p>
              </div>
            </div>
            <div class="reason-option" :class="{ active: selectedReason === 'others' }" @click="selectedReason = 'others'">
              <input type="radio" value="others" v-model="selectedReason" />
              <div class="flex-1">
                <p class="reason-label">Others</p>
                <textarea
                  v-if="selectedReason === 'others'"
                  v-model="customReason"
                  @click.stop
                  placeholder="Please specify..."
                  class="field-input mt-2"
                  rows="3"
                ></textarea>
              </div>
            </div>
          </div>
          <div class="modal-actions">
            <button @click="closeRejectModal" class="btn-outline">Cancel</button>
            <button
              @click="submitRejection"
              class="btn-danger"
              :disabled="!selectedReason || (selectedReason === 'others' && !customReason.trim())"
            >Reject Application</button>
          </div>
        </div>
      </div>
    </transition>

    <!-- VIEW REASON MODAL -->
    <transition name="modal">
      <div v-if="showReasonModal" class="modal-overlay" @click.self="showReasonModal = false">
        <div class="modal-panel modal-sm">
          <div class="modal-header">
            <h3>Rejection Reason</h3>
            <button class="modal-close" @click="showReasonModal = false">✕</button>
          </div>
          <div class="callout-red">{{ reasonApp?.rejection_reason }}</div>
          <div class="modal-actions">
            <button @click="showReasonModal = false" class="btn-outline">Close</button>
          </div>
        </div>
      </div>
    </transition>

    <!-- DOCUMENTS MODAL -->
    <transition name="modal">
      <div v-if="showDocsModal" class="modal-overlay" @click.self="closeDocuments">
        <div class="modal-panel">
          <div class="modal-header">
            <h3>Documents — {{ docsApp?.courier?.first_name }} {{ docsApp?.courier?.last_name }}</h3>
            <button class="modal-close" @click="closeDocuments">✕</button>
          </div>
          <div v-if="docsLoading" class="py-8 text-center"><div class="loading-spinner"></div></div>
          <div v-else-if="userDocuments.length === 0" class="empty-state"><p>No documents uploaded yet.</p></div>
          <div v-else class="doc-list">
            <div v-for="doc in userDocuments" :key="doc.id" class="doc-row">
              <div class="doc-info">
                <div>
                  <p class="doc-type">{{ formatRole(doc.doc_type) }}</p>
                  <p class="doc-date">Uploaded {{ formatDate(doc.created_at) }}</p>
                </div>
              </div>
              <div class="doc-actions">
                <span class="badge" :class="badgeClass(doc.status)">{{ doc.status }}</span>
                <button class="btn-sm-outline" @click="viewDocument(doc)">View</button>
              </div>
            </div>
          </div>
          <div class="modal-actions">
            <button @click="closeDocuments" class="btn-outline">Close</button>
          </div>
        </div>
      </div>
    </transition>

    <!-- DOC PREVIEW -->
    <transition name="modal">
      <div v-if="previewDoc" class="modal-overlay preview-overlay" @click.self="closePreview">
        <div class="preview-panel">
          <div class="modal-header">
            <h3>{{ formatRole(previewDoc.doc_type) }}</h3>
            <button class="modal-close" @click="closePreview">✕</button>
          </div>
          <div class="preview-body">
            <div v-if="previewLoading" class="loading-spinner"></div>
            <img v-else-if="previewUrl" :src="previewUrl" class="preview-image" alt="Document preview" />
          </div>
        </div>
      </div>
    </transition>

    <!-- CONFIRM MODAL -->
    <transition name="modal">
      <div v-if="confirmModal.show" class="modal-overlay confirm-overlay" @click.self="resolveConfirm(false)">
        <div class="modal-panel modal-sm">
          <h3 class="confirm-title">{{ confirmModal.title }}</h3>
          <p class="confirm-message">{{ confirmModal.message }}</p>
          <div class="confirm-actions">
            <button @click="resolveConfirm(false)" class="btn-outline confirm-cancel">Cancel</button>
            <button @click="resolveConfirm(true)" class="btn-primary confirm-confirm">{{ confirmModal.confirmLabel }}</button>
          </div>
        </div>
      </div>
    </transition>

  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue';
import { useLogistics } from '../composables/useLogistics';

const { supabase, companyName, applications, pendingCount, loadApplications } = useLogistics();

const search = ref('');
const statusFilter = ref('');
const loading = ref(false);

const acceptedCount = computed(() => applications.value.filter(a => a.status === 'accepted').length);
const rejectedCount = computed(() => applications.value.filter(a => a.status === 'rejected').length);

let searchDebounce = null;
function debouncedLoad() {
  clearTimeout(searchDebounce);
  searchDebounce = setTimeout(load, 350);
}

async function load() {
  loading.value = true;
  try {
    await loadApplications({ status: statusFilter.value, search: search.value });
  } catch (e) {
    showToast('Failed to load applications: ' + e.message, 'error');
  } finally {
    loading.value = false;
  }
}

// TOASTS
const toasts = ref([]);
function showToast(message, type = 'success') {
  const id = Date.now() + Math.random();
  toasts.value.push({ id, message, type });
  setTimeout(() => { toasts.value = toasts.value.filter(t => t.id !== id); }, 4000);
}

// CONFIRM
const confirmModal = reactive({ show: false, title: '', message: '', confirmLabel: 'Confirm', resolve: null });
function askConfirm(title, message, confirmLabel = 'Confirm') {
  return new Promise(resolve => {
    Object.assign(confirmModal, { show: true, title, message, confirmLabel, resolve });
  });
}
function resolveConfirm(result) {
  confirmModal.show = false;
  if (confirmModal.resolve) confirmModal.resolve(result);
  confirmModal.resolve = null;
}

// REJECT MODAL
const showRejectModal = ref(false);
const rejectApp = ref(null);
const selectedReason = ref('');
const customReason = ref('');

const rejectionReasons = [
  { value: 'incomplete_docs', label: 'Incomplete or unclear documents', description: 'Uploaded license, ID, or OR/CR is missing or unreadable.' },
  { value: 'vehicle_mismatch', label: 'Vehicle does not meet requirements', description: 'The courier\'s vehicle type doesn\'t fit current company needs.' },
  { value: 'capacity', label: 'No open capacity right now', description: 'The company isn\'t accepting new couriers at this time.' },
  { value: 'not_a_fit', label: 'Not a fit for this company', description: 'Coverage area, availability, or experience doesn\'t match.' },
];

function openRejectModal(app) {
  rejectApp.value = app;
  selectedReason.value = '';
  customReason.value = '';
  showRejectModal.value = true;
}
function closeRejectModal() {
  showRejectModal.value = false;
  rejectApp.value = null;
}

async function submitRejection() {
  let message = '';
  if (selectedReason.value === 'others') {
    message = customReason.value.trim();
    if (!message) { showToast('Please specify the reason.', 'error'); return; }
  } else {
    const r = rejectionReasons.find(r => r.value === selectedReason.value);
    message = r ? `${r.label} — ${r.description}` : selectedReason.value;
  }

  const app = { ...rejectApp.value };
  closeRejectModal();

  try {
    const { error } = await supabase
      .from('courier_applications')
      .update({ status: 'rejected', rejection_reason: message })
      .eq('id', app.id);
    if (error) throw error;

    sendEmail('/api/logistics/notify-application-rejected', {
      email: app.courier.email,
      name: `${app.courier.first_name} ${app.courier.last_name}`,
      company_name: companyName.value,
      reason: message,
    });

    showToast('Application rejected.', 'success');
    await load();
  } catch (e) {
    showToast('Failed to reject: ' + e.message, 'error');
  }
}

async function acceptApplication(app) {
  const ok = await askConfirm('Accept application', `Accept ${app.courier.first_name} ${app.courier.last_name} into ${companyName.value}?`, 'Accept');
  if (!ok) return;

  try {
    const { error } = await supabase
      .from('courier_applications')
      .update({ status: 'accepted' })
      .eq('id', app.id);
    if (error) throw error;

    sendEmail('/api/logistics/notify-application-accepted', {
      email: app.courier.email,
      name: `${app.courier.first_name} ${app.courier.last_name}`,
      company_name: companyName.value,
    });

    showToast(`${app.courier.first_name} accepted!`, 'success');
    await load();
  } catch (e) {
    showToast('Failed to accept: ' + e.message, 'error');
  }
}

// VIEW REASON
const showReasonModal = ref(false);
const reasonApp = ref(null);
function showRejectionReason(app) {
  reasonApp.value = app;
  showReasonModal.value = true;
}

// DOCUMENTS
const showDocsModal = ref(false);
const docsApp = ref(null);
const userDocuments = ref([]);
const docsLoading = ref(false);

async function openDocuments(app) {
  docsApp.value = app;
  showDocsModal.value = true;
  docsLoading.value = true;
  try {
    const { data, error } = await supabase
      .from('documents')
      .select('*')
      .eq('owner_kind', 'profile')
      .eq('profile_id', app.courier.id)
      .order('created_at', { ascending: false });
    if (error) throw error;
    userDocuments.value = data || [];
  } catch (e) {
    showToast('Failed to load documents: ' + e.message, 'error');
  } finally {
    docsLoading.value = false;
  }
}
function closeDocuments() {
  showDocsModal.value = false;
  docsApp.value = null;
  userDocuments.value = [];
}

const previewDoc = ref(null);
const previewUrl = ref('');
const previewLoading = ref(false);

async function viewDocument(doc) {
  previewDoc.value = doc;
  previewUrl.value = '';
  previewLoading.value = true;
  try {
    const { data, error } = await supabase.storage.from('documents').createSignedUrl(doc.storage_path, 300);
    if (error) throw error;
    previewUrl.value = data.signedUrl;
  } catch (e) {
    showToast('Failed to open document: ' + e.message, 'error');
    previewDoc.value = null;
  } finally {
    previewLoading.value = false;
  }
}
function closePreview() {
  previewDoc.value = null;
  previewUrl.value = '';
}

// HELPERS
function initials(app) {
  const n = `${app.courier?.first_name || ''} ${app.courier?.last_name || ''}`.trim();
  return n.split(' ').filter(Boolean).slice(0, 2).map(p => p[0]).join('').toUpperCase() || '?';
}
function formatDate(dateStr) {
  if (!dateStr) return '';
  return new Date(dateStr).toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' });
}
function formatRole(value) {
  if (!value) return '';
  return value.split('_').map(w => w.charAt(0).toUpperCase() + w.slice(1)).join(' ');
}
function badgeClass(status) {
  const s = status?.toLowerCase() || '';
  if (s === 'accepted' || s === 'approved') return 'badge-teal';
  if (s === 'pending') return 'badge-amber';
  if (s === 'rejected' || s === 'withdrawn') return 'badge-red';
  return 'badge-slate';
}

async function sendEmail(endpoint, data) {
  try {
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    await fetch(endpoint, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken },
      body: JSON.stringify(data),
    });
  } catch (e) {
    console.error('Failed to send email:', e);
  }
}

onMounted(load);
</script>

<style scoped>
@import '../../../css/logistics/logistics.css';
</style>