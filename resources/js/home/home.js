import { createApp } from 'vue';
import Home from './components/Home.vue';
import '../../css/home/layout.css';
import { mountCookieConsent } from '../shared/mountCookieConsent';

createApp(Home).mount('#home-app');
mountCookieConsent();
