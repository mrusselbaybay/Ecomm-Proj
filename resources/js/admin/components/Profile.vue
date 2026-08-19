<!-- resources/js/admin/components/Profile.vue -->
<template>
    <div class="card max-w-lg p-5">
        <h3 class="mb-3 text-sm font-bold text-slate-800">Admin Profile</h3>
        <div class="mb-4 space-y-3">
            <div>
                <label class="field-label">Full Name</label>
                <input class="field-input" v-model="adminProfile.name" />
            </div>
            <div>
                <label class="field-label">Email</label>
                <input
                    class="field-input"
                    v-model="adminProfile.email"
                    disabled
                />
            </div>
            <div>
                <label class="field-label">Role</label>
                <input
                    class="field-input"
                    v-model="adminProfile.role"
                    disabled
                />
            </div>
        </div>
        <button @click="updateProfile" class="btn-sm-gradient px-4 py-2">
            Save Changes
        </button>
        <div class="mt-5 border-t border-slate-100 pt-4">
            <h4 class="mb-2 text-sm font-bold text-slate-800">
                Change Password
            </h4>
            <div class="space-y-2">
                <input
                    type="password"
                    class="field-input"
                    placeholder="Current password"
                />
                <input
                    type="password"
                    class="field-input"
                    placeholder="New password"
                />
                <input
                    type="password"
                    class="field-input"
                    placeholder="Confirm new password"
                />
            </div>
            <button class="btn-outline mt-3">Update Password</button>
        </div>
    </div>
</template>

<script setup>
import { useAdmin } from '../composables/useAdmin';

const { adminProfile } = useAdmin();

function updateProfile() {
    alert('✅ Profile updated! (This is a placeholder)');
}
</script>

<style scoped>
.card {
    background: white;
    border: 1px solid #e5e7eb;
    border-radius: 0.75rem;
}
.field-input {
    width: 100%;
    padding: 0.5rem 0.7rem;
    font-size: 0.85rem;
    border: 1px solid #d1d5db;
    border-radius: 0.375rem;
    background: white;
}
.field-label {
    font-size: 0.65rem;
    font-weight: 700;
    color: #64748b;
    letter-spacing: 0.05em;
    text-transform: uppercase;
    display: block;
    margin-bottom: 0.25rem;
}
.btn-sm-gradient {
    background: linear-gradient(90deg, #ea580c, #f59e0b);
    color: white;
    font-weight: 600;
    font-size: 0.75rem;
    padding: 0.35rem 0.75rem;
    border-radius: 0.4rem;
}
.btn-outline {
    border: 1px solid #d1d5db;
    color: #334155;
    font-weight: 600;
    font-size: 0.75rem;
    padding: 0.35rem 0.75rem;
    border-radius: 0.4rem;
}
</style>
