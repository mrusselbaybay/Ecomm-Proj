<!-- resources/js/admin/components/Users.vue -->
<template>
  <div class="admin-users-wrap">

    <!-- Toasts -->
    <div class="toast-stack">
      <transition-group name="toast">
        <div v-for="t in toasts" :key="t.id" class="toast" :class="t.type">
          <svg v-if="t.type === 'success'" class="icon" viewBox="0 0 20 20" fill="none"><path d="M5 10.5l3 3 7-7" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
          <svg v-else class="icon" viewBox="0 0 20 20" fill="none"><path d="M10 6.5v4M10 13.5h.01" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><circle cx="10" cy="10" r="7.25" stroke="currentColor" stroke-width="1.5"/></svg>
          <span>{{ t.message }}</span>
        </div>
      </transition-group>
    </div>

    <!-- Header -->
    <div class="page-header">
      <div>
        <h2 class="page-title">User Management</h2>
        <p class="page-subtitle">Review applications, manage account access, and create staff accounts.</p>
      </div>
      <button @click="openCreateStaffModal" class="btn-primary">
        <svg class="icon" viewBox="0 0 20 20" fill="none"><path d="M10 4v12M4 10h12" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
        Create Admin / Logistics Admin
      </button>
    </div>

    <!-- Summary Cards -->
    <div class="grid grid-cols-5 gap-4 mb-6">
      <div class="stat-card">
        <p class="field-label">Total Users</p>
        <p class="text-2xl font-bold stat-total">{{ totalUsers }}</p>
      </div>
      <div class="stat-card">
        <p class="field-label">Pending</p>
        <p class="text-2xl font-bold stat-pending">{{ pendingCount }}</p>
      </div>
      <div class="stat-card">
        <p class="field-label">Active</p>
        <p class="text-2xl font-bold stat-active">{{ activeCount }}</p>
      </div>
      <div class="stat-card">
        <p class="field-label">Suspended</p>
        <p class="text-2xl font-bold stat-suspended">{{ suspendedCount }}</p>
      </div>
      <div class="stat-card">
        <p class="field-label">Deactivated</p>
        <p class="text-2xl font-bold stat-deactivated">{{ deactivatedCount }}</p>
      </div>
    </div>

    <!-- Filters -->
    <div class="toolbar">
      <div class="search-input">
        <svg class="icon" viewBox="0 0 20 20" fill="none"><circle cx="9" cy="9" r="6" stroke="currentColor" stroke-width="1.6"/><path d="M14 14l4 4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
        <input type="text" v-model="search" placeholder="Search by name or email..." @input="debouncedLoad" />
      </div>
      <select v-model="roleFilter" class="field-input w-40" @change="loadData">
        <option value="">All Roles</option>
        <option value="buyer">Buyer</option>
        <option value="seller">Seller</option>
        <option value="courier">Courier</option>
        <option value="driver">Driver</option>
        <option value="logistics">Logistics Owner</option>
        <option value="logistics_admin">Logistics Admin</option>
        <option value="admin">Admin</option>
      </select>
      <select v-model="statusFilter" class="field-input w-40" @change="loadData">
        <option value="">All Approval</option>
        <option value="pending">Pending</option>
        <option value="approved">Approved</option>
        <option value="rejected">Rejected</option>
      </select>
      <select v-model="accountFilter" class="field-input w-40" @change="loadData">
        <option value="">All Account Status</option>
        <option value="active">Active</option>
        <option value="suspended">Suspended</option>
        <option value="deactivated">Deactivated</option>
      </select>
    </div>

    <!-- Users Table -->
    <div class="card overflow-hidden">
      <table class="admin-table">
        <thead>
          <tr>
            <th>User</th>
            <th>Role</th>
            <th>Approval</th>
            <th>Account</th>
            <th>Documents</th>
            <th class="text-right">Actions</th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="loading">
            <td colspan="6" class="text-center py-8">
              <div class="loading-spinner"></div>
            </td>
          </tr>
          <tr v-else-if="accounts.length === 0">
            <td colspan="6" class="empty-state">
              <svg class="icon-lg" viewBox="0 0 24 24" fill="none"><path d="M4 20c0-3.3 3.6-6 8-6s8 2.7 8 6" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/><circle cx="12" cy="8" r="4" stroke="currentColor" stroke-width="1.5"/></svg>
              <p>No users match these filters.</p>
            </td>
          </tr>
          <tr v-for="user in accounts" :key="user.id">
            <td>
              <div class="flex items-center gap-3">
                <div class="avatar">{{ initials(user) }}</div>
                <div>
                  <p class="font-medium text-slate-800">{{ displayName(user) }}</p>
                  <p class="text-xs text-slate-500">{{ user.email }}</p>
                </div>
              </div>
            </td>
            <td>
              <span class="role-badge" :class="roleBadgeClass(user.role)">{{ formatRole(user.role) }}</span>
            </td>
            <td>
              <span class="badge" :class="approvalBadgeClass(user.status)">
                <span class="status-dot" :class="user.status"></span>{{ user.status }}
              </span>
            </td>
            <td>
              <span class="badge" :class="accountBadgeClass(user.account_status || 'pending')">
                <span class="status-dot" :class="user.account_status || 'pending'"></span>{{ user.account_status || 'pending' }}
              </span>
            </td>
            <td>
              <button @click="openDocuments(user)" class="btn-doc">
                <svg class="icon" viewBox="0 0 20 20" fill="none"><path d="M6 2.5h6l3 3v11a1 1 0 01-1 1H6a1 1 0 01-1-1v-13a1 1 0 011-1z" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/><path d="M8 10h5M8 13h5M12 2.5V6h3.5" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
                View
              </button>
            </td>
            <td>
              <div class="flex gap-2 flex-wrap justify-end items-center">
                <!-- PENDING USERS -->
                <template v-if="user.status === 'pending'">
                  <button @click="approveUser(user)" class="btn-sm-primary">Approve</button>
                  <button @click="openRejectModal(user)" class="btn-danger-outline">Reject</button>
                </template>

                <!-- REJECTED USERS -->
                <template v-if="user.status === 'rejected'">
                  <div class="flex items-center gap-2">
                    <button @click="reapproveUser(user)" class="btn-sm-primary">Re-approve</button>
                    <button 
                      v-if="user.rejection_reason" 
                      @click="showRejectionReason(user)" 
                      class="btn-sm-outline btn-view-reason"
                      title="View rejection reason"
                    >
                      <svg class="icon-xs" viewBox="0 0 16 16" fill="none" style="margin-right: 2px;">
                        <path d="M8 3.5a4.5 4.5 0 00-4.5 4.5c0 2.5 4.5 6.5 4.5 6.5s4.5-4 4.5-6.5A4.5 4.5 0 008 3.5z" stroke="currentColor" stroke-width="1.4"/>
                        <circle cx="8" cy="8" r="1.8" stroke="currentColor" stroke-width="1.4"/>
                      </svg>
                      View Reason
                    </button>
                  </div>
                </template>

                <!-- APPROVED USERS -->
                <template v-if="user.status === 'approved'">
                  <button
                    @click="activateUser(user)"
                    class="btn-outline btn-active"
                    :disabled="user.account_status === 'active'">
                    Activate
                  </button>
                  <button
                    @click="openStatusChangeModal(user, 'suspended')"
                    class="btn-outline btn-suspend"
                    :disabled="user.account_status === 'suspended'">
                    Suspend
                  </button>
                  <button
                    @click="openStatusChangeModal(user, 'deactivated')"
                    class="btn-danger-outline"
                    :disabled="user.account_status === 'deactivated'">
                    Deactivate
                  </button>
                </template>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
      <div v-if="!loading && accounts.length" class="table-footer">Showing {{ accounts.length }} user{{ accounts.length === 1 ? '' : 's' }}</div>
    </div>

    <!-- ============================================================ -->
    <!-- REJECTION MODAL -->
    <!-- ============================================================ -->
    <transition name="modal">
      <div v-if="showRejectModal" class="modal-overlay" @click.self="closeRejectModal">
        <div class="modal-panel modal-lg">
          <div class="modal-header">
            <h3>Reject Application</h3>
            <button class="modal-close" @click="closeRejectModal">
              <svg class="icon" viewBox="0 0 20 20" fill="none"><path d="M5 5l10 10M15 5L5 15" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
            </button>
          </div>
          <p class="modal-desc">
            Select a reason for rejecting <strong>{{ displayName(rejectUserData) }}</strong>'s application:
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
                  placeholder="Please specify the reason for rejection..."
                  class="field-input mt-2"
                  rows="3"
                ></textarea>
              </div>
            </div>
          </div>

          <div class="modal-actions">
            <button @click="closeRejectModal" class="btn-outline flex-1 py-2">Cancel</button>
            <button
              @click="submitRejection"
              class="btn-danger flex-1 py-2"
              :disabled="!selectedReason || (selectedReason === 'others' && !customReason.trim())"
            >
              Reject Application
            </button>
          </div>
        </div>
      </div>
    </transition>

    <!-- ============================================================ -->
    <!-- VIEW REJECTION REASON MODAL -->
    <!-- ============================================================ -->
    <transition name="modal">
      <div v-if="showReasonModal" class="modal-overlay" @click.self="showReasonModal = false">
        <div class="modal-panel modal-sm">
          <div class="modal-header">
            <h3>Rejection Reason</h3>
            <button class="modal-close" @click="showReasonModal = false">
              <svg class="icon" viewBox="0 0 20 20" fill="none"><path d="M5 5l10 10M15 5L5 15" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
            </button>
          </div>
          <p class="modal-desc">Reason for rejecting <strong>{{ displayName(reasonUser) }}</strong>:</p>
          <div class="callout-red">{{ reasonUser?.rejection_reason }}</div>
          <div class="modal-actions">
            <button @click="showReasonModal = false" class="btn-outline flex-1 py-2">Close</button>
          </div>
        </div>
      </div>
    </transition>

    <!-- ============================================================ -->
    <!-- ACCOUNT STATUS CHANGE MODAL (Suspension/Deactivation) -->
    <!-- ============================================================ -->
    <transition name="modal">
      <div v-if="showStatusChangeModal" class="modal-overlay" @click.self="closeStatusChangeModal">
        <div class="modal-panel modal-lg">
          <div class="modal-header">
            <h3>{{ statusChangeAction === 'suspended' ? 'Suspend Account' : 'Deactivate Account' }}</h3>
            <button class="modal-close" @click="closeStatusChangeModal">
              <svg class="icon" viewBox="0 0 20 20" fill="none"><path d="M5 5l10 10M15 5L5 15" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
            </button>
          </div>
          <p class="modal-desc">
            Please select a reason for {{ statusChangeAction === 'suspended' ? 'suspending' : 'deactivating' }} 
            <strong>{{ displayName(statusChangeUser) }}</strong>'s account:
          </p>

          <div class="space-y-3">
            <!-- Show reasons based on action -->
            <div
              v-for="reason in (statusChangeAction === 'suspended' ? suspensionReasons : deactivationReasons)"
              :key="reason.value"
              class="reason-option"
              :class="{ active: selectedStatusReason === reason.value }"
              @click="selectedStatusReason = reason.value"
            >
              <input type="radio" :value="reason.value" v-model="selectedStatusReason" />
              <div>
                <p class="reason-label">{{ reason.label }}</p>
                <p class="reason-desc">{{ reason.description }}</p>
              </div>
            </div>

            <!-- Others option with text box -->
            <div class="reason-option" :class="{ active: selectedStatusReason === 'others' }" @click="selectedStatusReason = 'others'">
              <input type="radio" value="others" v-model="selectedStatusReason" />
              <div class="flex-1">
                <p class="reason-label">Others</p>
                <textarea
                  v-if="selectedStatusReason === 'others'"
                  v-model="customStatusReason"
                  @click.stop
                  placeholder="Please specify the reason..."
                  class="field-input mt-2"
                  rows="3"
                ></textarea>
              </div>
            </div>
          </div>

          <div class="modal-actions">
            <button @click="closeStatusChangeModal" class="btn-outline flex-1 py-2">Cancel</button>
            <button
              @click="submitStatusChange"
              class="btn-danger flex-1 py-2"
              :disabled="!selectedStatusReason || (selectedStatusReason === 'others' && !customStatusReason.trim())"
            >
              {{ statusChangeAction === 'suspended' ? 'Suspend Account' : 'Deactivate Account' }}
            </button>
          </div>
        </div>
      </div>
    </transition>

    <!-- ============================================================ -->
    <!-- CREATE STAFF MODAL -->
    <!-- ============================================================ -->
    <transition name="modal">
      <div v-if="showCreateStaffModal" class="modal-overlay" @click.self="closeCreateStaffModal">
        <div class="modal-panel modal-lg">
          <div class="modal-header">
            <div class="flex items-center gap-3">
              <div class="modal-header-icon">
                <svg class="icon" viewBox="0 0 20 20" fill="none"><path d="M10 4v12M4 10h12" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
              </div>
              <div>
                <h3>Create Staff Account</h3>
                <p class="modal-subtitle">Add a new platform admin or logistics admin</p>
              </div>
            </div>
            <button class="modal-close" @click="closeCreateStaffModal">
              <svg class="icon" viewBox="0 0 20 20" fill="none"><path d="M5 5l10 10M15 5L5 15" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
            </button>
          </div>

          <p class="section-label">Choose a role</p>
          <div class="role-picker">
            <button
              type="button"
              class="role-card"
              :class="{ active: newStaff.role === 'admin' }"
              @click="newStaff.role = 'admin'"
            >
              <span class="role-card-check" v-if="newStaff.role === 'admin'">
                <svg class="icon-xs" viewBox="0 0 20 20" fill="none"><path d="M5 10.5l3 3 7-7" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>
              </span>
              <div class="role-card-icon">
                <svg class="icon" viewBox="0 0 20 20" fill="none"><path d="M10 2l6 3v4.5c0 4-2.6 6.8-6 8-3.4-1.2-6-4-6-8V5l6-3z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/></svg>
              </div>
              <p class="role-card-title">Platform Admin</p>
              <p class="role-card-desc">Full access to manage users, approvals, and platform settings.</p>
            </button>

            <button
              type="button"
              class="role-card"
              :class="{ active: newStaff.role === 'logistics_admin' }"
              @click="newStaff.role = 'logistics_admin'"
            >
              <span class="role-card-check" v-if="newStaff.role === 'logistics_admin'">
                <svg class="icon-xs" viewBox="0 0 20 20" fill="none"><path d="M5 10.5l3 3 7-7" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>
              </span>
              <div class="role-card-icon">
                <svg class="icon" viewBox="0 0 20 20" fill="none"><path d="M2.5 6.5h8v6h-8z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/><path d="M10.5 9h2.7l2.3 2.6v1.9h-5z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/><circle cx="5.5" cy="14.5" r="1.4" stroke="currentColor" stroke-width="1.4"/><circle cx="13" cy="14.5" r="1.4" stroke="currentColor" stroke-width="1.4"/></svg>
              </div>
              <p class="role-card-title">Logistics Admin</p>
              <p class="role-card-desc">Manages drivers and orders. Assign a company after creation.</p>
            </button>
          </div>

          <p class="section-label mt-5">Account details</p>
          <div class="form-grid">
            <div>
              <label class="field-label">First Name</label>
              <input v-model="newStaff.first_name" type="text" class="field-input" placeholder="Juan" />
            </div>
            <div>
              <label class="field-label">Last Name</label>
              <input v-model="newStaff.last_name" type="text" class="field-input" placeholder="Dela Cruz" />
            </div>
            <div>
              <label class="field-label">Middle Initial</label>
              <input v-model="newStaff.middle_initial" type="text" maxlength="1" class="field-input" placeholder="B" @input="capitalizeMiddleInitial" />
            </div>
            <div class="full-span">
              <label class="field-label">Email</label>
              <input v-model="newStaff.email" type="email" class="field-input" placeholder="name@nexmart.com" />
            </div>
            <div class="full-span">
              <label class="field-label">Temporary Password</label>
              <div class="password-row">
                <input v-model="newStaff.password" :type="showPassword ? 'text' : 'password'" class="field-input" placeholder="Minimum 8 characters" />
                <button type="button" class="pw-btn" @click="showPassword = !showPassword" title="Toggle visibility">
                  <svg class="icon" viewBox="0 0 20 20" fill="none"><path d="M2 10s3-6 8-6 8 6 8 6-3 6-8 6-8-6-8-6z" stroke="currentColor" stroke-width="1.4"/><circle cx="10" cy="10" r="2.3" stroke="currentColor" stroke-width="1.4"/></svg>
                </button>
                <button type="button" class="pw-btn" @click="generatePassword" title="Generate password">
                  <svg class="icon" viewBox="0 0 20 20" fill="none"><path d="M4 10a6 6 0 0110.9-3.4M16 10a6 6 0 01-10.9 3.4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/><path d="M14.5 3.5v3.5H11M5.5 16.5V13H9" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </button>
              </div>
            </div>
          </div>

          <div class="info-banner">
            <svg class="icon" viewBox="0 0 20 20" fill="none"><path d="M10 6.5v4M10 13.5h.01" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><circle cx="10" cy="10" r="7.25" stroke="currentColor" stroke-width="1.6"/></svg>
            <p>This account is created and email-verified immediately — no manual approval needed. Login credentials are emailed to them automatically.</p>
          </div>

          <div class="modal-actions">
            <button @click="closeCreateStaffModal" class="btn-outline flex-1 py-2">Cancel</button>
            <button @click="submitCreateStaff" class="btn-primary flex-1 py-2 justify-center" :disabled="creatingStaff">
              {{ creatingStaff ? 'Creating...' : 'Create Account' }}
            </button>
          </div>
        </div>
      </div>
    </transition>

    <!-- ============================================================ -->
    <!-- DOCUMENTS MODAL (VIEW ONLY) -->
    <!-- ============================================================ -->
    <transition name="modal">
      <div v-if="showDocsModal" class="modal-overlay" @click.self="closeDocuments">
        <div class="modal-panel">
          <div class="modal-header">
            <h3>Documents — {{ displayName(docsUser) }}</h3>
            <button class="modal-close" @click="closeDocuments">
              <svg class="icon" viewBox="0 0 20 20" fill="none"><path d="M5 5l10 10M15 5L5 15" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
            </button>
          </div>

          <div v-if="docsLoading" class="py-8 text-center"><div class="loading-spinner"></div></div>

          <div v-else-if="userDocuments.length === 0" class="empty-state">
            <svg class="icon-lg" viewBox="0 0 24 24" fill="none"><path d="M6 2.5h6l3 3v11a1 1 0 01-1 1H6a1 1 0 01-1-1v-13a1 1 0 011-1z" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/></svg>
            <p>No documents uploaded yet.</p>
          </div>

          <div v-else class="doc-list">
            <div v-for="doc in userDocuments" :key="doc.id" class="doc-row">
              <div class="doc-info">
                <svg class="icon" viewBox="0 0 20 20" fill="none"><path d="M6 2.5h6l3 3v11a1 1 0 01-1 1H6a1 1 0 01-1-1v-13a1 1 0 011-1z" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/></svg>
                <div>
                  <p class="doc-type">{{ formatRole(doc.doc_type) }}</p>
                  <p class="doc-date">Uploaded {{ formatDate(doc.created_at) }}</p>
                </div>
              </div>
              <div class="doc-actions">
                <span class="badge" :class="approvalBadgeClass(doc.status)">{{ doc.status }}</span>
                <button class="btn-sm-outline" @click="viewDocument(doc)">
                  <svg class="icon-xs" viewBox="0 0 20 20" fill="none"><path d="M2 10s3-6 8-6 8 6 8 6-3 6-8 6-8-6-8-6z" stroke="currentColor" stroke-width="1.4"/><circle cx="10" cy="10" r="2.3" stroke="currentColor" stroke-width="1.4"/></svg>
                  View
                </button>
              </div>
            </div>
          </div>

          <div class="modal-actions">
            <button @click="closeDocuments" class="btn-outline flex-1 py-2">Close</button>
          </div>
        </div>
      </div>
    </transition>

    <!-- DOCUMENT PREVIEW LIGHTBOX -->
    <transition name="modal">
      <div v-if="previewDoc" class="modal-overlay preview-overlay" @click.self="closePreview">
        <div class="preview-panel">
          <div class="modal-header">
            <h3>{{ formatRole(previewDoc.doc_type) }}</h3>
            <div class="flex items-center gap-2">
              <a v-if="previewUrl" :href="previewUrl" target="_blank" rel="noopener" class="btn-sm-outline">
                Open in new tab
              </a>
              <button class="modal-close" @click="closePreview">
                <svg class="icon" viewBox="0 0 20 20" fill="none"><path d="M5 5l10 10M15 5L5 15" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
              </button>
            </div>
          </div>
          <div class="preview-body">
            <div v-if="previewLoading" class="loading-spinner"></div>
            <template v-else-if="previewUrl">
              <img v-if="previewKind() === 'image'" :src="previewUrl" class="preview-image" alt="Document preview" />
              <iframe v-else-if="previewKind() === 'pdf'" :src="previewUrl" class="preview-frame"></iframe>
              <div v-else class="empty-state">
                <p>Preview not available for this file type.</p>
                <a :href="previewUrl" target="_blank" rel="noopener" class="btn-primary mt-3">Open File</a>
              </div>
            </template>
          </div>
        </div>
      </div>
    </transition>

    <!-- ============================================================ -->
    <!-- GENERIC CONFIRM MODAL -->
    <!-- ============================================================ -->
    <transition name="modal">
      <div v-if="confirmModal.show" class="modal-overlay confirm-overlay" @click.self="resolveConfirm(false)">
        <div class="modal-panel modal-sm">
          <div class="confirm-icon" :class="confirmModal.variant">
            <svg v-if="confirmModal.variant === 'danger'" class="icon-lg" viewBox="0 0 24 24" fill="none"><path d="M12 8v5M12 16.5h.01" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><circle cx="12" cy="12" r="9.25" stroke="currentColor" stroke-width="1.6"/></svg>
            <svg v-else class="icon-lg" viewBox="0 0 24 24" fill="none"><path d="M7 12.5l3.2 3.2L17 9" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><circle cx="12" cy="12" r="9.25" stroke="currentColor" stroke-width="1.6"/></svg>
          </div>
          <h3 class="confirm-title">{{ confirmModal.title }}</h3>
          <p class="modal-desc text-center">{{ confirmModal.message }}</p>
          <div class="modal-actions">
            <button @click="resolveConfirm(false)" class="btn-outline flex-1 py-2">Cancel</button>
            <button
              @click="resolveConfirm(true)"
              class="flex-1 py-2"
              :class="confirmModal.variant === 'danger' ? 'btn-danger' : 'btn-primary'"
            >
              {{ confirmModal.confirmLabel }}
            </button>
          </div>
        </div>
      </div>
    </transition>

  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue';
