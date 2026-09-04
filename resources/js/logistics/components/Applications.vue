<!-- resources/js/logistics/components/Applications.vue -->
<template>
    <div class="logistics-page">
        <header class="page-header">
            <div>
                <h2 class="page-title">Rider applications</h2>
                <p class="page-subtitle">
                    Review couriers who applied to join
                    {{ companyName || 'your company' }}.
                </p>
            </div>
            <div class="page-header-actions">
                <button
                    type="button"
                    class="btn-outline resignations-btn"
                    @click="openResignations"
                >
                    Resignation requests
                    <span
                        v-if="pendingResignationCount > 0"
                        class="resignations-badge"
                        >{{ pendingResignationCount }}</span
                    >
                </button>
                <button
                    type="button"
                    class="btn-outline btn-icon"
                    :disabled="refreshing"
                    @click="load(true)"
                >
                    <NavIcon name="refresh" :size="15" />
                    Refresh
                </button>
            </div>
        </header>

        <!-- The status counters double as the status filter, so the
             numbers on screen are the thing you click. Status is filtered
             client-side off one unfiltered fetch, which keeps the counts
             stable (they used to zero out as soon as a filter narrowed
             the server response) and makes switching tabs free. -->
        <div class="queue-toolbar">
            <div
                class="filter-chips"
                role="tablist"
                aria-label="Filter applications by status"
            >
                <button
                    v-for="option in statusOptions"
                    :key="option.value"
                    type="button"
                    role="tab"
                    class="filter-chip"
                    :class="{ active: statusFilter === option.value }"
                    :aria-selected="statusFilter === option.value"
                    @click="statusFilter = option.value"
                >
                    {{ option.label }}
                    <span class="filter-chip-count">{{ option.count }}</span>
                </button>
            </div>
            <div class="search-input queue-search">
                <NavIcon name="search" :size="15" class="icon" />
                <label for="applications-search" class="sr-only"
                    >Search by courier name or email</label
                >
                <input
                    id="applications-search"
                    v-model="search"
                    type="search"
                    placeholder="Search by courier name or email…"
                    @input="debouncedLoad"
                />
            </div>
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
                            <th class="text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="loading">
                            <td colspan="5">
                                <div class="skeleton-list skeleton-table">
                                    <span
                                        v-for="n in 5"
                                        :key="n"
                                        class="skeleton skeleton-row"
                                    ></span>
                                </div>
                            </td>
                        </tr>
                        <tr v-else-if="visibleApplications.length === 0">
                            <td colspan="5">
                                <div class="empty-state">
                                    <NavIcon name="applications" :size="30" />
                                    <strong>{{ emptyTitle }}</strong>
                                    <p>{{ emptyHint }}</p>
                                    <button
                                        v-if="hasActiveFilters"
                                        type="button"
                                        class="btn-outline"
                                        @click="clearFilters"
                                    >
                                        Clear filters
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <tr v-for="app in visibleApplications" :key="app.id">
                            <td>
                                <div class="flex items-center gap-3">
                                    <div class="avatar" aria-hidden="true">
                                        {{ initials(app.courier) }}
                                    </div>
                                    <div>
                                        <p class="font-medium text-slate-800">
                                            {{ app.courier?.first_name }}
                                            {{ app.courier?.last_name }}
                                        </p>
                                        <p class="text-xs text-slate-500">
                                            {{ app.courier?.email }}
                                        </p>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <p class="text-sm">
                                    {{ app.courier_details?.vehicle || '—' }}
                                </p>
                                <p class="text-xs text-slate-500">
                                    {{
                                        app.courier_details?.plate_number || ''
                                    }}
                                </p>
                            </td>
                            <td>
                                <span
                                    class="badge"
                                    :class="
                                        isInterviewing(app)
                                            ? 'badge-indigo'
                                            : badgeClass(app.status)
                                    "
                                >
                                    <span
                                        class="status-dot"
                                        :class="app.status"
                                        aria-hidden="true"
                                    ></span
                                    >{{
                                        isInterviewing(app)
                                            ? 'Interviewing'
                                            : app.status
                                    }}
                                </span>
                                <p
                                    v-if="
                                        isInterviewing(app) &&
                                        app.interview_scheduled_at
                                    "
                                    class="mt-1 text-xs text-slate-500"
                                >
                                    {{
                                        formatInterviewTime(
                                            app.interview_scheduled_at,
                                        )
                                    }}
                                </p>
                            </td>
                            <td>
                                <button
                                    @click="openDocuments(app)"
                                    class="btn-doc"
                                >
                                    View
                                </button>
                            </td>
                            <td>
                                <div class="action-buttons">
                                    <template
                                        v-if="
                                            app.status === 'pending' &&
                                            !app.interview_invited_at
                                        "
                                    >
                                        <button
                                            @click="openInterviewModal(app)"
                                            class="btn-sm-primary"
                                        >
                                            Proceed to Interview
                                        </button>
                                        <button
                                            @click="openRejectModal(app)"
                                            class="btn-danger-outline"
                                        >
                                            Reject
                                        </button>
                                    </template>
                                    <template
                                        v-else-if="
                                            app.status === 'pending' &&
                                            app.interview_invited_at
                                        "
                                    >
                                        <button
                                            @click="acceptApplication(app)"
                                            class="btn-sm-primary"
                                        >
                                            Accept
                                        </button>
                                        <button
                                            @click="openRejectModal(app)"
                                            class="btn-danger-outline"
                                        >
                                            Reject
                                        </button>
                                    </template>
                                    <template
                                        v-else-if="app.status === 'rejected'"
                                    >
                                        <button
                                            v-if="app.rejection_reason"
                                            @click="showRejectionReason(app)"
                                            class="btn-sm-outline"
                                        >
                                            View Reason
                                        </button>
                                    </template>
                                    <template
                                        v-else-if="app.status === 'accepted'"
                                    >
                                        <button
                                            class="btn-sm-outline"
                                            @click="openDetailsModal(app)"
                                        >
                                            Details
                                        </button>
                                        <button
                                            class="btn-danger-outline btn-fire"
                                            @click="openFireModal(app)"
                                        >
                                            Fire
                                        </button>
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
            <div
                v-if="showRejectModal"
                class="modal-overlay"
                @click.self="closeRejectModal"
            >
                <div
                    class="modal-panel modal-lg"
                    role="dialog"
                    aria-modal="true"
                    aria-labelledby="reject-modal-title"
                >
                    <div class="modal-header">
                        <h3 id="reject-modal-title">Reject Application</h3>
                        <button
                            class="modal-close"
                            aria-label="Close"
                            @click="closeRejectModal"
                        >
                            ✕
                        </button>
                    </div>
                    <p class="modal-desc">
                        Select a reason for rejecting
                        <strong
                            >{{ rejectApp?.courier?.first_name }}
                            {{ rejectApp?.courier?.last_name }}</strong
                        >'s application:
                    </p>
                    <div class="space-y-3">
                        <div
                            v-for="reason in rejectionReasons"
                            :key="reason.value"
                            class="reason-option"
                            :class="{ active: selectedReason === reason.value }"
                            @click="selectedReason = reason.value"
                        >
                            <input
                                type="radio"
                                :value="reason.value"
                                v-model="selectedReason"
                            />
                            <div>
                                <p class="reason-label">{{ reason.label }}</p>
                                <p class="reason-desc">
                                    {{ reason.description }}
                                </p>
                            </div>
                        </div>
                        <div
                            class="reason-option"
                            :class="{ active: selectedReason === 'others' }"
                            @click="selectedReason = 'others'"
                        >
                            <input
                                type="radio"
                                value="others"
                                v-model="selectedReason"
                            />
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
                        <button @click="closeRejectModal" class="btn-outline">
                            Cancel
                        </button>
                        <button
                            @click="submitRejection"
                            class="btn-danger"
                            :disabled="
                                !selectedReason ||
                                (selectedReason === 'others' &&
                                    !customReason.trim())
                            "
                        >
                            Reject Application
                        </button>
                    </div>
                </div>
            </div>
        </transition>

        <!-- FIRE MODAL -->
        <transition name="modal">
            <div
                v-if="fireApp"
                class="modal-overlay"
                @click.self="closeFireModal"
            >
                <div
                    class="modal-panel modal-sm"
                    role="alertdialog"
                    aria-modal="true"
                    aria-labelledby="fire-modal-title"
                >
                    <div class="modal-header">
                        <h3 id="fire-modal-title">Fire courier</h3>
                        <button
                            class="modal-close"
                            aria-label="Close"
                            @click="closeFireModal"
                        >
                            ✕
                        </button>
                    </div>
                    <p class="modal-desc">
                        <strong>{{ personName(fireApp.courier) }}</strong> will
                        be removed from your delivery areas immediately and
                        freed to join another company. They'll be emailed about
                        this.
                    </p>
                    <label class="field-label" for="fire-reason"
                        >Reason
                        <span class="text-slate-500"
                            >(optional, shared with the courier)</span
                        ></label
                    >
                    <textarea
                        id="fire-reason"
                        v-model="fireReason"
                        class="field-input mt-1"
                        rows="3"
                        maxlength="2000"
                        placeholder="e.g. Repeated missed pickups"
                    ></textarea>
                    <div class="modal-actions">
                        <button class="btn-outline" @click="closeFireModal">
                            Cancel
                        </button>
                        <button
                            class="btn-danger"
                            :disabled="firing"
                            @click="submitFire"
                        >
                            {{ firing ? 'Firing…' : 'Fire courier' }}
                        </button>
                    </div>
                </div>
            </div>
        </transition>

        <!-- DETAILS MODAL -->
        <transition name="modal">
            <div
                v-if="detailsApp"
                class="modal-overlay rider-view-overlay"
                @click.self="closeDetailsModal"
            >
                <div class="modal-panel modal-sm rider-view-panel">
                    <div class="modal-header">
                        <p class="eyebrow">Courier profile</p>
                        <button
                            type="button"
                            class="modal-close"
                            aria-label="Close"
                            @click="closeDetailsModal"
                        >
                            &times;
                        </button>
                    </div>

                    <div class="rider-view-identity">
                        <div class="avatar avatar-lg">
                            {{ initials(detailsApp.courier) }}
                        </div>
                        <h3 class="rider-view-name">
                            {{ personName(detailsApp.courier) }}
                        </h3>
                    </div>

                    <div class="details-field-grid">
                        <div class="full-span">
                            <label class="field-label">Address</label>
                            <input
                                class="field-input"
                                disabled
                                :value="
                                    detailsApp.courier?.address ||
                                    'No address on file'
                                "
                            />
                        </div>
                        <div>
                            <label class="field-label">Birth date</label>
                            <input
                                class="field-input"
                                disabled
                                :value="
                                    detailsApp.courier?.birthday
                                        ? formatDate(detailsApp.courier.birthday)
                                        : 'Not provided'
                                "
                            />
                        </div>
                        <div>
                            <label class="field-label">Vehicle type</label>
                            <input
                                class="field-input"
                                disabled
                                :value="
                                    detailsApp.courier_details?.vehicle ||
                                    'Not provided'
                                "
                            />
                        </div>
                        <div class="full-span">
                            <label class="field-label">Plate number</label>
                            <input
                                class="field-input"
                                disabled
                                :value="
                                    detailsApp.courier_details?.plate_number ||
                                    'Not provided'
                                "
                            />
                        </div>
                    </div>

                    <div class="modal-actions">
                        <button
                            type="button"
                            class="btn-outline"
                            @click="closeDetailsModal"
                        >
                            Close
                        </button>
                    </div>
                </div>
            </div>
        </transition>

        <!-- INTERVIEW MODAL -->
        <transition name="modal">
            <div
                v-if="showInterviewModal"
                class="modal-overlay"
                @click.self="closeInterviewModal"
            >
                <div
                    class="modal-panel modal-sm"
                    role="dialog"
                    aria-modal="true"
                    aria-labelledby="interview-modal-title"
                >
                    <div class="modal-header">
                        <h3 id="interview-modal-title">Schedule Interview</h3>
                        <button
                            class="modal-close"
                            aria-label="Close"
                            @click="closeInterviewModal"
                        >
                            ✕
                        </button>
                    </div>
                    <p class="modal-desc">
                        Pick a date and time to interview
                        <strong
                            >{{ interviewApp?.courier?.first_name }}
                            {{ interviewApp?.courier?.last_name }}</strong
                        >. Their application stays pending — this just sends
                        them an invite.
                    </p>
                    <div class="space-y-3">
                        <div>
                            <label class="field-label" for="interview-datetime"
                                >Interview date &amp; time</label
                            >
                            <input
                                id="interview-datetime"
                                type="datetime-local"
                                v-model="interviewDateTime"
                                :min="minInterviewDateTime"
                                class="field-input mt-2"
                            />
                        </div>
                        <div>
                            <label class="field-label" for="interview-notes"
                                >Notes for the courier (optional)</label
                            >
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
                        <button
                            @click="closeInterviewModal"
                            class="btn-outline"
                        >
                            Cancel
                        </button>
                        <button
                            @click="submitInterview"
                            class="btn-primary"
                            :disabled="!interviewDateTime"
                        >
                            Send Invite
                        </button>
                    </div>
                </div>
            </div>
        </transition>

        <!-- VIEW REASON MODAL -->
        <transition name="modal">
            <div
                v-if="showReasonModal"
                class="modal-overlay"
                @click.self="showReasonModal = false"
            >
                <div
                    class="modal-panel modal-sm"
                    role="dialog"
                    aria-modal="true"
                    aria-labelledby="reason-modal-title"
                >
                    <div class="modal-header">
                        <h3 id="reason-modal-title">Rejection Reason</h3>
                        <button
                            class="modal-close"
                            aria-label="Close"
                            @click="showReasonModal = false"
                        >
                            ✕
                        </button>
                    </div>
                    <div class="callout-red">
                        {{ reasonApp?.rejection_reason }}
                    </div>
                    <div class="modal-actions">
                        <button
                            @click="showReasonModal = false"
                            class="btn-outline"
                        >
                            Close
                        </button>
                    </div>
                </div>
            </div>
        </transition>

        <!-- DOCUMENTS MODAL -->
        <transition name="modal">
            <div
                v-if="showDocsModal"
                class="modal-overlay"
                @click.self="closeDocuments"
            >
                <div
                    class="modal-panel"
                    role="dialog"
                    aria-modal="true"
                    aria-labelledby="docs-modal-title"
                >
                    <div class="modal-header">
                        <h3 id="docs-modal-title">
                            Documents — {{ docsApp?.courier?.first_name }}
                            {{ docsApp?.courier?.last_name }}
                        </h3>
                        <button
                            class="modal-close"
                            aria-label="Close"
                            @click="closeDocuments"
                        >
                            ✕
                        </button>
                    </div>
                    <div
                        v-if="docsApp?.resume_original_name"
                        class="doc-row"
                        style="margin-bottom: 12px"
                    >
                        <div class="doc-info">
                            <div>
                                <p class="doc-type">
                                    Resume — {{ docsApp.resume_original_name }}
                                </p>
                                <p class="doc-date">
                                    {{ formatFileSize(docsApp.resume_size) }} ·
                                    Applied {{ formatDate(docsApp.applied_at) }}
                                </p>
                            </div>
                        </div>
                        <div class="doc-actions">
                            <button
                                class="btn-sm-outline"
                                :disabled="resumeLoading"
                                @click="viewResume(docsApp)"
                            >
                                {{ resumeLoading ? 'Opening…' : 'View Resume' }}
                            </button>
                        </div>
                    </div>
                    <div
                        v-if="docsApp?.license_original_name"
                        class="doc-row"
                        style="margin-bottom: 12px"
                    >
                        <div class="doc-info">
                            <div>
                                <p class="doc-type">
                                    Driver's License —
                                    {{ docsApp.license_original_name }}
                                </p>
                                <p class="doc-date">
                                    {{ formatFileSize(docsApp.license_size) }} ·
                                    Applied {{ formatDate(docsApp.applied_at) }}
                                </p>
                            </div>
                        </div>
                        <div class="doc-actions">
                            <button
                                class="btn-sm-outline"
                                :disabled="licenseLoading"
                                @click="viewLicense(docsApp)"
                            >
                                {{
                                    licenseLoading ? 'Opening…' : 'View License'
                                }}
                            </button>
                        </div>
                    </div>
                    <div
                        v-if="docsApp?.cover_note"
                        class="callout-red"
                        style="
                            background: #f0fdfa;
                            border-color: #99f6e4;
                            color: #0f766e;
                            margin-bottom: 12px;
                        "
                    >
                        <strong>Cover note:</strong> {{ docsApp.cover_note }}
                    </div>
                    <div v-if="docsLoading" class="py-8 text-center">
                        <div
                            class="loading-spinner"
                            role="status"
                            aria-label="Loading documents"
                        ></div>
                    </div>
                    <div
                        v-else-if="userDocuments.length === 0"
                        class="empty-state"
                    >
                        <p>No other documents uploaded yet.</p>
                    </div>
                    <div v-else class="doc-list">
                        <div
                            v-for="doc in userDocuments"
                            :key="doc.id"
                            class="doc-row"
                        >
                            <div class="doc-info">
                                <div>
                                    <p class="doc-type">
                                        {{ titleCase(doc.doc_type) }}
                                    </p>
                                    <p class="doc-date">
                                        Uploaded
                                        {{ formatDate(doc.created_at) }}
                                    </p>
                                </div>
                            </div>
                            <div class="doc-actions">
                                <span
                                    class="badge"
                                    :class="badgeClass(doc.status)"
                                    >{{ doc.status }}</span
                                >
                                <button
                                    class="btn-sm-outline"
                                    @click="viewDocument(doc)"
                                >
                                    View
                                </button>
                            </div>
                        </div>
                    </div>
                    <div class="modal-actions">
                        <button @click="closeDocuments" class="btn-outline">
                            Close
                        </button>
                    </div>
                </div>
            </div>
        </transition>

        <!-- DOC PREVIEW -->
        <transition name="modal">
            <div
                v-if="previewDoc"
                class="modal-overlay preview-overlay"
                @click.self="closePreview"
            >
                <div
                    class="preview-panel"
                    role="dialog"
                    aria-modal="true"
                    aria-labelledby="preview-modal-title"
                >
                    <div class="modal-header">
                        <h3 id="preview-modal-title">
                            {{ titleCase(previewDoc.doc_type) }}
                        </h3>
                        <button
                            class="modal-close"
                            aria-label="Close"
                            @click="closePreview"
                        >
                            ✕
                        </button>
                    </div>
                    <div class="preview-body">
                        <div
                            v-if="previewLoading"
                            class="loading-spinner"
                            role="status"
                            aria-label="Loading preview"
                        ></div>
                        <img
                            v-else-if="previewUrl"
                            :src="previewUrl"
                            class="preview-image"
                            alt="Document preview"
                        />
                    </div>
                </div>
            </div>
        </transition>

        <!-- RESIGNATION REQUESTS PANEL -->
        <transition name="modal">
            <div
                v-if="resignations.show"
                class="modal-overlay"
                @click.self="resignations.show = false"
            >
                <div
                    class="modal-panel modal-lg"
                    role="dialog"
                    aria-modal="true"
                >
                    <div class="modal-header">
                        <div>
                            <h3>Resignation requests</h3>
                            <p class="page-subtitle">
                                Couriers asking to leave {{ companyName }}.
                                Approving frees them to join another company.
                            </p>
                        </div>
                        <button
                            type="button"
                            class="modal-close"
                            @click="resignations.show = false"
                        >
                            &times;
                        </button>
                    </div>

                    <div v-if="resignations.loading" class="py-8">
                        <div class="loading-spinner"></div>
                    </div>
                    <div
                        v-else-if="resignationRequests.length === 0"
                        class="empty-state"
                    >
                        <strong>No resignation requests</strong>
                        <p>Nothing to review right now.</p>
                    </div>

                    <ul v-else class="resignation-list">
                        <li
                            v-for="r in resignationRequests"
                            :key="r.id"
                            class="resignation-item"
                        >
                            <div class="resignation-head">
                                <div>
                                    <strong>
                                        {{ r.courier?.first_name }}
                                        {{ r.courier?.last_name }}
                                    </strong>
                                    <span class="parcel-recipient">{{
                                        r.courier?.email ||
                                        r.courier?.contact_no ||
                                        '—'
                                    }}</span>
                                </div>
                                <span
                                    class="badge"
                                    :class="badgeClass(r.status)"
                                    >{{ r.status }}</span
                                >
                            </div>

                            <p class="resignation-meta">
                                Submitted {{ formatDate(r.submitted_at) }}
                            </p>
                            <p v-if="r.reason" class="resignation-reason">
                                “{{ r.reason }}”
                            </p>
                            <p
                                v-if="r.decision_note"
                                class="resignation-reason"
                            >
                                Decision note: {{ r.decision_note }}
                            </p>

                            <div class="resignation-actions">
                                <button
                                    v-if="r.has_letter"
                                    type="button"
                                    class="btn-sm-outline"
                                    @click="openResignationLetter(r)"
                                >
                                    View letter
                                </button>
                                <template v-if="r.status === 'pending'">
                                    <button
                                        type="button"
                                        class="btn-sm-primary"
                                        :disabled="resignations.busyId === r.id"
                                        @click="doApproveResignation(r)"
                                    >
                                        Approve
                                    </button>
                                    <button
                                        type="button"
                                        class="btn-sm-outline"
                                        :disabled="resignations.busyId === r.id"
                                        @click="
                                            resignations.rejectingId =
                                                resignations.rejectingId ===
                                                r.id
                                                    ? null
                                                    : r.id
                                        "
                                    >
                                        Reject
                                    </button>
                                </template>
                            </div>

                            <div
                                v-if="resignations.rejectingId === r.id"
                                class="resignation-reject"
                            >
                                <textarea
                                    v-model="resignations.rejectNote"
                                    class="field-input"
                                    rows="2"
                                    placeholder="Reason for rejecting (required) — the courier will see this"
                                ></textarea>
                                <div class="resignation-actions">
                                    <button
                                        type="button"
                                        class="btn-sm-outline"
                                        @click="resignations.rejectingId = null"
                                    >
                                        Cancel
                                    </button>
                                    <button
                                        type="button"
                                        class="btn-sm-primary"
                                        :disabled="
                                            resignations.busyId === r.id ||
                                            !resignations.rejectNote.trim()
                                        "
                                        @click="doRejectResignation(r)"
                                    >
                                        Confirm rejection
                                    </button>
                                </div>
                            </div>
                        </li>
                    </ul>

                    <div
                        v-if="resignationRequestsMeta.lastPage > 1"
                        class="driver-pagination"
                    >
                        <button
                            type="button"
                            class="btn-sm-outline"
                            :disabled="
                                resignations.page <= 1 || resignations.loading
                            "
                            @click="changeResignationsPage(resignations.page - 1)"
                        >
                            Prev
                        </button>
                        <span
                            >Page {{ resignations.page }} of
                            {{ resignationRequestsMeta.lastPage }}</span
                        >
                        <button
                            type="button"
                            class="btn-sm-outline"
                            :disabled="
                                resignations.page >=
                                    resignationRequestsMeta.lastPage ||
                                resignations.loading
                            "
                            @click="changeResignationsPage(resignations.page + 1)"
                        >
                            Next
                        </button>
                    </div>
                </div>
            </div>
        </transition>

        <!-- The confirm dialog and the toast stack are rendered once by
             LogisticsLayout for the whole portal. -->
    </div>
