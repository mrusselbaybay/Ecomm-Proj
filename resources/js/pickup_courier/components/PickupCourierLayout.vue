<!-- resources/js/pickup_courier/components/PickupCourierLayout.vue -->
<template>
  <div class="pickup-courier-app">
    <!-- Loading State -->
    <div v-if="isLoading" class="min-h-screen flex items-center justify-center">
      <div class="text-center">
        <div class="loading-spinner mx-auto mb-4"></div>
        <p class="text-slate-500">Loading...</p>
      </div>
    </div>
    
    <!-- Main Content -->
    <div v-else-if="isAuthenticated" class="container mx-auto px-4 py-8">
      <!-- Header -->
      <div class="flex items-center justify-between mb-6 gap-3 flex-wrap">
        <div>
          <h1 class="text-2xl font-bold">Find Work</h1>
          <p class="text-sm text-gray-500">Browse and apply to logistics companies</p>
        </div>
        <div class="flex items-center gap-3">
          <button
            @click="showApplicationsModal = true"
            class="relative inline-flex items-center gap-2 bg-white border border-gray-200 hover:border-teal-400 text-gray-700 text-sm font-medium px-3 py-2 rounded-xl shadow-sm transition"
          >
            <svg class="w-4 h-4 text-teal-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
            </svg>
            My Applications
            <span v-if="applications.length > 0" class="bg-teal-600 text-white text-xs font-bold rounded-full min-w-[20px] h-5 px-1 flex items-center justify-center">{{ applications.length }}</span>
          </button>
          <span class="text-sm text-gray-600 hidden sm:inline">{{ user?.email }}</span>
          <button @click="logout" class="text-sm text-red-600 hover:underline">Logout</button>
        </div>
      </div>

      <!-- Available companies -->
      <h2 class="text-lg font-semibold mb-4">Available logistics companies</h2>
      
      <!-- Filters -->
      <div class="flex gap-4 mb-4 flex-wrap">
        <div class="flex-1 min-w-[200px]">
          <input 
            type="text" 
            v-model="search" 
            placeholder="Search company or email..." 
            class="field-input w-full"
            @input="debouncedLoad"
          />
        </div>
        <div>
          <select v-model="selectedRegion" class="field-input" @change="loadData">
            <option value="All Regions">All Regions</option>
            <option v-for="region in regions" :key="region" :value="region">{{ region }}</option>
          </select>
        </div>
        <button @click="loadData" class="btn-primary">Apply Filters</button>
      </div>

      <!-- Companies list -->
      <div v-if="loading" class="text-center py-8">
        <div class="loading-spinner"></div>
        <p class="text-gray-500 mt-2">Loading companies...</p>
      </div>
      <div v-else-if="companies.length === 0" class="text-center py-8 text-gray-500">
        No logistics companies match your filters.
      </div>
      <div v-else-if="visibleCompanies.length === 0" class="text-center py-8 text-gray-500">
        You've already applied to every company matching your filters.
        <button @click="showApplicationsModal = true" class="text-teal-600 hover:underline font-medium">View My Applications</button>
      </div>
      <div v-else v-for="company in visibleCompanies" :key="company.id" class="border rounded-xl p-4 mb-3 bg-white shadow-sm hover:shadow-md transition-shadow cursor-pointer" @click="openApplyModal(company)">
        <div class="flex items-start gap-4">
          <div class="w-12 h-12 rounded-2xl flex items-center justify-center text-white font-bold text-xl" :style="{ background: 'linear-gradient(135deg, #0d9488, #5fc9bc)' }">
            {{ getInitials(company.company_name) }}
          </div>
          <div class="flex-1">
            <h3 class="font-semibold text-lg">{{ company.company_name }}</h3>
            <p class="text-sm text-gray-500">{{ company.role || 'Logistics Company' }}</p>
            <div class="flex flex-wrap gap-2 mt-2">
              <span class="text-xs bg-teal-50 text-teal-700 px-3 py-1 rounded-full flex items-center gap-1">
                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/>
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/>
                </svg>
                {{ company.region || 'No region' }}
              </span>
              <span class="text-xs bg-teal-50 text-teal-700 px-3 py-1 rounded-full flex items-center gap-1">
                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 13.255A23.931 23.931 0 0112 15c-3.183 0-6.22-.62-9-1.745M16 6V4a2 2 0 00-2-2h-4a2 2 0 00-2 2v2m4 6h.01M5 20h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                </svg>
                {{ company.employment_type || 'Logistics' }}
              </span>
              <span class="text-xs bg-teal-50 text-teal-700 px-3 py-1 rounded-full flex items-center gap-1">
                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v1m0 1V9m0 1v1m0 1v1m0 1V19m-2-3h4"/>
                </svg>
                {{ company.salary_range || 'Contact for details' }}
              </span>
            </div>
          </div>
          <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
          </svg>
        </div>
      </div>
    </div>

    <!-- APPLY MODAL -->
    <div v-if="showApplyModal" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4" @click.self="closeApplyModal">
      <div class="bg-white rounded-2xl max-w-2xl w-full max-h-[90vh] overflow-y-auto">
        <!-- Modal Header -->
        <div class="sticky top-0 bg-white border-b border-gray-100 px-6 py-4 rounded-t-2xl">
          <div class="flex items-center justify-between">
            <div class="flex items-center gap-3">
              <div class="w-10 h-10 rounded-xl flex items-center justify-center text-white font-bold" :style="{ background: 'linear-gradient(135deg, #0d9488, #5fc9bc)' }">
                {{ selectedCompany ? getInitials(selectedCompany.company_name) : '?' }}
              </div>
              <div>
                <h3 class="font-bold text-lg">{{ selectedCompany?.company_name || 'Company' }}</h3>
                <p class="text-sm text-gray-500">{{ selectedCompany?.role || 'Logistics Company' }}</p>
              </div>
            </div>
            <button @click="closeApplyModal" class="text-gray-400 hover:text-gray-600">
              <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
              </svg>
            </button>
          </div>
        </div>

        <!-- Modal Body -->
        <div class="p-6 space-y-6">
          <!-- Company Details -->
          <div class="bg-gray-50 rounded-xl p-4">
            <h4 class="font-semibold text-sm text-gray-700 mb-3 flex items-center gap-2">
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
              </svg>
              About this role
            </h4>
            <p class="text-sm text-gray-600 leading-relaxed">{{ selectedCompany?.description || 'No description available.' }}</p>
          </div>

          <!-- Key Details -->
          <div class="bg-gray-50 rounded-xl p-4">
            <h4 class="font-semibold text-sm text-gray-700 mb-3 flex items-center gap-2">
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zm10 0a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zm10 0a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"/>
              </svg>
              Key details
            </h4>
            <div class="grid grid-cols-2 gap-3">
              <div class="bg-white rounded-lg p-3">
                <p class="text-xs text-gray-400 font-medium">Industry</p>
                <p class="text-sm font-semibold">{{ selectedCompany?.industry || 'Logistics' }}</p>
              </div>
              <div class="bg-white rounded-lg p-3">
                <p class="text-xs text-gray-400 font-medium">Region</p>
                <p class="text-sm font-semibold">{{ selectedCompany?.region || 'N/A' }}</p>
              </div>
              <div class="bg-white rounded-lg p-3">
                <p class="text-xs text-gray-400 font-medium">Email</p>
                <p class="text-sm font-semibold truncate">{{ selectedCompany?.company_email || 'N/A' }}</p>
              </div>
              <div class="bg-white rounded-lg p-3">
                <p class="text-xs text-gray-400 font-medium">Contact</p>
                <p class="text-sm font-semibold">{{ selectedCompany?.company_contact_no || 'N/A' }}</p>
              </div>
            </div>
          </div>

          <!-- Apply Form (only if not applied) -->
          <div v-if="selectedCompany && !isCompanyApplied(selectedCompany.id)" class="space-y-4">
            <!-- Resume Upload -->
            <div>
              <label class="block text-sm font-semibold text-gray-700 mb-2">
                Resume <span class="text-red-500">*</span>
              </label>
              <div @click="$refs.resumeInput.click()" class="border-2 border-dashed rounded-xl p-6 text-center cursor-pointer hover:border-teal-500 transition-colors" :class="resumeFile ? 'border-teal-500 bg-teal-50' : 'border-gray-300'">
                <input type="file" ref="resumeInput" class="hidden" accept=".pdf,.doc,.docx" @change="handleResumeUpload">
                <div v-if="!resumeFile">
                  <svg class="w-10 h-10 mx-auto text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/>
                  </svg>
                  <p class="mt-2 text-sm text-gray-600">Click to upload your resume</p>
                  <p class="text-xs text-gray-400">PDF, DOC or DOCX · Required to apply</p>
                </div>
                <div v-else class="flex items-center justify-between">
                  <div class="flex items-center gap-3">
                    <svg class="w-8 h-8 text-teal-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                    </svg>
                    <div class="text-left">
                      <p class="text-sm font-medium text-gray-700">{{ resumeFile.name }}</p>
                      <p class="text-xs text-gray-400">{{ formatFileSize(resumeFile.size) }} · Tap to replace</p>
                    </div>
                  </div>
                  <button @click.stop="resumeFile = null" class="text-gray-400 hover:text-red-500">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                  </button>
                </div>
              </div>
            </div>

            <!-- Cover Note -->
            <div>
              <label class="block text-sm font-semibold text-gray-700 mb-2">
                Cover note <span class="text-gray-400">(optional)</span>
              </label>
              <textarea 
                v-model="coverNote" 
                rows="4"
                class="w-full border border-gray-300 rounded-xl px-4 py-3 focus:border-teal-500 focus:ring-2 focus:ring-teal-200 outline-none transition"
                placeholder="Tell the hiring team briefly why you're a good fit..."
              ></textarea>
              <p class="text-xs text-gray-400 mt-1">Maximum 2000 characters</p>
            </div>
          </div>
        </div>

        <!-- Modal Footer -->
        <div class="sticky bottom-0 bg-white border-t border-gray-100 px-6 py-4 rounded-b-2xl">
          <button 
            v-if="selectedCompany && !isCompanyApplied(selectedCompany.id)"
            @click="submitApplication" 
            class="w-full bg-teal-600 hover:bg-teal-700 text-white font-bold py-3 rounded-xl transition disabled:opacity-50 disabled:cursor-not-allowed flex items-center justify-center gap-2"
            :disabled="!resumeFile || submitting"
          >
            <svg v-if="!submitting" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/>
            </svg>
            <svg v-else class="w-5 h-5 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
              <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
            </svg>
            {{ submitting ? 'Submitting...' : !resumeFile ? 'Attach Resume to Apply' : 'Submit Application' }}
          </button>
          <button v-else @click="closeApplyModal" class="w-full bg-gray-100 hover:bg-gray-200 text-gray-700 font-bold py-3 rounded-xl transition">
            Close
          </button>
        </div>
      </div>
    </div>

    <!-- MY APPLICATIONS MODAL -->
    <div v-if="showApplicationsModal" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4" @click.self="showApplicationsModal = false">
      <div class="bg-white rounded-2xl max-w-2xl w-full max-h-[90vh] overflow-y-auto">
        <!-- Modal Header -->
        <div class="sticky top-0 bg-white border-b border-gray-100 px-6 py-4 rounded-t-2xl flex items-center justify-between">
          <div>
            <h3 class="font-bold text-lg">My Applications</h3>
            <p class="text-sm text-gray-500">Companies you've applied to</p>
          </div>
          <button @click="showApplicationsModal = false" class="text-gray-400 hover:text-gray-600">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
            </svg>
          </button>
        </div>

        <!-- Modal Body -->
        <div class="p-6">
          <div v-if="applications.length === 0" class="text-center py-10 text-gray-500">
            You haven't applied to any companies yet.
          </div>
          <template v-else>
            <!-- Application Summary Cards -->
            <div class="grid grid-cols-2 gap-4 mb-6">
              <div class="bg-amber-500 rounded-2xl p-4 text-white shadow-lg">
                <div class="flex items-center justify-between">
                  <div class="bg-white/20 rounded-xl p-2">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                  </div>
                  <span class="text-2xl font-bold">{{ pendingCount }}</span>
                </div>
                <p class="font-semibold mt-2">Pending</p>
                <p class="text-sm text-white/80">{{ pendingCount }} application{{ pendingCount !== 1 ? 's' : '' }} waiting</p>
              </div>
              <div class="bg-red-500 rounded-2xl p-4 text-white shadow-lg">
                <div class="flex items-center justify-between">
                  <div class="bg-white/20 rounded-xl p-2">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                  </div>
                  <span class="text-2xl font-bold">{{ rejectedCount }}</span>
                </div>
                <p class="font-semibold mt-2">Rejected</p>
                <p class="text-sm text-white/80">{{ rejectedCount }} application{{ rejectedCount !== 1 ? 's' : '' }} declined</p>
              </div>
            </div>

            <!-- Applications list -->
            <div v-for="app in applications" :key="app.id" class="border rounded-xl p-4 mb-3 bg-white shadow-sm">
              <div class="flex items-start gap-4">
                <div class="w-11 h-11 rounded-xl flex items-center justify-center" :class="statusBgClass(app.status)">
                  <svg class="w-5 h-5" :class="statusIconClass(app.status)" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path v-if="app.status === 'pending'" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    <path v-else-if="app.status === 'accepted'" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    <path v-else-if="app.status === 'rejected'" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                  </svg>
                </div>
                <div class="flex-1">
                  <div class="flex items-center justify-between gap-2">
                    <h3 class="font-medium">{{ app.company?.company_name }}</h3>
                    <span class="text-xs px-3 py-1 rounded-full whitespace-nowrap" :class="isInterviewing(app) ? 'bg-indigo-100 text-indigo-700' : statusBadgeClass(app.status)">
                      {{ isInterviewing(app) ? 'Interviewing' : app.status }}
                    </span>
                  </div>
                  <p class="text-sm text-gray-500">{{ app.company?.role || 'Logistics Company' }}</p>
                  <div class="flex items-center gap-3 mt-1 text-xs text-gray-400">
                    <span class="flex items-center gap-1">
                      <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                      </svg>
                      {{ formatDate(app.applied_at) }}
                    </span>
                    <span class="flex items-center gap-1">
                      <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/>
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/>
                      </svg>
                      {{ app.company?.region || 'No region' }}
                    </span>
                  </div>
                  <div v-if="isInterviewing(app)" class="text-xs text-indigo-600 mt-2">
                    <p v-if="app.interview_scheduled_at" class="font-semibold">Interview: {{ formatInterviewTime(app.interview_scheduled_at) }}</p>
                    <p>This company invited you to interview. Your application is still pending their final decision.</p>
                  </div>
                  <div class="flex items-center gap-3 mt-2">
                    <button
                      v-if="app.resume_original_name"
                      @click="viewMyResume(app)"
                      :disabled="resumeLoadingId === app.id"
                      class="text-xs text-teal-600 hover:text-teal-800 font-medium disabled:opacity-50"
                    >
                      {{ resumeLoadingId === app.id ? 'Opening…' : 'View my resume' }}
                    </button>
                    <button v-if="app.status === 'pending'" @click="withdrawApplication(app)" class="text-xs text-red-500 hover:text-red-700 font-medium">
                      Withdraw
                    </button>
                  </div>
                </div>
              </div>
            </div>
          </template>
        </div>
      </div>
    </div>

    <!-- Not Authenticated -->
    <div v-if="!isLoading && !isAuthenticated" class="min-h-screen flex items-center justify-center">
      <div class="text-center">
        <h1 class="text-2xl font-bold text-red-600 mb-2">Access Denied</h1>
        <p class="text-gray-500 mb-4">Please log in to access this page.</p>
        <a href="/login" class="text-teal-600 hover:underline">Go to Login</a>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';

