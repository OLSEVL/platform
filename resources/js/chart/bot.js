/**
 * Bot chart page entry (Vite).
 * Load order mirrors the old Blade stack: LWC → helpers → instances → page → popup.
 */
import './lwc-global.js';
import './fetch-helper.js';
import './candle-smooth-helper.js';
import './markers-helper.js';
import './live-feed.js';
import './trade-lite.js';
import './timeframe.js';
import './indicator-instances.js';
import './bot-chart.js';
import './indicators-popup.js';
