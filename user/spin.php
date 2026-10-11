<?php
/**
 * User Spin Wheel Page
 * SMM Panel - PHP 8.3+
 *
 * Fully integrated user-facing Spin Wheel with:
 * - Dynamic SVG-rendered wheel with responsive segments
 * - Fixed indicator pointer at top
 * - Central branded SPIN button with live state handling
 * - Bet amount selector (₹1, ₹5, etc.)
 * - Native smooth RAF animation with authentic physics & deceleration
 * - Atomic server validation before animation initiates
 * - SweetAlert2 popup handling for Jackpots, Cash Rewards, Money Back, and Try Again
 * - Real live Recent Winners board underneath
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/spin_wheel_engine.php';

Auth::requireLogin();
$user = Auth::user();
$db = Database::getConnection();

// Check if wheel is globally enabled
$isWheelEnabled = SpinWheelEngine::isEnabled();
$activeBets = SpinWheelEngine::getBets(true);
$defaultBet = !empty($activeBets) ? $activeBets[0] : null;

// Initial recent winners
$recentWinners = SpinWheelEngine::getRecentWinners(8);

$pageTitle = "Spin & Win - " . app_name();
require_once __DIR__ . '/../includes/header.php';
?>

<!-- Ambient Floating Pastel Petals & Glows -->
<div class="fixed inset-0 pointer-events-none overflow-hidden -z-10 select-none">
    <div class="absolute top-16 left-1/4 w-80 h-80 rounded-full bg-purple-200/25 blur-3xl"></div>
    <div class="absolute top-48 right-1/4 w-80 h-80 rounded-full bg-blue-200/20 blur-3xl"></div>
    <div class="absolute bottom-20 left-1/3 w-96 h-96 rounded-full bg-indigo-100/30 blur-3xl"></div>
</div>

<div class="max-w-4xl mx-auto space-y-6 sm:space-y-8 pb-12" id="spinWheelApp">

    <!-- Top Navigation Breadcrumb & Balance Widget -->
    <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 pt-1 px-1">
        <div class="flex items-center gap-3">
            <a href="/user/dashboard.php" class="w-10 h-10 rounded-2xl bg-white border border-slate-200/80 shadow-xs flex items-center justify-center text-slate-500 hover:text-blue-600 hover:border-blue-200 transition-all">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M15 19l-7-7 7-7"/></svg>
            </a>
            <div>
                <div class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full bg-purple-50 border border-purple-100 text-[10px] font-extrabold text-purple-700 uppercase tracking-wider">
                    <span class="w-1.5 h-1.5 rounded-full bg-purple-600 animate-pulse"></span>
                    <span>Instant Rewards</span>
                </div>
                <h1 class="text-xl sm:text-2xl font-black text-slate-900 tracking-tight leading-snug">
                    Spin &amp; Win
                </h1>
            </div>
        </div>

        <!-- Real User Balance Card -->
        <div class="flex items-center gap-3 w-full sm:w-auto justify-between sm:justify-end bg-white/80 sm:bg-white backdrop-blur-md p-3 sm:px-4 sm:py-2.5 rounded-2xl border border-slate-200/80 shadow-xs">
            <div class="text-left sm:text-right">
                <span class="text-[11px] text-slate-400 font-medium block leading-tight">Your Balance</span>
                <div id="userBalanceDisplay" class="text-lg sm:text-xl font-black text-slate-900 tabular-nums font-mono-nums tracking-tight">
                    <?= format_currency($user['balance']) ?>
                </div>
            </div>
            <a href="/user/add-funds.php" class="px-3.5 py-2 bg-blue-600 hover:bg-blue-700 active:scale-95 text-white font-extrabold text-xs rounded-xl shadow-md shadow-blue-500/25 transition-all flex items-center gap-1.5">
                <span class="text-sm font-black">+</span>
                <span>Add Funds</span>
            </a>
        </div>
    </div>

    <?php if (!$isWheelEnabled): ?>
        <!-- Disabled Notification State -->
        <div class="bg-white rounded-3xl p-8 sm:p-12 border border-slate-200/80 shadow-xl text-center space-y-4">
            <div class="w-16 h-16 rounded-2xl bg-amber-50 text-amber-500 border border-amber-200/60 flex items-center justify-center mx-auto shadow-inner">
                <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
            </div>
            <h2 class="text-xl font-black text-slate-900">Spin Wheel Temporarily Paused</h2>
            <p class="text-sm text-slate-500 max-w-md mx-auto">
                The administrator is currently performing routine maintenance or updating rewards. Please check back shortly!
            </p>
            <div class="pt-2">
                <a href="/user/dashboard.php" class="inline-flex px-5 py-2.5 rounded-xl bg-blue-600 text-white font-bold text-xs shadow-md shadow-blue-500/25 hover:bg-blue-700 transition-all">
                    Return to Dashboard
                </a>
            </div>
        </div>
    <?php elseif (empty($activeBets)): ?>
        <!-- No Bets Configured State -->
        <div class="bg-white rounded-3xl p-8 sm:p-12 border border-slate-200/80 shadow-xl text-center space-y-4">
            <div class="w-16 h-16 rounded-2xl bg-blue-50 text-blue-500 border border-blue-200/60 flex items-center justify-center mx-auto">
                <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            </div>
            <h2 class="text-xl font-black text-slate-900">Configuring Available Bets</h2>
            <p class="text-sm text-slate-500 max-w-md mx-auto">
                No bet tiers are currently active. Please contact administrator support.
            </p>
        </div>
    <?php else: ?>

        <!-- MAIN SPIN WHEEL CARD CONTAINER -->
        <div class="bg-white/95 backdrop-blur-xl rounded-3xl sm:rounded-[2.5rem] p-6 sm:p-10 border border-slate-200/80 shadow-xl shadow-slate-200/50 flex flex-col items-center relative overflow-hidden">

            <!-- Subtle top banner glow -->
            <div class="pointer-events-none absolute -top-24 -left-24 w-72 h-72 bg-gradient-to-br from-purple-400/15 via-blue-500/15 to-transparent rounded-full blur-2xl"></div>
            <div class="pointer-events-none absolute -bottom-24 -right-24 w-72 h-72 bg-gradient-to-tl from-indigo-400/15 via-pink-500/15 to-transparent rounded-full blur-2xl"></div>

            <!-- Bet Selector Pills -->
            <div class="w-full max-w-md mb-6 sm:mb-8 text-center">
                <span class="text-xs font-bold text-slate-400 uppercase tracking-wider block mb-2.5">
                    Select Spin Amount
                </span>
                <div class="inline-flex p-1.5 rounded-2xl bg-slate-100/90 border border-slate-200/60 gap-1.5 shadow-inner" id="betSelectorContainer">
                    <?php foreach ($activeBets as $idx => $b): ?>
                        <button type="button"
                                onclick="window.selectSpinBet(<?= (int)$b['id'] ?>, <?= (float)$b['bet_amount'] ?>)"
                                id="betBtn-<?= (int)$b['id'] ?>"
                                data-bet-id="<?= (int)$b['id'] ?>"
                                data-bet-amount="<?= (float)$b['bet_amount'] ?>"
                                class="bet-pill px-5 sm:px-6 py-2.5 rounded-xl text-xs sm:text-sm font-extrabold transition-all cursor-pointer <?= ($idx === 0) ? 'bg-gradient-to-r from-blue-600 to-indigo-600 text-white shadow-md shadow-blue-500/25 active-bet' : 'text-slate-600 hover:text-slate-900 hover:bg-white/60' ?>">
                            <?= format_currency($b['bet_amount']) ?>
                        </button>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Interactive Spin Wheel Stage -->
            <div class="relative w-[320px] h-[320px] sm:w-[420px] sm:h-[420px] max-w-full flex items-center justify-center my-2 select-none">

                <!-- Outer Glowing Bezel Ring -->
                <div class="absolute inset-0 rounded-full bg-gradient-to-tr from-amber-400 via-purple-500 to-blue-500 p-2 shadow-2xl shadow-indigo-500/20">
                    <div class="w-full h-full rounded-full bg-slate-900 p-2 relative">
                        <!-- Decorative Bezel Studs/LED lights around perimeter -->
                        <div class="absolute inset-0 flex items-center justify-center pointer-events-none" id="bezelLights">
                            <!-- Injected by JS based on screen radius -->
                        </div>
                    </div>
                </div>

                <!-- Fixed Top Indicator Pointer (Points directly downwards to top segment at 12 o'clock) -->
                <div class="absolute -top-3 z-30 flex flex-col items-center pointer-events-none filter drop-shadow-lg">
                    <div class="w-8 h-8 rounded-full bg-gradient-to-b from-amber-300 to-amber-500 border-2 border-white shadow-md flex items-center justify-center">
                        <div class="w-3 h-3 rounded-full bg-amber-700"></div>
                    </div>
                    <svg class="w-7 h-7 text-amber-500 -mt-2.5" viewBox="0 0 24 24" fill="currentColor">
                        <polygon points="12,22 4,6 20,6"/>
                    </svg>
                </div>

                <!-- Rotatable Wheel SVG Canvas Container -->
                <div id="wheelCanvasWrapper"
                     class="w-[288px] h-[288px] sm:w-[388px] sm:h-[388px] rounded-full overflow-hidden relative z-10 will-change-transform">
                    <svg id="wheelSvg"
                         viewBox="0 0 400 400"
                         class="w-full h-full transform-gpu"
                         xmlns="http://www.w3.org/2000/svg">
                        <!-- Segments will be drawn dynamically via JS from verified public config -->
                        <g id="wheelSegmentsGroup"></g>
                    </svg>
                </div>

                <!-- Central Spin Button (Fixed at center, elevated above rotating wheel) -->
                <div class="absolute z-20 flex items-center justify-center">
                    <button type="button"
                            id="spinActionBtn"
                            onclick="window.initiateSpin()"
                            class="group relative w-20 h-20 sm:w-24 sm:h-24 rounded-full bg-gradient-to-tr from-blue-600 via-indigo-600 to-purple-600 text-white font-black text-xs sm:text-sm tracking-wider uppercase shadow-xl shadow-blue-600/40 hover:scale-105 active:scale-95 transition-all flex flex-col items-center justify-center border-4 border-white cursor-pointer focus:outline-none focus:ring-4 focus:ring-blue-400/40">
                        <!-- Internal shimmer ring -->
                        <div class="absolute inset-1 rounded-full border border-white/30 pointer-events-none"></div>

                        <!-- Center Icon + Text -->
                        <svg class="w-5 h-5 text-amber-300 group-hover:rotate-180 transition-transform duration-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                        </svg>
                        <span class="mt-0.5 tracking-tight font-black" id="spinBtnText">SPIN</span>
                    </button>
                </div>

            </div>

            <!-- Current Selected Bet Info & Rules Footer -->
            <div class="mt-6 sm:mt-8 text-center space-y-1">
                <p class="text-xs text-slate-500 font-medium">
                    Spin cost: <span id="currentCostLabel" class="font-extrabold text-slate-900"><?= format_currency($defaultBet ? $defaultBet['bet_amount'] : 1) ?></span> per play.
                    Winnings credit instantly to your wallet.
                </p>
                <div class="flex items-center justify-center gap-4 text-[11px] text-slate-400 pt-1">
                    <span class="inline-flex items-center gap-1">
                        <svg class="w-3.5 h-3.5 text-emerald-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                        Instant Credit
                    </span>
                    <span>•</span>
                    <span class="inline-flex items-center gap-1">
                        <svg class="w-3.5 h-3.5 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                        Verified Outcomes
                    </span>
                </div>
            </div>

        </div>

        <!-- RECENT WINNERS LIVE BOARD -->
        <div class="bg-white rounded-3xl p-6 sm:p-8 border border-slate-200/80 shadow-xl space-y-5">
            <div class="flex items-center justify-between pb-3 border-b border-slate-100">
                <div class="flex items-center gap-2.5">
                    <div class="w-9 h-9 rounded-xl bg-amber-50 text-amber-500 border border-amber-200/60 flex items-center justify-center">
                        <svg class="w-5 h-5 text-amber-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
                        </svg>
                    </div>
                    <div>
                        <h2 class="text-base sm:text-lg font-black text-slate-900 tracking-tight">Recent Winners</h2>
                        <p class="text-[11px] text-slate-400">Verified payouts from completed spins</p>
                    </div>
                </div>

                <div class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-emerald-50 border border-emerald-100 text-[10px] font-extrabold text-emerald-700 tracking-wide uppercase">
                    <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-ping"></span>
                    <span>LIVE</span>
                </div>
            </div>

            <!-- Winners Grid/List -->
            <div id="recentWinnersList" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                <?php if (empty($recentWinners)): ?>
                    <div class="col-span-full py-8 text-center text-slate-400 text-xs font-semibold">
                        No winning spins recorded yet. Be the first to win!
                    </div>
                <?php else: ?>
                    <?php foreach ($recentWinners as $w): 
                        $isJackpot = ($w['reward_type'] === 'jackpot');
                    ?>
                        <div class="p-3 rounded-2xl border transition-all flex items-center justify-between <?= $isJackpot ? 'bg-gradient-to-r from-purple-50 to-indigo-50 border-purple-200' : 'bg-slate-50/80 border-slate-200/60 hover:bg-slate-100/80' ?>">
                            <div class="flex items-center gap-2.5">
                                <div class="w-8 h-8 rounded-xl flex items-center justify-center <?= $isJackpot ? 'bg-purple-600 text-white' : 'bg-blue-100 text-blue-700' ?>">
                                    <?php if ($isJackpot): ?>
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 3v4M3 5h4M6 17v4m-2-2h4m5-16l2.286 6.857L21 12l-5.714 2.143L13 21l-2.286-6.857L5 12l5.714-2.143L13 3z"/></svg>
                                    <?php else: ?>
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                    <?php endif; ?>
                                </div>
                                <div>
                                    <span class="text-xs font-bold text-slate-800 block leading-tight font-mono-nums"><?= e($w['masked_user']) ?></span>
                                    <span class="text-[10px] text-slate-400 block"><?= e($w['relative_time']) ?></span>
                                </div>
                            </div>
                            <div class="text-right">
                                <span class="text-xs sm:text-sm font-black tabular-nums font-mono-nums <?= $isJackpot ? 'text-purple-700' : 'text-emerald-600' ?>">
                                    +<?= e($w['formatted_win']) ?>
                                </span>
                                <?php if ($isJackpot): ?>
                                    <span class="text-[9px] font-black text-purple-600 uppercase tracking-tighter block">JACKPOT</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

    <?php endif; ?>

</div>

<!-- Pass CSRF Token and Initial Configuration to JavaScript -->
<script>
    window.SPIN_CSRF_TOKEN = '<?= CSRF::generate() ?>';
    window.INITIAL_BET_ID = <?= (int)($defaultBet ? $defaultBet['id'] : 0) ?>;
    window.INITIAL_USER_BALANCE = <?= (float)$user['balance'] ?>;
</script>

<!-- Spin Wheel Client Engine Scripts -->
<script>
(function() {
    let currentBetId = window.INITIAL_BET_ID;
    let isSpinning = false;
    let currentRotation = 0;
    let wheelSegments = [];
    let currentBetAmount = 1;

    const wheelSvgGroup = document.getElementById('wheelSegmentsGroup');
    const spinBtn = document.getElementById('spinActionBtn');
    const spinBtnText = document.getElementById('spinBtnText');
    const balanceDisplay = document.getElementById('userBalanceDisplay');
    const costLabel = document.getElementById('currentCostLabel');
    const wheelWrapper = document.getElementById('wheelCanvasWrapper');
    const prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    // SVG Icon Paths dictionary matching server definition
    const SVG_ICONS = {
        'trophy': '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>',
        'coin': '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>',
        'gift': '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.2" d="M12 8v13m0-13V4a2 2 0 10-4 0v4m4-4a2 2 0 114 0v4M4 8h16v4H4V8zm2 4v8a2 2 0 002 2h8a2 2 0 002-2v-8"/>',
        'refresh': '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>',
        'sparkles': '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.2" d="M5 3v4M3 5h4M6 17v4m-2-2h4m5-16l2.286 6.857L21 12l-5.714 2.143L13 21l-2.286-6.857L5 12l5.714-2.143L13 3z"/>',
        'cross': '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.2" d="M6 18L18 6M6 6l12 12"/>'
    };

    // Load Public Wheel Configuration for the selected bet
    async function loadWheelConfig(betId) {
        try {
            const res = await fetch(`/api/spin.php?action=get_config&bet_id=${betId}`);
            const data = await res.json();
            if (data.success && data.wheel_config) {
                wheelSegments = data.wheel_config.segments || [];
                currentBetAmount = data.wheel_config.bet_amount;
                renderWheelSegments(wheelSegments);
                if (costLabel) costLabel.textContent = data.wheel_config.formatted_bet;
                if (balanceDisplay && data.formatted_balance) {
                    balanceDisplay.textContent = data.formatted_balance;
                }
            } else {
                if (typeof Swal !== 'undefined') {
                    Swal.fire({
                        icon: 'error',
                        title: 'Configuration Error',
                        text: data.error || 'Failed to load wheel configuration'
                    });
                }
            }
        } catch (err) {
            console.error('Failed to load wheel configuration:', err);
        }
    }

    // Render Wheel SVG Segments with Pie Wedges, Radial Labels, and Clean SVGs
    function renderWheelSegments(segments) {
        if (!wheelSvgGroup) return;
        wheelSvgGroup.innerHTML = '';

        const count = segments.length;
        if (count === 0) return;

        const cx = 200;
        const cy = 200;
        const r = 200;
        const arcAngle = (2 * Math.PI) / count;
        const arcDeg = 360 / count;

        segments.forEach((seg, i) => {
            const startAngle = i * arcAngle;
            const endAngle = (i + 1) * arcAngle;

            // Compute SVG Path coordinates for segment wedge
            const x1 = cx + r * Math.sin(startAngle);
            const y1 = cy - r * Math.cos(startAngle);
            const x2 = cx + r * Math.sin(endAngle);
            const y2 = cy - r * Math.cos(endAngle);
            const largeArc = (endAngle - startAngle > Math.PI) ? 1 : 0;

            const pathD = `M ${cx} ${cy} L ${x1} ${y1} A ${r} ${r} 0 ${largeArc} 1 ${x2} ${y2} Z`;

            // Segment G element
            const segGroup = document.createElementNS('http://www.w3.org/2000/svg', 'g');
            segGroup.setAttribute('class', 'wheel-segment');

            // Wedge path
            const pathEl = document.createElementNS('http://www.w3.org/2000/svg', 'path');
            pathEl.setAttribute('d', pathD);
            pathEl.setAttribute('fill', seg.color_bg || '#3b82f6');
            pathEl.setAttribute('stroke', '#ffffff');
            pathEl.setAttribute('stroke-width', '2');
            segGroup.appendChild(pathEl);

            // Compute rotation for text & icon along center angle of segment
            const midAngleDeg = (i * arcDeg) + (arcDeg / 2);

            // Container for text/icon oriented radially
            const textGroup = document.createElementNS('http://www.w3.org/2000/svg', 'g');
            textGroup.setAttribute('transform', `rotate(${midAngleDeg}, ${cx}, ${cy})`);

            // Text Label
            const textEl = document.createElementNS('http://www.w3.org/2000/svg', 'text');
            textEl.setAttribute('x', cx);
            textEl.setAttribute('y', cy - (r * 0.62));
            textEl.setAttribute('fill', seg.color_text || '#ffffff');
            textEl.setAttribute('text-anchor', 'middle');
            textEl.setAttribute('dominant-baseline', 'central');
            textEl.setAttribute('font-family', 'Plus Jakarta Sans, sans-serif');
            textEl.setAttribute('font-size', count > 8 ? '10' : '11.5');
            textEl.setAttribute('font-weight', '800');
            textEl.setAttribute('letter-spacing', '0.2px');
            textEl.textContent = seg.label;
            textGroup.appendChild(textEl);

            // SVG Icon inside segment wedge
            const iconKey = seg.icon_key || 'trophy';
            const iconPathD = SVG_ICONS[iconKey] || SVG_ICONS['trophy'];
            const iconSvg = document.createElementNS('http://www.w3.org/2000/svg', 'g');
            iconSvg.setAttribute('transform', `translate(${cx - 9}, ${cy - (r * 0.82)}) scale(0.75)`);
            iconSvg.setAttribute('stroke', seg.color_text || '#ffffff');
            iconSvg.setAttribute('fill', 'none');
            iconSvg.innerHTML = iconPathD;
            textGroup.appendChild(iconSvg);

            segGroup.appendChild(textGroup);
            wheelSvgGroup.appendChild(segGroup);
        });
    }

    // Switch Bet Amount Selector
    window.selectSpinBet = function(betId, amount) {
        if (isSpinning) return;
        currentBetId = betId;
        currentBetAmount = amount;

        document.querySelectorAll('.bet-pill').forEach(btn => {
            const btnId = parseInt(btn.getAttribute('data-bet-id'), 10);
            if (btnId === betId) {
                btn.className = 'bet-pill px-5 sm:px-6 py-2.5 rounded-xl text-xs sm:text-sm font-extrabold transition-all cursor-pointer bg-gradient-to-r from-blue-600 to-indigo-600 text-white shadow-md shadow-blue-500/25 active-bet';
            } else {
                btn.className = 'bet-pill px-5 sm:px-6 py-2.5 rounded-xl text-xs sm:text-sm font-extrabold transition-all cursor-pointer text-slate-600 hover:text-slate-900 hover:bg-white/60';
            }
        });

        loadWheelConfig(betId);
    };

    // Initiate Spin: Client requests server result FIRST, then smoothly animates to target
    window.initiateSpin = async function() {
        if (isSpinning) return;

        // Button Loading State
        isSpinning = true;
        spinBtn.disabled = true;
        spinBtnText.textContent = '...';
        spinBtn.classList.add('opacity-75', 'cursor-not-allowed');

        try {
            const formData = new FormData();
            formData.append('csrf_token', window.SPIN_CSRF_TOKEN);
            formData.append('bet_id', currentBetId);

            const response = await fetch('/api/spin.php?action=spin', {
                method: 'POST',
                body: formData
            });

            const data = await response.json();

            if (!data.success) {
                isSpinning = false;
                spinBtn.disabled = false;
                spinBtnText.textContent = 'SPIN';
                spinBtn.classList.remove('opacity-75', 'cursor-not-allowed');

                if (typeof Swal !== 'undefined') {
                    Swal.fire({
                        icon: data.insufficient_balance ? 'warning' : 'error',
                        title: data.insufficient_balance ? 'Insufficient Balance' : 'Spin Failed',
                        text: data.error || 'Could not process spin.',
                        confirmButtonColor: '#2563eb',
                        confirmButtonText: data.insufficient_balance ? 'Add Funds Now' : 'OK'
                    }).then((result) => {
                        if (data.insufficient_balance && result.isConfirmed) {
                            window.location.href = '/user/add-funds.php';
                        }
                    });
                } else {
                    alert(data.error || 'Spin error');
                }
                return;
            }

            // Immediately update displayed balance to deduct bet amount
            if (balanceDisplay && data.formatted_balance) {
                balanceDisplay.textContent = data.formatted_balance;
            }

            // Execute Native Smooth Physics Animation to Authoritative Target Angle
            animateWheelToAngle(data.target_angle, () => {
                // Animation completed callback
                isSpinning = false;
                spinBtn.disabled = false;
                spinBtnText.textContent = 'SPIN';
                spinBtn.classList.remove('opacity-75', 'cursor-not-allowed');

                // Display Polished SweetAlert2 Result Modal
                displayResultPopup(data);

                // Refresh Recent Winners Board
                refreshRecentWinners();
            });

        } catch (err) {
            console.error('Spin request error:', err);
            isSpinning = false;
            spinBtn.disabled = false;
            spinBtnText.textContent = 'SPIN';
            spinBtn.classList.remove('opacity-75', 'cursor-not-allowed');

            if (typeof Swal !== 'undefined') {
                Swal.fire({
                    icon: 'error',
                    title: 'Network Issue',
                    text: 'Connection error while processing spin. Please check your transaction history.'
                });
            }
        }
    };

    // Browser Native Smooth Wheel Animation with Easing Physics
    function animateWheelToAngle(targetAngleDeg, onComplete) {
        if (!wheelWrapper) {
            onComplete();
            return;
        }

        if (prefersReducedMotion) {
            // Respect reduced motion: instantly snap to target angle
            currentRotation = (Math.floor(currentRotation / 360) * 360) + targetAngleDeg;
            wheelWrapper.style.transform = `rotate(${currentRotation}deg)`;
            setTimeout(onComplete, 200);
            return;
        }

        // Full rotations: 5 to 7 full 360-degree spins plus the target segment offset
        const extraSpins = 360 * 6; // 2160 degrees
        const currentNormalized = currentRotation % 360;
        let diff = (targetAngleDeg - currentNormalized);
        if (diff < 0) diff += 360;

        const startAngle = currentRotation;
        const finalAngle = currentRotation + extraSpins + diff;
        const totalDistance = finalAngle - startAngle;

        const duration = 4800; // 4.8 seconds authentic casino wheel spin
        const startTime = performance.now();

        // Custom Quintic Out Easing Curve: Rapid acceleration & smooth decelerating roll
        function easeOutQuint(t) {
            return 1 - Math.pow(1 - t, 5);
        }

        function frame(now) {
            const elapsed = now - startTime;
            const progress = Math.min(elapsed / duration, 1);
            const easedProgress = easeOutQuint(progress);

            const angleNow = startAngle + (totalDistance * easedProgress);
            wheelWrapper.style.transform = `rotate(${angleNow}deg)`;

            if (progress < 1) {
                requestAnimationFrame(frame);
            } else {
                currentRotation = finalAngle;
                wheelWrapper.style.transform = `rotate(${finalAngle}deg)`;
                setTimeout(onComplete, 300);
            }
        }

        requestAnimationFrame(frame);
    }

    // Display Polished SweetAlert2 Result Popups
    function displayResultPopup(result) {
        if (typeof Swal === 'undefined') return;

        const rType = result.reward_type;
        const label = result.outcome_label;
        const amtStr = result.formatted_reward;
        const balanceStr = result.formatted_balance;

        if (rType === 'jackpot') {
            // JACKPOT CELEBRATION MODAL
            Swal.fire({
                title: '<span class="text-2xl font-black text-purple-700 tracking-tight">JACKPOT WINNER!</span>',
                html: `
                    <div class="space-y-3 py-2 text-center">
                        <div class="w-16 h-16 rounded-3xl bg-gradient-to-tr from-purple-600 to-indigo-600 text-white flex items-center justify-center mx-auto shadow-lg shadow-purple-500/30">
                            <svg class="w-9 h-9" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 3v4M3 5h4M6 17v4m-2-2h4m5-16l2.286 6.857L21 12l-5.714 2.143L13 21l-2.286-6.857L5 12l5.714-2.143L13 3z"/></svg>
                        </div>
                        <div class="text-3xl font-black text-slate-900 font-mono-nums">${amtStr}</div>
                        <p class="text-xs text-slate-500 font-medium">Congratulations! The grand jackpot has been verified and deposited into your wallet.</p>
                        <div class="p-3 bg-purple-50 border border-purple-100 rounded-2xl text-xs font-bold text-purple-800">
                            Updated Balance: <span class="font-black">${balanceStr}</span>
                        </div>
                    </div>
                `,
                confirmButtonText: 'Collect Winnings',
                confirmButtonColor: '#7c3aed',
                customClass: {
                    popup: 'rounded-3xl border border-purple-200'
                }
            });
        } else if (rType === 'reward') {
            // STANDARD MONETARY REWARD
            Swal.fire({
                title: '<span class="text-xl font-black text-slate-900">You Won!</span>',
                html: `
                    <div class="space-y-3 py-2 text-center">
                        <div class="w-14 h-14 rounded-2xl bg-emerald-50 text-emerald-600 border border-emerald-200 flex items-center justify-center mx-auto">
                            <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        </div>
                        <div class="text-2xl font-black text-emerald-600 font-mono-nums">+${amtStr}</div>
                        <p class="text-xs text-slate-500 font-medium">Your prize has been credited to your account balance.</p>
                        <div class="p-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs font-semibold text-slate-700">
                            Updated Balance: <strong class="text-slate-900">${balanceStr}</strong>
                        </div>
                    </div>
                `,
                confirmButtonText: 'Awesome!',
                confirmButtonColor: '#2563eb',
                customClass: {
                    popup: 'rounded-3xl'
                }
            });
        } else if (rType === 'money_back') {
            // MONEY BACK OUTCOME
            Swal.fire({
                title: '<span class="text-xl font-black text-slate-900">Money Back!</span>',
                html: `
                    <div class="space-y-3 py-2 text-center">
                        <div class="w-14 h-14 rounded-2xl bg-blue-50 text-blue-600 border border-blue-200 flex items-center justify-center mx-auto">
                            <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                        </div>
                        <div class="text-xl font-black text-blue-600 font-mono-nums">${amtStr} Returned</div>
                        <p class="text-xs text-slate-500 font-medium">Your spin bet was fully refunded back into your wallet balance.</p>
                        <div class="p-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs font-semibold text-slate-700">
                            Balance: <strong class="text-slate-900">${balanceStr}</strong>
                        </div>
                    </div>
                `,
                confirmButtonText: 'Spin Again',
                confirmButtonColor: '#2563eb',
                customClass: {
                    popup: 'rounded-3xl'
                }
            });
        } else {
            // LOSING / NO REWARD OUTCOME
            Swal.fire({
                title: '<span class="text-xl font-black text-slate-900">Better Luck Next Time</span>',
                html: `
                    <div class="space-y-3 py-2 text-center">
                        <div class="w-14 h-14 rounded-2xl bg-slate-100 text-slate-500 border border-slate-200 flex items-center justify-center mx-auto">
                            <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                        </div>
                        <p class="text-xs text-slate-500 font-medium">No reward on this turn. Give it another spin to hit the jackpot!</p>
                        <div class="p-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs font-semibold text-slate-700">
                            Current Balance: <strong class="text-slate-900">${balanceStr}</strong>
                        </div>
                    </div>
                `,
                confirmButtonText: 'Try Again',
                confirmButtonColor: '#475569',
                customClass: {
                    popup: 'rounded-3xl'
                }
            });
        }
    }

    // Refresh Recent Winners Board Safely
    async function refreshRecentWinners() {
        const listEl = document.getElementById('recentWinnersList');
        if (!listEl) return;

        try {
            const res = await fetch('/api/spin.php?action=recent_winners&limit=8');
            const data = await res.json();
            if (data.success && data.winners) {
                if (data.winners.length === 0) {
                    listEl.innerHTML = '<div class="col-span-full py-8 text-center text-slate-400 text-xs font-semibold">No winning spins recorded yet. Be the first to win!</div>';
                    return;
                }

                listEl.innerHTML = data.winners.map(w => {
                    const isJp = (w.reward_type === 'jackpot');
                    return `
                        <div class="p-3 rounded-2xl border transition-all flex items-center justify-between ${isJp ? 'bg-gradient-to-r from-purple-50 to-indigo-50 border-purple-200' : 'bg-slate-50/80 border-slate-200/60 hover:bg-slate-100/80'}">
                            <div class="flex items-center gap-2.5">
                                <div class="w-8 h-8 rounded-xl flex items-center justify-center ${isJp ? 'bg-purple-600 text-white' : 'bg-blue-100 text-blue-700'}">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                    </svg>
                                </div>
                                <div>
                                    <span class="text-xs font-bold text-slate-800 block leading-tight font-mono-nums">${w.masked_user}</span>
                                    <span class="text-[10px] text-slate-400 block">${w.relative_time}</span>
                                </div>
                            </div>
                            <div class="text-right">
                                <span class="text-xs sm:text-sm font-black tabular-nums font-mono-nums ${isJp ? 'text-purple-700' : 'text-emerald-600'}">
                                    +${w.formatted_win}
                                </span>
                                ${isJp ? '<span class="text-[9px] font-black text-purple-600 uppercase tracking-tighter block">JACKPOT</span>' : ''}
                            </div>
                        </div>
                    `;
                }).join('');
            }
        } catch (err) {
            console.error('Failed to refresh winners:', err);
        }
    }

    // Initialize on page load
    if (currentBetId > 0) {
        loadWheelConfig(currentBetId);
    }
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