const SUPABASE_URL = import.meta.env.VITE_SUPABASE_URL;
const SUPABASE_ANON_KEY = import.meta.env.VITE_SUPABASE_ANON_KEY;
const supabase = window.supabase.createClient(SUPABASE_URL, SUPABASE_ANON_KEY);

const isLoading = ref(true);
const isAuthenticated = ref(false);
const user = ref(null);
const applications = ref([]);
const companies = ref([]);
const regions = ref([]);
const appliedCompanyIds = ref([]);
const search = ref('');
const selectedRegion = ref('All Regions');
const loading = ref(false);
const showApplyModal = ref(false);
const showApplicationsModal = ref(false);
const selectedCompany = ref(null);
const selectedCompanyId = ref(null);
const resumeFile = ref(null);
const coverNote = ref('');
const submitting = ref(false);
const withdrawing = ref(false);
const resumeLoadingId = ref(null);

// Computed
const pendingCount = computed(() => applications.value.filter(a => a.status === 'pending').length);
const rejectedCount = computed(() => applications.value.filter(a => a.status === 'rejected').length);
// Companies the courier hasn't applied to (or already withdrew from) — the
// only ones offered on the main browse page. Applied-to companies live in
// the "My Applications" modal instead.
const visibleCompanies = computed(() => companies.value.filter(c => !isCompanyApplied(c.id)));

