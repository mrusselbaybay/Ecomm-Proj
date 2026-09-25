// Logistics company dashboard.
import '../../css/logistics/logistics.css';
import { createApp } from 'vue';
import LogisticsLayout from './components/LogisticsLayout.vue';

export default function mount(el) {
    createApp(LogisticsLayout).mount(el);
}
