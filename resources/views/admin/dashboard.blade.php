<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>NEXMART — Admin</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://unpkg.com/vue@3/dist/vue.global.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/@supabase/supabase-js@2"></script>
  
  <style>
    @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Playfair+Display:wght@700;800&display=swap');

    body { font-family: 'Inter', system-ui, sans-serif; background: #fafafc; margin: 0; }
    .display-font { font-family: 'Playfair Display', serif; }

    .side-panel {
      background-color: #0f1420;
      background-image:
        radial-gradient(circle at 15% 25%, rgba(234,88,12,0.12) 0, transparent 45%),
        radial-gradient(circle at 85% 80%, rgba(234,88,12,0.10) 0, transparent 45%),
        radial-gradient(circle, rgba(255,255,255,0.05) 1px, transparent 1px);
      background-size: auto, auto, 22px 22px;
    }

    .btn-gradient { background: linear-gradient(90deg, #ea580c, #f59e0b); }
    .btn-gradient:hover { filter: brightness(1.05); }

    .field-input {
      width: 100%;
      padding: 0.5rem 0.7rem;
      font-size: 0.85rem;
      border: 1px solid #d1d5db;
      border-radius: 0.375rem;
      background: white;
      transition: border-color 0.15s ease, box-shadow 0.15s ease;
    }
    .field-input:focus {
      outline: none;
      border-color: #ea580c;
      box-shadow: 0 0 0 3px rgba(234,88,12,0.12);
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

    .card {
      background: white;
      border: 1px solid #e5e7eb;
      border-radius: 0.75rem;
    }

    .stat-card {
      background: white;
      border: 1px solid #e5e7eb;
      border-radius: 0.75rem;
      padding: 1rem 1.15rem;
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
    .badge-green  { background: #dcfce7; color: #15803d; }
    .badge-amber  { background: #fef3c7; color: #b45309; }
    .badge-red    { background: #fee2e2; color: #b91c1c; }
    .badge-slate  { background: #f1f5f9; color: #475569; }

    .btn-outline {
      border: 1px solid #d1d5db;
      color: #334155;
      font-weight: 600;
      font-size: 0.75rem;
      padding: 0.35rem 0.75rem;
      border-radius: 0.4rem;
      transition: background 0.15s ease;
    }
    .btn-outline:hover { background: #f8fafc; }

    .btn-sm-gradient {
      background: linear-gradient(90deg, #ea580c, #f59e0b);
      color: white;
      font-weight: 600;
      font-size: 0.75rem;
      padding: 0.35rem 0.75rem;
      border-radius: 0.4rem;
    }
    .btn-sm-gradient:hover { filter: brightness(1.05); }

    .btn-danger-outline {
      border: 1px solid #fecaca;
      color: #b91c1c;
      font-weight: 600;
      font-size: 0.75rem;
      padding: 0.35rem 0.75rem;
      border-radius: 0.4rem;
      background: white;
    }
    .btn-danger-outline:hover { background: #fef2f2; }

    table.admin-table { width: 100%; border-collapse: collapse; font-size: 0.82rem; }
    table.admin-table th {
      text-align: left;
      font-size: 0.65rem;
      font-weight: 700;
      color: #64748b;
      letter-spacing: 0.05em;
      text-transform: uppercase;
      padding: 0.6rem 0.75rem;
      border-bottom: 1px solid #e5e7eb;
    }
    table.admin-table td {
      padding: 0.65rem 0.75rem;
      border-bottom: 1px solid #f1f5f9;
      color: #334155;
    }
    table.admin-table tr:hover td { background: #fafafa; }

    .section-title { font-family: 'Playfair Display', serif; font-weight: 800; }

    .fade-enter-active, .fade-leave-active { transition: opacity 0.12s ease; }
    .fade-enter-from, .fade-leave-to { opacity: 0; }

    .loading-spinner {
      border: 3px solid #f3f3f3;
      border-top: 3px solid #ea580c;
      border-radius: 50%;
      width: 40px;
      height: 40px;
      animation: spin 1s linear infinite;
    }
    @keyframes spin {
      0% { transform: rotate(0deg); }
      100% { transform: rotate(360deg); }
    }
  </style>
</head>
<body>
  <div id="app">
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
      <aside class="side-panel w-64 flex-shrink-0 text-white flex flex-col justify-between px-4 py-6" style="height:100vh;overflow-y:auto;">
        <div>
          <div class="flex items-center gap-3 mb-8 px-2">
            <div class="w-9 h-9 rounded-xl bg-gradient-to-br from-orange-500 to-amber-500 flex items-center justify-center">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4Z"/><path d="M3 6h18"/><path d="M16 10a4 4 0 0 1-8 0"/></svg>
            </div>
            <div>
              <p class="text-sm font-bold tracking-wide leading-tight">NEXMART</p>
              <p class="text-[0.65rem] text-orange-400 font-bold tracking-widest uppercase leading-tight">Admin Panel</p>
            </div>
          </div>

          <nav class="space-y-1">
            <div v-for="item in navItems" :key="item.id" @click="selectSection(item.id)"
                 class="sidebar-link" :class="{ active: currentSection === item.id }">
              <span class="icon-wrap" v-html="getIcon(item.icon)"></span>
              <span>@{{ item.label }}</span>
            </div>
          </nav>
        </div>

        <div>
          <div class="border-t border-white/10 pt-3 mb-2">
            <div class="sidebar-link" @click="requestLogout">
              <span class="icon-wrap">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
              </span>
              <span>Logout</span>
            </div>
          </div>
          <div class="flex items-center gap-2 px-2">
            <div class="w-8 h-8 rounded-full bg-orange-500/20 text-orange-400 flex items-center justify-center text-xs font-bold">
              @{{ adminInitials }}
            </div>
            <div class="leading-tight">
              <p class="text-xs font-semibold text-white">@{{ adminProfile.name }}</p>
              <p class="text-[0.65rem] text-slate-400">@{{ adminProfile.role }}</p>
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
              <h1 class="section-title text-3xl text-slate-900">@{{ sectionLabel }}</h1>
            </div>
          </div>

          <!-- VIEW DASHBOARD -->
          <div v-if="currentSection === 'dashboard'">
            <div class="grid grid-cols-4 gap-4 mb-6">
              <div v-for="s in stats" :key="s.label" class="stat-card">
                <p class="field-label mb-1">@{{ s.label }}</p>
                <p class="text-2xl font-bold text-slate-900">@{{ s.value }}</p>
                <p class="text-xs text-slate-400 mt-1">@{{ s.delta }}</p>
              </div>
            </div>
            <div class="card p-5">
              <h3 class="font-bold text-slate-800 mb-3 text-sm">Recent Notifications</h3>
              <div class="space-y-3">
                <div v-for="(n, idx) in notifications" :key="idx" class="flex items-start gap-3 pb-3" :class="{ 'border-b border-slate-100': idx < notifications.length - 1 }">
                  <div class="w-2 h-2 rounded-full bg-orange-500 mt-1.5 flex-shrink-0"></div>
                  <div class="flex-1">
                    <p class="text-sm text-slate-700">@{{ n.text }}</p>
                    <p class="text-xs text-slate-400 mt-0.5">@{{ n.time }}</p>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <!-- MANAGE ACCOUNT REGISTRATIONS -->
          <div v-if="currentSection === 'registrations'">
            <p class="text-sm text-slate-500 mb-4">Review submitted information and requirements, then approve or disapprove. The applicant is notified of the decision by email.</p>
            <div class="card overflow-hidden">
              <table class="admin-table">
                <thead>
                  <tr><th>Applicant</th><th>Role</th><th>Submitted</th><th>Status</th><th>Actions</th></tr>
                </thead>
                <tbody>
                  <tr v-for="(a, idx) in pendingApplications" :key="idx">
                    <td class="font-medium text-slate-800">@{{ a.name }}</td>
                    <td>@{{ a.role }}</td>
                    <td>@{{ a.submitted }}</td>
                    <td><span class="badge" :class="statusBadgeClass(a.status)">@{{ a.status }}</span></td>
                    <td>
                      <div class="flex gap-2">
                        <button @click="approveUser(a)" class="btn-sm-gradient">Approve</button>
                        <button @click="rejectUser(a)" class="btn-danger-outline">Disapprove</button>
                        <button class="btn-outline">View Docs</button>
                      </div>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>

          <!-- MANAGE USER ACCOUNTS -->
          <div v-if="currentSection === 'accounts'">
            <p class="text-sm text-slate-500 mb-4">View user profiles and activate, suspend, or deactivate accounts.</p>
            <div class="card overflow-hidden">
              <table class="admin-table">
                <thead>
                  <tr><th>Name</th><th>Email</th><th>Role</th><th>Status</th><th>Actions</th></tr>
                </thead>
                <tbody>
                  <tr v-for="(u, idx) in userAccounts" :key="idx">
                    <td class="font-medium text-slate-800">@{{ u.name }}</td>
                    <td>@{{ u.email }}</td>
                    <td>@{{ u.role }}</td>
                    <td><span class="badge" :class="statusBadgeClass(u.status)">@{{ u.status }}</span></td>
                    <td>
                      <div class="flex gap-2">
                        <button @click="updateUserStatus(u, 'active')" class="btn-outline">Activate</button>
                        <button @click="updateUserStatus(u, 'suspended')" class="btn-outline">Suspend</button>
                        <button @click="updateUserStatus(u, 'deactivated')" class="btn-danger-outline">Deactivate</button>
                      </div>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>

          <!-- MONITOR SELLER COMPLIANCE -->
          <div v-if="currentSection === 'compliance'">
            <p class="text-sm text-slate-500 mb-4">Verify products belong to the seller's registered category, identify prohibited or inappropriate products, and issue warnings or suspend accounts for violations.</p>
            <div class="card overflow-hidden">
              <table class="admin-table">
                <thead>
                  <tr><th>Seller</th><th>Registered Category</th><th>Flagged Products</th><th>Note</th><th>Status</th><th>Actions</th></tr>
                </thead>
                <tbody>
                  <tr v-for="(s, idx) in sellerCompliance" :key="idx">
                    <td class="font-medium text-slate-800">@{{ s.seller }}</td>
                    <td>@{{ s.category }}</td>
                    <td>@{{ s.flagged }}</td>
                    <td class="text-slate-500">@{{ s.note }}</td>
                    <td><span class="badge" :class="statusBadgeClass(s.level)">@{{ s.level }}</span></td>
                    <td>
                      <div class="flex gap-2">
                        <button class="btn-outline">Issue Warning</button>
                        <button class="btn-danger-outline">Suspend</button>
                      </div>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>

          <!-- MANAGE COMPLAINTS AND DISPUTES -->
          <div v-if="currentSection === 'complaints'">
            <p class="text-sm text-slate-500 mb-4">Review complaint details and supporting evidence, and coordinate with the buyer, seller, and/or courier involved.</p>
            <div class="card overflow-hidden">
              <table class="admin-table">
                <thead>
                  <tr><th>Complaint ID</th><th>Filed By</th><th>Against</th><th>Reason</th><th>Status</th><th>Actions</th></tr>
                </thead>
                <tbody>
                  <tr v-for="(c, idx) in complaints" :key="idx">
                    <td class="font-medium text-slate-800">@{{ c.id }}</td>
                    <td>@{{ c.buyer }}</td>
                    <td>@{{ c.against }}</td>
                    <td>@{{ c.reason }}</td>
                    <td><span class="badge" :class="statusBadgeClass(c.status)">@{{ c.status }}</span></td>
                    <td><button class="btn-outline">View Case</button></td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>

          <!-- MANAGE COMMISSION -->
          <div v-if="currentSection === 'commission'">
            <p class="text-sm text-slate-500 mb-4">Platform commission is calculated at a flat 10% of each order's total.</p>
            <div class="card overflow-hidden">
              <table class="admin-table">
                <thead>
                  <tr><th>Order</th><th>Seller</th><th>Order Total</th><th>Commission (10%)</th><th>Seller Payout</th></tr>
                </thead>
                <tbody>
                  <tr v-for="(r, idx) in commissionRows" :key="idx">
                    <td class="font-medium text-slate-800">@{{ r.orderId }}</td>
                    <td>@{{ r.seller }}</td>
                    <td>@{{ r.total }}</td>
                    <td class="text-orange-600 font-semibold">@{{ r.commission }}</td>
                    <td>@{{ r.payout }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>

          <!-- GENERATE REPORTS -->
          <div v-if="currentSection === 'reports'">
            <div class="grid grid-cols-2 gap-4">
              <div class="card p-5">
                <h3 class="font-bold text-slate-800 mb-1 text-sm">Sales Summary Report</h3>
                <p class="text-xs text-slate-500 mb-3">Total sales, order volume, and top-performing sellers for a chosen period.</p>
                <div class="grid grid-cols-2 gap-2 mb-3">
                  <div><label class="field-label">From</label><input type="date" class="field-input" /></div>
                  <div><label class="field-label">To</label><input type="date" class="field-input" /></div>
                </div>
                <button class="btn-sm-gradient w-full py-2">Generate Report</button>
              </div>
              <div class="card p-5">
                <h3 class="font-bold text-slate-800 mb-1 text-sm">Commission Report</h3>
                <p class="text-xs text-slate-500 mb-3">Platform commission earned across all sellers for a chosen period.</p>
                <div class="grid grid-cols-2 gap-2 mb-3">
                  <div><label class="field-label">From</label><input type="date" class="field-input" /></div>
                  <div><label class="field-label">To</label><input type="date" class="field-input" /></div>
                </div>
                <button class="btn-sm-gradient w-full py-2">Generate Report</button>
              </div>
            </div>
          </div>

          <!-- MANAGE PLATFORM SETTINGS -->
          <div v-if="currentSection === 'settings'">
            <div class="grid grid-cols-2 gap-4">
              <div class="card p-5">
                <h3 class="font-bold text-slate-800 mb-3 text-sm">Post Announcement</h3>
                <div class="space-y-2 mb-3">
                  <div><label class="field-label">Title</label><input class="field-input" placeholder="Announcement title" /></div>
                  <div><label class="field-label">Message</label><textarea class="field-input" rows="3" placeholder="Write your announcement..."></textarea></div>
                </div>
                <button class="btn-sm-gradient w-full py-2 mb-4">Post Announcement</button>
                <div class="space-y-2">
                  <div v-for="(a, idx) in announcements" :key="idx" class="flex items-center justify-between text-sm border-t border-slate-100 pt-2">
                    <span class="text-slate-700">@{{ a.title }}</span>
                    <span class="text-xs text-slate-400">@{{ a.date }}</span>
                  </div>
                </div>
              </div>
              <div class="card p-5">
                <h3 class="font-bold text-slate-800 mb-3 text-sm">Platform Policies</h3>
                <div class="space-y-2">
                  <div v-for="(p, idx) in policies" :key="idx" class="flex items-center justify-between border-b border-slate-100 pb-2">
                    <div>
                      <p class="text-sm text-slate-700 font-medium">@{{ p.name }}</p>
                      <p class="text-xs text-slate-400">Updated @{{ p.updated }}</p>
                    </div>
                    <button class="btn-outline">Edit</button>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <!-- CHAT / MESSAGING -->
          <div v-if="currentSection === 'chat'">
            <div class="card overflow-hidden flex" style="height: 460px;">
              <div class="w-64 border-r border-slate-100 overflow-y-auto">
                <div v-for="(c, idx) in chatContacts" :key="idx" @click="activeChat = idx"
                     class="px-4 py-3 border-b border-slate-100 cursor-pointer" :class="activeChat === idx ? 'bg-orange-50' : 'hover:bg-slate-50'">
                  <div class="flex items-center justify-between">
                    <p class="text-sm font-semibold text-slate-800">@{{ c.name }}</p>
                    <span v-if="c.unread" class="badge badge-green">@{{ c.unread }}</span>
                  </div>
                  <p class="text-xs text-slate-400 truncate">@{{ c.last }}</p>
                </div>
              </div>
              <div class="flex-1 flex flex-col">
                <div class="flex-1 overflow-y-auto p-4 space-y-2">
                  <div v-for="(m, idx) in chatMessages[activeChat]" :key="idx" class="flex" :class="m.from === 'me' ? 'justify-end' : 'justify-start'">
                    <div class="max-w-xs px-3 py-2 rounded-lg text-sm" :class="m.from === 'me' ? 'btn-gradient text-white' : 'bg-slate-100 text-slate-700'">
                      @{{ m.text }}
                    </div>
                  </div>
                </div>
                <div class="border-t border-slate-100 p-3 flex gap-2">
                  <input class="field-input" placeholder="Type a message..." />
                  <button class="btn-sm-gradient px-4">Send</button>
                </div>
              </div>
            </div>
          </div>

          <!-- ACCOUNT MANAGEMENT -->
          <div v-if="currentSection === 'account'">
            <div class="card p-5 max-w-lg">
              <h3 class="font-bold text-slate-800 mb-3 text-sm">Admin Profile</h3>
              <div class="space-y-3 mb-4">
                <div><label class="field-label">Full Name</label><input class="field-input" v-model="adminProfile.name" /></div>
                <div><label class="field-label">Email</label><input class="field-input" v-model="adminProfile.email" disabled /></div>
                <div><label class="field-label">Role</label><input class="field-input" v-model="adminProfile.role" disabled /></div>
              </div>
              <button @click="updateProfile" class="btn-sm-gradient px-4 py-2">Save Changes</button>
              <div class="border-t border-slate-100 mt-5 pt-4">
                <h4 class="font-bold text-slate-800 mb-2 text-sm">Change Password</h4>
                <div class="space-y-2">
                  <input type="password" class="field-input" placeholder="Current password" />
                  <input type="password" class="field-input" placeholder="New password" />
                  <input type="password" class="field-input" placeholder="Confirm new password" />
                </div>
                <button class="btn-outline mt-3">Update Password</button>
              </div>
            </div>
          </div>

        </div>
      </main>

      <!-- LOGOUT CONFIRM MODAL -->
      <div v-if="showLogoutConfirm" class="fixed inset-0 bg-black/40 flex items-center justify-center z-50">
        <div class="card p-6 w-80">
          <h3 class="font-bold text-slate-900 mb-1">Log out?</h3>
          <p class="text-sm text-slate-500 mb-4">You'll need to sign in again to access the admin panel.</p>
          <div class="flex gap-2">
            <button @click="cancelLogout" class="btn-outline flex-1 py-2">Cancel</button>
            <button @click="confirmLogout" class="btn-sm-gradient flex-1 py-2">Log Out</button>
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

  <script>
    // ---------- Supabase Configuration ----------
    const SUPABASE_URL = '{{ env('VITE_SUPABASE_URL') }}';
    const SUPABASE_ANON_KEY = '{{ env('VITE_SUPABASE_ANON_KEY') }}';
    
    console.log('✅ Supabase URL:', SUPABASE_URL);
    console.log('✅ Supabase Key:', SUPABASE_ANON_KEY ? 'Loaded' : '❌ Missing');
    
    // Use a different variable name to avoid conflicts with the global supabase
    const supabaseClient = window.supabase.createClient(SUPABASE_URL, SUPABASE_ANON_KEY);

    const { createApp, ref, computed, onMounted } = Vue;

    const App = {
      setup() {
        const currentSection = ref('dashboard');
        const showLogoutConfirm = ref(false);
        const isLoading = ref(true);
        const isAuthenticated = ref(false);
        const isAdmin = ref(false);
        const adminUser = ref(null);

        const navItems = [
          { id: 'dashboard',     label: 'View Dashboard',              icon: 'grid' },
          { id: 'registrations', label: 'Account Registrations',       icon: 'userCheck' },
          { id: 'accounts',      label: 'User Accounts',               icon: 'users' },
          { id: 'compliance',    label: 'Seller Compliance',           icon: 'shield' },
          { id: 'complaints',    label: 'Complaints & Disputes',       icon: 'alert' },
          { id: 'commission',    label: 'Commission (10%)',            icon: 'percent' },
          { id: 'reports',       label: 'Generate Reports',            icon: 'file' },
          { id: 'settings',      label: 'Platform Settings',           icon: 'settings' },
          { id: 'chat',          label: 'Chat / Messaging',            icon: 'chat' },
          { id: 'account',       label: 'Account Management',          icon: 'userCog' },
        ];

        const sectionLabel = computed(() => {
          const found = navItems.find(n => n.id === currentSection.value);
          return found ? found.label : '';
        });

        const adminInitials = computed(() => {
          if (!adminProfile.value.name) return 'AU';
          const parts = adminProfile.value.name.split(' ');
          if (parts.length >= 2) {
            return (parts[0][0] + parts[1][0]).toUpperCase();
          }
          return parts[0].substring(0, 2).toUpperCase();
        });

        const adminProfile = ref({
          name: 'Admin User',
          email: '',
          role: 'Platform Administrator',
        });

        // ---------------- Hardcoded Data (Replace with API calls) ----------------
        const stats = [
          { label: 'Total Users',          value: '18,204',  delta: '+3.2% this month' },
          { label: 'Active Sellers',       value: '2,341',   delta: '+1.1% this month' },
          { label: 'Pending Registrations',value: '47',      delta: '12 today' },
          { label: 'Open Complaints',      value: '9',       delta: '2 high priority' },
        ];

        const notifications = [
          { text: 'New seller registration from "Dela Cruz Trading" awaiting review.', time: '10 min ago', type: 'registration' },
          { text: 'Complaint #C-1042 escalated by buyer juan@email.com.', time: '43 min ago', type: 'complaint' },
          { text: 'Seller "QuickBuy PH" flagged for a possible prohibited item listing.', time: '1 hr ago', type: 'compliance' },
          { text: 'Monthly commission report is ready to generate.', time: '3 hrs ago', type: 'report' },
          { text: 'Courier applicant Mark Santos submitted OR/CR and license.', time: '5 hrs ago', type: 'registration' },
        ];

        const pendingApplications = [
          { id: 1, name: 'Juan Dela Cruz', role: 'Buyer',    submitted: 'Aug 12, 2026', status: 'Pending' },
          { id: 2, name: 'Maria Santos',   role: 'Seller',   submitted: 'Aug 12, 2026', status: 'Pending' },
          { id: 3, name: 'Mark Reyes',     role: 'Courier',  submitted: 'Aug 13, 2026', status: 'Pending' },
          { id: 4, name: 'ABC Logistics Inc.', role: 'Logistics', submitted: 'Aug 13, 2026', status: 'Pending' },
          { id: 5, name: 'Liza Fernandez', role: 'Seller',   submitted: 'Aug 14, 2026', status: 'Pending' },
        ];

        const userAccounts = [
          { id: 1, name: 'Juan Dela Cruz',  email: 'juan@email.com',  role: 'Buyer',   status: 'Active' },
          { id: 2, name: 'Maria Santos',    email: 'maria@email.com', role: 'Seller',  status: 'Active' },
          { id: 3, name: 'Mark Reyes',      email: 'mark@email.com',  role: 'Courier', status: 'Suspended' },
          { id: 4, name: 'Liza Fernandez',  email: 'liza@email.com',  role: 'Seller',  status: 'Deactivated' },
          { id: 5, name: 'ABC Logistics Inc.', email: 'info@abclogistics.com', role: 'Logistics', status: 'Active' },
        ];

        const sellerCompliance = [
          { seller: 'QuickBuy PH', category: 'Electronics', flagged: 1, note: 'Listing under review for category mismatch', level: 'Warning' },
          { seller: 'Green Grocer', category: 'Food & Beverage', flagged: 0, note: 'No violations on record', level: 'Clear' },
          { seller: 'Trendy Threads', category: 'Apparel', flagged: 2, note: 'Possible counterfeit branding reported', level: 'Suspended' },
        ];

        const complaints = [
          { id: 'C-1042', buyer: 'Juan Dela Cruz', against: 'QuickBuy PH (Seller)', reason: 'Item not as described', status: 'Escalated' },
          { id: 'C-1041', buyer: 'Liza Fernandez', against: 'Mark Reyes (Courier)', reason: 'Late delivery', status: 'In Review' },
          { id: 'C-1039', buyer: 'Ana Lopez',      against: 'Green Grocer (Seller)', reason: 'Damaged product', status: 'Resolved' },
        ];

        const commissionRows = [
          { orderId: '#ORD-8841', seller: 'QuickBuy PH', total: '₱2,450.00', commission: '₱245.00', payout: '₱2,205.00' },
          { orderId: '#ORD-8840', seller: 'Green Grocer', total: '₱890.00',  commission: '₱89.00',  payout: '₱801.00' },
          { orderId: '#ORD-8839', seller: 'Trendy Threads', total: '₱1,320.00', commission: '₱132.00', payout: '₱1,188.00' },
        ];

        const announcements = [
          { title: 'Scheduled maintenance — Aug 20, 12AM–2AM', date: 'Aug 13, 2026' },
          { title: 'Updated seller commission policy now in effect', date: 'Aug 5, 2026' },
        ];

        const policies = [
          { name: 'Seller Terms & Category Guidelines', updated: 'Jul 28, 2026' },
          { name: 'Buyer Refund & Return Policy', updated: 'Jul 15, 2026' },
          { name: 'Courier Code of Conduct', updated: 'Jun 30, 2026' },
        ];

        const chatContacts = [
          { name: 'Maria Santos (Seller)', last: 'Is my permit re-upload received?', unread: 2 },
          { name: 'Mark Reyes (Courier)',  last: 'Requesting suspension review.', unread: 0 },
          { name: 'Juan Dela Cruz (Buyer)', last: 'Thanks for resolving my complaint!', unread: 0 },
        ];
        const activeChat = ref(0);
        const chatMessages = [
          [
            { from: 'them', text: 'Hi, is my business permit re-upload received?' },
            { from: 'me',   text: 'Yes, we received it — verification is in progress.' },
            { from: 'them', text: 'Thank you, please let me know once approved.' },
          ],
          [
            { from: 'them', text: 'I was suspended after one late delivery, can this be reviewed?' },
            { from: 'me',   text: 'Sending your case to the compliance team now.' },
          ],
          [
            { from: 'them', text: 'Thanks for resolving my complaint!' },
            { from: 'me',   text: 'Happy to help, Juan!' },
          ],
        ];

        // ---------- Authentication Check ----------
        async function checkAuth() {
          isLoading.value = true;
          try {
            const { data: { user }, error } = await supabaseClient.auth.getUser();
            
            if (error || !user) {
              isAuthenticated.value = false;
              isAdmin.value = false;
              window.location.href = '/';
              return;
            }

            const { data: profile, error: profileError } = await supabaseClient
              .from('profiles')
              .select('role, first_name, last_name, email')
              .eq('id', user.id)
              .single();

            if (profileError || !profile) {
              isAuthenticated.value = false;
              isAdmin.value = false;
              window.location.href = '/';
              return;
            }

            if (profile.role !== 'admin') {
              isAuthenticated.value = true;
              isAdmin.value = false;
              window.location.href = '/';
              return;
            }

            isAuthenticated.value = true;
            isAdmin.value = true;
            adminUser.value = user;
            
            adminProfile.value = {
              name: `${profile.first_name || 'Admin'} ${profile.last_name || 'User'}`,
              email: profile.email || user.email,
              role: 'Platform Administrator',
            };

          } catch (error) {
            console.error('Auth error:', error);
            window.location.href = '/';
          } finally {
            isLoading.value = false;
          }
        }

        // ---------- Logout ----------
        async function confirmLogout() {
          try {
            await supabaseClient.auth.signOut();
            window.location.href = '/';
          } catch (error) {
            console.error('Logout error:', error);
          }
        }

        function requestLogout() {
          showLogoutConfirm.value = true;
        }

        function cancelLogout() {
          showLogoutConfirm.value = false;
        }

        // ---------- Admin Actions ----------
        function statusBadgeClass(status) {
          const s = status.toLowerCase();
          if (['active', 'approved', 'resolved', 'clear'].includes(s)) return 'badge-green';
          if (['pending', 'in review', 'warning'].includes(s)) return 'badge-amber';
          if (['suspended', 'deactivated', 'escalated'].includes(s)) return 'badge-red';
          return 'badge-slate';
        }

        function selectSection(id) {
          currentSection.value = id;
        }

        async function approveUser(user) {
          if (confirm(`Approve ${user.name}?`)) {
            alert(`✅ ${user.name} approved!`);
          }
        }

        async function rejectUser(user) {
          if (confirm(`Reject ${user.name}?`)) {
            alert(`❌ ${user.name} rejected.`);
          }
        }

        async function updateUserStatus(user, status) {
          if (confirm(`Set ${user.name} to ${status}?`)) {
            alert(`✅ ${user.name} set to ${status}.`);
          }
        }

        async function updateProfile() {
          alert('✅ Profile updated!');
        }

        // ---------- Icon Helper ----------
        function getIcon(iconName) {
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
        }

        // ---------- Lifecycle ----------
        onMounted(() => {
          checkAuth();
        });

        return {
          currentSection, sectionLabel, navItems, showLogoutConfirm,
          isLoading, isAuthenticated, isAdmin, adminUser, adminProfile, adminInitials,
          stats, notifications, pendingApplications, userAccounts,
          sellerCompliance, complaints, commissionRows, announcements, policies,
          chatContacts, activeChat, chatMessages,
          statusBadgeClass, selectSection, requestLogout, confirmLogout, cancelLogout,
          approveUser, rejectUser, updateUserStatus, updateProfile, getIcon,
        };
      },
    };

    createApp(App).mount('#app');
</script>
</body>
</html>