</template>

<script setup>
import { ref, reactive, computed, onActivated, onMounted } from 'vue';
import { useLogistics } from '../composables/useLogistics';
import { useLogisticsUi } from '../composables/useLogisticsUi';
import NavIcon from './NavIcon.vue';

const {
    supabase,
    companyName,
    applications,
    pendingResignationCount,
    loadApplications,
    patchApplication,
    logisticsFetch,
    resignationRequests,
    resignationRequestsMeta,
    loadResignationRequests,
    approveResignation,
    rejectResignation,
    resignationLetterUrl,
} = useLogistics();
const {
    notify,
    notifyError,
    askConfirm,
    formatDate,
    formatFloatingDateTime,
    formatFileSize,
    initials,
    personName,
    badgeClass,
    titleCase,
} = useLogisticsUi();

const search = ref('');
const statusFilter = ref('');
// `loading` is true only during the very first load — it gates the
// skeleton. Re-entering the tab (onActivated) refreshes silently through
// the shared cache, so the skeleton never flashes over data already on
// screen; `refreshing` drives the Refresh button's disabled state.
const loading = ref(true);
const refreshing = ref(false);

// ---- Status filtering ------------------------------------------------
// Only `search` goes to the server (it needs the database). Status is
// narrowed client-side from the one unfiltered list, so the counters stay
// accurate no matter which chip is selected and switching between them
// costs no requests at all.
const STATUSES = ['pending', 'accepted', 'rejected', 'withdrawn'];

