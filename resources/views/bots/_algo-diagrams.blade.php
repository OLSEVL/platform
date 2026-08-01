{{-- SVG-схемы алгоритмов для попапа /bots (ключ = meta.diagram). --}}
<script>
window.ALGO_DIAGRAMS = {
    // Общая рамка: тёмный фон, подписи CALL/PUT.
    _wrap(inner, caption) {
        return `<svg viewBox="0 0 420 200" class="w-full h-auto" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="${caption}">
            <rect width="420" height="200" rx="10" fill="#0c0e14"/>
            <text x="12" y="18" fill="#71717a" font-size="11" font-family="ui-sans-serif,system-ui">${caption}</text>
            ${inner}
        </svg>`;
    },
    _candle(x, o, c, w = 18) {
        const up = c >= o;
        const bodyTop = Math.min(o, c);
        const bodyH = Math.max(4, Math.abs(c - o));
        const color = up ? '#34d399' : '#f87171';
        const wickTop = Math.min(o, c) - 10;
        const wickBot = Math.max(o, c) + 10;
        return `<line x1="${x}" y1="${wickTop}" x2="${x}" y2="${wickBot}" stroke="${color}" stroke-width="2"/>
            <rect x="${x - w/2}" y="${bodyTop}" width="${w}" height="${bodyH}" fill="${color}" rx="1"/>`;
    },
    counter_three() {
        // 3 медвежьих → CALL
        const cs = this._candle(70, 70, 100) + this._candle(120, 85, 115) + this._candle(170, 100, 130);
        return this._wrap(`
            ${cs}
            <path d="M200 110 L250 80" stroke="#34d399" stroke-width="3" fill="none" marker-end="url(#arrG)"/>
            <defs><marker id="arrG" markerWidth="8" markerHeight="8" refX="6" refY="3" orient="auto"><path d="M0,0 L6,3 L0,6 Z" fill="#34d399"/></marker></defs>
            <rect x="260" y="60" width="70" height="28" rx="6" fill="#064e3b"/>
            <text x="295" y="79" text-anchor="middle" fill="#6ee7b7" font-size="14" font-weight="700" font-family="ui-sans-serif,system-ui">CALL</text>
            <text x="70" y="175" text-anchor="middle" fill="#a1a1aa" font-size="11" font-family="ui-sans-serif,system-ui">3↓</text>
            <text x="120" y="175" text-anchor="middle" fill="#a1a1aa" font-size="11" font-family="ui-sans-serif,system-ui">3↓</text>
            <text x="170" y="175" text-anchor="middle" fill="#a1a1aa" font-size="11" font-family="ui-sans-serif,system-ui">3↓</text>
            <text x="210" y="155" fill="#a1a1aa" font-size="11" font-family="ui-sans-serif,system-ui">ожидаем откат ↑</text>
        `, 'Контртренд: 3 медвежьих → CALL');
    },
    impulse_three() {
        const cs = this._candle(70, 120, 90) + this._candle(120, 105, 75) + this._candle(170, 90, 60);
        return this._wrap(`
            ${cs}
            <path d="M200 70 L250 55" stroke="#34d399" stroke-width="3" fill="none"/>
            <rect x="260" y="40" width="70" height="28" rx="6" fill="#064e3b"/>
            <text x="295" y="59" text-anchor="middle" fill="#6ee7b7" font-size="14" font-weight="700" font-family="ui-sans-serif,system-ui">CALL</text>
            <text x="120" y="175" text-anchor="middle" fill="#a1a1aa" font-size="11" font-family="ui-sans-serif,system-ui">3 бычьих подряд → вход по тренду</text>
        `, 'Импульс / три солдата → CALL');
    },
    pinbar() {
        // Бычий пинбар: длинная нижняя тень
        return this._wrap(`
            <line x1="140" y1="50" x2="140" y2="160" stroke="#34d399" stroke-width="2"/>
            <rect x="128" y="50" width="24" height="22" fill="#34d399" rx="1"/>
            <text x="180" y="70" fill="#a1a1aa" font-size="12" font-family="ui-sans-serif,system-ui">маленькое тело</text>
            <text x="180" y="140" fill="#a1a1aa" font-size="12" font-family="ui-sans-serif,system-ui">длинная нижняя тень</text>
            <path d="M200 100 L250 90" stroke="#34d399" stroke-width="3" fill="none"/>
            <rect x="260" y="75" width="70" height="28" rx="6" fill="#064e3b"/>
            <text x="295" y="94" text-anchor="middle" fill="#6ee7b7" font-size="14" font-weight="700" font-family="ui-sans-serif,system-ui">CALL</text>
        `, 'Бычий пинбар (молот)');
    },
    bb_reversion() {
        return this._wrap(`
            <path d="M40 70 Q210 50 380 70" stroke="#60a5fa" stroke-width="2" fill="none"/>
            <path d="M40 110 Q210 110 380 110" stroke="#a1a1aa" stroke-width="1.5" stroke-dasharray="4 3" fill="none"/>
            <path d="M40 150 Q210 170 380 150" stroke="#60a5fa" stroke-width="2" fill="none"/>
            <text x="50" y="62" fill="#60a5fa" font-size="10" font-family="ui-sans-serif,system-ui">upper BB</text>
            <text x="50" y="168" fill="#60a5fa" font-size="10" font-family="ui-sans-serif,system-ui">lower BB</text>
            <circle cx="210" cy="175" r="7" fill="#f87171"/>
            <text x="225" y="180" fill="#fca5a5" font-size="11" font-family="ui-sans-serif,system-ui">close за нижней</text>
            <path d="M210 165 L210 125" stroke="#34d399" stroke-width="3" fill="none"/>
            <rect x="230" y="100" width="70" height="28" rx="6" fill="#064e3b"/>
            <text x="265" y="119" text-anchor="middle" fill="#6ee7b7" font-size="14" font-weight="700" font-family="ui-sans-serif,system-ui">CALL</text>
        `, 'BB mean-reversion: вынос вниз → CALL');
    },
    oscillator_zones() {
        return this._wrap(`
            <rect x="40" y="40" width="340" height="40" fill="#7f1d1d" opacity="0.45" rx="4"/>
            <rect x="40" y="140" width="340" height="40" fill="#064e3b" opacity="0.45" rx="4"/>
            <text x="50" y="64" fill="#fca5a5" font-size="12" font-family="ui-sans-serif,system-ui">перекупленность → PUT</text>
            <text x="50" y="164" fill="#6ee7b7" font-size="12" font-family="ui-sans-serif,system-ui">перепроданность → CALL</text>
            <path d="M60 120 Q140 90 200 150 Q280 50 360 80" stroke="#e4e4e7" stroke-width="2.5" fill="none"/>
            <circle cx="200" cy="150" r="6" fill="#34d399"/>
            <text x="212" y="154" fill="#a1a1aa" font-size="11" font-family="ui-sans-serif,system-ui">вход CALL</text>
        `, 'Осциллятор (RSI / WR / CCI)');
    },
    line_cross() {
        return this._wrap(`
            <path d="M50 140 Q150 130 250 70 Q320 50 380 45" stroke="#34d399" stroke-width="2.5" fill="none"/>
            <path d="M50 100 Q150 110 250 100 Q320 95 380 90" stroke="#fbbf24" stroke-width="2" fill="none"/>
            <text x="50" y="160" fill="#34d399" font-size="11" font-family="ui-sans-serif,system-ui">линия A</text>
            <text x="50" y="90" fill="#fbbf24" font-size="11" font-family="ui-sans-serif,system-ui">сигнал</text>
            <circle cx="200" cy="95" r="7" fill="#34d399"/>
            <text x="215" y="88" fill="#a1a1aa" font-size="11" font-family="ui-sans-serif,system-ui">бычий кросс → CALL</text>
        `, 'Пересечение линий (RVI / MACD / Stoch)');
    },
    channel_break() {
        return this._wrap(`
            <path d="M40 70 L380 55" stroke="#60a5fa" stroke-width="2" fill="none"/>
            <path d="M40 140 L380 125" stroke="#60a5fa" stroke-width="2" fill="none"/>
            <text x="50" y="62" fill="#60a5fa" font-size="10" font-family="ui-sans-serif,system-ui">верх канала</text>
            <text x="50" y="158" fill="#60a5fa" font-size="10" font-family="ui-sans-serif,system-ui">низ канала</text>
            <path d="M80 120 L160 105 L220 95 L280 40" stroke="#e4e4e7" stroke-width="2.5" fill="none"/>
            <circle cx="280" cy="40" r="6" fill="#34d399"/>
            <rect x="300" y="25" width="70" height="28" rx="6" fill="#064e3b"/>
            <text x="335" y="44" text-anchor="middle" fill="#6ee7b7" font-size="14" font-weight="700" font-family="ui-sans-serif,system-ui">CALL</text>
            <text x="200" y="185" text-anchor="middle" fill="#a1a1aa" font-size="11" font-family="ui-sans-serif,system-ui">пробой верхней границы</text>
        `, 'Пробой канала / конверта');
    },
    sar_cross() {
        return this._wrap(`
            <circle cx="80" cy="60" r="4" fill="#fbbf24"/><circle cx="120" cy="55" r="4" fill="#fbbf24"/>
            <circle cx="160" cy="58" r="4" fill="#fbbf24"/><circle cx="200" cy="62" r="4" fill="#fbbf24"/>
            <circle cx="250" cy="130" r="4" fill="#fbbf24"/><circle cx="290" cy="135" r="4" fill="#fbbf24"/>
            <circle cx="330" cy="128" r="4" fill="#fbbf24"/>
            <path d="M70 110 L210 100 L240 90 L340 70" stroke="#e4e4e7" stroke-width="2.5" fill="none"/>
            <text x="80" y="45" fill="#fbbf24" font-size="10" font-family="ui-sans-serif,system-ui">SAR над ценой</text>
            <text x="260" y="155" fill="#fbbf24" font-size="10" font-family="ui-sans-serif,system-ui">SAR под ценой</text>
            <rect x="230" y="70" width="70" height="28" rx="6" fill="#064e3b"/>
            <text x="265" y="89" text-anchor="middle" fill="#6ee7b7" font-size="14" font-weight="700" font-family="ui-sans-serif,system-ui">CALL</text>
        `, 'Кросс Parabolic SAR');
    },
    pivot_levels() {
        return this._wrap(`
            <line x1="40" y1="55" x2="380" y2="55" stroke="#34d399" stroke-width="2" stroke-dasharray="6 4"/>
            <line x1="40" y1="100" x2="380" y2="100" stroke="#a1a1aa" stroke-width="1.5"/>
            <line x1="40" y1="145" x2="380" y2="145" stroke="#f87171" stroke-width="2" stroke-dasharray="6 4"/>
            <text x="50" y="48" fill="#6ee7b7" font-size="12" font-family="ui-sans-serif,system-ui">R1 — кросс вверх → CALL</text>
            <text x="50" y="95" fill="#a1a1aa" font-size="11" font-family="ui-sans-serif,system-ui">Pivot</text>
            <text x="50" y="168" fill="#fca5a5" font-size="12" font-family="ui-sans-serif,system-ui">S1 — кросс вниз → PUT</text>
            <path d="M100 120 L180 110 L240 50" stroke="#e4e4e7" stroke-width="2" fill="none"/>
            <circle cx="240" cy="50" r="6" fill="#34d399"/>
        `, 'Pivot R1 / S1');
    },
    thick_candle() {
        return this._wrap(`
            ${this._candle(120, 130, 55, 36)}
            ${this._candle(200, 100, 95, 16)}
            <text x="120" y="175" text-anchor="middle" fill="#6ee7b7" font-size="11" font-family="ui-sans-serif,system-ui">тело ≥ порога</text>
            <text x="200" y="175" text-anchor="middle" fill="#71717a" font-size="11" font-family="ui-sans-serif,system-ui">слишком тонкая</text>
            <rect x="280" y="70" width="70" height="28" rx="6" fill="#064e3b"/>
            <text x="315" y="89" text-anchor="middle" fill="#6ee7b7" font-size="14" font-weight="700" font-family="ui-sans-serif,system-ui">CALL</text>
        `, 'Толстая сигнальная свеча');
    },
    level_bounce() {
        return this._wrap(`
            <line x1="40" y1="130" x2="380" y2="130" stroke="#38bdf8" stroke-width="3"/>
            <text x="50" y="122" fill="#38bdf8" font-size="11" font-family="ui-sans-serif,system-ui">сильный уровень (конфлюенс)</text>
            <path d="M80 70 L140 100 L190 135 L230 90 L280 70" stroke="#e4e4e7" stroke-width="2.5" fill="none"/>
            <circle cx="190" cy="135" r="6" fill="#fbbf24"/>
            <text x="200" y="155" fill="#a1a1aa" font-size="11" font-family="ui-sans-serif,system-ui">касание + rejection</text>
            <rect x="300" y="55" width="70" height="28" rx="6" fill="#064e3b"/>
            <text x="335" y="74" text-anchor="middle" fill="#6ee7b7" font-size="14" font-weight="700" font-family="ui-sans-serif,system-ui">CALL</text>
        `, 'Отбой от сильного уровня');
    },
    smc_flow() {
        return this._wrap(`
            <rect x="30" y="50" width="100" height="50" rx="8" fill="#14532d" stroke="#22c55e"/>
            <text x="80" y="80" text-anchor="middle" fill="#86efac" font-size="13" font-family="ui-sans-serif,system-ui">UT Bot ✓</text>
            <rect x="160" y="50" width="100" height="50" rx="8" fill="#14532d" stroke="#22c55e"/>
            <text x="210" y="80" text-anchor="middle" fill="#86efac" font-size="13" font-family="ui-sans-serif,system-ui">3BR ✓</text>
            <rect x="290" y="50" width="100" height="50" rx="8" fill="#14532d" stroke="#22c55e"/>
            <text x="340" y="80" text-anchor="middle" fill="#86efac" font-size="12" font-family="ui-sans-serif,system-ui">OB зона ✓</text>
            <text x="210" y="140" text-anchor="middle" fill="#a1a1aa" font-size="13" font-family="ui-sans-serif,system-ui">все три = одна сторона</text>
            <rect x="175" y="155" width="70" height="28" rx="6" fill="#064e3b"/>
            <text x="210" y="174" text-anchor="middle" fill="#6ee7b7" font-size="14" font-weight="700" font-family="ui-sans-serif,system-ui">CALL</text>
        `, 'SMC Flow: UT ∧ 3BR ∧ OB');
    },
    llm() {
        return this._wrap(`
            <rect x="40" y="55" width="110" height="90" rx="8" fill="#1e293b" stroke="#64748b"/>
            <text x="95" y="100" text-anchor="middle" fill="#94a3b8" font-size="12" font-family="ui-sans-serif,system-ui">свечи M1/MTF</text>
            <path d="M160 100 L200 100" stroke="#a1a1aa" stroke-width="2" marker-end="url(#arrL)"/>
            <defs><marker id="arrL" markerWidth="8" markerHeight="8" refX="6" refY="3" orient="auto"><path d="M0,0 L6,3 L0,6 Z" fill="#a1a1aa"/></marker></defs>
            <rect x="210" y="55" width="110" height="90" rx="8" fill="#312e81" stroke="#818cf8"/>
            <text x="265" y="100" text-anchor="middle" fill="#c7d2fe" font-size="13" font-family="ui-sans-serif,system-ui">LLM</text>
            <path d="M330 100 L360 100" stroke="#a1a1aa" stroke-width="2"/>
            <rect x="350" y="80" width="55" height="40" rx="6" fill="#064e3b"/>
            <text x="377" y="105" text-anchor="middle" fill="#6ee7b7" font-size="12" font-weight="700" font-family="ui-sans-serif,system-ui">JSON</text>
        `, 'LLM решает CALL/PUT по свечам');
    },
    generic() {
        return this._wrap(`
            <text x="210" y="100" text-anchor="middle" fill="#a1a1aa" font-size="14" font-family="ui-sans-serif,system-ui">Схема для этого типа — в текстовых секциях ниже</text>
        `, 'Алгоритм');
    },
};
</script>
