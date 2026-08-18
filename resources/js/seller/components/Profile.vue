<!-- resources/js/seller/components/Profile.vue -->
<template>
  <div>
    <form @submit.prevent="handleSave">
      <!-- Personal Information -->
      <div class="card form-section">
        <p class="form-section-title">Personal Information</p>
        <p class="form-section-desc">This is the information on file for your seller account.</p>

        <div class="form-grid">
          <div>
            <label class="field-label">Last Name <span style="color:var(--teal-500);">*</span></label>
            <input v-model="formData.last_name" @input="onNameInput('last_name')" class="field-input" placeholder="Dela Cruz" />
            <span v-if="errors.last_name" class="save-msg error">{{ errors.last_name }}</span>
          </div>
          <div>
            <label class="field-label">First Name <span style="color:var(--teal-500);">*</span></label>
            <input v-model="formData.first_name" @input="onNameInput('first_name')" class="field-input" placeholder="Juan" />
            <span v-if="errors.first_name" class="save-msg error">{{ errors.first_name }}</span>
          </div>
          <div>
            <label class="field-label">M.I.</label>
            <input v-model="formData.middle_initial" maxlength="1" @input="onMiddleInitialInput" class="field-input" placeholder="B" />
          </div>

          <div>
            <label class="field-label">Sex <span style="color:var(--teal-500);">*</span></label>
            <select v-model="formData.sex" class="field-input">
              <option value="">Select</option>
              <option value="Male">Male</option>
              <option value="Female">Female</option>
            </select>
          </div>
          <div>
            <label class="field-label">Birthday <span style="color:var(--teal-500);">*</span></label>
            <input v-model="formData.birthday" type="date" :max="todayStr" class="field-input" />
            <span v-if="errors.birthday" class="save-msg error">{{ errors.birthday }}</span>
          </div>
          <div>
            <label class="field-label">Age</label>
            <input :value="age !== null ? age : '—'" class="field-input" disabled />
          </div>

          <div>
            <label class="field-label">Contact No. <span style="color:var(--teal-500);">*</span></label>
            <input v-model="formData.contact_no" @input="onContactInput" class="field-input" placeholder="09XXXXXXXXX" maxlength="11" />
            <span v-if="errors.contact_no" class="save-msg error">{{ errors.contact_no }}</span>
          </div>
          <div class="full-span" style="grid-column: span 2;">
            <label class="field-label">Email</label>
            <input :value="profile?.email" class="field-input" disabled />
          </div>
        </div>
      </div>

      <!-- Address -->
      <div class="card form-section">
        <p class="form-section-title">Store Address</p>
        <p class="form-section-desc">Used for logistics and courier pickup/delivery matching.</p>

        <div class="form-grid">
          <div>
            <label class="field-label">Province <span style="color:var(--teal-500);">*</span></label>
            <select v-model="formData.province_code" @change="onProvinceChange" class="field-input" :disabled="loadingProvinces">
              <option value="">{{ loadingProvinces ? 'Loading provinces…' : 'Select province' }}</option>
              <option v-for="p in provinceOptions" :key="p.code" :value="p.code">{{ p.name }}</option>
            </select>
          </div>
          <div>
            <label class="field-label">Municipality / City <span style="color:var(--teal-500);">*</span></label>
            <select v-model="formData.municipality_code" @change="onMunicipalityChange" class="field-input" :disabled="loadingMunicipalities || !formData.province_code">
              <option value="">{{ loadingMunicipalities ? 'Loading…' : 'Select municipality/city' }}</option>
              <option v-for="m in municipalityOptions" :key="m.code" :value="m.code">{{ m.name }}</option>
            </select>
          </div>
          <div>
            <label class="field-label">Barangay <span style="color:var(--teal-500);">*</span></label>
            <select v-model="formData.barangay" class="field-input" :disabled="loadingBarangays || !formData.municipality_code">
              <option value="">{{ loadingBarangays ? 'Loading…' : 'Select barangay' }}</option>
              <option v-for="b in barangayOptions" :key="b.code" :value="b.name">{{ b.name }}</option>
            </select>
          </div>

          <div>
            <label class="field-label">House / Unit No.</label>
            <input v-model="formData.house_no" class="field-input" placeholder="123" />
          </div>
          <div class="full-span" style="grid-column: span 2;">
            <label class="field-label">Street <span style="color:var(--teal-500);">*</span></label>
            <input v-model="formData.street" class="field-input" placeholder="Rizal St." />
          </div>
        </div>

        <p v-if="addressApiError" class="save-msg error" style="margin-top:0.75rem;">
          {{ addressApiError }}
          <button type="button" @click="fetchProvinces" style="text-decoration:underline;font-weight:600;margin-left:0.25rem;">Retry</button>
        </p>
      </div>

      <!-- Business Information -->
      <div class="card form-section">
        <p class="form-section-title">Business Information</p>
        <p class="form-section-desc">Shown to buyers on your storefront.</p>

        <div class="form-grid">
          <div class="full-span" style="grid-column: span 2;">
            <label class="field-label">Business Name <span style="color:var(--teal-500);">*</span></label>
            <input v-model="formData.business_name" class="field-input" placeholder="My Store" />
          </div>
          <div>
            <label class="field-label">Line of Business <span style="color:var(--teal-500);">*</span></label>
            <select v-model="formData.line_of_business" class="field-input">
              <option value="">Select category</option>
              <option v-for="opt in LINE_OF_BUSINESS_OPTIONS" :key="opt" :value="opt">{{ opt }}</option>
            </select>
          </div>
        </div>
      </div>

      <!-- Compliance Documents (read-only summary) -->
      <div class="card form-section">
        <p class="form-section-title">Compliance Documents</p>
        <p class="form-section-desc">Submitted during registration. Contact support to resubmit a document.</p>

        <div v-if="documents.length === 0" class="empty-state">
          <p>No documents on file.</p>
        </div>
        <div v-else class="doc-list">
          <div v-for="doc in documents" :key="doc.id" class="doc-row">
            <div class="doc-info">
              <div class="avatar">{{ docTypeLabel(doc.doc_type).slice(0, 2).toUpperCase() }}</div>
              <div>
                <p class="doc-type">{{ docTypeLabel(doc.doc_type) }}</p>
                <p class="doc-date">Submitted {{ formatDate(doc.created_at) }}</p>
              </div>
            </div>
            <span class="badge" :class="statusBadgeClass(doc.status)">{{ doc.status }}</span>
          </div>
        </div>
      </div>

      <!-- Actions -->
      <div class="form-actions">
        <span v-if="saveSuccess" class="save-msg success">{{ saveSuccess }}</span>
        <span v-if="saveError" class="save-msg error">{{ saveError }}</span>
        <button type="button" class="btn-outline" @click="resetForm" :disabled="savingProfile">Reset</button>
        <button type="submit" class="btn-primary" :disabled="savingProfile">
          {{ savingProfile ? 'Saving…' : 'Save Changes' }}
        </button>
      </div>
    </form>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue';