const statusCounts = computed(() => {
    const counts = Object.fromEntries(STATUSES.map((s) => [s, 0]));

    for (const app of applications.value) {
        if (counts[app.status] !== undefined) {
            counts[app.status] += 1;
        }
    }

    return counts;
});

const statusOptions = computed(() => [
    { value: '', label: 'All', count: applications.value.length },
    ...STATUSES.map((value) => ({
        value,
        label: titleCase(value),
        count: statusCounts.value[value],
    })),
]);

const visibleApplications = computed(() =>
    statusFilter.value
        ? applications.value.filter((app) => app.status === statusFilter.value)
        : applications.value,
);

const hasActiveFilters = computed(
    () => Boolean(statusFilter.value) || search.value.trim().length > 0,
);

const emptyTitle = computed(() => {
    if (search.value.trim()) {
        return 'No couriers match that search';
    }

    if (statusFilter.value) {
        return `No ${statusFilter.value} applications`;
    }

    return 'No applications yet';
});

const emptyHint = computed(() =>
    hasActiveFilters.value
        ? 'Try a different name, email, or status.'
        : 'Couriers who apply to your company will appear here.',
);

function clearFilters() {
    statusFilter.value = '';
    search.value = '';
    load();
}

// ---- Resignation requests panel ----
// 5 per page, fetched from the server one page at a time — this used to
// pull the company's entire resignation history into the browser on every
// open, no matter how long it had grown.
const resignations = reactive({
    show: false,
    loading: false,
    page: 1,
    busyId: null,
    rejectingId: null,
    rejectNote: '',
});