import { useAdmin } from '../composables/useAdmin';

const {
  accounts,
  pendingCount,
  supabase
} = useAdmin();

const search = ref('');
const roleFilter = ref('');
const statusFilter = ref('');
const accountFilter = ref('');
const loading = ref(false);
const currentAdminId = ref(null);

let searchDebounce = null;
function debouncedLoad() {
  clearTimeout(searchDebounce);
  searchDebounce = setTimeout(loadData, 350);
}

// ============================================================
// TOASTS
// ============================================================
const toasts = ref([]);
function showToast(message, type = 'success') {
  const id = Date.now() + Math.random();
  toasts.value.push({ id, message, type });
  setTimeout(() => {
    toasts.value = toasts.value.filter(t => t.id !== id);
  }, 4000);
}

// ============================================================
// GENERIC CONFIRM MODAL
// ============================================================
const confirmModal = reactive({
  show: false,
  title: '',
  message: '',
  confirmLabel: 'Confirm',
  variant: 'primary',
  resolve: null
});

function askConfirm(title, message, opts = {}) {
  return new Promise(resolve => {
    confirmModal.show = true;
    confirmModal.title = title;
    confirmModal.message = message;
    confirmModal.confirmLabel = opts.confirmLabel || 'Confirm';
    confirmModal.variant = opts.variant || 'primary';
    confirmModal.resolve = resolve;
  });
}

