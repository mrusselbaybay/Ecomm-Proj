<!-- resources/js/logistics/components/Applications.vue -->
<template>
  <div class="logistics-page">

    <div class="toast-stack" role="status" aria-live="polite">
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
      <div class="stat-card accent-total">
        <div class="stat-card-top">
          <p class="field-label">Total</p>
          <span class="stat-icon" aria-hidden="true">
            <svg class="icon-sm" viewBox="0 0 24 24" fill="none"><path d="M9 12h6M9 16h6M9 8h1M6 4h8l4 4v12a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" /></svg>
          </span>
        </div>
        <p class="text-2xl font-bold stat-total">{{ applications.length }}</p>
      </div>
      <div class="stat-card accent-pending">
        <div class="stat-card-top">
          <p class="field-label">Pending</p>
          <span class="stat-icon" aria-hidden="true">
            <svg class="icon-sm" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="8.5" stroke="currentColor" stroke-width="1.8" /><path d="M12 7.5V12l3 2" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" /></svg>
          </span>
        </div>
        <p class="text-2xl font-bold stat-pending">{{ pendingCount }}</p>
      </div>
      <div class="stat-card accent-active">
        <div class="stat-card-top">
          <p class="field-label">Accepted</p>
          <span class="stat-icon" aria-hidden="true">
            <svg class="icon-sm" viewBox="0 0 24 24" fill="none"><path d="m5 13 4 4L19 7" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" /></svg>
          </span>
        </div>
        <p class="text-2xl font-bold stat-active">{{ acceptedCount }}</p>
      </div>
      <div class="stat-card accent-deactivated">
        <div class="stat-card-top">
          <p class="field-label">Rejected</p>
          <span class="stat-icon" aria-hidden="true">
            <svg class="icon-sm" viewBox="0 0 24 24" fill="none"><path d="M6 6l12 12M18 6 6 18" stroke="currentColor" stroke-width="2" stroke-linecap="round" /></svg>
          </span>
        </div>
        <p class="text-2xl font-bold stat-deactivated">{{ rejectedCount }}</p>
      </div>
    </div>

    <div class="toolbar">
      <div class="search-input">
        <svg class="icon" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="11" cy="11" r="7" stroke="currentColor" stroke-width="1.8" /><path d="m20 20-3.5-3.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" /></svg>
        <label for="applications-search" class="sr-only">Search by courier name or email</label>
        <input id="applications-search" type="text" v-model="search" placeholder="Search by courier name or email..." @input="debouncedLoad" />
      </div>
      <label for="applications-status" class="sr-only">Filter by status</label>
      <select id="applications-status" v-model="statusFilter" class="field-input w-40" @change="load">
        <option value="">All Statuses</option>
        <option value="pending">Pending</option>
        <option value="accepted">Accepted</option>
        <option value="rejected">Rejected</option>
        <option value="withdrawn">Withdrawn</option>
      </select>
    </div>

    <div class="card overflow-hidden">
      <div class="table-scroll">
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
            <td colspan="5" class="text-center py-8"><div class="loading-spinner" role="status" aria-label="Loading applications"></div></td>
          </tr>
          <tr v-else-if="applications.length === 0">
            <td colspan="5">
              <div class="empty-state">
                <svg class="icon-lg empty-state-icon" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M9 12h6M9 16h6M9 8h1M6 4h8l4 4v12a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2Z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round" /></svg>
                <p>No applications match these filters.</p>
              </div>
            </td>
          </tr>
          <tr v-for="app in applications" :key="app.id">
            <td>
              <div class="flex items-center gap-3">
                <div class="avatar" aria-hidden="true">{{ initials(app) }}</div>
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
              <span class="badge" :class="isInterviewing(app) ? 'badge-indigo' : badgeClass(app.status)">
                <span class="status-dot" :class="app.status" aria-hidden="true"></span>{{ isInterviewing(app) ? 'Interviewing' : app.status }}
              </span>
              <p v-if="isInterviewing(app) && app.interview_scheduled_at" class="text-xs text-slate-500 mt-1">{{ formatInterviewTime(app.interview_scheduled_at) }}</p>
            </td>
            <td>
              <button @click="openDocuments(app)" class="btn-doc">View</button>
            </td>
            <td>
              <div class="action-buttons">
                <template v-if="app.status === 'pending' && !app.interview_invited_at">
                  <button @click="openInterviewModal(app)" class="btn-sm-primary">Proceed to Interview</button>
                  <button @click="openRejectModal(app)" class="btn-danger-outline">Reject</button>
                </template>
                <template v-else-if="app.status === 'pending' && app.interview_invited_at">
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
    </div>

    <!-- REJECT MODAL -->
    <transition name="modal">
      <div v-if="showRejectModal" class="modal-overlay" @click.self="closeRejectModal">
        <div class="modal-panel modal-lg" role="dialog" aria-modal="true" aria-labelledby="reject-modal-title">
          <div class="modal-header">
            <h3 id="reject-modal-title">Reject Application</h3>
            <button class="modal-close" aria-label="Close" @click="closeRejectModal">✕</button>
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

    <!-- INTERVIEW MODAL -->
    <transition name="modal">
      <div v-if="showInterviewModal" class="modal-overlay" @click.self="closeInterviewModal">
        <div class="modal-panel modal-sm" role="dialog" aria-modal="true" aria-labelledby="interview-modal-title">
          <div class="modal-header">
            <h3 id="interview-modal-title">Schedule Interview</h3>
            <button class="modal-close" aria-label="Close" @click="closeInterviewModal">✕</button>
          </div>
          <p class="modal-desc">
            Pick a date and time to interview <strong>{{ interviewApp?.courier?.first_name }} {{ interviewApp?.courier?.last_name }}</strong>. Their application stays pending — this just sends them an invite.
          </p>
          <div class="space-y-3">
            <div>
              <label class="field-label" for="interview-datetime">Interview date &amp; time</label>
              <input
                id="interview-datetime"
                type="datetime-local"
                v-model="interviewDateTime"
                :min="minInterviewDateTime"
                class="field-input mt-2"
              />
            </div>
            <div>
              <label class="field-label" for="interview-notes">Notes for the courier (optional)</label>
              <textarea
                id="interview-notes"
                v-model="interviewNotes"
                rows="3"
                class="field-input mt-2"
                placeholder="e.g. video call link, office address, what to bring..."
              ></textarea>
            </div>
          </div>
          <div class="modal-actions">
            <button @click="closeInterviewModal" class="btn-outline">Cancel</button>
            <button @click="submitInterview" class="btn-primary" :disabled="!interviewDateTime">Send Invite</button>
          </div>
        </div>
      </div>
    </transition>

    <!-- VIEW REASON MODAL -->
    <transition name="modal">
      <div v-if="showReasonModal" class="modal-overlay" @click.self="showReasonModal = false">
        <div class="modal-panel modal-sm" role="dialog" aria-modal="true" aria-labelledby="reason-modal-title">
          <div class="modal-header">
            <h3 id="reason-modal-title">Rejection Reason</h3>
            <button class="modal-close" aria-label="Close" @click="showReasonModal = false">✕</button>
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
        <div class="modal-panel" role="dialog" aria-modal="true" aria-labelledby="docs-modal-title">
          <div class="modal-header">
            <h3 id="docs-modal-title">Documents — {{ docsApp?.courier?.first_name }} {{ docsApp?.courier?.last_name }}</h3>
            <button class="modal-close" aria-label="Close" @click="closeDocuments">✕</button>
          </div>
          <div v-if="docsApp?.resume_original_name" class="doc-row" style="margin-bottom: 12px;">
            <div class="doc-info">
              <div>
                <p class="doc-type">Resume — {{ docsApp.resume_original_name }}</p>
                <p class="doc-date">{{ formatFileSize(docsApp.resume_size) }} · Applied {{ formatDate(docsApp.applied_at) }}</p>
              </div>
            </div>
            <div class="doc-actions">
              <button class="btn-sm-outline" :disabled="resumeLoading" @click="viewResume(docsApp)">{{ resumeLoading ? 'Opening…' : 'View Resume' }}</button>
            </div>
          </div>
          <div v-if="docsApp?.cover_note" class="callout-red" style="background:#f0fdfa;border-color:#99f6e4;color:#0f766e;margin-bottom:12px;">
            <strong>Cover note:</strong> {{ docsApp.cover_note }}
          </div>
          <div v-if="docsLoading" class="py-8 text-center"><div class="loading-spinner" role="status" aria-label="Loading documents"></div></div>
          <div v-else-if="userDocuments.length === 0" class="empty-state"><p>No other documents uploaded yet.</p></div>
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
        <div class="preview-panel" role="dialog" aria-modal="true" aria-labelledby="preview-modal-title">
          <div class="modal-header">
            <h3 id="preview-modal-title">{{ formatRole(previewDoc.doc_type) }}</h3>
            <button class="modal-close" aria-label="Close" @click="closePreview">✕</button>
          </div>
          <div class="preview-body">
            <div v-if="previewLoading" class="loading-spinner" role="status" aria-label="Loading preview"></div>
            <img v-else-if="previewUrl" :src="previewUrl" class="preview-image" alt="Document preview" />
          </div>
        </div>
      </div>
    </transition>

    <!-- CONFIRM MODAL -->
    <transition name="modal">
      <div v-if="confirmModal.show" class="modal-overlay confirm-overlay" @click.self="resolveConfirm(false)">
        <div class="modal-panel modal-sm" role="alertdialog" aria-modal="true" aria-labelledby="confirm-modal-title">
          <h3 id="confirm-modal-title" class="confirm-title">{{ confirmModal.title }}</h3>
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

