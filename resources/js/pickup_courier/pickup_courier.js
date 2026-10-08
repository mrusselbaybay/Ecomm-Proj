// resources/js/pickup_courier/pickup_courier.js
import { createApp } from 'vue';
import { mountCookieConsent } from '../shared/mountCookieConsent';
import PickupCourierLayout from './components/PickupCourierLayout.vue';

createApp(PickupCourierLayout).mount('#app');
mountCookieConsent();