function resolveConfirm(result) {
  confirmModal.show = false;
  if (confirmModal.resolve) confirmModal.resolve(result);
  confirmModal.resolve = null;
}

// ============================================================
// REJECTION MODAL
// ============================================================
const showRejectModal = ref(false);
const rejectUserData = ref(null);
const selectedReason = ref('');
const customReason = ref('');

const rejectionReasons = [
  { value: 'invalid_information', label: 'Invalid or incomplete information', description: 'The submitted details are missing, incorrect, or don\'t match the requirements.' },
  { value: 'invalid_id', label: 'Invalid identification', description: 'The uploaded ID or supporting document is unclear, expired, or cannot be verified.' },
  { value: 'not_eligible', label: 'Does not meet eligibility requirements', description: 'The user does not qualify for the service or organization.' },
  { value: 'fraudulent', label: 'Suspicious or fraudulent information', description: 'The submitted information appears fake, inconsistent, or potentially fraudulent.' }
];

// View reason modal
const showReasonModal = ref(false);
const reasonUser = ref(null);

// ============================================================
// ACCOUNT STATUS CHANGE MODAL (Suspension/Deactivation)
// ============================================================
const showStatusChangeModal = ref(false);
const statusChangeUser = ref(null);
const statusChangeAction = ref(''); // 'suspended' or 'deactivated'
const selectedStatusReason = ref('');
const customStatusReason = ref('');