// ✅ Helper functions to check application status
function isCompanyApplied(companyId) {
  if (!companyId) return false;
  return appliedCompanyIds.value.includes(companyId);
}

// True while status is still 'pending' but the company has invited the
// courier to interview (status itself never changes for this step).
function isInterviewing(app) {
  return app?.status === 'pending' && !!app?.interview_invited_at;
}

// Formats interview_scheduled_at as the exact digits the logistics staff
// picked, with no timezone conversion (it's a "floating" local time, not a
// real UTC instant) — mirrors formatInterviewTime() in the logistics
// dashboard's Applications.vue.
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

let searchTimeout = null;

function debouncedLoad() {
  clearTimeout(searchTimeout);
  searchTimeout = setTimeout(() => {
    loadData();
  }, 500);
}

function getInitials(name) {
  if (!name) return '?';
  return name.charAt(0).toUpperCase();
}

function formatFileSize(bytes) {
  const kb = bytes / 1024;
  if (kb < 1024) return `${Math.round(kb)} KB`;
  return `${(kb / 1024).toFixed(1)} MB`;
}

function formatDate(dateStr) {
  if (!dateStr) return '';
  const date = new Date(dateStr);
  const now = new Date();
  const diffDays = Math.floor((now - date) / (1000 * 60 * 60 * 24));
  if (diffDays === 0) return 'Today';
  if (diffDays === 1) return 'Yesterday';
  return `${diffDays} days ago`;
}

