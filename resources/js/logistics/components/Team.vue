<!-- resources/js/logistics/components/Team.vue
     The company's team: members (owner + invited users) and pending
     invitations. All permission rules live in Api\Logistics\
     TeamController; this page only hides actions the server would reject. -->
<template>
    <div class="logistics-page">
        <header class="page-header">
            <div class="page-header-titles">
                <span class="page-icon-badge tone-info">
                    <NavIcon name="team" :size="22" />
                </span>
                <div>
                    <h2 class="page-title">Team</h2>
                    <p class="page-subtitle">
                        People who can sign in to
                        {{ companyName || 'your company' }}'s portal.
                    </p>
                </div>
            </div>
            <div v-if="me.can_manage" class="page-header-actions">
                <button type="button" class="btn-primary btn-icon" @click="openInvite()">
                    <NavIcon name="plus" :size="15" />
                    Invite member
                </button>
            </div>
        </header>

        <div v-if="loadError" class="callout-red callout-block" role="alert">
            <NavIcon name="alert" :size="18" />
            <div>
                <strong>We couldn't load your team.</strong>
                <p>{{ loadError }}</p>
            </div>
            <button type="button" class="btn-outline" @click="load">Try again</button>
        </div>

        <div v-if="me.can_manage" class="tab-group team-tabs">
            <button
                type="button"
                class="tab-btn"
                :class="{ 'is-active': view === 'members' }"
                @click="view = 'members'"
            >
                <NavIcon name="team" :size="15" />
                Members
                <span class="tab-btn-count">{{ members.length }}</span>
            </button>
            <button
                type="button"
                class="tab-btn"
                :class="{ 'is-active': view === 'invites' }"
                @click="view = 'invites'"
            >
                <NavIcon name="mail" :size="15" />
                Pending invites
                <span v-if="invitations.length" class="tab-btn-count">{{ invitations.length }}</span>
            </button>
        </div>

        <!-- Members -->
        <section v-if="view === 'members'" class="card team-card">
            <div v-if="loading && !members.length" class="skeleton-list" aria-label="Loading team">
                <div v-for="n in 3" :key="n" class="team-row">
                    <span class="skeleton skeleton-circle avatar"></span>
                    <div class="person-copy">
                        <span class="skeleton skeleton-row"></span>
                    </div>
                </div>
            </div>

            <ul v-else class="people-list">
                <li
                    v-for="member in members"
                    :key="member.id"
                    class="team-row"
                    :class="{ 'is-suspended': member.status === 'suspended' }"
                >
                    <span class="avatar" aria-hidden="true">
                        <img v-if="member.avatar_url" :src="member.avatar_url" alt="" width="38" height="38" loading="lazy" />
                        <template v-else>{{ initials({ first_name: member.name }) }}</template>
                    </span>
                    <div class="person-copy">
                        <strong>
                            {{ member.name }}
                            <span v-if="member.id === me.id" class="team-you">You</span>
                        </strong>
                        <span class="team-email">{{ member.email }}</span>
                    </div>

                    <div class="team-row-meta">
                        <span v-if="member.status === 'suspended'" class="badge badge-red">Suspended</span>
                        <select
                            v-if="canManage(member)"
                            class="field-input team-role-select"
                            :value="member.role"
                            :disabled="busyId === member.id"
                            :aria-label="`Role for ${member.name}`"
                            @change="changeRole(member, $event.target.value)"
                        >
                            <option v-for="role in me.assignable_roles" :key="role" :value="role">
                                {{ ROLE_LABELS[role] }}
                            </option>
                        </select>
                        <span v-else class="badge" :class="member.role === 'owner' ? 'badge-indigo' : 'badge-slate'">
                            {{ ROLE_LABELS[member.role] }}
                        </span>
                    </div>

                    <div v-if="canManage(member) || canTransferTo(member)" class="team-row-actions">
                        <button
                            v-if="canTransferTo(member)"
                            type="button"
                            class="btn-sm-outline"
                            :disabled="busyId === member.id"
                            @click="transferTo(member)"
                        >
                            Make owner
                        </button>
                        <template v-if="canManage(member)">
                            <button
                                type="button"
                                class="btn-sm-outline"
                                :disabled="busyId === member.id"
                                @click="toggleSuspend(member)"
                            >
                                {{ member.status === 'suspended' ? 'Reactivate' : 'Suspend' }}
                            </button>
                            <button
                                type="button"
                                class="btn-danger-outline"
                                :disabled="busyId === member.id"
                                @click="removeMember(member)"
                            >
                                Remove
                            </button>
                        </template>
                    </div>
                </li>
            </ul>

            <footer v-if="me.role && !loading" class="team-footer">
                <p v-if="me.role === 'owner'" class="field-hint">
                    You own this company. To leave, make another member the owner first.
                </p>
                <template v-else>
                    <p class="field-hint">You're a {{ ROLE_LABELS[me.role] }} on this team.</p>
                    <button type="button" class="btn-danger-outline" @click="leaveTeam">
                        Leave team
                    </button>
                </template>
            </footer>
        </section>

        <!-- Pending invites -->
        <section v-else class="card team-card">
            <div v-if="!invitations.length" class="empty-state">
                <NavIcon name="mail" :size="32" class="empty-state-icon" />
                <p>No pending invitations.</p>
                <button type="button" class="btn-outline empty-action" @click="openInvite()">
                    Invite someone
                </button>
            </div>
            <ul v-else class="people-list">
                <li v-for="invite in invitations" :key="invite.id" class="team-row">
                    <span class="avatar team-avatar-muted" aria-hidden="true">
                        <NavIcon name="mail" :size="16" />
                    </span>
                    <div class="person-copy">
                        <strong>{{ invite.email }}</strong>
                        <span class="team-email">
                            {{ ROLE_LABELS[invite.role] }}
                            <template v-if="invite.invited_by"> · invited by {{ invite.invited_by }}</template>
                        </span>
                    </div>
                    <div class="team-row-meta">
                        <span v-if="invite.renewal_requested" class="badge badge-amber">Asked for new link</span>
                        <span v-if="invite.is_expired" class="badge badge-red">Expired</span>
                        <span v-else class="badge badge-slate" :title="formatDateTime(invite.expires_at)">
                            {{ expiresLabel(invite.expires_at) }}
                        </span>
                    </div>
                    <div class="team-row-actions">
                        <button
                            type="button"
                            class="btn-sm-outline"
                            :disabled="busyId === invite.id"
                            @click="resendInvite(invite)"
                        >
                            Resend
                        </button>
                        <button
                            type="button"
                            class="btn-danger-outline"
                            :disabled="busyId === invite.id"
                            @click="revokeInvite(invite)"
                        >
                            Revoke
                        </button>
                    </div>
                </li>
            </ul>
        </section>

        <!-- Invite modal -->
        <Teleport to=".logistics-shell">
            <div v-if="inviteOpen" class="modal-overlay" @click.self="inviteOpen = false">
                <form class="modal-panel" role="dialog" aria-modal="true" aria-labelledby="invite-title" @submit.prevent="sendInvite">
                    <div class="modal-header">
                        <h3 id="invite-title">Invite a team member</h3>
                        <button type="button" class="modal-close" aria-label="Close" @click="inviteOpen = false">
                            <NavIcon name="close" :size="15" />
                        </button>
                    </div>
                    <p class="modal-desc">
                        They'll get an email with a link to join. The link expires in 7 days.
                    </p>

                    <label class="form-field">
                        <span>Email address</span>
                        <input
                            ref="inviteEmailEl"
                            v-model.trim="inviteForm.email"
                            type="email"
                            class="field-input"
                            placeholder="name@company.com"
                            autocomplete="off"
                            required
                        />
                    </label>

                    <fieldset class="team-role-options">
                        <legend>Role</legend>
                        <label
                            v-for="role in me.assignable_roles"
                            :key="role"
                            class="reason-option"
                            :class="{ 'is-selected': inviteForm.role === role }"
                        >
                            <input v-model="inviteForm.role" type="radio" name="invite-role" :value="role" />
                            <span>
                                <span class="reason-label">{{ ROLE_LABELS[role] }}</span>
                                <span class="reason-desc">{{ ROLE_DESCRIPTIONS[role] }}</span>
                            </span>
                        </label>
                    </fieldset>

                    <p v-if="inviteError" class="callout-red team-form-error" role="alert">{{ inviteError }}</p>

                    <div class="modal-actions">
                        <button type="button" class="btn-outline" @click="inviteOpen = false">Cancel</button>
                        <button type="submit" class="btn-primary" :disabled="inviting">
                            {{ inviting ? 'Sending…' : 'Send invite' }}
                        </button>
                    </div>
                </form>
            </div>
        </Teleport>
    </div>