// Suspension reasons
const suspensionReasons = [
  { 
    value: 'chargebacks_fraud', 
    label: 'Chargebacks or payment fraud', 
    description: 'The user has been involved in chargebacks or fraudulent payment activities.' 
  },
  { 
    value: 'prohibited_products', 
    label: 'Selling prohibited or counterfeit products', 
    description: 'The user is selling items that violate platform policies or are counterfeit.' 
  },
  { 
    value: 'failed_payments', 
    label: 'Too many failed or suspicious payment attempts', 
    description: 'Multiple failed or suspicious payment attempts have been detected.' 
  },
  { 
    value: 'abusive_behavior', 
    label: 'Abusive, threatening, or inappropriate behavior', 
    description: 'The user has engaged in abusive, threatening, or inappropriate behavior.' 
  }
];

// Deactivation reasons
const deactivationReasons = [
  { 
    value: 'repeated_violations', 
    label: 'Repeated suspension or serious violations', 
    description: 'The user has been suspended multiple times or committed serious violations.' 
  },
  { 
    value: 'security_concerns', 
    label: 'Account security concerns', 
    description: 'The account poses security risks to the platform or other users.' 
  }
];

// ============================================================
// CREATE STAFF MODAL
// ============================================================
const showCreateStaffModal = ref(false);
const creatingStaff = ref(false);
const showPassword = ref(false);
const newStaff = reactive({
  role: 'admin',
  first_name: '',
  last_name: '',
  middle_initial: '',
  email: '',
  password: ''
});

