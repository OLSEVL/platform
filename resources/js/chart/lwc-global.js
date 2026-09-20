/**
 * Expose Lightweight Charts v5 as window.LightweightCharts — same contract as the
 * former unpkg standalone build used by dashboard / bots/show Blade pages.
 */
import * as LightweightCharts from 'lightweight-charts';

window.LightweightCharts = LightweightCharts;

export { LightweightCharts };
export default LightweightCharts;