// `boot()` already warms page 1 in the background on mount/tab-activate
// (it only powers the pending badge there, but populates the shared
// cache). Opening the panel used to force a fresh network round-trip every
// time regardless — this reuses that cache within its TTL instead, and
// only shows the spinner when nothing is loaded yet.
async function refreshResignations(force = false) {
    resignations.loading = force || resignationRequests.value.length === 0;

    try {
        await loadResignationRequests({ page: resignations.page, force });
    } catch (e) {
        notifyError(e, 'Failed to load resignation requests.');
    } finally {
        resignations.loading = false;
    }
}

async function openResignations() {
    resignations.show = true;
    resignations.page = 1;
    resignations.rejectingId = null;
    resignations.rejectNote = '';
    await refreshResignations();
}

function changeResignationsPage(page) {
    if (
        page < 1 ||
        page > resignationRequestsMeta.value.lastPage ||
        resignations.loading
    ) {
        return;
    }

    resignations.page = page;
    refreshResignations();
}

async function doApproveResignation(r) {
    const ok = await askConfirm({
        title: 'Approve resignation',
        message: `Approve ${r.courier?.first_name || 'this courier'}'s resignation? They'll be removed from your delivery areas and freed to join another company.`,
        confirmLabel: 'Approve',
    });

    if (!ok) {
        return;
    }

    resignations.busyId = r.id;

    try {
        await approveResignation(r.id);
        notify('Resignation approved.');
        // A review can shuffle which rows land on which page (pending
        // sorts first) — the composable already invalidated the cache,
        // so this re-fetches the page being looked at rather than
        // trusting a now-stale local patch.
        await refreshResignations(true);
    } catch (e) {
        notifyError(e, 'Failed to approve the resignation.');
    } finally {
        resignations.busyId = null;
    }
}