function capitalizeMiddleInitial() {
  if (newStaff.middle_initial) {
    newStaff.middle_initial = newStaff.middle_initial.toUpperCase();
  }
}

function resetNewStaff() {
  newStaff.role = 'admin';
  newStaff.first_name = '';
  newStaff.last_name = '';
  newStaff.middle_initial = '';
  newStaff.email = '';
  newStaff.password = '';
  showPassword.value = false;
}

function generatePassword() {
  const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#$%';
  let pwd = '';
  for (let i = 0; i < 12; i++) pwd += chars[Math.floor(Math.random() * chars.length)];
  newStaff.password = pwd;
  showPassword.value = true;
}

function openCreateStaffModal() {
  resetNewStaff();
  showCreateStaffModal.value = true;
}

function closeCreateStaffModal() {
  showCreateStaffModal.value = false;
}

function isValidEmail(email) {
  return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
}

async function submitCreateStaff() {
  if (!newStaff.first_name.trim() || !newStaff.last_name.trim()) {
    showToast('Please enter a first and last name.', 'error');
    return;
  }
  if (!isValidEmail(newStaff.email)) {
    showToast('Please enter a valid email address.', 'error');
    return;
  }
  if (!newStaff.password || newStaff.password.length < 8) {
    showToast('Password must be at least 8 characters. Use the generate button if needed.', 'error');
    return;
  }

  creatingStaff.value = true;
  try {
    const { data, error } = await supabase.auth.admin.createUser({
      email: newStaff.email,
      password: newStaff.password,
      email_confirm: true,
      user_metadata: {
        role: newStaff.role,
        first_name: newStaff.first_name,
        last_name: newStaff.last_name,
        middle_initial: newStaff.middle_initial || ''
      }
    });
    if (error) throw error;

    const newUserId = data.user.id;

    const { error: profileError } = await supabase
      .from('profiles')
      .update({ status: 'approved', account_status: 'active' })
      .eq('id', newUserId);
    if (profileError) throw profileError;

    await sendEmail('/api/admin/notify-account-created', {
      email: newStaff.email,
      name: `${newStaff.first_name} ${newStaff.last_name}`,
      password: newStaff.password,
      role: newStaff.role
    });

    showToast(`${formatRole(newStaff.role)} account created for ${newStaff.email}.`, 'success');
    closeCreateStaffModal();
    await loadData();
  } catch (error) {
    console.error('Error creating staff account:', error);
    showToast('Failed to create account: ' + error.message, 'error');
  } finally {
    creatingStaff.value = false;
  }
}