import { useSeller } from '../composables/useSeller';

const {
  profile,
  address,
  sellerDetails,
  documents,
  savingProfile,
  saveError,
  saveSuccess,
  LINE_OF_BUSINESS_OPTIONS,
  age,
  saveProfile,
  formatDate,
  docTypeLabel,
  statusBadgeClass,
} = useSeller();

const PSGC_BASE = '/api/psgc';

const todayStr = new Date().toISOString().split('T')[0];

const errors = reactive({ last_name: '', first_name: '', birthday: '', contact_no: '' });

const formData = reactive({
  last_name: '',
  first_name: '',
  middle_initial: '',
  sex: '',
  birthday: '',
  contact_no: '',
  province_code: '',
  province_name: '',
  municipality_code: '',
  municipality_name: '',
  barangay: '',
  street: '',
  house_no: '',
  business_name: '',
  line_of_business: '',
});

const provinceOptions = ref([]);
const municipalityOptions = ref([]);
const barangayOptions = ref([]);
const loadingProvinces = ref(false);
const loadingMunicipalities = ref(false);
const loadingBarangays = ref(false);
const addressApiError = ref('');
const provinceCache = { value: [] };

function populateFormFromState() {
  if (profile.value) {
    formData.last_name = profile.value.last_name || '';
    formData.first_name = profile.value.first_name || '';
    formData.middle_initial = profile.value.middle_initial || '';
    formData.sex = profile.value.sex || '';
    formData.birthday = profile.value.birthday || '';
    formData.contact_no = profile.value.contact_no || '';
  }
  if (address.value) {
    formData.province_code = address.value.province_code || '';
    formData.province_name = address.value.province_name || '';
    formData.municipality_code = address.value.municipality_code || '';
    formData.municipality_name = address.value.municipality_name || '';
    formData.barangay = address.value.barangay || '';
    formData.street = address.value.street || '';
    formData.house_no = address.value.house_no || '';
  }
  if (sellerDetails.value) {
    formData.business_name = sellerDetails.value.business_name || '';
    formData.line_of_business = sellerDetails.value.line_of_business || '';
  }
}