function isInterviewing(app) {
  return app.status === 'pending' && !!app.interview_invited_at;
}

// Formats a stored interview_scheduled_at (or interview_invited_at) value
// as the exact wall-clock digits that were picked, with no timezone
// conversion — the value is a "floating" local time, not a real instant,
// since it's just what the logistics staff typed into the picker.
function formatInterviewTime(value) {
  if (!value) return '';
  const naive = value.replace(/(\.\d+)?(Z|[+-]\d{2}:?\d{2})$/, '');
  const date = new Date(naive);
  if (Number.isNaN(date.getTime())) return value;
  return date.toLocaleString('en-US', {
    weekday: 'short', year: 'numeric', month: 'short', day: 'numeric',
    hour: 'numeric', minute: '2-digit',
  });
}

// INTERVIEW MODAL
const showInterviewModal = ref(false);
const interviewApp = ref(null);
const interviewDateTime = ref('');
const interviewNotes = ref('');

function pad(n) { return String(n).padStart(2, '0'); }
function toLocalInputValue(date) {
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
}
const minInterviewDateTime = computed(() => toLocalInputValue(new Date()));

function openInterviewModal(app) {
  interviewApp.value = app;
  interviewDateTime.value = '';
  interviewNotes.value = '';
  showInterviewModal.value = true;
}
function closeInterviewModal() {
  showInterviewModal.value = false;
  interviewApp.value = null;
}