// ============================================================
// DOCUMENTS MODAL (VIEW ONLY)
// ============================================================
const showDocsModal = ref(false);
const docsUser = ref(null);
const userDocuments = ref([]);
const docsLoading = ref(false);

async function openDocuments(user) {
  docsUser.value = user;
  showDocsModal.value = true;
  docsLoading.value = true;
  try {
    const { data, error } = await supabase
      .from('documents')
      .select('*')
      .eq('owner_kind', 'profile')
      .eq('profile_id', user.id)
      .order('created_at', { ascending: false });
    if (error) throw error;
    userDocuments.value = data || [];
  } catch (error) {
    console.error('Error loading documents:', error);
    showToast('Failed to load documents: ' + error.message, 'error');
  } finally {
    docsLoading.value = false;
  }
}

function closeDocuments() {
  showDocsModal.value = false;
  docsUser.value = null;
  userDocuments.value = [];
}

// Document preview lightbox
const previewDoc = ref(null);
const previewUrl = ref('');
const previewLoading = ref(false);
const previewContentType = ref('');

async function viewDocument(doc) {
  previewDoc.value = doc;
  previewUrl.value = '';
  previewContentType.value = '';
  previewLoading.value = true;
  try {
    const { data, error } = await supabase.storage.from('documents').createSignedUrl(doc.storage_path, 300);
    if (error) throw error;
    previewUrl.value = data.signedUrl;

    try {
      const headRes = await fetch(data.signedUrl, { method: 'HEAD' });
      previewContentType.value = headRes.headers.get('content-type') || '';
    } catch (headErr) {
      console.warn('Could not detect content type:', headErr);
    }
  } catch (error) {
    console.error('Error creating signed URL:', error);
    showToast('Failed to open document: ' + error.message, 'error');
    previewDoc.value = null;
  } finally {
    previewLoading.value = false;
  }
}

function closePreview() {
  previewDoc.value = null;
  previewUrl.value = '';
  previewContentType.value = '';
}

function previewKind() {
  const mime = previewContentType.value || previewDoc.value?.mime_type || '';
  if (mime.startsWith('image/')) return 'image';
  if (mime === 'application/pdf') return 'pdf';

  const path = previewDoc.value?.storage_path || '';
  if (/\.(jpe?g|png|gif|webp|bmp|svg)$/i.test(path)) return 'image';
  if (/\.pdf$/i.test(path)) return 'pdf';

  return 'other';
}