function resetForm() {
  populateFormFromState();
  Object.keys(errors).forEach(k => (errors[k] = ''));
}

// ---------- PSGC address lookups (mirrors resources/js/app.js signup form) ----------
function dedupeByCodeOrName(items = []) {
  const seen = new Map();
  for (const item of items) {
    if (!item || typeof item !== 'object') continue;
    const code = String(item.code ?? '').trim();
    const name = String(item.name ?? '').trim();
    if (!code && !name) continue;
    const key = code || name.toLowerCase().replace(/\s+/g, ' ');
    if (!seen.has(key)) seen.set(key, item);
  }
  return Array.from(seen.values()).sort((a, b) => a.name.localeCompare(b.name));
}

async function fetchProvinces() {
  if (provinceCache.value.length > 0) {
    provinceOptions.value = provinceCache.value;
    return;
  }
  loadingProvinces.value = true;
  addressApiError.value = '';
  try {
    const regionsRes = await fetch(`${PSGC_BASE}/regions?limit=100`);
    if (!regionsRes.ok) throw new Error('Request failed: ' + regionsRes.status);
    const regionsJson = await regionsRes.json();
    const regions = regionsJson.data || [];

    const provinceResults = await Promise.all(
      regions.map(async (r) => {
        try {
          const res = await fetch(`${PSGC_BASE}/provinces?region_code=${r.code}`);
          if (!res.ok) return [];
          const json = await res.json();
          return json.data || [];
        } catch {
          return [];
        }
      })
    );

    const allProvinces = dedupeByCodeOrName(provinceResults.flat());
    if (allProvinces.length === 0) throw new Error('No provinces returned');

    provinceCache.value = allProvinces;
    provinceOptions.value = allProvinces;

    // Preserve the saved province if it isn't in the freshly fetched list yet
    // (e.g. slightly different casing) so the select doesn't blank out.
    if (formData.province_code && !allProvinces.some(p => p.code === formData.province_code)) {
      provinceOptions.value = [{ code: formData.province_code, name: formData.province_name }, ...allProvinces];
    }
  } catch {
    addressApiError.value = 'Could not load provinces from the PSGC API. Check your connection and retry.';
  } finally {
    loadingProvinces.value = false;
  }
}