function statusBadgeClass(status) {
  const classes = {
    'pending': 'bg-amber-100 text-amber-700',
    'accepted': 'bg-green-100 text-green-700',
    'rejected': 'bg-red-100 text-red-700',
    'withdrawn': 'bg-gray-100 text-gray-700'
  };
  return classes[status] || 'bg-gray-100 text-gray-700';
}

function statusBgClass(status) {
  const classes = {
    'pending': 'bg-amber-100',
    'accepted': 'bg-green-100',
    'rejected': 'bg-red-100',
    'withdrawn': 'bg-gray-100'
  };
  return classes[status] || 'bg-gray-100';
}

function statusIconClass(status) {
  const classes = {
    'pending': 'text-amber-600',
    'accepted': 'text-green-600',
    'rejected': 'text-red-600',
    'withdrawn': 'text-gray-600'
  };
  return classes[status] || 'text-gray-600';
}

async function checkAuth() {
  isLoading.value = true;
  try {
    const { data: { user: authUser }, error } = await supabase.auth.getUser();
    if (error || !authUser) {
      isAuthenticated.value = false;
      isLoading.value = false;
      return;
    }

    const { data: profile, error: profileError } = await supabase
      .from('profiles')
      .select('role, first_name, last_name, email, account_status, status, id')
      .eq('id', authUser.id)
      .single();

    if (profileError || !profile) {
      isAuthenticated.value = false;
      isLoading.value = false;
      return;
    }

    if (profile.role !== 'courier') {
      isAuthenticated.value = false;
      isLoading.value = false;
      return;
    }

    if (profile.account_status === 'suspended' || profile.account_status === 'deactivated') {
      isAuthenticated.value = false;
      isLoading.value = false;
      return;
    }

    user.value = { ...authUser, profile };
    isAuthenticated.value = true;
    await loadData();
  } catch (error) {
    console.error('Auth error:', error);
    isAuthenticated.value = false;
  } finally {
    isLoading.value = false;
  }
}