// ============================================================
// DISPLAY HELPERS
// ============================================================
function displayName(user) {
  if (!user) return '';
  return user.full_name || `${user.first_name || ''} ${user.last_name || ''}`.trim() || user.email;
}

function initials(user) {
  const name = displayName(user);
  return name.split(' ').filter(Boolean).slice(0, 2).map(p => p[0]).join('').toUpperCase() || '?';
}

function formatRole(value) {
  if (!value) return '';
  return value.split('_').map(w => w.charAt(0).toUpperCase() + w.slice(1)).join(' ');
}

function formatDate(dateStr) {
  if (!dateStr) return '';
  return new Date(dateStr).toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' });
}

function roleBadgeClass(role) {
  return {
    'role-admin': role === 'admin',
    'role-logistics-admin': role === 'logistics_admin',
    'role-seller': role === 'seller',
    'role-courier': role === 'courier',
    'role-driver': role === 'driver',
    'role-logistics': role === 'logistics',
    'role-buyer': role === 'buyer'
  };
}

// Computed counts
const totalUsers = computed(() => accounts.value.length);
const activeCount = computed(() => accounts.value.filter(u => u.account_status === 'active').length);
const suspendedCount = computed(() => accounts.value.filter(u => u.account_status === 'suspended').length);
const deactivatedCount = computed(() => accounts.value.filter(u => u.account_status === 'deactivated').length);

// Badge classes
function approvalBadgeClass(status) {
  const s = status?.toLowerCase() || '';
  if (s === 'approved') return 'badge-teal';
  if (s === 'pending') return 'badge-amber';
  if (s === 'rejected') return 'badge-red';
  return 'badge-slate';
}

function accountBadgeClass(status) {
  const s = status?.toLowerCase() || '';
  if (s === 'active') return 'badge-teal';
  if (s === 'suspended') return 'badge-amber';
  if (s === 'deactivated') return 'badge-red';
  return 'badge-slate';
}

// ============================================================
// DATA LOADING
// ============================================================
async function loadData() {
  loading.value = true;
  try {
    let query = supabase
      .from('profiles')
      .select('*')
      .order('created_at', { ascending: false });

    if (search.value) {
      query = query.or(`first_name.ilike.%${search.value}%,last_name.ilike.%${search.value}%,email.ilike.%${search.value}%`);
    }
    if (roleFilter.value) query = query.eq('role', roleFilter.value);
    if (statusFilter.value) query = query.eq('status', statusFilter.value);
    if (accountFilter.value) query = query.eq('account_status', accountFilter.value);

    const { data, error } = await query;
    if (error) throw error;
    accounts.value = data || [];

    pendingCount.value = accounts.value.filter(u => u.status === 'pending').length;
  } catch (error) {
    console.error('Error loading accounts:', error);
    showToast('Failed to load users: ' + error.message, 'error');
  } finally {
    loading.value = false;
  }
}

// Helper function to send email via Laravel
async function sendEmail(endpoint, data) {
  try {
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    const response = await fetch(endpoint, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'X-CSRF-TOKEN': csrfToken
      },
      body: JSON.stringify(data)
    });

    const result = await response.json();
    if (!response.ok) {
      console.error('Email error:', result);
      return false;
    }
    return true;
  } catch (error) {
    console.error('Failed to send email:', error);
    return false;
  }
}

// ============================================================
// APPROVAL ACTIONS (WITH EMAIL)
// ============================================================
async function approveUser(user) {
  const ok = await askConfirm('Approve application', `Approve ${displayName(user)}? They will be able to log in immediately.`, { confirmLabel: 'Approve' });
  if (!ok) return;

  try {
    const { error: confirmError } = await supabase.auth.admin.updateUserById(user.id, { email_confirm: true });
    if (confirmError) console.warn('Could not auto-confirm email:', confirmError);

    const { error } = await supabase
      .from('profiles')
      .update({ status: 'approved', account_status: 'active' })
      .eq('id', user.id);
    if (error) throw error;

    const emailSent = await sendEmail('/api/admin/notify-approval', {
      email: user.email,
      name: displayName(user),
      user_id: user.id
    });

    showToast(
      `${displayName(user)} approved.${emailSent ? ' An email notification has been sent.' : ' (Email failed to send)'}`,
      emailSent ? 'success' : 'error'
    );
    await loadData();
  } catch (error) {
    console.error('Error approving user:', error);
    showToast('Failed to approve user: ' + error.message, 'error');
  }
}

async function reapproveUser(user) {
  const ok = await askConfirm('Re-approve application', `Re-approve ${displayName(user)}? This will allow them to log in again.`, { confirmLabel: 'Re-approve' });
  if (!ok) return;

  try {
    const { error } = await supabase
      .from('profiles')
      .update({ status: 'approved', account_status: 'active', rejection_reason: null })
      .eq('id', user.id);
    if (error) throw error;

    const emailSent = await sendEmail('/api/admin/notify-approval', {
      email: user.email,
      name: displayName(user),
      user_id: user.id
    });

    showToast(
      `${displayName(user)} re-approved.${emailSent ? ' An email notification has been sent.' : ' (Email failed to send)'}`,
      emailSent ? 'success' : 'error'
    );
    await loadData();
  } catch (error) {
    console.error('Error re-approving user:', error);
    showToast('Failed to re-approve user: ' + error.message, 'error');
  }
}

