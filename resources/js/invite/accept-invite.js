import '../../css/app.css';
import { createApp } from 'vue';
import { mountCookieConsent } from '../shared/mountCookieConsent';
import AcceptInvite from './AcceptInvite.vue';

createApp(AcceptInvite).mount('#invite-app');
mountCookieConsent();