async function loadData() {
  if (!user.value) return;
  loading.value = true;
  
  try {
    const params = new URLSearchParams();
    if (selectedRegion.value !== 'All Regions') {
      params.append('region', selectedRegion.value);
    }
    if (search.value) {
      params.append('search', search.value);
    }
    // The backend has no Laravel session for this Supabase-only app, so the
    // courier's id has to be passed explicitly (same as the apply/withdraw
    // calls already do) — without it, appliedCompanyIds always comes back
    // empty and nothing gets filtered off the main list.
    if (user.value?.id) {
      params.append('user_id', user.value.id);
    }

    const response = await fetch(`/pickup-courier/companies?${params.toString()}`, {
      headers: {
        'Accept': 'application/json',
      }
    });

    if (!response.ok) {
      throw new Error('Failed to fetch data');
    }

    const data = await response.json();
    
    companies.value = data.companies || [];
    regions.value = data.regions || [];
    applications.value = data.applications || [];
    appliedCompanyIds.value = data.appliedCompanyIds || [];

  } catch (error) {
    console.error('Error loading data:', error);
    await loadDataDirect();
  } finally {
    loading.value = false;
  }
}

async function loadDataDirect() {
  try {
    const userId = user.value.id;
    
    let query = supabase
      .from('logistics_companies')
      .select('*')
      .eq('account_status', 'active')
      .order('company_name');

    if (selectedRegion.value !== 'All Regions') {
      query = query.eq('region', selectedRegion.value);
    }
    if (search.value) {
      query = query.or(`company_name.ilike.%${search.value}%,company_email.ilike.%${search.value}%`);
    }

    const { data: companiesData } = await query;
    companies.value = companiesData || [];

    const { data: regionsData } = await supabase
      .from('logistics_companies')
      .select('region')
      .not('region', 'is', null)
      .neq('region', '')
      .order('region');

    const uniqueRegions = [...new Set(regionsData?.map(r => r.region) || [])];
    regions.value = uniqueRegions;

    const { data: appsData } = await supabase
      .from('courier_applications')
      .select('*, company:logistics_companies(*)')
      .eq('courier_profile_id', userId)
      .neq('status', 'withdrawn')
      .order('applied_at', { ascending: false });

    applications.value = appsData || [];
    appliedCompanyIds.value = applications.value.map(a => a.logistics_company_id);

  } catch (error) {
    console.error('Error loading data directly:', error);
  }
}

