
import { computed, ref } from 'vue';

/*
|--------------------------------------------------------------------------
| Shared Buyer Account State
|--------------------------------------------------------------------------
|
| These values are temporary frontend data. Because the ref is outside the
| composable, profile changes remain available while the Buyer switches views.
|
| Later:
|
| Account.vue
|      ↓
| Laravel API
|      ↓
| Supabase/PostgreSQL
|      ↓
| buyerProfile.value = API response
|
*/

const buyerProfile = ref({
    buyerId: 'BUYER-001',
    firstName: 'Juan',
    middleInitial: 'S',
    lastName: 'Dela Cruz',
    sex: 'Male',
    email: 'buyer@nexmart.test',
    contactNumber: '09123456789',
    birthday: '2001-05-15',
    accountStatus: 'Approved',
    emailVerified: true,
    createdAt: new Date().toISOString(),
    updatedAt: new Date().toISOString()
});

function calculateAge(birthday) {
    if (!birthday) {
        return null;
    }

    const birthDate = new Date(
        `${birthday}T00:00:00`
    );

    if (Number.isNaN(birthDate.getTime())) {
        return null;
    }

    const today = new Date();
    let age =
        today.getFullYear() -
        birthDate.getFullYear();

    const monthDifference =
        today.getMonth() -
        birthDate.getMonth();

    if (
        monthDifference < 0 ||
        (
            monthDifference === 0 &&
            today.getDate() < birthDate.getDate()
        )
    ) {
        age--;
    }

    return age >= 0 ? age : null;
}

const buyerFullName = computed(() => {
    const middle = buyerProfile.value.middleInitial
        ? `${buyerProfile.value.middleInitial}.`
        : '';

    return [
        buyerProfile.value.firstName,
        middle,
        buyerProfile.value.lastName
    ]
        .filter(Boolean)
        .join(' ');
});

const buyerInitials = computed(() => {
    const firstInitial =
        buyerProfile.value.firstName?.charAt(0) ||
        '';

    const lastInitial =
        buyerProfile.value.lastName?.charAt(0) ||
        '';

    return `${firstInitial}${lastInitial}`
        .toUpperCase() ||
        'BU';
});

const buyerAge = computed(() => {
    return calculateAge(
        buyerProfile.value.birthday
    );
});

function updateBuyerProfile(profileData) {
    if (!profileData) {
        return null;
    }

    buyerProfile.value = {
        ...buyerProfile.value,
        firstName: String(
            profileData.firstName || ''
        ).trim(),
        middleInitial: String(
            profileData.middleInitial || ''
        )
            .trim()
            .charAt(0)
            .toUpperCase(),
        lastName: String(
            profileData.lastName || ''
        ).trim(),
        sex: String(
            profileData.sex || ''
        ),
        email: String(
            profileData.email || ''
        )
            .trim()
            .toLowerCase(),
        contactNumber: String(
            profileData.contactNumber || ''
        ).trim(),
        birthday: String(
            profileData.birthday || ''
        ),
        updatedAt: new Date().toISOString()
    };

    return buyerProfile.value;
}

export function useBuyerAccount() {
    return {
        buyerProfile,
        buyerFullName,
        buyerInitials,
        buyerAge,
        calculateAge,
        updateBuyerProfile
    };
}