async function submitInterview() {
  if (!interviewDateTime.value) {
    showToast('Please pick a date and time.', 'error');
    return;
  }

  const app = interviewApp.value;
  const scheduledAt = interviewDateTime.value; // kept as the raw picked value, no timezone conversion
  const notes = interviewNotes.value.trim();
  closeInterviewModal();

  try {
    const { error } = await supabase
      .from('courier_applications')
      .update({
        interview_invited_at: new Date().toISOString(),
        interview_scheduled_at: scheduledAt,
      })
      .eq('id', app.id);
    if (error) throw error;

    sendEmail('/api/logistics/notify-application-interview', {
      email: app.courier.email,
      name: `${app.courier.first_name} ${app.courier.last_name}`,
      company_name: companyName.value,
      interview_at: scheduledAt,
      notes: notes || null,
    });

    showToast(`${app.courier.first_name} invited to interview.`, 'success');
    await load();
  } catch (e) {
    showToast('Failed to invite to interview: ' + e.message, 'error');
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

const resumeLoading = ref(false);

async function viewResume(app) {
  resumeLoading.value = true;
  try {
    const { data: { session } } = await supabase.auth.getSession();
    const response = await fetch(`/api/logistics/applications/${app.id}/resume`, {
      headers: {
        'Accept': 'application/json',
        ...(session?.access_token ? { 'Authorization': `Bearer ${session.access_token}` } : {}),
      },
    });
    const payload = await response.json();
    if (!response.ok) throw new Error(payload.message || 'Failed to load resume.');
    window.open(payload.url, '_blank', 'noopener');
  } catch (e) {
    showToast('Failed to open resume: ' + e.message, 'error');
  } finally {
    resumeLoading.value = false;
  }
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
function formatFileSize(bytes) {
  if (!bytes) return '';
  const kb = bytes / 1024;
  if (kb < 1024) return `${Math.round(kb)} KB`;
  return `${(kb / 1024).toFixed(1)} MB`;
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
