/**
 * Dashboard chart page entry (Vite).
 * Load order mirrors the old Blade stack: LWC global → helpers → page runtime → popup.
 */
import './lwc-global.js';
import './fetch-helper.js';
import './candle-smooth-helper.js';
import './markers-helper.js';
import './live-feed.js';
import './trade-lite.js';
import './timeframe.js';
import './dashboard-chart.js';
import './indicators-popup.js';