</template>

<script setup>
import { nextTick, onActivated, onMounted, reactive, ref } from 'vue';
import { useLogistics } from '../composables/useLogistics';
import { useLogisticsUi } from '../composables/useLogisticsUi';
import NavIcon from './NavIcon.vue';

const ROLE_LABELS = {
    owner: 'Owner',
    admin: 'Admin',
    manager: 'Manager',
    operator: 'Operator',
    viewer: 'Viewer',
};
const ROLE_DESCRIPTIONS = {
    admin: 'Everything, including inviting and managing the team.',
    manager: 'Runs day-to-day operations: parcels, areas, riders.',
    operator: 'Handles parcel sorting and hand-offs.',
    viewer: 'Can look around but not change anything.',
};

const { companyName, logisticsFetch } = useLogistics();
const { notify, askConfirm, initials, formatDateTime } = useLogisticsUi();

function expiresLabel(value) {
    const hours = Math.max(0, (new Date(value).getTime() - Date.now()) / 3_600_000);

    return hours >= 24
        ? `Expires in ${Math.floor(hours / 24)} d`
        : `Expires in ${Math.max(1, Math.floor(hours))} h`;
}

const view = ref('members');
const loading = ref(true);
const loadError = ref('');
const me = ref({ id: null, role: null, can_manage: false, assignable_roles: [] });
const members = ref([]);
const invitations = ref([]);
const busyId = ref(null);