async function fetchMunicipalities(provinceCode, { preserveSelection = false } = {}) {
  if (!preserveSelection) {
    municipalityOptions.value = [];
    barangayOptions.value = [];
    formData.municipality_code = '';
    formData.municipality_name = '';
    formData.barangay = '';
  }
  if (!provinceCode) return;

  loadingMunicipalities.value = true;
  addressApiError.value = '';
  try {
    const res = await fetch(`${PSGC_BASE}/cities-municipalities?province_code=${provinceCode}`);
    if (!res.ok) throw new Error('Request failed: ' + res.status);
    const json = await res.json();
    const data = (json.data || []).slice().sort((a, b) => a.name.localeCompare(b.name));
    if (preserveSelection && formData.municipality_code && !data.some(m => m.code === formData.municipality_code)) {
      municipalityOptions.value = [{ code: formData.municipality_code, name: formData.municipality_name }, ...data];
    } else {
      municipalityOptions.value = data;
    }
  } catch {
    addressApiError.value = 'Could not load cities/municipalities. Please try again.';
  } finally {
    loadingMunicipalities.value = false;
  }
}

async function fetchBarangays(municipalityCode, { preserveSelection = false } = {}) {
  if (!preserveSelection) {
    barangayOptions.value = [];
    formData.barangay = '';
  }
  if (!municipalityCode) return;

  loadingBarangays.value = true;
  addressApiError.value = '';
  try {
    const res = await fetch(`${PSGC_BASE}/barangays?city_municipality_code=${municipalityCode}&limit=500`);
    if (!res.ok) throw new Error('Request failed: ' + res.status);
    const json = await res.json();
    const data = (json.data || []).slice().sort((a, b) => a.name.localeCompare(b.name));
    if (preserveSelection && formData.barangay && !data.some(b => b.name === formData.barangay)) {
      barangayOptions.value = [{ code: 'current', name: formData.barangay }, ...data];
    } else {
      barangayOptions.value = data;
    }
  } catch {
    addressApiError.value = 'Could not load barangays. Please try again.';
  } finally {
    loadingBarangays.value = false;
  }
}

function onProvinceChange() {
  const selected = provinceOptions.value.find(p => p.code === formData.province_code);
  formData.province_name = selected?.name || '';
  fetchMunicipalities(formData.province_code);
}

function onMunicipalityChange() {
  const selected = municipalityOptions.value.find(m => m.code === formData.municipality_code);
  formData.municipality_name = selected?.name || '';
  fetchBarangays(formData.municipality_code);
}

// ---------- Input formatting / validation (mirrors resources/js/app.js) ----------
function onNameInput(field) {
  formData[field] = formData[field].replace(/[^A-Za-z\s-]/g, '');
  errors[field] = formData[field].trim() ? '' : 'This field is required';
}
function onMiddleInitialInput() {
  formData.middle_initial = formData.middle_initial.replace(/[^A-Za-z]/g, '').toUpperCase().slice(0, 1);
}
function onContactInput() {
  formData.contact_no = formData.contact_no.replace(/\D/g, '').slice(0, 11);
}

function validate() {
  let valid = true;
  errors.last_name = formData.last_name.trim() ? '' : (valid = false, 'Last name is required');
  errors.first_name = formData.first_name.trim() ? '' : (valid = false, 'First name is required');

  if (!formData.birthday) {
    errors.birthday = 'Birthday is required';
    valid = false;
  } else if (new Date(formData.birthday) > new Date()) {
    errors.birthday = 'Cannot select a future date';
    valid = false;
  } else {
    errors.birthday = '';
  }

  if (!/^09\d{9}$/.test(formData.contact_no)) {
    errors.contact_no = 'Enter a valid 11-digit number starting with 09';
    valid = false;
  } else {
    errors.contact_no = '';
  }

  return valid;
}

async function handleSave() {
  if (!validate()) return;
  await saveProfile({ ...formData });
}

onMounted(async () => {
  populateFormFromState();
  await fetchProvinces();
  if (formData.province_code) {
    await fetchMunicipalities(formData.province_code, { preserveSelection: true });
  }
  if (formData.municipality_code) {
    await fetchBarangays(formData.municipality_code, { preserveSelection: true });
  }
});
</script>