function openApplyModal(company) {
  selectedCompany.value = company;
  selectedCompanyId.value = company.id;
  resumeFile.value = null;
  coverNote.value = '';
  showApplyModal.value = true;
}

function closeApplyModal() {
  showApplyModal.value = false;
  selectedCompany.value = null;
  selectedCompanyId.value = null;
  resumeFile.value = null;
  coverNote.value = '';
}

function handleResumeUpload(event) {
  const file = event.target.files[0];
  if (file) {
    const validTypes = ['application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
    if (!validTypes.includes(file.type)) {
      alert('Please upload a PDF, DOC, or DOCX file.');
      return;
    }
    if (file.size > 5 * 1024 * 1024) {
      alert('File size must be less than 5MB.');
      return;
    }
    resumeFile.value = file;
  }
}

async function submitApplication() {
  if (!selectedCompanyId.value) {
    alert('Company information is missing. Please try again.');
    return;
  }

  // ✅ Check if already applied before submitting
  if (isCompanyApplied(selectedCompanyId.value)) {
    alert('You have already applied to this company. Withdraw your current application first.');
    return;
  }

  if (!resumeFile.value) {
    alert('Please attach your resume before applying.');
    return;
  }

  if (submitting.value) return;

  submitting.value = true;
  
  try {
    const formData = new FormData();
    formData.append('resume', resumeFile.value);
    formData.append('cover_note', coverNote.value || '');
    
    if (user.value && user.value.id) {
      formData.append('user_id', user.value.id);
    } else {
      alert('Please log in again. User ID not found.');
      submitting.value = false;
      return;
    }

    const response = await fetch(`/pickup-courier/applications/${selectedCompanyId.value}/apply`, {
      method: 'POST',
      headers: {
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
        'Accept': 'application/json',
      },
      body: formData
    });

    if (!response.ok) {
      const error = await response.json();
      throw new Error(error.error || 'Failed to submit application');
    }

    const data = await response.json();
    console.log('Success response:', data);
    
    await loadData();
    closeApplyModal();
    
    const companyName = selectedCompany.value?.company_name || 'the company';
    alert(`Application sent to ${companyName}. Status: Pending.`);
    
  } catch (error) {
    console.error('Error submitting application:', error);
    alert(error.message || 'Failed to submit application. Please try again.');
  } finally {
    submitting.value = false;
  }
}

async function withdrawApplication(app) {
  if (!app) {
    alert('No application found to withdraw.');
    return;
  }
  
  if (!confirm(`Withdraw your application to ${app.company?.company_name || 'this company'}?`)) {
    return;
  }
  
  withdrawing.value = true;
  
  try {
    const formData = new FormData();
    formData.append('user_id', user.value.id);

    const response = await fetch(`/pickup-courier/applications/${app.id}/withdraw`, {
      method: 'POST',
      headers: {
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
        'Accept': 'application/json',
      },
      body: formData
    });

    if (!response.ok) {
      const error = await response.json();
      throw new Error(error.error || 'Failed to withdraw application');
    }

    // Refresh data (the My Applications modal stays open so the courier
    // can see the updated list / withdraw from another company).
    await loadData();
    
    alert('Application withdrawn successfully.');
  } catch (error) {
    console.error('Error withdrawing application:', error);
    alert(error.message || 'Failed to withdraw application.');
  } finally {
    withdrawing.value = false;
  }
}

async function viewMyResume(app) {
  if (!user.value?.id) return;
  resumeLoadingId.value = app.id;
  try {
    const params = new URLSearchParams({ user_id: user.value.id });
    const response = await fetch(`/pickup-courier/applications/${app.id}/resume?${params.toString()}`, {
      headers: { 'Accept': 'application/json' },
    });
    const payload = await response.json();
    if (!response.ok) throw new Error(payload.error || 'Failed to load your resume.');
    window.open(payload.url, '_blank', 'noopener');
  } catch (error) {
    console.error('Error opening resume:', error);
    alert(error.message || 'Failed to open your resume.');
  } finally {
    resumeLoadingId.value = null;
  }
}

async function logout() {
  await supabase.auth.signOut();
  window.location.href = '/';
}

onMounted(() => {
  checkAuth();
});
</script>

<style scoped>
.loading-spinner {
  border: 3px solid #f3f3f3;
  border-top: 3px solid #0d9488;
  border-radius: 50%;
  width: 40px;
  height: 40px;
  animation: spin 1s linear infinite;
  margin: 0 auto;
}
@keyframes spin {
  0% { transform: rotate(0deg); }
  100% { transform: rotate(360deg); }
}

.field-input {
  padding: 8px 12px;
  border: 1px solid #d1d5db;
  border-radius: 8px;
  font-size: 14px;
  background: white;
}
.field-input:focus {
  outline: none;
  border-color: #0d9488;
  box-shadow: 0 0 0 3px rgba(13, 148, 136, 0.1);
}

.btn-primary {
  background: #0d9488;
  color: white;
  padding: 8px 20px;
  border: none;
  border-radius: 6px;
  cursor: pointer;
  font-weight: 600;
  transition: background 0.2s;
}
.btn-primary:hover {
  background: #0f766e;
}

.container {
  max-width: 800px;
}
</style>