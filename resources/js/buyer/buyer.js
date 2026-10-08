import { createApp } from 'vue';
import Dashboard from './components/Dashboard.vue';
import '../../css/buyer/layout.css';
import { mountCookieConsent } from '../shared/mountCookieConsent';

const app = createApp(Dashboard);

app.mount('#buyer-app');
mountCookieConsent();