const inviteOpen = ref(false);
const inviting = ref(false);
const inviteError = ref('');
const inviteEmailEl = ref(null);
const inviteForm = reactive({ email: '', role: 'operator' });

async function request(path, options = {}) {
    const response = await logisticsFetch(`/api/logistics/team${path}`, {
        ...options,
        headers: options.body ? { 'Content-Type': 'application/json' } : undefined,
        body: options.body ? JSON.stringify(options.body) : undefined,
    });
    const payload = await response.json().catch(() => ({}));

    if (!response.ok) {
        throw new Error(
            Object.values(payload.errors || {}).flat()[0] || payload.message || 'Something went wrong.',
        );
    }

    return payload;
}

async function load() {
    loading.value = true;
    loadError.value = '';

    try {
        const data = await request('');
        me.value = data.me;
        members.value = data.members;
        invitations.value = data.invitations;

        if (!data.me.can_manage) {
            view.value = 'members';
        }
    } catch (error) {
        loadError.value = error.message;
    } finally {
        loading.value = false;
    }
}

function canManage(member) {
    return member.id !== me.value.id && me.value.assignable_roles.includes(member.role);
}

function canTransferTo(member) {
    return me.value.role === 'owner' && member.role !== 'owner' && member.status === 'active';
}

/** Runs one row action with a per-row busy flag and a toast on failure. */
async function rowAction(id, fn) {
    busyId.value = id;

    try {
        await fn();
    } catch (error) {
        notify(error.message, 'error');
    } finally {
        busyId.value = null;
    }
}

function changeRole(member, role) {
    const previous = member.role;
    member.role = role; // optimistic

    return rowAction(member.id, async () => {
        try {
            await request(`/members/${member.id}`, { method: 'PATCH', body: { role } });
            notify(`${member.name} is now ${ROLE_LABELS[role]}.`);
        } catch (error) {
            member.role = previous;
            throw error;
        }
    });
}

async function toggleSuspend(member) {
    const suspending = member.status !== 'suspended';

    if (
        suspending &&
        !(await askConfirm({
            title: `Suspend ${member.name}?`,
            message: 'They will immediately lose access to the portal until reactivated.',
            confirmLabel: 'Suspend',
            tone: 'danger',
        }))
    ) {
        return;
    }

    await rowAction(member.id, async () => {
        const data = await request(`/members/${member.id}/${suspending ? 'suspend' : 'reactivate'}`, { method: 'POST' });
        member.status = data.status;
        notify(data.message);
    });
}

async function removeMember(member) {
    const confirmed = await askConfirm({
        title: `Remove ${member.name}?`,
        message: 'They will lose access to this company. You can invite them again later.',
        confirmLabel: 'Remove',
        tone: 'danger',
    });

    if (!confirmed) {
        return;
    }

    await rowAction(member.id, async () => {
        const data = await request(`/members/${member.id}`, { method: 'DELETE' });
        members.value = members.value.filter((m) => m.id !== member.id);
        notify(data.message);
    });
}

async function transferTo(member) {
    const confirmed = await askConfirm({
        title: `Make ${member.name} the owner?`,
        message: 'They will own this company. You will stay on the team as an Admin and can no longer manage other admins.',
        confirmLabel: 'Transfer ownership',
        tone: 'danger',
    });

    if (!confirmed) {
        return;
    }

    await rowAction(member.id, async () => {
        await request('/transfer-ownership', { method: 'POST', body: { profile_id: member.id } });
        // Roles change portal-wide — reload so every page re-resolves them.
        window.location.reload();
    });
}