async function doRejectResignation(r) {
    const note = resignations.rejectNote.trim();

    if (!note) {
        return;
    }

    resignations.busyId = r.id;

    try {
        await rejectResignation(r.id, note);
        resignations.rejectingId = null;
        resignations.rejectNote = '';
        notify('Resignation rejected.');
        await refreshResignations(true);
    } catch (e) {
        notifyError(e, 'Failed to reject the resignation.');
    } finally {
        resignations.busyId = null;
    }
}

async function openResignationLetter(r) {
    try {
        const url = await resignationLetterUrl(r.id);
        window.open(url, '_blank', 'noopener');
    } catch (e) {
        notifyError(e, 'Could not open the letter.');
    }
}

let searchDebounce = null;
function debouncedLoad() {
    clearTimeout(searchDebounce);
    searchDebounce = setTimeout(() => load(true), 350);
}

// Fetches unfiltered-by-status so the chips can count and filter locally.
// Does not flip `loading` back on for refreshes — that flag is a
// first-load-only skeleton gate (see its declaration).
async function load(force = false) {
    refreshing.value = true;

    try {
        await loadApplications({ search: search.value.trim() }, { force });
    } catch (e) {
        notifyError(e, 'Failed to load applications.');
    } finally {
        loading.value = false;
        refreshing.value = false;
    }
}