function openRejectModal(user) {
  rejectUserData.value = user;
  selectedReason.value = '';
  customReason.value = '';
  showRejectModal.value = true;
}

function closeRejectModal() {
  showRejectModal.value = false;
  rejectUserData.value = null;
  selectedReason.value = '';
  customReason.value = '';
}

async function submitRejection() {
  if (!selectedReason.value) {
    showToast('Please select a reason for rejection.', 'error');
    return;
  }

  let rejectionMessage = '';
  if (selectedReason.value === 'others') {
    rejectionMessage = customReason.value.trim();
    if (!rejectionMessage) {
      showToast('Please specify the reason for rejection.', 'error');
      return;
    }
  } else {
    const reason = rejectionReasons.find(r => r.value === selectedReason.value);
    rejectionMessage = reason ? `${reason.label} — ${reason.description}` : selectedReason.value;
  }

  try {
    const { error } = await supabase
      .from('profiles')
      .update({ status: 'rejected', account_status: 'deactivated', rejection_reason: rejectionMessage })
      .eq('id', rejectUserData.value.id);
    if (error) throw error;

    const emailSent = await sendEmail('/api/admin/notify-rejection', {
      email: rejectUserData.value.email,
      name: displayName(rejectUserData.value),
      reason: rejectionMessage
    });

    showToast(
      `${displayName(rejectUserData.value)} rejected.${emailSent ? ' An email notification has been sent.' : ' (Email failed to send)'}`,
      emailSent ? 'success' : 'error'
    );
    closeRejectModal();
    await loadData();
  } catch (error) {
    console.error('Error rejecting user:', error);
    showToast('Failed to reject user: ' + error.message, 'error');
  }
}

// ============================================================
// ACCOUNT STATUS ACTIONS
// ============================================================

// Activate user (simple confirmation, no reason needed)
async function activateUser(user) {
  const ok = await askConfirm(
    'Activate account',
    `Are you sure you want to activate ${displayName(user)}?`,
    { confirmLabel: 'Activate', variant: 'primary' }
  );
  if (!ok) return;

  try {
    const { error } = await supabase.from('profiles').update({ account_status: 'active' }).eq('id', user.id);
    if (error) throw error;

    const emailSent = await sendEmail('/api/admin/notify-status-change', {
      email: user.email,
      name: displayName(user),
      status: 'active'
    });

    showToast(
      `${displayName(user)} has been activated.${emailSent ? ' An email notification has been sent.' : ' (Email failed to send)'}`,
      emailSent ? 'success' : 'error'
    );
    await loadData();
  } catch (error) {
    console.error('Error activating user:', error);
    showToast('Failed to activate user: ' + error.message, 'error');
  }
}

// Open status change modal (for suspension/deactivation)
function openStatusChangeModal(user, action) {
  statusChangeUser.value = user;
  statusChangeAction.value = action;
  selectedStatusReason.value = '';
  customStatusReason.value = '';
  showStatusChangeModal.value = true;
}

function closeStatusChangeModal() {
  showStatusChangeModal.value = false;
  statusChangeUser.value = null;
  statusChangeAction.value = '';
  selectedStatusReason.value = '';
  customStatusReason.value = '';
}

async function submitStatusChange() {
  if (!selectedStatusReason.value) {
    showToast('Please select a reason.', 'error');
    return;
  }

  let reasonMessage = '';
  if (selectedStatusReason.value === 'others') {
    reasonMessage = customStatusReason.value.trim();
    if (!reasonMessage) {
      showToast('Please specify the reason.', 'error');
      return;
    }
  } else {
    // Find the reason from either suspension or deactivation lists
    const allReasons = [...suspensionReasons, ...deactivationReasons];
    const reason = allReasons.find(r => r.value === selectedStatusReason.value);
    reasonMessage = reason ? `${reason.label} — ${reason.description}` : selectedStatusReason.value;
  }

  const labels = { 
    suspended: 'suspend', 
    deactivated: 'deactivate' 
  };
  
  const status = statusChangeAction.value;

  try {
    const { error } = await supabase
      .from('profiles')
      .update({ 
        account_status: status
      })
      .eq('id', statusChangeUser.value.id);

    if (error) throw error;

    // Send status change email with reason
    const emailSent = await sendEmail('/api/admin/notify-status-change', {
      email: statusChangeUser.value.email,
      name: displayName(statusChangeUser.value),
      status: status,
      reason: reasonMessage
    });

    showToast(
      `${displayName(statusChangeUser.value)} has been ${labels[status]}d.${emailSent ? ' An email notification has been sent.' : ' (Email failed to send)'}`,
      emailSent ? 'success' : 'error'
    );
    
    closeStatusChangeModal();
    await loadData();
  } catch (error) {
    console.error(`Error ${status}ing user:`, error);
    showToast(`Failed to ${status} user: ` + error.message, 'error');
  }
}

// ============================================================
// VIEW REJECTION REASON
// ============================================================
function showRejectionReason(user) {
  reasonUser.value = user;
  showReasonModal.value = true;
}

// ============================================================
// LIFECYCLE
// ============================================================
onMounted(async () => {
  loadData();
  try {
    const { data } = await supabase.auth.getUser();
    currentAdminId.value = data?.user?.id || null;
  } catch (error) {
    console.warn('Could not resolve current admin id:', error);
  }
});
</script>

<style scoped>
@import '../../../css/admin/users.css';
</style>