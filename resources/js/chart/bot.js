/**
 * Bot chart page entry (Vite).
 * Load order mirrors the old Blade stack: LWC → helpers → instances → page → popup.
 */
import './lwc-global.js';
import './fetch-helper.js';
import './candle-smooth-helper.js';
import './markers-helper.js';
import './canvas-overlay.js';
import './smc-flow-overlay.js';
import './live-feed.js';
import './trade-lite.js';
import './timeframe.js';
import './indicator-instances.js';
import './strat-assistant.js';
import './liquidity-swings.js';
import './price-action-dgt.js';
import './smc-flow-compute.js';
import './classic-indicators.js';
import './bot-chart.js';
import './indicators-popup.js';