// REJECT MODAL
const showRejectModal = ref(false);
const rejectApp = ref(null);
const selectedReason = ref('');
const customReason = ref('');

const rejectionReasons = [
    {
        value: 'incomplete_docs',
        label: 'Incomplete or unclear documents',
        description: 'Uploaded license, ID, or OR/CR is missing or unreadable.',
    },
    {
        value: 'vehicle_mismatch',
        label: 'Vehicle does not meet requirements',
        description:
            "The courier's vehicle type doesn't fit current company needs.",
    },
    {
        value: 'capacity',
        label: 'No open capacity right now',
        description: "The company isn't accepting new couriers at this time.",
    },
    {
        value: 'not_a_fit',
        label: 'Not a fit for this company',
        description:
            "Coverage area, availability, or experience doesn't match.",
    },
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

        if (!message) {
            notify('Please specify the reason.', 'error');

            return;
        }
    } else {
        const r = rejectionReasons.find(
            (r) => r.value === selectedReason.value,
        );
        message = r ? `${r.label} — ${r.description}` : selectedReason.value;
    }

    const app = { ...rejectApp.value };
    closeRejectModal();

    try {
        const { error } = await supabase
            .from('courier_applications')
            .update({ status: 'rejected', rejection_reason: message })
            .eq('id', app.id);

        if (error) {
            throw error;
        }

        sendEmail('/api/logistics/notify-application-rejected', {
            email: app.courier.email,
            name: personName(app.courier),
            company_name: companyName.value,
            reason: message,
        });

        // The row is patched locally instead of re-downloading the whole
        // list — the only thing that changed is this application.
        patchApplication(app.id, {
            status: 'rejected',
            rejection_reason: message,
        });
        notify('Application rejected.');
    } catch (e) {
        notifyError(e, 'Failed to reject the application.');
    }
}

