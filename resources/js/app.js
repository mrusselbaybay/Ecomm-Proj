// resources/js/app.js
const { createApp, ref, computed, onMounted } = Vue;

// ---------- Configuration ----------
const SUPABASE_URL = import.meta.env.VITE_SUPABASE_URL;
const SUPABASE_ANON_KEY = import.meta.env.VITE_SUPABASE_ANON_KEY;

console.log('✅ Supabase URL:', SUPABASE_URL);
console.log('✅ Supabase Key:', SUPABASE_ANON_KEY ? 'Loaded' : '❌ Missing');

// ---------- Initialize Supabase ----------
const supabase = window.supabase.createClient(SUPABASE_URL, SUPABASE_ANON_KEY);

// ---------- PSGC API Base ----------
const PSGC_BASE = 'https://rootscratch.com/api/psgc';

// ---------- Cookie Helpers ----------
function setCookie(name, value, days = 7) {
  const d = new Date();
  d.setTime(d.getTime() + days * 24 * 60 * 60 * 1000);
  document.cookie = `${name}=${encodeURIComponent(value)};expires=${d.toUTCString()};path=/`;
}

function getCookie(name) {
  const match = document.cookie.match('(^|;)\\s*' + name + '\\s*=\\s*([^;]+)');
  return match ? decodeURIComponent(match.pop()) : null;
}

function deleteCookie(name) {
  document.cookie = `${name}=;expires=Thu, 01 Jan 1970 00:00:00 UTC;path=/;`;
}

// ---------- Validation Helpers ----------
function validateName(name) {
  return /^[A-Za-z\s\-]+$/.test(name);
}

function validateMiddleInitial(mi) {
  if (mi === '') return true;
  return /^[A-Za-z]$/.test(mi);
}

function validateEmail(email) {
  return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
}

function validateContactNumber(contact) {
  return /^09\d{9}$/.test(contact);
}

function validateBirthday(birthday) {
  if (!birthday) return false;
  const selected = new Date(birthday);
  const today = new Date();
  today.setHours(0, 0, 0, 0);
  return selected <= today;
}

function validatePassword(password) {
  return password.length >= 8;
}