async function leaveTeam() {
    const confirmed = await askConfirm({
        title: `Leave ${companyName.value || 'this company'}?`,
        message: 'You will lose access to this portal immediately.',
        confirmLabel: 'Leave team',
        tone: 'danger',
    });

    if (!confirmed) {
        return;
    }

    try {
        await request('/leave', { method: 'POST' });
        window.location.reload();
    } catch (error) {
        notify(error.message, 'error');
    }
}

function openInvite(prefill = {}) {
    inviteForm.email = prefill.email || '';
    inviteForm.role = prefill.role || (me.value.assignable_roles.includes('operator') ? 'operator' : me.value.assignable_roles[0]);
    inviteError.value = '';
    inviteOpen.value = true;
    nextTick(() => inviteEmailEl.value?.focus());
}

async function sendInvite() {
    inviting.value = true;
    inviteError.value = '';

    try {
        const data = await request('/invitations', { method: 'POST', body: { ...inviteForm } });
        invitations.value = [data.invitation, ...invitations.value.filter((i) => i.id !== data.invitation.id)];
        inviteOpen.value = false;
        view.value = 'invites';
        notify(data.message);
    } catch (error) {
        inviteError.value = error.message;
    } finally {
        inviting.value = false;
    }
}

function resendInvite(invite) {
    return rowAction(invite.id, async () => {
        const data = await request(`/invitations/${invite.id}/resend`, { method: 'POST' });
        Object.assign(invite, data.invitation);
        notify(data.message);
    });
}

async function revokeInvite(invite) {
    const confirmed = await askConfirm({
        title: 'Revoke invitation?',
        message: `The link sent to ${invite.email} will stop working.`,
        confirmLabel: 'Revoke',
        tone: 'danger',
    });

    if (!confirmed) {
        return;
    }

    await rowAction(invite.id, async () => {
        const data = await request(`/invitations/${invite.id}`, { method: 'DELETE' });
        invitations.value = invitations.value.filter((i) => i.id !== invite.id);
        notify(data.message);
    });
}

onMounted(load);

// <KeepAlive> keeps this page mounted; refresh quietly when revisited so
// accepted invites show up as members.
let mounted = false;
onActivated(() => {
    if (mounted) {
        load();
    }
    mounted = true;
});
</script>

<style scoped>
.team-tabs {
    margin-bottom: 16px;
}
.team-card {
    padding: 8px 20px;
}
.team-row {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 12px;
    padding: 14px 0;
    border-bottom: 1px solid var(--lg-border);
}
.team-row:last-child {
    border-bottom: none;
}
.team-row.is-suspended .person-copy,
.team-row.is-suspended .avatar {
    opacity: 0.55;
}
.team-row .avatar {
    flex-shrink: 0;
    overflow: hidden;
}
.team-row .avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}
.team-avatar-muted {
    background: var(--lg-surface-muted, #f1f5f9);
    color: var(--lg-slate);
    display: grid;
    place-items: center;
}
.team-email {
    display: block;
    font-size: 13px;
    color: var(--lg-slate);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.team-you {
    margin-left: 6px;
    font-size: 11px;
    font-weight: 600;
    color: var(--lg-primary);
}
.team-row-meta,
.team-row-actions {
    display: flex;
    align-items: center;
    gap: 8px;
}
.team-role-select {
    width: auto;
    min-width: 120px;
    min-height: 36px;
    padding: 6px 10px;
}
.team-footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    flex-wrap: wrap;
    padding: 14px 0;
    border-top: 1px solid var(--lg-border);
}
.team-role-options {
    border: none;
    padding: 0;
    margin: 16px 0 0;
    display: grid;
    gap: 8px;
}
.team-role-options legend {
    font-size: 13px;
    font-weight: 600;
    color: var(--lg-slate-600);
    margin-bottom: 6px;
}
.team-role-options .reason-option {
    display: flex;
    gap: 10px;
    align-items: flex-start;
    cursor: pointer;
}
.team-role-options .reason-option > span {
    display: flex;
    flex-direction: column;
}
.team-form-error {
    margin-top: 12px;
    padding: 10px 12px;
    border-radius: var(--lg-radius-sm);
    font-size: 13px;
}

@media (max-width: 640px) {
    .team-card {
        padding: 4px 14px;
    }
    .team-row-actions {
        width: 100%;
        padding-left: 50px;
    }
}
</style>
