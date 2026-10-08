// resources/js/seller/seller.js
import '../../css/seller/layout.css';
import { createApp } from 'vue';
import { mountCookieConsent } from '../shared/mountCookieConsent';
import SellerLayout from './components/SellerLayout.vue';

createApp(SellerLayout).mount('#app');
mountCookieConsent();