// ---------- Main Application ----------
const App = {
  setup() {
    // UI State
    const mode = ref('login');
    const signupStep = ref('role');
    const selectedRole = ref(null);
    const showPassword = ref(false);
    const rememberMe = ref(false);
    const email = ref('');
    const password = ref('');
    const confirmPassword = ref('');
    const forgotEmail = ref('');
    const errorMsg = ref('');
    const successMsg = ref('');
    const loggedInUser = ref(getCookie('nexmart_session') ? JSON.parse(getCookie('nexmart_session')) : null);
    const isLogisticsSignup = ref(false);
    const showAdminSignup = ref(false);
    const adminSignupLoading = ref(false);

    // Password Reset Wizard
    const resetStep = ref(1); // 1: Email, 2: Verify Code, 3: New Password
    const resetEmail = ref('');
    const resetCode = ref('');
    const newPassword = ref('');
    const newConfirmPassword = ref('');
    const isVerifying = ref(false);

    // Admin Signup Form
    const adminForm = ref({
      email: '',
      password: '',
      confirmPassword: '',
      firstName: '',
      lastName: '',
      role: 'admin'
    });

    // Validation errors for inline display
    const validationErrors = ref({
      firstName: '',
      lastName: '',
      middleInitial: '',
      email: '',
      contactNo: '',
      birthday: '',
      password: '',
      confirmPassword: '',
      companyName: '',
      companyEmail: '',
      companyContactNo: '',
      companyTIN: '',
      ownerFirstName: '',
      ownerLastName: '',
      ownerMiddleInitial: '',
      ownerEmail: '',
      ownerContactNo: '',
      ownerBirthday: '',
      driverFirstName: '',
      driverLastName: '',
      driverMiddleInitial: '',
      driverEmail: '',
      driverContactNo: '',
      driverBirthday: '',
      driverPassword: '',
      driverConfirmPassword: '',
    });

    // Registration Form Fields
    const form = ref({
      lastName: '',
      firstName: '',
      middleInitial: '',
      sex: '',
      contactNo: '',
      birthday: '',
      provinceCode: '',
      province: '',
      municipalityCode: '',
      municipality: '',
      barangay: '',
      street: '',
      houseNo: '',
      idFile: null,
      businessName: '',
      lineOfBusiness: '',
      businessPermit: null,
      vehicle: '',
      plateNumber: '',
      orcrFile: null,
      licenseFile: null,
      companyName: '',
      companyAddress: '',
      companyProvinceCode: '',
      companyProvince: '',
      companyMunicipalityCode: '',
      companyMunicipality: '',
      companyBarangay: '',
      companyStreet: '',
      companyHouseNo: '',
      companyContactNo: '',
      companyEmail: '',
      companyTIN: '',
      companySECReg: '',
      ownerLastName: '',
      ownerFirstName: '',
      ownerMiddleInitial: '',
      ownerSex: '',
      ownerBirthday: '',
      ownerContactNo: '',
      ownerEmail: '',
      ownerIdFile: null,
      businessPermitFile: null,
      mayorPermitFile: null,
      dtiRegFile: null,
      driverFirstName: '',
      driverLastName: '',
      driverMiddleInitial: '',
      driverSex: '',
      driverBirthday: '',
      driverContactNo: '',
      driverEmail: '',
      driverPassword: '',
      driverConfirmPassword: '',
      driverIdFile: null,
      driverLicenseFile: null,
      driverVehicle: '',
      driverPlateNumber: '',
      driverOrcrFile: null,
    });

    // PSGC API State
    const provinceOptions = ref([]);
    const municipalityOptions = ref([]);
    const barangayOptions = ref([]);
    const companyProvinceOptions = ref([]);
    const companyMunicipalityOptions = ref([]);
    const companyBarangayOptions = ref([]);
    const loadingProvinces = ref(false);
    const loadingMunicipalities = ref(false);
    const loadingBarangays = ref(false);
    const loadingCompanyProvinces = ref(false);
    const loadingCompanyMunicipalities = ref(false);
    const loadingCompanyBarangays = ref(false);
    const addressApiError = ref('');

    // Step Definitions
    const steps = ['Personal', 'Address', 'Security', 'Documents'];
    const logisticsSteps = ['Company Info', 'Owner Details', 'Address', 'Security', 'Documents'];
    const driverSteps = ['Personal', 'Address', 'Security', 'Documents'];

    // Computed: Current Step Index
    const currentStepIndex = computed(() => {
      if (isLogisticsSignup.value) {
        const stepMap = {
          'company': 0,
          'owner': 1,
          'address': 2,
          'security': 3,
          'documents': 4
        };
        return stepMap[signupStep.value] !== undefined ? stepMap[signupStep.value] : -1;
      } else if (selectedRole.value === 'driver') {
        const stepMap = {
          'driverPersonal': 0,
          'driverAddress': 1,
          'driverSecurity': 2,
          'driverDocuments': 3
        };
        return stepMap[signupStep.value] !== undefined ? stepMap[signupStep.value] : -1;
      } else {
        const stepMap = {
          'personal': 0,
          'address': 1,
          'security': 2,
          'documents': 3
        };
        return stepMap[signupStep.value] !== undefined ? stepMap[signupStep.value] : -1;
      }
    });

    // Role Options
    const roles = [
      { id: 'buyer', label: 'Buyer', desc: 'Shop from verified local sellers', icon: 'bag' },
      { id: 'seller', label: 'Seller', desc: 'Open your store, reach customers', icon: 'house' },
      { id: 'courier', label: 'Courier', desc: 'Deliver & earn on your schedule', icon: 'truck' },
      { id: 'driver', label: 'Driver', desc: 'Drive & deliver for logistics companies', icon: 'car' }
    ];

    // ---------- Validation Functions ----------
    function clearValidationError(field) {
      validationErrors.value[field] = '';
    }

    function setValidationError(field, message) {
      validationErrors.value[field] = message;
    }

    function validatePersonalFields() {
      let isValid = true;
      
      if (!form.value.firstName) {
        setValidationError('firstName', 'First name is required');
        isValid = false;
      } else if (!validateName(form.value.firstName)) {
        setValidationError('firstName', 'Only letters allowed');
        isValid = false;
      } else {
        clearValidationError('firstName');
      }

      if (!form.value.lastName) {
        setValidationError('lastName', 'Last name is required');
        isValid = false;
      } else if (!validateName(form.value.lastName)) {
        setValidationError('lastName', 'Only letters allowed');
        isValid = false;
      } else {
        clearValidationError('lastName');
      }

      if (form.value.middleInitial && !validateMiddleInitial(form.value.middleInitial)) {
        setValidationError('middleInitial', 'Only 1 letter allowed');
        isValid = false;
      } else {
        clearValidationError('middleInitial');
      }

      if (!email.value) {
        setValidationError('email', 'Email is required');
        isValid = false;
      } else if (!validateEmail(email.value)) {
        setValidationError('email', 'Invalid email format');
        isValid = false;
      } else {
        clearValidationError('email');
      }

      if (!form.value.contactNo) {
        setValidationError('contactNo', 'Contact number is required');
        isValid = false;
      } else if (!validateContactNumber(form.value.contactNo)) {
        setValidationError('contactNo', 'Must start with 09 and be 11 digits');
        isValid = false;
      } else {
        clearValidationError('contactNo');
      }

      if (!form.value.birthday) {
        setValidationError('birthday', 'Birthday is required');
        isValid = false;
      } else if (!validateBirthday(form.value.birthday)) {
        setValidationError('birthday', 'Cannot select future date');
        isValid = false;
      } else {
        clearValidationError('birthday');
      }

      if (!form.value.sex) {
        errorMsg.value = 'Please select your sex';
        isValid = false;
      }

      return isValid;
    }

    function validateDriverPersonal() {
      let isValid = true;
      
      if (!form.value.driverFirstName) {
        setValidationError('driverFirstName', 'First name is required');
        isValid = false;
      } else if (!validateName(form.value.driverFirstName)) {
        setValidationError('driverFirstName', 'Only letters allowed');
        isValid = false;
      } else {
        clearValidationError('driverFirstName');
      }

      if (!form.value.driverLastName) {
        setValidationError('driverLastName', 'Last name is required');
        isValid = false;
      } else if (!validateName(form.value.driverLastName)) {
        setValidationError('driverLastName', 'Only letters allowed');
        isValid = false;
      } else {
        clearValidationError('driverLastName');
      }

      if (form.value.driverMiddleInitial && !validateMiddleInitial(form.value.driverMiddleInitial)) {
        setValidationError('driverMiddleInitial', 'Only 1 letter allowed');
        isValid = false;
      } else {
        clearValidationError('driverMiddleInitial');
      }

      if (!form.value.driverEmail) {
        setValidationError('driverEmail', 'Email is required');
        isValid = false;
      } else if (!validateEmail(form.value.driverEmail)) {
        setValidationError('driverEmail', 'Invalid email format');
        isValid = false;
      } else {
        clearValidationError('driverEmail');
      }

      if (!form.value.driverContactNo) {
        setValidationError('driverContactNo', 'Contact number is required');
        isValid = false;
      } else if (!validateContactNumber(form.value.driverContactNo)) {
        setValidationError('driverContactNo', 'Must start with 09 and be 11 digits');
        isValid = false;
      } else {
        clearValidationError('driverContactNo');
      }

      if (!form.value.driverBirthday) {
        setValidationError('driverBirthday', 'Birthday is required');
        isValid = false;
      } else if (!validateBirthday(form.value.driverBirthday)) {
        setValidationError('driverBirthday', 'Cannot select future date');
        isValid = false;
      } else {
        clearValidationError('driverBirthday');
      }

      if (!form.value.driverSex) {
        errorMsg.value = 'Please select your sex';
        isValid = false;
      }

      return isValid;
    }

    function validateAddressFields() {
      const { province, municipality, barangay, street } = form.value;
      if (!province || !municipality || !barangay || !street) {
        errorMsg.value = 'Please fill in all address fields.';
        return false;
      }
      return true;
    }

    function validateDriverAddressFields() {
      const { province, municipality, barangay, street } = form.value;
      if (!province || !municipality || !barangay || !street) {
        errorMsg.value = 'Please fill in all address fields.';
        return false;
      }
      return true;
    }

    function validateSecurityFields() {
      let isValid = true;
      
      if (!password.value) {
        setValidationError('password', 'Password is required');
        isValid = false;
      } else if (!validatePassword(password.value)) {
        setValidationError('password', 'Min 8 characters');
        isValid = false;
      } else {
        clearValidationError('password');
      }

      if (!confirmPassword.value) {
        setValidationError('confirmPassword', 'Please confirm password');
        isValid = false;
      } else if (password.value !== confirmPassword.value) {
        setValidationError('confirmPassword', 'Passwords do not match');
        isValid = false;
      } else {
        clearValidationError('confirmPassword');
      }

      return isValid;
    }

    function validateDriverSecurityFields() {
      let isValid = true;
      
      if (!form.value.driverPassword) {
        setValidationError('driverPassword', 'Password is required');
        isValid = false;
      } else if (!validatePassword(form.value.driverPassword)) {
        setValidationError('driverPassword', 'Min 8 characters');
        isValid = false;
      } else {
        clearValidationError('driverPassword');
      }

      if (!form.value.driverConfirmPassword) {
        setValidationError('driverConfirmPassword', 'Please confirm password');
        isValid = false;
      } else if (form.value.driverPassword !== form.value.driverConfirmPassword) {
        setValidationError('driverConfirmPassword', 'Passwords do not match');
        isValid = false;
      } else {
        clearValidationError('driverConfirmPassword');
      }

      return isValid;
    }

    function validateDocuments() {
      if (selectedRole.value === 'buyer' && !form.value.idFile) {
        errorMsg.value = 'Please upload an ID.';
        return false;
      }
      if (selectedRole.value === 'seller') {
        if (!form.value.idFile || !form.value.businessPermit || !form.value.businessName || !form.value.lineOfBusiness) {
          errorMsg.value = 'Please fill in all seller documents and business info.';
          return false;
        }
      }
      if (selectedRole.value === 'courier') {
        if (!form.value.vehicle || !form.value.plateNumber || !form.value.orcrFile || !form.value.licenseFile) {
          errorMsg.value = 'Please fill in all courier documents and vehicle info.';
          return false;
        }
      }
      if (selectedRole.value === 'driver') {
        if (!form.value.driverVehicle || !form.value.driverPlateNumber || !form.value.driverOrcrFile || !form.value.driverLicenseFile || !form.value.driverIdFile) {
          errorMsg.value = 'Please fill in all driver documents and vehicle info.';
          return false;
        }
      }
      return true;
    }

    // Logistics validations
    function validateCompany() {
      const { companyName, companyContactNo, companyEmail, companyTIN } = form.value;
      if (!companyName || !companyContactNo || !companyEmail || !companyTIN) {
        errorMsg.value = 'Please fill in all required company fields.';
        return false;
      }
      if (!validateEmail(companyEmail)) {
        errorMsg.value = 'Invalid company email format.';
        return false;
      }
      if (!validateContactNumber(companyContactNo)) {
        errorMsg.value = 'Contact number must start with 09 and be 11 digits.';
        return false;
      }
      return true;
    }

    function validateOwner() {
      const { ownerLastName, ownerFirstName, ownerSex, ownerBirthday, ownerContactNo, ownerEmail } = form.value;
      if (!ownerLastName || !ownerFirstName || !ownerSex || !ownerBirthday || !ownerContactNo || !ownerEmail) {
        errorMsg.value = 'Please fill in all required owner details.';
        return false;
      }
      if (!validateName(ownerFirstName) || !validateName(ownerLastName)) {
        errorMsg.value = 'Names should only contain letters.';
        return false;
      }
      if (!validateEmail(ownerEmail)) {
        errorMsg.value = 'Invalid owner email format.';
        return false;
      }
      if (!validateContactNumber(ownerContactNo)) {
        errorMsg.value = 'Owner contact number must start with 09 and be 11 digits.';
        return false;
      }
      if (!validateBirthday(ownerBirthday)) {
        errorMsg.value = 'Cannot select future date for birthday.';
        return false;
      }
      return true;
    }

    function validateLogisticsAddress() {
      const { companyProvince, companyMunicipality, companyBarangay, companyStreet } = form.value;
      if (!companyProvince || !companyMunicipality || !companyBarangay || !companyStreet) {
        errorMsg.value = 'Please fill in all address fields.';
        return false;
      }
      return true;
    }

    function validateLogisticsSecurity() {
      if (password.value.length < 8) {
        errorMsg.value = 'Password must be at least 8 characters.';
        return false;
      }
      if (password.value !== confirmPassword.value) {
        errorMsg.value = 'Passwords do not match.';
        return false;
      }
      return true;
    }

    function validateLogisticsDocuments() {
      if (!form.value.ownerIdFile || !form.value.businessPermitFile || !form.value.mayorPermitFile || !form.value.dtiRegFile) {
        errorMsg.value = 'Please upload all required documents.';
        return false;
      }
      return true;
    }

    // ---------- Admin Signup Function ----------
    async function handleAdminSignup() {
      adminSignupLoading.value = true;
      resetMessages();

      if (!adminForm.value.firstName || !validateName(adminForm.value.firstName)) {
        errorMsg.value = 'Please enter a valid first name (letters only).';
        adminSignupLoading.value = false;
        return;
      }
      if (!adminForm.value.lastName || !validateName(adminForm.value.lastName)) {
        errorMsg.value = 'Please enter a valid last name (letters only).';
        adminSignupLoading.value = false;
        return;
      }
      if (!adminForm.value.email || !validateEmail(adminForm.value.email)) {
        errorMsg.value = 'Please enter a valid email address.';
        adminSignupLoading.value = false;
        return;
      }
      if (!adminForm.value.password || adminForm.value.password.length < 8) {
        errorMsg.value = 'Password must be at least 8 characters.';
        adminSignupLoading.value = false;
        return;
      }
      if (adminForm.value.password !== adminForm.value.confirmPassword) {
        errorMsg.value = 'Passwords do not match.';
        adminSignupLoading.value = false;
        return;
      }

      try {
        const { data, error } = await supabase.auth.signUp({
          email: adminForm.value.email.trim().toLowerCase(),
          password: adminForm.value.password,
          options: {
            data: {
              role: 'admin',
              first_name: adminForm.value.firstName,
              last_name: adminForm.value.lastName
            }
          }
        });

        if (error) throw error;

        if (data?.user) {
          const { error: updateError } = await supabase
            .from('profiles')
            .update({ 
              role: 'admin', 
              status: 'approved',
              first_name: adminForm.value.firstName,
              last_name: adminForm.value.lastName
            })
            .eq('id', data.user.id);

          if (updateError) throw updateError;

          successMsg.value = 'Admin account created successfully!';
          
          adminForm.value = {
            email: '',
            password: '',
            confirmPassword: '',
            firstName: '',
            lastName: '',
            role: 'admin'
          };
          
          setTimeout(() => {
            showAdminSignup.value = false;
            successMsg.value = '';
          }, 2000);
        }
      } catch (error) {
        errorMsg.value = error.message || 'Failed to create admin account.';
      } finally {
        adminSignupLoading.value = false;
      }
    }

    // ---------- PSGC API Functions ----------
    async function fetchProvinces() {
      loadingProvinces.value = true;
      loadingCompanyProvinces.value = true;
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
            } catch (e) {
              return [];
            }
          })
        );

        const allProvinces = provinceResults.flat();
        if (allProvinces.length === 0) throw new Error('No provinces returned');
        const sorted = allProvinces.slice().sort((a, b) => a.name.localeCompare(b.name));
        provinceOptions.value = sorted;
        companyProvinceOptions.value = sorted;
      } catch (err) {
        addressApiError.value = 'Could not load provinces from the PSGC API. Check your connection and retry.';
      } finally {
        loadingProvinces.value = false;
        loadingCompanyProvinces.value = false;
      }
    }

    async function fetchMunicipalities(provinceCode, isCompany = false) {
      if (isCompany) {
        companyMunicipalityOptions.value = [];
        companyBarangayOptions.value = [];
        form.value.companyMunicipalityCode = '';
        form.value.companyMunicipality = '';
        form.value.companyBarangay = '';
      } else {
        municipalityOptions.value = [];
        barangayOptions.value = [];
        form.value.municipalityCode = '';
        form.value.municipality = '';
        form.value.barangay = '';
      }
      
      if (!provinceCode) return;
      
      if (isCompany) {
        loadingCompanyMunicipalities.value = true;
      } else {
        loadingMunicipalities.value = true;
      }
      addressApiError.value = '';
      
      try {
        const prefix = provinceCode.substring(0, 5);
        const res = await fetch(`${PSGC_BASE}/cities-municipalities?province_code=${prefix}`);
        if (!res.ok) throw new Error('Request failed: ' + res.status);
        const json = await res.json();
        const data = (json.data || []).slice().sort((a, b) => a.name.localeCompare(b.name));
        
        if (isCompany) {
          companyMunicipalityOptions.value = data;
        } else {
          municipalityOptions.value = data;
        }
      } catch (err) {
        addressApiError.value = 'Could not load cities/municipalities. Check your connection and retry.';
      } finally {
        if (isCompany) {
          loadingCompanyMunicipalities.value = false;
        } else {
          loadingMunicipalities.value = false;
        }
      }
    }

    async function fetchBarangays(municipalityCode, isCompany = false) {
      if (isCompany) {
        companyBarangayOptions.value = [];
        form.value.companyBarangay = '';
      } else {
        barangayOptions.value = [];
        form.value.barangay = '';
      }
      
      if (!municipalityCode) return;
      
      if (isCompany) {
        loadingCompanyBarangays.value = true;
      } else {
        loadingBarangays.value = true;
      }
      addressApiError.value = '';
      
      try {
        const cityCode = municipalityCode.substring(0, 7);
        const res = await fetch(`${PSGC_BASE}/barangays?city_municipality_code=${cityCode}&limit=500`);
        if (!res.ok) {
          let detail = '';
          try { detail = await res.text(); } catch (e) {}
          throw new Error('Request failed: ' + res.status + (detail ? ' — ' + detail : ''));
        }
        const json = await res.json();
        const data = (json.data || []).slice().sort((a, b) => a.name.localeCompare(b.name));
        
        if (isCompany) {
          companyBarangayOptions.value = data;
        } else {
          barangayOptions.value = data;
        }
      } catch (err) {
        addressApiError.value = 'Could not load barangays. Check your connection and retry.';
      } finally {
        if (isCompany) {
          loadingCompanyBarangays.value = false;
        } else {
          loadingBarangays.value = false;
        }
      }
    }

    // Address Change Handlers
    function onProvinceChange() {
      const selected = provinceOptions.value.find(p => p.code === form.value.provinceCode);
      form.value.province = selected ? selected.name : '';
      fetchMunicipalities(form.value.provinceCode, false);
    }

    function onMunicipalityChange() {
      const selected = municipalityOptions.value.find(m => m.code === form.value.municipalityCode);
      form.value.municipality = selected ? selected.name : '';
      fetchBarangays(form.value.municipalityCode, false);
    }

    function onCompanyProvinceChange() {
      const selected = companyProvinceOptions.value.find(p => p.code === form.value.companyProvinceCode);
      form.value.companyProvince = selected ? selected.name : '';
      fetchMunicipalities(form.value.companyProvinceCode, true);
    }

    function onCompanyMunicipalityChange() {
      const selected = companyMunicipalityOptions.value.find(m => m.code === form.value.companyMunicipalityCode);
      form.value.companyMunicipality = selected ? selected.name : '';
      fetchBarangays(form.value.companyMunicipalityCode, true);
    }

    function retryAddressLoad() {
      if (!form.value.provinceCode) {
        fetchProvinces();
      } else if (!form.value.municipalityCode) {
        fetchMunicipalities(form.value.provinceCode, false);
      } else {
        fetchBarangays(form.value.municipalityCode, false);
      }
    }

    // ---------- Navigation Functions ----------
    function resetMessages() {
      errorMsg.value = '';
      successMsg.value = '';
    }

    function switchMode(next) {
      mode.value = next;
      signupStep.value = 'role';
      selectedRole.value = null;
      isLogisticsSignup.value = false;
      resetMessages();
      resetStep.value = 1;
      resetEmail.value = '';
      resetCode.value = '';
      newPassword.value = '';
      newConfirmPassword.value = '';
      forgotEmail.value = '';
      
      form.value = { 
        lastName:'', firstName:'', middleInitial:'', sex:'', contactNo:'', birthday:'', 
        provinceCode:'', province:'', municipalityCode:'', municipality:'', barangay:'', street:'', houseNo:'', 
        idFile:null, businessName:'', lineOfBusiness:'', businessPermit:null, 
        vehicle:'', plateNumber:'', orcrFile:null, licenseFile:null,
        companyName:'', companyAddress:'', companyProvinceCode:'', companyProvince:'', 
        companyMunicipalityCode:'', companyMunicipality:'', companyBarangay:'', 
        companyStreet:'', companyHouseNo:'', companyContactNo:'', companyEmail:'', 
        companyTIN:'', companySECReg:'', ownerLastName:'', ownerFirstName:'', 
        ownerMiddleInitial:'', ownerSex:'', ownerBirthday:'', ownerContactNo:'', 
        ownerEmail:'', ownerIdFile:null, businessPermitFile:null, mayorPermitFile:null, 
        dtiRegFile:null,
        driverFirstName:'', driverLastName:'', driverMiddleInitial:'', driverSex:'', 
        driverBirthday:'', driverContactNo:'', driverEmail:'', driverPassword:'', 
        driverConfirmPassword:'', driverIdFile:null, driverLicenseFile:null, 
        driverVehicle:'', driverPlateNumber:'', driverOrcrFile:null
      };
      
      Object.keys(validationErrors.value).forEach(key => {
        validationErrors.value[key] = '';
      });
      
      municipalityOptions.value = [];
      barangayOptions.value = [];
      companyMunicipalityOptions.value = [];
      companyBarangayOptions.value = [];
      addressApiError.value = '';
      email.value = '';
      password.value = '';
      confirmPassword.value = '';
    }

    function startLogisticsSignup() {
      isLogisticsSignup.value = true;
      signupStep.value = 'company';
      resetMessages();
    }

    function selectRole(role) {
      selectedRole.value = role;
      resetMessages();
      
      Object.keys(validationErrors.value).forEach(key => {
        validationErrors.value[key] = '';
      });
      
      if (role === 'driver') {
        signupStep.value = 'driverPersonal';
      } else {
        signupStep.value = 'personal';
      }
    }

    function goToStep(step) {
      if (selectedRole.value === 'driver') {
        if (step === 'driverAddress' && signupStep.value === 'driverPersonal') {
          if (!validateDriverPersonal()) return;
        }
        if (step === 'driverSecurity' && signupStep.value === 'driverAddress') {
          if (!validateDriverAddressFields()) return;
        }
        if (step === 'driverDocuments' && signupStep.value === 'driverSecurity') {
          if (!validateDriverSecurityFields()) return;
        }
      } else if (!isLogisticsSignup.value) {
        if (step === 'address' && signupStep.value === 'personal') {
          if (!validatePersonalFields()) return;
        }
        if (step === 'security' && signupStep.value === 'address') {
          if (!validateAddressFields()) return;
        }
        if (step === 'documents' && signupStep.value === 'security') {
          if (!validateSecurityFields()) return;
        }
      } else {
        if (step === 'owner' && signupStep.value === 'company') {
          if (!validateCompany()) return;
        }
        if (step === 'address' && signupStep.value === 'owner') {
          if (!validateOwner()) return;
        }
        if (step === 'security' && signupStep.value === 'address') {
          if (!validateLogisticsAddress()) return;
        }
        if (step === 'documents' && signupStep.value === 'security') {
          if (!validateLogisticsSecurity()) return;
        }
      }
      
      signupStep.value = step;
      resetMessages();
    }

    function handleFileUpload(event, field) {
      const file = event.target.files[0];
      if (file) {
        form.value[field] = file;
      }
    }

    function submitRegistration() {
      resetMessages();
      
      if (selectedRole.value === 'driver') {
        if (!validateDriverPersonal() || !validateDriverSecurityFields() || !validateDocuments()) return;
        successMsg.value = 'Driver registration submitted! Please wait for administrator approval.';
      } else if (isLogisticsSignup.value) {
        if (!validateLogisticsDocuments()) return;
        successMsg.value = 'Logistics company registration submitted! Please wait for administrator approval.';
      } else {
        if (!validateDocuments()) return;
        successMsg.value = 'Registration submitted! Please wait for administrator approval.';
      }
      
      signupStep.value = 'complete';
    }

    // ---------- Login Functions ----------
    async function handleLogin() {
      resetMessages();

      const { data, error } = await supabase.auth.signInWithPassword({
        email: email.value.trim().toLowerCase(),
        password: password.value
      });

      if (error) {
        console.log('Supabase auth error:', error);
        errorMsg.value = 'Email or password is incorrect.';
        return;
      }

      const user = data.user;

      // Get user profile
      const { data: profile, error: profileError } = await supabase
        .from('profiles')
        .select('role, first_name, last_name, email')
        .eq('id', user.id)
        .single();

      if (profileError) {
        console.log('Profile error:', profileError);
        errorMsg.value = 'Could not fetch user profile.';
        return;
      }

      const userRole = profile?.role || user.user_metadata?.role || 'buyer';

      loggedInUser.value = {
        email: user.email,
        role: userRole
      };

      setCookie(
        'nexmart_session',
        JSON.stringify(loggedInUser.value),
        rememberMe.value ? 30 : 1
      );

      // Redirect admins to dashboard
      if (userRole === 'admin') {
        window.location.href = '/admin/dashboard';
      } else {
        successMsg.value = `Welcome back! Logged in as ${userRole}.`;
      }
    }

    // ---------- Password Reset Functions (Step-by-Step Wizard) ----------
    async function handleSendCode() {
      resetMessages();

      if (!forgotEmail.value) {
        errorMsg.value = 'Please enter your email address.';
        return;
      }

      try {
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

        const response = await fetch('/api/password/send-code', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-CSRF-TOKEN': csrfToken
          },
          body: JSON.stringify({
            email: forgotEmail.value.trim().toLowerCase()
          })
        });

        const data = await response.json();

        if (!response.ok) {
          throw new Error(data.message || 'Something went wrong');
        }

        resetEmail.value = forgotEmail.value;
        forgotEmail.value = '';
        resetStep.value = 2;
        successMsg.value = 'A verification code has been sent to your email.';
      } catch (err) {
        errorMsg.value = err.message || 'Could not send code. Please try again.';
      }
    }

    async function handleVerifyCode() {
      resetMessages();

      if (!resetCode.value || resetCode.value.length !== 6) {
        errorMsg.value = 'Please enter the 6-digit verification code.';
        return;
      }

      isVerifying.value = true;

      try {
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

        const response = await fetch('/api/password/verify-code', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-CSRF-TOKEN': csrfToken
          },
          body: JSON.stringify({
            email: resetEmail.value,
            code: resetCode.value
          })
        });

        const data = await response.json();

        if (!response.ok) {
          throw new Error(data.message || 'Invalid code. Please try again.');
        }

        resetStep.value = 3;
        successMsg.value = 'Code verified! Please enter your new password.';
      } catch (err) {
        errorMsg.value = err.message || 'Invalid or expired code. Please request a new one.';
      } finally {
        isVerifying.value = false;
      }
    }

    async function handleResetPassword() {
      resetMessages();

      if (!newPassword.value || newPassword.value.length < 8) {
        errorMsg.value = 'Password must be at least 8 characters.';
        return;
      }
      if (newPassword.value !== newConfirmPassword.value) {
        errorMsg.value = 'Passwords do not match.';
        return;
      }

      try {
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

        const response = await fetch('/api/password/reset', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-CSRF-TOKEN': csrfToken
          },
          body: JSON.stringify({
            email: resetEmail.value,
            code: resetCode.value,
            password: newPassword.value,
            password_confirmation: newConfirmPassword.value
          })
        });

        const data = await response.json();

        if (!response.ok) {
          throw new Error(data.message || 'Failed to reset password.');
        }

        // Reset all fields
        resetCode.value = '';
        newPassword.value = '';
        newConfirmPassword.value = '';
        resetEmail.value = '';
        resetStep.value = 1;
        mode.value = 'login';
        successMsg.value = 'Password reset successfully! You can now log in.';
      } catch (err) {
        errorMsg.value = err.message || 'Could not reset password. Please try again.';
      }
    }

    async function handleResendCode() {
      resetMessages();

      if (!resetEmail.value) {
        errorMsg.value = 'Email address not found. Please start over.';
        resetStep.value = 1;
        return;
      }

      try {
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

        const response = await fetch('/api/password/resend-code', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-CSRF-TOKEN': csrfToken
          },
          body: JSON.stringify({
            email: resetEmail.value
          })
        });

        const data = await response.json();

        if (!response.ok) {
          throw new Error(data.message || 'Could not resend code.');
        }

        successMsg.value = 'New verification code sent to your email.';
      } catch (err) {
        errorMsg.value = err.message || 'Could not resend code. Please try again.';
      }
    }

    function goToResetStep(step) {
      resetStep.value = step;
      resetMessages();
    }

    function continueAsGuest() {
      loggedInUser.value = { email: null, role: 'guest' };
      setCookie('nexmart_session', JSON.stringify(loggedInUser.value), 1);
    }

    function logout() {
      loggedInUser.value = null;
      deleteCookie('nexmart_session');
      resetMessages();
    }

    // Input handlers for contact number (only digits)
    function formatContactNumber(event, field) {
      let value = event.target.value;
      value = value.replace(/\D/g, '');
      if (value.length > 11) {
        value = value.slice(0, 11);
      }
      event.target.value = value;
      if (field) {
        form.value[field] = value;
      }
    }

    // Input handlers for name (only letters and spaces)
    function formatName(event, field) {
      let value = event.target.value;
      value = value.replace(/[^A-Za-z\s\-]/g, '');
      event.target.value = value;
      if (field) {
        form.value[field] = value;
      }
    }

    // Input handler for middle initial (only 1 letter)
    function formatMiddleInitial(event, field) {
      let value = event.target.value;
      value = value.replace(/[^A-Za-z]/g, '');
      if (value.length > 1) {
        value = value.slice(0, 1);
      }
      event.target.value = value;
      if (field) {
        form.value[field] = value;
      }
    }

    // ---------- Lifecycle ----------
    onMounted(() => {
      fetchProvinces();

      supabase.auth.onAuthStateChange((event) => {
        if (event === 'PASSWORD_RECOVERY') {
          mode.value = 'reset';
          resetMessages();
        }
      });
    });

    // ---------- Return ----------
    return {
      mode, showPassword, rememberMe, email, password, confirmPassword,
      forgotEmail, errorMsg, successMsg, loggedInUser, roles, selectedRole, signupStep,
      form, steps, logisticsSteps, driverSteps, currentStepIndex, isLogisticsSignup,
      provinceOptions, municipalityOptions, barangayOptions,
      companyProvinceOptions, companyMunicipalityOptions, companyBarangayOptions,
      loadingProvinces, loadingMunicipalities, loadingBarangays,
      loadingCompanyProvinces, loadingCompanyMunicipalities, loadingCompanyBarangays,
      addressApiError, validationErrors,
      onProvinceChange, onMunicipalityChange, onCompanyProvinceChange, onCompanyMunicipalityChange,
      retryAddressLoad,
      switchMode, handleLogin, continueAsGuest, selectRole, goToStep,
      handleFileUpload, submitRegistration, logout, startLogisticsSignup,
      formatContactNumber, formatName, formatMiddleInitial,
      validatePersonalFields, validateDriverPersonal, validateSecurityFields,
      validateDriverSecurityFields, validateAddressFields, validateDocuments,
      showAdminSignup, adminForm, adminSignupLoading, handleAdminSignup,
      // Password Reset Wizard
      resetStep, resetEmail, resetCode, newPassword, newConfirmPassword, isVerifying,
      handleSendCode, handleVerifyCode, handleResetPassword, handleResendCode, goToResetStep
    };
  },
  
  // ---------- Template ----------
  template: `
  <div class="min-h-screen flex flex-col md:flex-row" style="height:100vh;overflow:hidden;">

    <!-- LEFT PANEL -->
    <div class="side-panel md:w-[42%] w-full text-white px-8 py-10 md:px-12 md:py-14 flex flex-col justify-between" style="height:100vh;overflow:hidden;">
      <div>
        <div class="flex items-center gap-3 mb-10">
          <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-orange-500 to-amber-500 flex items-center justify-center">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4Z"/><path d="M3 6h18"/><path d="M16 10a4 4 0 0 1-8 0"/></svg>
          </div>
          <span class="text-lg font-bold tracking-wide">NEXMART</span>
        </div>
        <p class="text-orange-400 text-xs font-bold tracking-widest uppercase mb-4">Philippines' Trusted Marketplace</p>
        <h1 class="display-font text-4xl md:text-[2.6rem] leading-tight font-extrabold mb-5">
          One platform.<br/>
          <span class="text-orange-500">Every</span> role.
        </h1>
        <p class="text-slate-400 text-sm leading-relaxed mb-10 max-w-sm">
          Whether you're shopping, running a store, or delivering parcels — NEXMART is built to grow with you.
        </p>

        <div class="space-y-3">
          <div v-for="r in roles" :key="r.id" class="role-card rounded-xl px-4 py-3 flex items-center gap-3">
            <div class="w-9 h-9 rounded-lg bg-orange-500/15 flex items-center justify-center shrink-0 text-orange-400">
              <svg v-if="r.icon === 'bag'" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4Z"/><path d="M3 6h18"/><path d="M16 10a4 4 0 0 1-8 0"/></svg>
              <svg v-if="r.icon === 'house'" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2Z"/><path d="M9 22V12h6v10"/></svg>
              <svg v-if="r.icon === 'truck'" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 18V6a1 1 0 0 0-1-1H4a1 1 0 0 0-1 1v11a1 1 0 0 0 1 1h1"/><path d="M15 18H9"/><path d="M19 18h2a1 1 0 0 0 1-1v-3.65a1 1 0 0 0-.22-.624l-3.48-4.35A1 1 0 0 0 17.52 8H14"/><circle cx="7" cy="18" r="2"/><circle cx="17" cy="18" r="2"/></svg>
              <svg v-if="r.icon === 'car'" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 2h-3a5 5 0 0 0-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 0 1 1-1h3z"/></svg>
            </div>
            <div>
              <p class="text-sm font-semibold">{{ r.label }}</p>
              <p class="text-xs text-slate-400">Register as {{ r.label }}</p>
            </div>
          </div>
        </div>
      </div>
      <div class="border-t border-white/10 pt-5 mt-10 text-xs text-slate-500 flex gap-4">
        <span>Terms of Service</span>
        <span>Privacy Policy</span>
      </div>
    </div>

    <!-- RIGHT PANEL -->
    <div class="flex-1 flex items-center justify-center px-6" style="height:100vh;overflow:hidden;">
      <div class="w-full max-w-md" style="max-height:100vh;">

        <!-- LOGGED IN -->
        <div v-if="loggedInUser" class="text-center" style="padding:2rem 0;">
          <h2 class="display-font text-3xl font-bold text-slate-900 mb-2">You're in!</h2>
          <p class="text-slate-500 mb-6">
            <span v-if="loggedInUser.email">Logged in as <strong>{{ loggedInUser.email }}</strong> ({{ loggedInUser.role }})</span>
            <span v-else>Guest</span>
          </p>
          <button @click="logout" class="w-full border border-slate-300 text-slate-700 font-semibold py-3 rounded-lg hover:bg-slate-50">Log out</button>
        </div>

        <!-- NOT LOGGED IN -->
        <div v-else class="form-container" style="padding:1rem 0;">
          <div class="flex items-center justify-between mb-1">
            <h2 class="display-font text-3xl font-bold text-slate-900">
              {{ mode === 'login' ? 'Welcome back' : mode === 'forgot' || mode === 'reset' ? 'Reset Password' : 'Create an account' }}
            </h2>
            <div class="flex items-center gap-3">
              <button @click="showAdminSignup = true" class="text-xs text-orange-500 hover:text-orange-600 font-semibold hover:underline whitespace-nowrap">
                👑 Admin Sign Up
              </button>
              <span v-if="errorMsg" class="text-xs text-red-600 font-medium max-w-[150px] text-right">{{ errorMsg }}</span>
            </div>
          </div>
          <p class="text-slate-500 text-sm mb-4">
            {{ mode === 'login' ? 'Sign in to your NEXMART account.' : mode === 'forgot' || mode === 'reset' ? 'Reset your password securely.' : (isLogisticsSignup ? 'Register your logistics company.' : (selectedRole === 'driver' ? 'Register as a driver.' : 'Select your role to get started.')) }}
          </p>

          <!-- TABS -->
          <div v-if="mode === 'login' || mode === 'signup'" class="flex bg-slate-100 rounded-lg p-1 mb-4">
            <button @click="switchMode('login')" class="tab-pill flex-1 py-2 rounded-md text-sm font-semibold" :class="mode === 'login' ? 'bg-white text-orange-600 shadow-sm' : 'text-slate-500'">Log In</button>
            <button @click="switchMode('signup')" class="tab-pill flex-1 py-2 rounded-md text-sm font-semibold" :class="mode === 'signup' ? 'bg-white text-orange-600 shadow-sm' : 'text-slate-500'">Sign Up</button>
          </div>

          <!-- FORGOT PASSWORD / RESET WIZARD -->
          <div v-if="mode === 'forgot' || mode === 'reset'" class="space-y-4">
            <!-- Progress Steps -->
            <div class="flex items-center gap-2 mb-4">
              <div class="flex items-center flex-1">
                <div class="step-dot" :class="{ 'bg-orange-500 text-white': resetStep >= 1, 'bg-slate-200 text-slate-500': resetStep < 1 }">1</div>
                <div class="step-line" :class="{ 'active': resetStep > 1 }"></div>
              </div>
              <div class="flex items-center flex-1">
                <div class="step-dot" :class="{ 'bg-orange-500 text-white': resetStep >= 2, 'bg-slate-200 text-slate-500': resetStep < 2 }">2</div>
                <div class="step-line" :class="{ 'active': resetStep > 2 }"></div>
              </div>
              <div class="flex items-center flex-1">
                <div class="step-dot" :class="{ 'bg-orange-500 text-white': resetStep >= 3, 'bg-slate-200 text-slate-500': resetStep < 3 }">3</div>
              </div>
            </div>

            <!-- Step 1: Enter Email -->
            <div v-if="resetStep === 1">
              <p class="text-sm text-slate-500 mb-3">Enter your email to receive a verification code.</p>
              <div>
                <label class="field-label">Email Address <span class="text-orange-500">*</span></label>
                <input v-model="forgotEmail" type="email" placeholder="juan@email.com" class="field-input" />
              </div>
              <p v-if="errorMsg" class="text-sm text-red-600 mt-2">{{ errorMsg }}</p>
              <p v-if="successMsg" class="text-sm text-green-600 mt-2">{{ successMsg }}</p>
              <button @click="handleSendCode" class="btn-gradient w-full text-white font-semibold py-2.5 rounded-lg mt-3">Send Verification Code</button>
              <p class="text-center text-sm text-slate-500 mt-3">
                Remembered it? <a href="#" @click.prevent="switchMode('login')" class="text-orange-600 font-semibold hover:underline">Back to login</a>
              </p>
            </div>

            <!-- Step 2: Verify Code -->
            <div v-if="resetStep === 2">
              <p class="text-sm text-slate-500 mb-3">Enter the 6-digit code sent to your email.</p>
              <div>
                <label class="field-label">Verification Code <span class="text-orange-500">*</span></label>
                <input v-model="resetCode" type="text" placeholder="6-digit code" maxlength="6" class="field-input" />
                <p class="text-xs text-slate-400 mt-1">Check your email for the code. Expires in 15 minutes.</p>
              </div>
              <p v-if="errorMsg" class="text-sm text-red-600 mt-2">{{ errorMsg }}</p>
              <p v-if="successMsg" class="text-sm text-green-600 mt-2">{{ successMsg }}</p>
              <button @click="handleVerifyCode" :disabled="isVerifying" class="btn-gradient w-full text-white font-semibold py-2.5 rounded-lg mt-3 disabled:opacity-50">
                {{ isVerifying ? 'Verifying...' : 'Verify Code' }}
              </button>
              <div class="flex items-center justify-between mt-3">
                <p class="text-sm text-slate-500">
                  <a href="#" @click.prevent="handleResendCode" class="text-orange-600 font-semibold hover:underline">Resend code</a>
                </p>
                <p class="text-sm text-slate-500">
                  <a href="#" @click.prevent="goToResetStep(1)" class="text-orange-600 font-semibold hover:underline">Change email</a>
                </p>
              </div>
            </div>

            <!-- Step 3: New Password -->
            <div v-if="resetStep === 3">
              <p class="text-sm text-slate-500 mb-3">Create a new password for your account.</p>
              <div>
                <label class="field-label">New Password <span class="text-orange-500">*</span></label>
                <div class="relative">
                  <input :type="showPassword ? 'text' : 'password'" v-model="newPassword" placeholder="Min 8 characters" class="field-input pr-10" />
                  <button type="button" @click="showPassword = !showPassword" class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400">
                    <svg v-if="!showPassword" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
                    <svg v-else width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c6.5 0 10 8 10 8a13.16 13.16 0 0 1-1.67 2.68"/><path d="M6.61 6.61A13.53 13.53 0 0 0 2 12s3.5 8 10 8a9.74 9.74 0 0 0 5.39-1.61"/><path d="m2 2 20 20"/><path d="M14.12 14.12a3 3 0 1 1-4.24-4.24"/></svg>
                  </button>
                </div>
              </div>
              <div class="mt-2">
                <label class="field-label">Confirm New Password <span class="text-orange-500">*</span></label>
                <input :type="showPassword ? 'text' : 'password'" v-model="newConfirmPassword" placeholder="Re-enter password" class="field-input" />
              </div>
              <p v-if="errorMsg" class="text-sm text-red-600 mt-2">{{ errorMsg }}</p>
              <p v-if="successMsg" class="text-sm text-green-600 mt-2">{{ successMsg }}</p>
              <button @click="handleResetPassword" class="btn-gradient w-full text-white font-semibold py-2.5 rounded-lg mt-3">Reset Password</button>
            </div>
          </div>

          <!-- LOGIN FORM -->
          <div v-if="mode === 'login'" class="space-y-3">
            <div>
              <label class="field-label">Email Address <span class="text-orange-500">*</span></label>
              <input v-model="email" type="email" placeholder="juan@email.com" class="field-input" />
            </div>
            <div>
              <label class="field-label">Password <span class="text-orange-500">*</span></label>
              <div class="relative">
                <input :type="showPassword ? 'text' : 'password'" v-model="password" placeholder="Enter your password" class="field-input pr-10" />
                <button type="button" @click="showPassword = !showPassword" class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400">
                  <svg v-if="!showPassword" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
                  <svg v-else width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c6.5 0 10 8 10 8a13.16 13.16 0 0 1-1.67 2.68"/><path d="M6.61 6.61A13.53 13.53 0 0 0 2 12s3.5 8 10 8a9.74 9.74 0 0 0 5.39-1.61"/><path d="m2 2 20 20"/><path d="M14.12 14.12a3 3 0 1 1-4.24-4.24"/></svg>
                </button>
              </div>
            </div>
            <div class="flex items-center justify-between text-sm">
              <label class="flex items-center gap-2 text-slate-600"><input type="checkbox" v-model="rememberMe" class="rounded border-slate-300 text-orange-500 focus:ring-orange-400" /> Remember me</label>
              <a href="#" @click.prevent="switchMode('forgot')" class="text-orange-600 font-medium hover:underline">Forgot password?</a>
            </div>
            <p v-if="errorMsg" class="text-sm text-red-600">{{ errorMsg }}</p>
            <p v-if="successMsg" class="text-sm text-green-600">{{ successMsg }}</p>
            <button @click="handleLogin" class="btn-gradient w-full text-white font-semibold py-2.5 rounded-lg">Sign In</button>
            <div class="flex items-center gap-3"><div class="flex-1 h-px bg-slate-200"></div><span class="text-xs text-slate-400">or</span><div class="flex-1 h-px bg-slate-200"></div></div>
            <button @click="continueAsGuest" class="w-full border border-slate-300 text-slate-700 font-semibold py-2.5 rounded-lg hover:bg-slate-50">Continue as Guest</button>
            <p class="text-center text-sm text-slate-500">Don't have an account? <a href="#" @click.prevent="switchMode('signup')" class="text-orange-600 font-semibold hover:underline">Sign up</a></p>
            <p class="text-center text-xs text-slate-400">Try: juan@email.com / password123</p>
          </div>

          <!-- SIGNUP -->
          <div v-else-if="mode === 'signup'" style="display:flex;flex-direction:column;height:100%;">
            <!-- ROLE SELECTION -->
            <div v-if="signupStep === 'role' && !isLogisticsSignup" class="space-y-4" style="flex:1;display:flex;flex-direction:column;justify-content:center;">
              <p class="text-sm text-slate-600">Select your role to start your registration.</p>
              <div class="grid grid-cols-2 gap-3">
                <button v-for="r in roles" :key="r.id" @click="selectRole(r.id)" class="signup-role-card rounded-xl p-4 text-center" :class="{ selected: selectedRole === r.id }">
                  <div class="w-9 h-9 mx-auto mb-2 flex items-center justify-center text-slate-400">
                    <svg v-if="r.icon === 'bag'" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4Z"/><path d="M3 6h18"/><path d="M16 10a4 4 0 0 1-8 0"/></svg>
                    <svg v-if="r.icon === 'house'" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2Z"/><path d="M9 22V12h6v10"/></svg>
                    <svg v-if="r.icon === 'truck'" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M14 18V6a1 1 0 0 0-1-1H4a1 1 0 0 0-1 1v11a1 1 0 0 0 1 1h1"/><path d="M15 18H9"/><path d="M19 18h2a1 1 0 0 0 1-1v-3.65a1 1 0 0 0-.22-.624l-3.48-4.35A1 1 0 0 0 17.52 8H14"/><circle cx="7" cy="18" r="2"/><circle cx="17" cy="18" r="2"/></svg>
                    <svg v-if="r.icon === 'car'" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M18 2h-3a5 5 0 0 0-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 0 1 1-1h3z"/></svg>
                  </div>
                  <p class="text-sm font-bold text-slate-800">{{ r.label }}</p>
                  <p class="text-xs text-slate-500 mt-1 leading-snug">{{ r.desc }}</p>
                </button>
              </div>
              
              <!-- Logistics Company Link -->
              <div class="mt-4 pt-4 border-t border-slate-200">
                <p class="text-center text-sm text-slate-600">
                  Have your own logistics company? 
                  <a href="#" @click.prevent="startLogisticsSignup" class="logistics-link">Sign up here</a>
                </p>
              </div>
              
              <p class="text-center text-sm text-slate-500">Already have an account? <a href="#" @click.prevent="switchMode('login')" class="text-orange-600 font-semibold hover:underline">Log in</a></p>
            </div>

            <!-- DRIVER SIGNUP FLOW -->
            <div v-else-if="selectedRole === 'driver' && signupStep !== 'complete'" style="display:flex;flex-direction:column;height:100%;">
              <!-- Step indicator -->
              <div class="flex items-center gap-1 mb-3">
                <div v-for="(s, idx) in driverSteps" :key="idx" class="flex items-center flex-1">
                  <div class="step-dot" :class="{ 'bg-orange-500 text-white': currentStepIndex >= idx, 'bg-slate-200 text-slate-500': currentStepIndex < idx }">{{ idx + 1 }}</div>
                  <div v-if="idx < driverSteps.length - 1" class="step-line" :class="{ 'active': currentStepIndex > idx }"></div>
                </div>
              </div>

              <div class="flex items-center justify-between mb-2">
                <p class="text-sm text-slate-500">Driver Registration</p>
                <span class="text-sm font-medium text-orange-600">Step {{ currentStepIndex + 1 }} of 4</span>
              </div>
              <h3 class="display-font text-xl font-bold text-slate-900 mb-3">{{ driverSteps[currentStepIndex] }}</h3>

              <div class="fields-container">
                <!-- DRIVER PERSONAL -->
                <div v-if="signupStep === 'driverPersonal'">
                  <div class="form-grid">
                    <div>
                      <label class="field-label">Last Name <span class="text-orange-500">*</span></label>
                      <input v-model="form.driverLastName" @input="formatName($event, 'driverLastName')" placeholder="Dela Cruz" class="field-input" :class="{ 'border-red-500': validationErrors.driverLastName }" />
                      <span v-if="validationErrors.driverLastName" class="text-xs text-red-500">{{ validationErrors.driverLastName }}</span>
                    </div>
                    <div>
                      <label class="field-label">First Name <span class="text-orange-500">*</span></label>
                      <input v-model="form.driverFirstName" @input="formatName($event, 'driverFirstName')" placeholder="Juan" class="field-input" :class="{ 'border-red-500': validationErrors.driverFirstName }" />
                      <span v-if="validationErrors.driverFirstName" class="text-xs text-red-500">{{ validationErrors.driverFirstName }}</span>
                    </div>
                    <div>
                      <label class="field-label">M.I.</label>
                      <input v-model="form.driverMiddleInitial" @input="formatMiddleInitial($event, 'driverMiddleInitial')" maxlength="1" placeholder="B" class="field-input" :class="{ 'border-red-500': validationErrors.driverMiddleInitial }" />
                      <span v-if="validationErrors.driverMiddleInitial" class="text-xs text-red-500">{{ validationErrors.driverMiddleInitial }}</span>
                    </div>
                    <div>
                      <label class="field-label">Sex <span class="text-orange-500">*</span></label>
                      <select v-model="form.driverSex" class="field-input" :class="{ 'border-red-500': !form.driverSex && errorMsg }">
                        <option value="">Select</option><option value="Male">Male</option><option value="Female">Female</option>
                      </select>
                    </div>
                    <div>
                      <label class="field-label">Birthday <span class="text-orange-500">*</span></label>
                      <input v-model="form.driverBirthday" type="date" class="field-input" :class="{ 'border-red-500': validationErrors.driverBirthday }" />
                      <span v-if="validationErrors.driverBirthday" class="text-xs text-red-500">{{ validationErrors.driverBirthday }}</span>
                    </div>
                    <div>
                      <label class="field-label">Contact No. <span class="text-orange-500">*</span></label>
                      <input v-model="form.driverContactNo" @input="formatContactNumber($event, 'driverContactNo')" placeholder="09XXXXXXXXX" class="field-input" :class="{ 'border-red-500': validationErrors.driverContactNo }" />
                      <span v-if="validationErrors.driverContactNo" class="text-xs text-red-500">{{ validationErrors.driverContactNo }}</span>
                    </div>
                    <div class="full-width">
                      <label class="field-label">Email <span class="text-orange-500">*</span></label>
                      <input v-model="form.driverEmail" type="email" placeholder="driver@email.com" class="field-input" :class="{ 'border-red-500': validationErrors.driverEmail }" />
                      <span v-if="validationErrors.driverEmail" class="text-xs text-red-500">{{ validationErrors.driverEmail }}</span>
                    </div>
                  </div>
                </div>

                <!-- DRIVER ADDRESS -->
                <div v-if="signupStep === 'driverAddress'">
                  <div class="form-grid">
                    <div class="full-width">
                      <label class="field-label">Province <span class="text-orange-500">*</span></label>
                      <select v-model="form.provinceCode" @change="onProvinceChange" class="field-input" :disabled="loadingProvinces">
                        <option value="">{{ loadingProvinces ? 'Loading provinces…' : 'Select province' }}</option>
                        <option v-for="p in provinceOptions" :key="p.code" :value="p.code">{{ p.name }}</option>
                      </select>
                    </div>
                    <div class="full-width">
                      <label class="field-label">Municipality / City <span class="text-orange-500">*</span></label>
                      <select v-model="form.municipalityCode" @change="onMunicipalityChange" class="field-input" :disabled="!form.provinceCode || loadingMunicipalities">
                        <option value="">{{ loadingMunicipalities ? 'Loading…' : (form.provinceCode ? 'Select municipality/city' : 'Select a province first') }}</option>
                        <option v-for="m in municipalityOptions" :key="m.code" :value="m.code">{{ m.name }}</option>
                      </select>
                    </div>
                    <div class="full-width">
                      <label class="field-label">Barangay <span class="text-orange-500">*</span></label>
                      <select v-model="form.barangay" class="field-input" :disabled="!form.municipalityCode || loadingBarangays">
                        <option value="">{{ loadingBarangays ? 'Loading…' : (form.municipalityCode ? 'Select barangay' : 'Select a municipality/city first') }}</option>
                        <option v-for="b in barangayOptions" :key="b.code" :value="b.name">{{ b.name }}</option>
                      </select>
                    </div>
                    <div><label class="field-label">Street <span class="text-orange-500">*</span></label><input v-model="form.street" placeholder="Street name" class="field-input" /></div>
                    <div><label class="field-label">House / Unit #</label><input v-model="form.houseNo" placeholder="123, Unit B" class="field-input" /></div>
                  </div>
                  <p v-if="addressApiError" class="text-sm text-red-600 mt-2">
                    {{ addressApiError }}
                    <button type="button" @click="retryAddressLoad" class="underline font-semibold ml-1">Retry</button>
                  </p>
                  <span v-if="errorMsg && !addressApiError" class="text-xs text-red-500">{{ errorMsg }}</span>
                </div>

                <!-- DRIVER SECURITY -->
                <div v-if="signupStep === 'driverSecurity'">
                  <div class="form-grid">
                    <div class="full-width">
                      <label class="field-label">Password <span class="text-orange-500">*</span></label>
                      <div class="relative">
                        <input :type="showPassword ? 'text' : 'password'" v-model="form.driverPassword" placeholder="Min 8 characters" class="field-input pr-10" :class="{ 'border-red-500': validationErrors.driverPassword }" />
                        <button type="button" @click="showPassword = !showPassword" class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400">
                          <svg v-if="!showPassword" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
                          <svg v-else width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c6.5 0 10 8 10 8a13.16 13.16 0 0 1-1.67 2.68"/><path d="M6.61 6.61A13.53 13.53 0 0 0 2 12s3.5 8 10 8a9.74 9.74 0 0 0 5.39-1.61"/><path d="m2 2 20 20"/><path d="M14.12 14.12a3 3 0 1 1-4.24-4.24"/></svg>
                        </button>
                      </div>
                      <span v-if="validationErrors.driverPassword" class="text-xs text-red-500">{{ validationErrors.driverPassword }}</span>
                    </div>
                    <div class="full-width">
                      <label class="field-label">Confirm Password <span class="text-orange-500">*</span></label>
                      <input :type="showPassword ? 'text' : 'password'" v-model="form.driverConfirmPassword" placeholder="Re-enter password" class="field-input" :class="{ 'border-red-500': validationErrors.driverConfirmPassword }" />
                      <span v-if="validationErrors.driverConfirmPassword" class="text-xs text-red-500">{{ validationErrors.driverConfirmPassword }}</span>
                    </div>
                    <div class="full-width flex items-center gap-2 text-sm text-slate-600"><input type="checkbox" v-model="showPassword" /> Show passwords</div>
                  </div>
                </div>

                <!-- DRIVER DOCUMENTS -->
                <div v-if="signupStep === 'driverDocuments'">
                  <div class="form-grid">
                    <div class="full-width"><label class="field-label">Vehicle Type <span class="text-orange-500">*</span></label>
                      <select v-model="form.driverVehicle" class="field-input">
                        <option value="">Select</option><option value="Motorcycle">Motorcycle</option><option value="Car">Car</option><option value="Van">Van</option><option value="Truck">Truck</option>
                      </select>
                    </div>
                    <div class="full-width"><label class="field-label">Plate Number <span class="text-orange-500">*</span></label><input v-model="form.driverPlateNumber" placeholder="ABC-1234" class="field-input" /></div>
                    <div class="full-width"><label class="field-label">Upload Valid ID <span class="text-orange-500">*</span></label><input type="file" @change="handleFileUpload($event, 'driverIdFile')" class="field-input text-sm p-1" /></div>
                    <div class="full-width"><label class="field-label">Upload Driver's License <span class="text-orange-500">*</span></label><input type="file" @change="handleFileUpload($event, 'driverLicenseFile')" class="field-input text-sm p-1" /></div>
                    <div class="full-width"><label class="field-label">Upload OR/CR <span class="text-orange-500">*</span></label><input type="file" @change="handleFileUpload($event, 'driverOrcrFile')" class="field-input text-sm p-1" /></div>
                  </div>
                  <span v-if="errorMsg" class="text-xs text-red-500">{{ errorMsg }}</span>
                </div>

                <p v-if="successMsg" class="text-sm text-green-600 mt-2">{{ successMsg }}</p>
              </div>

              <!-- Navigation -->
              <div class="nav-container">
                <div class="flex gap-3">
                  <button v-if="currentStepIndex > 0" @click="goToStep(['driverPersonal', 'driverAddress', 'driverSecurity', 'driverDocuments'][currentStepIndex - 1])" class="flex-1 border border-slate-300 text-slate-700 font-semibold py-2 rounded-lg hover:bg-slate-50">Back</button>
                  <button v-if="currentStepIndex < 3" @click="goToStep(['driverPersonal', 'driverAddress', 'driverSecurity', 'driverDocuments'][currentStepIndex + 1])" class="flex-1 btn-gradient text-white font-semibold py-2 rounded-lg">Next</button>
                  <button v-if="currentStepIndex === 3" @click="submitRegistration" class="flex-1 btn-gradient text-white font-semibold py-2 rounded-lg">Submit</button>
                </div>
              </div>
            </div>

            <!-- LOGISTICS SIGNUP FLOW -->
            <div v-else-if="isLogisticsSignup && signupStep !== 'complete'" style="display:flex;flex-direction:column;height:100%;">
              <!-- Step indicator -->
              <div class="flex items-center gap-1 mb-3">
                <div v-for="(s, idx) in logisticsSteps" :key="idx" class="flex items-center flex-1">
                  <div class="step-dot" :class="{ 'bg-orange-500 text-white': currentStepIndex >= idx, 'bg-slate-200 text-slate-500': currentStepIndex < idx }">{{ idx + 1 }}</div>
                  <div v-if="idx < logisticsSteps.length - 1" class="step-line" :class="{ 'active': currentStepIndex > idx }"></div>
                </div>
              </div>

              <div class="flex items-center justify-between mb-2">
                <p class="text-sm text-slate-500">Logistics Company Registration</p>
                <span class="text-sm font-medium text-orange-600">Step {{ currentStepIndex + 1 }} of 5</span>
              </div>
              <h3 class="display-font text-xl font-bold text-slate-900 mb-3">{{ logisticsSteps[currentStepIndex] }}</h3>

              <div class="fields-container">
                <!-- COMPANY INFO -->
                <div v-if="signupStep === 'company'">
                  <div class="form-grid">
                    <div class="full-width"><label class="field-label">Company Name <span class="text-orange-500">*</span></label><input v-model="form.companyName" placeholder="ABC Logistics Inc." class="field-input" /></div>
                    <div class="full-width"><label class="field-label">Company Email <span class="text-orange-500">*</span></label><input v-model="form.companyEmail" type="email" placeholder="info@abclogistics.com" class="field-input" /></div>
                    <div><label class="field-label">Contact No. <span class="text-orange-500">*</span></label><input v-model="form.companyContactNo" @input="formatContactNumber($event, 'companyContactNo')" placeholder="09XXXXXXXXX" class="field-input" /></div>
                    <div><label class="field-label">TIN <span class="text-orange-500">*</span></label><input v-model="form.companyTIN" placeholder="123-456-789-000" class="field-input" /></div>
                    <div class="full-width"><label class="field-label">SEC Registration #</label><input v-model="form.companySECReg" placeholder="SEC Reg. No. (if applicable)" class="field-input" /></div>
                  </div>
                  <span v-if="errorMsg" class="text-xs text-red-500">{{ errorMsg }}</span>
                </div>

                <!-- OWNER DETAILS -->
                <div v-if="signupStep === 'owner'">
                  <div class="form-grid">
                    <div><label class="field-label">Owner Last Name <span class="text-orange-500">*</span></label><input v-model="form.ownerLastName" @input="formatName($event, 'ownerLastName')" placeholder="Dela Cruz" class="field-input" /></div>
                    <div><label class="field-label">Owner First Name <span class="text-orange-500">*</span></label><input v-model="form.ownerFirstName" @input="formatName($event, 'ownerFirstName')" placeholder="Juan" class="field-input" /></div>
                    <div><label class="field-label">M.I.</label><input v-model="form.ownerMiddleInitial" @input="formatMiddleInitial($event, 'ownerMiddleInitial')" maxlength="1" placeholder="B" class="field-input" /></div>
                    <div><label class="field-label">Sex <span class="text-orange-500">*</span></label>
                      <select v-model="form.ownerSex" class="field-input">
                        <option value="">Select</option><option value="Male">Male</option><option value="Female">Female</option>
                      </select>
                    </div>
                    <div><label class="field-label">Birthday <span class="text-orange-500">*</span></label><input v-model="form.ownerBirthday" type="date" class="field-input" /></div>
                    <div><label class="field-label">Contact No. <span class="text-orange-500">*</span></label><input v-model="form.ownerContactNo" @input="formatContactNumber($event, 'ownerContactNo')" placeholder="09XXXXXXXXX" class="field-input" /></div>
                    <div class="full-width"><label class="field-label">Owner Email <span class="text-orange-500">*</span></label><input v-model="form.ownerEmail" type="email" placeholder="juan@email.com" class="field-input" /></div>
                  </div>
                  <span v-if="errorMsg" class="text-xs text-red-500">{{ errorMsg }}</span>
                </div>

                <!-- ADDRESS -->
                <div v-if="signupStep === 'address'">
                  <div class="form-grid">
                    <div class="full-width">
                      <label class="field-label">Province <span class="text-orange-500">*</span></label>
                      <select v-model="form.companyProvinceCode" @change="onCompanyProvinceChange" class="field-input" :disabled="loadingCompanyProvinces">
                        <option value="">{{ loadingCompanyProvinces ? 'Loading provinces…' : 'Select province' }}</option>
                        <option v-for="p in companyProvinceOptions" :key="p.code" :value="p.code">{{ p.name }}</option>
                      </select>
                    </div>
                    <div class="full-width">
                      <label class="field-label">Municipality / City <span class="text-orange-500">*</span></label>
                      <select v-model="form.companyMunicipalityCode" @change="onCompanyMunicipalityChange" class="field-input" :disabled="!form.companyProvinceCode || loadingCompanyMunicipalities">
                        <option value="">{{ loadingCompanyMunicipalities ? 'Loading…' : (form.companyProvinceCode ? 'Select municipality/city' : 'Select a province first') }}</option>
                        <option v-for="m in companyMunicipalityOptions" :key="m.code" :value="m.code">{{ m.name }}</option>
                      </select>
                    </div>
                    <div class="full-width">
                      <label class="field-label">Barangay <span class="text-orange-500">*</span></label>
                      <select v-model="form.companyBarangay" class="field-input" :disabled="!form.companyMunicipalityCode || loadingCompanyBarangays">
                        <option value="">{{ loadingCompanyBarangays ? 'Loading…' : (form.companyMunicipalityCode ? 'Select barangay' : 'Select a municipality/city first') }}</option>
                        <option v-for="b in companyBarangayOptions" :key="b.code" :value="b.name">{{ b.name }}</option>
                      </select>
                    </div>
                    <div><label class="field-label">Street <span class="text-orange-500">*</span></label><input v-model="form.companyStreet" placeholder="Street name" class="field-input" /></div>
                    <div><label class="field-label">House / Unit #</label><input v-model="form.companyHouseNo" placeholder="123, Unit B" class="field-input" /></div>
                  </div>
                  <p v-if="addressApiError" class="text-sm text-red-600 mt-2">
                    {{ addressApiError }}
                    <button type="button" @click="retryAddressLoad" class="underline font-semibold ml-1">Retry</button>
                  </p>
                  <span v-if="errorMsg && !addressApiError" class="text-xs text-red-500">{{ errorMsg }}</span>
                </div>

                <!-- SECURITY -->
                <div v-if="signupStep === 'security'">
                  <div class="form-grid">
                    <div class="full-width"><label class="field-label">Password <span class="text-orange-500">*</span></label>
                      <div class="relative">
                        <input :type="showPassword ? 'text' : 'password'" v-model="password" placeholder="Min 8 characters" class="field-input pr-10" />
                        <button type="button" @click="showPassword = !showPassword" class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400">
                          <svg v-if="!showPassword" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
                          <svg v-else width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c6.5 0 10 8 10 8a13.16 13.16 0 0 1-1.67 2.68"/><path d="M6.61 6.61A13.53 13.53 0 0 0 2 12s3.5 8 10 8a9.74 9.74 0 0 0 5.39-1.61"/><path d="m2 2 20 20"/><path d="M14.12 14.12a3 3 0 1 1-4.24-4.24"/></svg>
                        </button>
                      </div>
                    </div>
                    <div class="full-width"><label class="field-label">Confirm Password <span class="text-orange-500">*</span></label><input :type="showPassword ? 'text' : 'password'" v-model="confirmPassword" placeholder="Re-enter password" class="field-input" /></div>
                    <div class="full-width flex items-center gap-2 text-sm text-slate-600"><input type="checkbox" v-model="showPassword" /> Show passwords</div>
                  </div>
                  <span v-if="errorMsg" class="text-xs text-red-500">{{ errorMsg }}</span>
                </div>

                <!-- DOCUMENTS -->
                <div v-if="signupStep === 'documents'">
                  <div class="form-grid">
                    <div class="full-width"><label class="field-label">Owner's Valid ID <span class="text-orange-500">*</span></label><input type="file" @change="handleFileUpload($event, 'ownerIdFile')" class="field-input text-sm p-1" /></div>
                    <div class="full-width"><label class="field-label">Business Permit <span class="text-orange-500">*</span></label><input type="file" @change="handleFileUpload($event, 'businessPermitFile')" class="field-input text-sm p-1" /></div>
                    <div class="full-width"><label class="field-label">Mayor's Permit <span class="text-orange-500">*</span></label><input type="file" @change="handleFileUpload($event, 'mayorPermitFile')" class="field-input text-sm p-1" /></div>
                    <div class="full-width"><label class="field-label">DTI / SEC Registration <span class="text-orange-500">*</span></label><input type="file" @change="handleFileUpload($event, 'dtiRegFile')" class="field-input text-sm p-1" /></div>
                  </div>
                  <span v-if="errorMsg" class="text-xs text-red-500">{{ errorMsg }}</span>
                </div>

                <p v-if="successMsg" class="text-sm text-green-600 mt-2">{{ successMsg }}</p>
              </div>

              <!-- Navigation -->
              <div class="nav-container">
                <div class="flex gap-3">
                  <button v-if="currentStepIndex > 0" @click="goToStep(['company', 'owner', 'address', 'security', 'documents'][currentStepIndex - 1])" class="flex-1 border border-slate-300 text-slate-700 font-semibold py-2 rounded-lg hover:bg-slate-50">Back</button>
                  <button v-if="currentStepIndex < 4" @click="goToStep(['company', 'owner', 'address', 'security', 'documents'][currentStepIndex + 1])" class="flex-1 btn-gradient text-white font-semibold py-2 rounded-lg">Next</button>
                  <button v-if="currentStepIndex === 4" @click="submitRegistration" class="flex-1 btn-gradient text-white font-semibold py-2 rounded-lg">Submit</button>
                </div>
              </div>
            </div>

            <!-- REGULAR WIZARD STEPS -->
            <div v-else-if="signupStep !== 'complete' && !isLogisticsSignup && selectedRole !== 'driver'" style="display:flex;flex-direction:column;height:100%;">
              <!-- Step indicator -->
              <div class="flex items-center gap-1 mb-3">
                <div v-for="(s, idx) in steps" :key="idx" class="flex items-center flex-1">
                  <div class="step-dot" :class="{ 'bg-orange-500 text-white': currentStepIndex >= idx, 'bg-slate-200 text-slate-500': currentStepIndex < idx }">{{ idx + 1 }}</div>
                  <div v-if="idx < steps.length - 1" class="step-line" :class="{ 'active': currentStepIndex > idx }"></div>
                </div>
              </div>

              <div class="flex items-center justify-between mb-2">
                <p class="text-sm text-slate-500">Register as <span class="font-semibold text-slate-800 capitalize">{{ selectedRole }}</span></p>
                <span class="text-sm font-medium text-orange-600">Step {{ currentStepIndex + 1 }} of 4</span>
              </div>
              <h3 class="display-font text-xl font-bold text-slate-900 mb-3">{{ steps[currentStepIndex] }} Info</h3>

              <div class="fields-container">
                <!-- PERSONAL INFO -->
                <div v-if="signupStep === 'personal'">
                  <div class="form-grid">
                    <div>
                      <label class="field-label">Last Name <span class="text-orange-500">*</span></label>
                      <input v-model="form.lastName" @input="formatName($event, 'lastName')" placeholder="Dela Cruz" class="field-input" :class="{ 'border-red-500': validationErrors.lastName }" />
                      <span v-if="validationErrors.lastName" class="text-xs text-red-500">{{ validationErrors.lastName }}</span>
                    </div>
                    <div>
                      <label class="field-label">First Name <span class="text-orange-500">*</span></label>
                      <input v-model="form.firstName" @input="formatName($event, 'firstName')" placeholder="Juan" class="field-input" :class="{ 'border-red-500': validationErrors.firstName }" />
                      <span v-if="validationErrors.firstName" class="text-xs text-red-500">{{ validationErrors.firstName }}</span>
                    </div>
                    <div>
                      <label class="field-label">M.I.</label>
                      <input v-model="form.middleInitial" @input="formatMiddleInitial($event, 'middleInitial')" maxlength="1" placeholder="B" class="field-input" :class="{ 'border-red-500': validationErrors.middleInitial }" />
                      <span v-if="validationErrors.middleInitial" class="text-xs text-red-500">{{ validationErrors.middleInitial }}</span>
                    </div>
                    <div>
                      <label class="field-label">Sex <span class="text-orange-500">*</span></label>
                      <select v-model="form.sex" class="field-input" :class="{ 'border-red-500': !form.sex && errorMsg }">
                        <option value="">Select</option><option value="Male">Male</option><option value="Female">Female</option>
                      </select>
                    </div>
                    <div>
                      <label class="field-label">Birthday <span class="text-orange-500">*</span></label>
                      <input v-model="form.birthday" type="date" class="field-input" :class="{ 'border-red-500': validationErrors.birthday }" />
                      <span v-if="validationErrors.birthday" class="text-xs text-red-500">{{ validationErrors.birthday }}</span>
                    </div>
                    <div>
                      <label class="field-label">Contact No. <span class="text-orange-500">*</span></label>
                      <input v-model="form.contactNo" @input="formatContactNumber($event, 'contactNo')" placeholder="09XXXXXXXXX" class="field-input" :class="{ 'border-red-500': validationErrors.contactNo }" />
                      <span v-if="validationErrors.contactNo" class="text-xs text-red-500">{{ validationErrors.contactNo }}</span>
                    </div>
                    <div class="full-width">
                      <label class="field-label">Email <span class="text-orange-500">*</span></label>
                      <input v-model="email" type="email" placeholder="juan@email.com" class="field-input" :class="{ 'border-red-500': validationErrors.email }" />
                      <span v-if="validationErrors.email" class="text-xs text-red-500">{{ validationErrors.email }}</span>
                    </div>
                  </div>
                </div>

                <!-- ADDRESS -->
                <div v-if="signupStep === 'address'">
                  <div class="form-grid">
                    <div class="full-width">
                      <label class="field-label">Province <span class="text-orange-500">*</span></label>
                      <select v-model="form.provinceCode" @change="onProvinceChange" class="field-input" :disabled="loadingProvinces">
                        <option value="">{{ loadingProvinces ? 'Loading provinces…' : 'Select province' }}</option>
                        <option v-for="p in provinceOptions" :key="p.code" :value="p.code">{{ p.name }}</option>
                      </select>
                    </div>
                    <div class="full-width">
                      <label class="field-label">Municipality / City <span class="text-orange-500">*</span></label>
                      <select v-model="form.municipalityCode" @change="onMunicipalityChange" class="field-input" :disabled="!form.provinceCode || loadingMunicipalities">
                        <option value="">{{ loadingMunicipalities ? 'Loading…' : (form.provinceCode ? 'Select municipality/city' : 'Select a province first') }}</option>
                        <option v-for="m in municipalityOptions" :key="m.code" :value="m.code">{{ m.name }}</option>
                      </select>
                    </div>
                    <div class="full-width">
                      <label class="field-label">Barangay <span class="text-orange-500">*</span></label>
                      <select v-model="form.barangay" class="field-input" :disabled="!form.municipalityCode || loadingBarangays">
                        <option value="">{{ loadingBarangays ? 'Loading…' : (form.municipalityCode ? 'Select barangay' : 'Select a municipality/city first') }}</option>
                        <option v-for="b in barangayOptions" :key="b.code" :value="b.name">{{ b.name }}</option>
                      </select>
                    </div>
                    <div><label class="field-label">Street <span class="text-orange-500">*</span></label><input v-model="form.street" placeholder="Street name" class="field-input" /></div>
                    <div><label class="field-label">House / Unit #</label><input v-model="form.houseNo" placeholder="123, Unit B" class="field-input" /></div>
                  </div>
                  <p v-if="addressApiError" class="text-sm text-red-600 mt-2">
                    {{ addressApiError }}
                    <button type="button" @click="retryAddressLoad" class="underline font-semibold ml-1">Retry</button>
                  </p>
                  <span v-if="errorMsg && !addressApiError" class="text-xs text-red-500">{{ errorMsg }}</span>
                </div>

                <!-- SECURITY -->
                <div v-if="signupStep === 'security'">
                  <div class="form-grid">
                    <div class="full-width">
                      <label class="field-label">Password <span class="text-orange-500">*</span></label>
                      <div class="relative">
                        <input :type="showPassword ? 'text' : 'password'" v-model="password" placeholder="Min 8 characters" class="field-input pr-10" :class="{ 'border-red-500': validationErrors.password }" />
                        <button type="button" @click="showPassword = !showPassword" class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400">
                          <svg v-if="!showPassword" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
                          <svg v-else width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c6.5 0 10 8 10 8a13.16 13.16 0 0 1-1.67 2.68"/><path d="M6.61 6.61A13.53 13.53 0 0 0 2 12s3.5 8 10 8a9.74 9.74 0 0 0 5.39-1.61"/><path d="m2 2 20 20"/><path d="M14.12 14.12a3 3 0 1 1-4.24-4.24"/></svg>
                        </button>
                      </div>
                      <span v-if="validationErrors.password" class="text-xs text-red-500">{{ validationErrors.password }}</span>
                    </div>
                    <div class="full-width">
                      <label class="field-label">Confirm Password <span class="text-orange-500">*</span></label>
                      <input :type="showPassword ? 'text' : 'password'" v-model="confirmPassword" placeholder="Re-enter password" class="field-input" :class="{ 'border-red-500': validationErrors.confirmPassword }" />
                      <span v-if="validationErrors.confirmPassword" class="text-xs text-red-500">{{ validationErrors.confirmPassword }}</span>
                    </div>
                    <div class="full-width flex items-center gap-2 text-sm text-slate-600"><input type="checkbox" v-model="showPassword" /> Show passwords</div>
                  </div>
                </div>

                <!-- DOCUMENTS -->
                <div v-if="signupStep === 'documents'">
                  <div class="form-grid">
                    <template v-if="selectedRole === 'buyer'">
                      <div class="full-width"><label class="field-label">Upload ID <span class="text-orange-500">*</span></label><input type="file" @change="handleFileUpload($event, 'idFile')" class="field-input text-sm p-1" /></div>
                    </template>
                    <template v-if="selectedRole === 'seller'">
                      <div class="full-width"><label class="field-label">Business Name <span class="text-orange-500">*</span></label><input v-model="form.businessName" placeholder="My Store" class="field-input" /></div>
                      <div class="full-width"><label class="field-label">Line of Business <span class="text-orange-500">*</span></label><input v-model="form.lineOfBusiness" placeholder="Retail, Food" class="field-input" /></div>
                      <div class="full-width"><label class="field-label">Upload ID <span class="text-orange-500">*</span></label><input type="file" @change="handleFileUpload($event, 'idFile')" class="field-input text-sm p-1" /></div>
                      <div class="full-width"><label class="field-label">Upload Business Permit <span class="text-orange-500">*</span></label><input type="file" @change="handleFileUpload($event, 'businessPermit')" class="field-input text-sm p-1" /></div>
                    </template>
                    <template v-if="selectedRole === 'courier'">
                      <div class="full-width"><label class="field-label">Vehicle <span class="text-orange-500">*</span></label>
                        <select v-model="form.vehicle" class="field-input">
                          <option value="">Select</option><option value="Motorcycle">Motorcycle</option><option value="Car">Car</option><option value="Van">Van</option><option value="Bicycle">Bicycle</option>
                        </select>
                      </div>
                      <div class="full-width"><label class="field-label">Plate Number <span class="text-orange-500">*</span></label><input v-model="form.plateNumber" placeholder="ABC-1234" class="field-input" /></div>
                      <div class="full-width"><label class="field-label">Upload OR/CR <span class="text-orange-500">*</span></label><input type="file" @change="handleFileUpload($event, 'orcrFile')" class="field-input text-sm p-1" /></div>
                      <div class="full-width"><label class="field-label">Upload ID / Driver's License <span class="text-orange-500">*</span></label><input type="file" @change="handleFileUpload($event, 'licenseFile')" class="field-input text-sm p-1" /></div>
                    </template>
                  </div>
                  <span v-if="errorMsg" class="text-xs text-red-500">{{ errorMsg }}</span>
                </div>

                <p v-if="successMsg" class="text-sm text-green-600 mt-2">{{ successMsg }}</p>
              </div>

              <!-- Navigation -->
              <div class="nav-container">
                <div class="flex gap-3">
                  <button v-if="currentStepIndex > 0" @click="goToStep(steps[currentStepIndex - 1].toLowerCase())" class="flex-1 border border-slate-300 text-slate-700 font-semibold py-2 rounded-lg hover:bg-slate-50">Back</button>
                  <button v-if="currentStepIndex < 3" @click="goToStep(steps[currentStepIndex + 1].toLowerCase())" class="flex-1 btn-gradient text-white font-semibold py-2 rounded-lg">Next</button>
                  <button v-if="currentStepIndex === 3" @click="submitRegistration" class="flex-1 btn-gradient text-white font-semibold py-2 rounded-lg">Submit</button>
                </div>
              </div>
            </div>

            <!-- COMPLETE -->
            <div v-else-if="signupStep === 'complete'" class="text-center space-y-4" style="padding:2rem 0;">
              <div class="w-20 h-20 rounded-full bg-green-100 text-green-600 flex items-center justify-center mx-auto text-3xl">✓</div>
              <h3 class="display-font text-2xl font-bold text-slate-900">Registration submitted!</h3>
              <p class="text-slate-600 text-sm">Please wait for the administrator's approval, which will be sent to your email.</p>
              <button @click="switchMode('login')" class="btn-gradient text-white font-semibold py-2 px-6 rounded-lg">Go to Login</button>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- ADMIN SIGNUP MODAL -->
  <div v-if="showAdminSignup" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50">
    <div class="bg-white rounded-xl p-6 w-full max-w-md max-h-[90vh] overflow-y-auto">
      <div class="flex items-center justify-between mb-4">
        <h3 class="text-xl font-bold text-slate-900">Create Admin Account</h3>
        <button @click="showAdminSignup = false" class="text-slate-400 hover:text-slate-600 text-xl">&times;</button>
      </div>
      
      <div class="space-y-3">
        <div>
          <label class="field-label">First Name <span class="text-orange-500">*</span></label>
          <input v-model="adminForm.firstName" @input="formatName($event, 'firstName')" placeholder="Juan" class="field-input" />
        </div>
        <div>
          <label class="field-label">Last Name <span class="text-orange-500">*</span></label>
          <input v-model="adminForm.lastName" @input="formatName($event, 'lastName')" placeholder="Dela Cruz" class="field-input" />
        </div>
        <div>
          <label class="field-label">Email <span class="text-orange-500">*</span></label>
          <input v-model="adminForm.email" type="email" placeholder="admin@nexmart.com" class="field-input" />
        </div>
        <div>
          <label class="field-label">Password <span class="text-orange-500">*</span></label>
          <div class="relative">
            <input :type="showPassword ? 'text' : 'password'" v-model="adminForm.password" placeholder="Min 8 characters" class="field-input pr-10" />
            <button type="button" @click="showPassword = !showPassword" class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400">
              <svg v-if="!showPassword" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
              <svg v-else width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c6.5 0 10 8 10 8a13.16 13.16 0 0 1-1.67 2.68"/><path d="M6.61 6.61A13.53 13.53 0 0 0 2 12s3.5 8 10 8a9.74 9.74 0 0 0 5.39-1.61"/><path d="m2 2 20 20"/><path d="M14.12 14.12a3 3 0 1 1-4.24-4.24"/></svg>
            </button>
          </div>
        </div>
        <div>
          <label class="field-label">Confirm Password <span class="text-orange-500">*</span></label>
          <input :type="showPassword ? 'text' : 'password'" v-model="adminForm.confirmPassword" placeholder="Confirm password" class="field-input" />
        </div>
        
        <p v-if="errorMsg" class="text-sm text-red-600">{{ errorMsg }}</p>
        <p v-if="successMsg" class="text-sm text-green-600">{{ successMsg }}</p>
        
        <button @click="handleAdminSignup" :disabled="adminSignupLoading" class="btn-gradient w-full text-white font-semibold py-2.5 rounded-lg disabled:opacity-50">
          {{ adminSignupLoading ? 'Creating...' : 'Create Admin Account' }}
        </button>
      </div>
    </div>
  </div>
  `
};

// Mount the app
createApp(App).mount('#app');