// DETAILS MODAL — read-only profile popup for an accepted courier,
// mirroring the rider-view popup on the Riders & Areas page.
const detailsApp = ref(null);
function openDetailsModal(app) {
    detailsApp.value = app;
}
function closeDetailsModal() {
    detailsApp.value = null;
}

// FIRE MODAL — end an accepted courier's engagement. Unlike the other
// actions this goes through the Laravel API (not a direct Supabase
// write): the server withdraws the application, pulls the rider off this
// company's delivery areas in one transaction, and emails the courier —
// the same "free the courier" path resignation approval uses.
const fireApp = ref(null);
const fireReason = ref('');
const firing = ref(false);

function openFireModal(app) {
    fireApp.value = app;
    fireReason.value = '';
}
function closeFireModal() {
    if (firing.value) {
        return;
    }

    fireApp.value = null;
    fireReason.value = '';
}

async function submitFire() {
    const app = fireApp.value;

    if (!app || firing.value) {
        return;
    }

    firing.value = true;

    try {
        const response = await logisticsFetch(
            `/api/logistics/applications/${app.id}/terminate`,
            {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ reason: fireReason.value.trim() }),
            },
        );
        const payload = await response.json().catch(() => ({}));

        if (!response.ok) {
            throw new Error(payload.message || 'Failed to fire the courier.');
        }

        patchApplication(app.id, {
            status: 'withdrawn',
            rejection_reason: fireReason.value.trim() || null,
        });
        firing.value = false;
        fireApp.value = null;
        fireReason.value = '';
        notify(`${app.courier?.first_name || 'Courier'} has been let go.`);
    } catch (e) {
        firing.value = false;
        notifyError(e, 'Failed to fire the courier.');
    }
}

function isInterviewing(app) {
    return app.status === 'pending' && !!app.interview_invited_at;
}

// interview_scheduled_at is a "floating" local time (just what staff typed
// into the picker), so it is rendered with no timezone conversion.
const formatInterviewTime = formatFloatingDateTime;

// INTERVIEW MODAL
const showInterviewModal = ref(false);
const interviewApp = ref(null);
const interviewDateTime = ref('');
const interviewNotes = ref('');

function pad(n) {
    return String(n).padStart(2, '0');
}
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
        notify('Please pick a date and time.', 'error');

        return;
    }

    const app = interviewApp.value;
    const scheduledAt = interviewDateTime.value; // kept as the raw picked value, no timezone conversion
    const notes = interviewNotes.value.trim();
    const invitedAt = new Date().toISOString();
    closeInterviewModal();

    try {
        const { error } = await supabase
            .from('courier_applications')
            .update({
                interview_invited_at: invitedAt,
                interview_scheduled_at: scheduledAt,
            })
            .eq('id', app.id);

        if (error) {
            throw error;
        }

        sendEmail('/api/logistics/notify-application-interview', {
            email: app.courier.email,
            name: personName(app.courier),
            company_name: companyName.value,
            interview_at: scheduledAt,
            notes: notes || null,
        });

        patchApplication(app.id, {
            interview_invited_at: invitedAt,
            interview_scheduled_at: scheduledAt,
        });
        notify(`${app.courier.first_name} invited to interview.`);
    } catch (e) {
        notifyError(e, 'Failed to invite to interview.');
    }
}

async function acceptApplication(app) {
    const ok = await askConfirm({
        title: 'Accept application',
        message: `Accept ${personName(app.courier)} into ${companyName.value}?`,
        confirmLabel: 'Accept',
    });

    if (!ok) {
        return;
    }

    try {
        const { error } = await supabase
            .from('courier_applications')
            .update({ status: 'accepted' })
            .eq('id', app.id);

        if (error) {
            throw error;
        }

        sendEmail('/api/logistics/notify-application-accepted', {
            email: app.courier.email,
            name: personName(app.courier),
            company_name: companyName.value,
        });

        patchApplication(app.id, { status: 'accepted' });
        notify(`${app.courier.first_name} accepted.`);
    } catch (e) {
        notifyError(e, 'Failed to accept the application.');
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

        if (error) {
            throw error;
        }

        userDocuments.value = data || [];
    } catch (e) {
        notifyError(e, 'Failed to load documents.');
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
        const response = await logisticsFetch(
            `/api/logistics/applications/${app.id}/resume`,
        );
        const payload = await response.json();

        if (!response.ok) {
            throw new Error(payload.message || 'Failed to load resume.');
        }

        window.open(payload.url, '_blank', 'noopener');
    } catch (e) {
        notifyError(e, 'Failed to open the resume.');
    } finally {
        resumeLoading.value = false;
    }
}

const licenseLoading = ref(false);

async function viewLicense(app) {
    licenseLoading.value = true;

    try {
        const response = await logisticsFetch(
            `/api/logistics/applications/${app.id}/license`,
        );
        const payload = await response.json();

        if (!response.ok) {
            throw new Error(payload.message || 'Failed to load license.');
        }

        window.open(payload.url, '_blank', 'noopener');
    } catch (e) {
        notifyError(e, "Failed to open the driver's license.");
    } finally {
        licenseLoading.value = false;
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
        const { data, error } = await supabase.storage
            .from('documents')
            .createSignedUrl(doc.storage_path, 300);

        if (error) {
            throw error;
        }

        previewUrl.value = data.signedUrl;
    } catch (e) {
        notifyError(e, 'Failed to open the document.');
        previewDoc.value = null;
    } finally {
        previewLoading.value = false;
    }
}
function closePreview() {
    previewDoc.value = null;
    previewUrl.value = '';
}

// formatDate / formatFileSize / initials / badgeClass / titleCase all
// come from useLogisticsUi now — they used to be redeclared, slightly
// differently, in four separate components.

async function sendEmail(endpoint, data) {
    try {
        const csrfToken =
            document
                .querySelector('meta[name="csrf-token"]')
                ?.getAttribute('content') || '';
        await fetch(endpoint, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': csrfToken,
            },
            body: JSON.stringify(data),
        });
    } catch (e) {
        console.error('Failed to send email:', e);
    }
}

function boot() {
    load();
    // Best-effort — just powers the button's pending badge; the panel
    // re-fetches on open.
    loadResignationRequests().catch(() => {});
}

onMounted(boot);

// <KeepAlive> means onMounted fires once, so re-entering the tab re-checks
// staleness. The shared cache makes this free while the data is fresh and
// refetches only once it has aged past the TTL.
onActivated(boot);
</script>

<style scoped>
.details-field-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 14px;
}
.details-field-grid .full-span {
    grid-column: 1 / -1;
}
.details-field-grid .field-input:disabled {
    cursor: default;
}

.resignations-btn {
    position: relative;
    white-space: nowrap;
}
.resignations-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 20px;
    height: 20px;
    margin-left: 8px;
    padding: 0 6px;
    border-radius: 999px;
    background: #dc2626;
    color: #fff;
    font-size: 11px;
    font-weight: 800;
    line-height: 1;
}
.resignation-list {
    list-style: none;
    margin: 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: 12px;
    max-height: 60vh;
    overflow-y: auto;
}
.resignation-item {
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 14px;
}
.resignation-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 12px;
}
.resignation-head strong {
    display: block;
    font-weight: 700;
    color: #0f172a;
}
.resignation-meta {
    margin: 6px 0 0;
    font-size: 12px;
    color: #64748b;
}
.resignation-reason {
    margin: 6px 0 0;
    font-size: 13px;
    color: #334155;
}
.resignation-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-top: 12px;
}
.resignation-reject {
    margin-top: 10px;
    display: flex;
    flex-direction: column;
    gap: 8px;
}
</style>
