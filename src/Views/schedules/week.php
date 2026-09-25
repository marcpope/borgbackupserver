<?php
$dayLabels = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
$dayLabelsLong = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
$pxPerHour = 72;
$gridHeight = 24 * $pxPerHour;
// A block is at least $minBlockPx tall so its text is readable. For the
// lane algorithm we need to reserve at least this many minutes of vertical
// space so short back-to-back blocks don't visually overlap.
$minBlockPx = 26;
$minBlockMin = max(1, (int) ceil($minBlockPx * 60 / $pxPerHour));

// Group blocks by day so we can render just one day at a time, and compute
// per-day lane layout for overlapping blocks.
$blocksByDay = [0 => [], 1 => [], 2 => [], 3 => [], 4 => [], 5 => [], 6 => []];
foreach ($blocks as $b) {
    $blocksByDay[$b['day_idx']][] = $b;
}
foreach ($blocksByDay as &$dayBlocks) {
    usort($dayBlocks, fn($a, $b) => $a['start_min'] <=> $b['start_min']);
    $lanes = [];
    foreach ($dayBlocks as &$blk) {
        // Use the RENDERED height (in minutes) for lane packing so that short
        // blocks we've inflated to the min-height don't get another block
        // drawn on top of them.
        $renderedMin = max($blk['duration_min'], $minBlockMin);
        $placed = false;
        foreach ($lanes as $laneIdx => $laneEnd) {
            if ($blk['start_min'] >= $laneEnd) {
                $blk['lane'] = $laneIdx;
                $lanes[$laneIdx] = $blk['start_min'] + $renderedMin;
                $placed = true;
                break;
            }
        }
        if (!$placed) {
            $blk['lane'] = count($lanes);
            $lanes[$blk['lane']] = $blk['start_min'] + $renderedMin;
        }
    }
    unset($blk);
    $laneCount = max(1, count($lanes));
    foreach ($dayBlocks as &$blk) {
        $blk['lane_count'] = $laneCount;
    }
    unset($blk);
}
unset($dayBlocks);

$nowInUserTz = new \DateTime('now', new \DateTimeZone($userTz));
$todayIdx = ((int) $nowInUserTz->format('N')) - 1;
$currentMinuteOfDay = ((int) $nowInUserTz->format('G')) * 60 + (int) $nowInUserTz->format('i');
$currentTimeLabel = $is24h ? $nowInUserTz->format('H:i') : $nowInUserTz->format('g:i A');

function bbs_agent_color(int $id): string
{
    // Per-agent accent only. Blocks keep the dashboard-blue base color;
    // this hue is used as a thin identity stripe/dot so clients stay distinct.
    $hue = ($id * 137) % 360;
    return "hsl({$hue}, 58%, 56%)";
}

function bbs_day_block_progress_pct(int $dayIdx, int $startMin, float $heightPx, int $todayIdx, int $currentMinuteOfDay, int $pxPerHour): float
{
    if ($dayIdx < $todayIdx) return 100.0;
    if ($dayIdx > $todayIdx) return 0.0;

    $topPx = $startMin * ($pxPerHour / 60);
    $linePx = $currentMinuteOfDay * ($pxPerHour / 60);
    $pastPx = $linePx - $topPx;

    return min(100.0, max(0.0, ($pastPx / max(1.0, $heightPx)) * 100));
}

function bbs_schedule_progress_pct(int $dayIdx, int $startMin, int $durationMin, int $todayIdx, int $currentMinuteOfDay): float
{
    if ($dayIdx < $todayIdx) return 100.0;
    if ($dayIdx > $todayIdx) return 0.0;

    return min(100.0, max(0.0, (($currentMinuteOfDay - $startMin) / max(1, $durationMin)) * 100));
}

function bbs_day_block_phase(float $pastPct): string
{
    if ($pastPct >= 99.9) return 'past';
    if ($pastPct <= 0.0) return 'future';
    return 'active';
}

// Pick a set of "nice" y-axis tick values for the histogram, including 0 and
// max. Tries to keep the count around 5–6 labels so the axis stays readable
// regardless of whether max is 3 or 300.
function bbs_histogram_ticks(int $max): array
{
    if ($max <= 0) return [0];
    if ($max <= 5) return range(0, $max);
    $step = max(1, (int) ceil($max / 5));
    $ticks = [];
    for ($i = 0; $i <= $max; $i += $step) $ticks[] = $i;
    if (end($ticks) !== $max) $ticks[] = $max;
    return $ticks;
}

?>

<style>
:root {
    --schedule-laser: #0d6efd;
    --schedule-laser-hot: #36a2eb;
    --schedule-laser-core: #eaf6ff;
    --schedule-flow-trail: rgba(13, 110, 253, 0.14);
    --schedule-flow-trail-strong: rgba(54, 162, 235, 0.26);
    --schedule-grid-bg: linear-gradient(180deg, rgba(13, 110, 253, 0.04), var(--bs-tertiary-bg));
    --schedule-block-bg: #2f73c9;
    --schedule-block-bg-2: #224f93;
    --schedule-concrete-bg: #c5cad2;
    --schedule-concrete-bg-2: #9da6b2;
    --schedule-concrete-ink: #1f2933;
    --schedule-concrete-line: rgba(43, 50, 60, 0.14);
    --schedule-error-bg: #cf5b5f;
    --schedule-error-bg-2: #8f2732;
    --schedule-error-hot: #ff777d;
    --schedule-error-ink: #fff3f4;
    --schedule-error-crack: rgba(70, 6, 14, 0.5);
    --schedule-error-cracked:
        linear-gradient(135deg, rgba(255, 255, 255, 0.14), rgba(65, 4, 12, 0.46)),
        linear-gradient(23deg, transparent 0 18%, rgba(255, 205, 210, 0.42) 18% 19%, transparent 19% 100%),
        linear-gradient(31deg, transparent 0 35%, var(--schedule-error-crack) 35% 38%, transparent 38% 100%),
        linear-gradient(151deg, transparent 0 58%, rgba(255, 210, 214, 0.42) 58% 59.5%, transparent 59.5% 100%),
        linear-gradient(104deg, transparent 0 44%, rgba(50, 0, 8, 0.5) 44% 46%, transparent 46% 100%),
        repeating-linear-gradient(112deg, transparent 0 10px, rgba(78, 0, 12, 0.42) 10px 12px, transparent 12px 23px),
        repeating-linear-gradient(36deg, transparent 0 18px, rgba(255, 195, 200, 0.18) 18px 19px, transparent 19px 37px),
        linear-gradient(135deg, var(--schedule-error-bg), var(--schedule-error-bg-2));
}
[data-bs-theme="dark"] {
    --schedule-laser: #36a2ff;
    --schedule-laser-hot: #79e7ff;
    --schedule-laser-core: #f0fbff;
    --schedule-flow-trail: rgba(54, 162, 255, 0.12);
    --schedule-flow-trail-strong: rgba(13, 202, 240, 0.28);
    --schedule-grid-bg: linear-gradient(180deg, rgba(54, 162, 255, 0.055), #1f252d);
    --schedule-block-bg: #1e63ad;
    --schedule-block-bg-2: #17395f;
    --schedule-concrete-bg: #56616e;
    --schedule-concrete-bg-2: #333b45;
    --schedule-concrete-ink: #f1f5f9;
    --schedule-concrete-line: rgba(255, 255, 255, 0.08);
    --schedule-error-bg: #9f2f39;
    --schedule-error-bg-2: #451018;
    --schedule-error-hot: #ff5c66;
    --schedule-error-ink: #fff5f6;
    --schedule-error-crack: rgba(12, 0, 3, 0.68);
}
.schedule-shell {
    color-scheme: light dark;
}
.hist-container {
    --hist-axis-offset: 56px;
    --timeline-block-min-width: 46px;
    position: relative;
    height: 170px;
    display: block;
    padding-bottom: 4px;
    isolation: isolate;
}
.hist-gridlines {
    position: absolute;
    top: 0;
    bottom: 18px; /* match bar-wrap padding-bottom so we don't draw over the x-labels */
    left: var(--hist-axis-offset);   /* start after the yaxis column */
    right: 0;
    pointer-events: none;
    z-index: 1;
}
.hist-gridlines .hline {
    position: absolute;
    left: 0;
    right: 0;
    height: 1px;
    background: var(--bs-border-color);
    opacity: 0.35;
}
.hist-gridlines .vline {
    position: absolute;
    top: 0;
    bottom: 0;
    width: 1px;
    background: var(--bs-border-color);
    opacity: 0.15;
}
.hist-current-time-line {
    position: absolute;
    top: 0;
    bottom: 18px;
    width: 0;
    border-left: 2px solid var(--schedule-laser-core);
    z-index: 5;
    pointer-events: none;
    filter: drop-shadow(0 0 8px var(--schedule-laser-hot));
}
.hist-current-time-line::before {
    content: "";
    position: absolute;
    left: -6px;
    top: -5px;
    width: 10px;
    height: 10px;
    border-radius: 50%;
    background: var(--schedule-laser-core);
    box-shadow:
        0 0 0 3px rgba(54, 162, 255, 0.18),
        0 0 18px var(--schedule-laser-hot),
        0 0 34px var(--schedule-laser);
}
.hist-current-time-line .current-time-label {
    position: absolute;
    top: -20px;
    left: 8px;
    padding: 2px 8px;
    border-radius: 999px;
    background: linear-gradient(135deg, var(--schedule-laser), #243a6b);
    color: #fff;
    font-size: 0.68rem;
    line-height: 1.25;
    font-weight: 600;
    font-variant-numeric: tabular-nums;
    white-space: nowrap;
    border: 1px solid rgba(255, 255, 255, 0.28);
    box-shadow: 0 0 18px rgba(54, 162, 255, 0.38), 0 1px 4px rgba(0, 0, 0, 0.25);
}
.hist-current-time-line.near-end .current-time-label {
    left: auto;
    right: 8px;
}
.hist-bar-wrap {
    position: relative;
    display: flex;
    flex-direction: column;
    justify-content: flex-end;
    height: 100%;
    padding-bottom: 18px;
    z-index: 2;
}
.hist-bar {
    width: 100%;
    display: flex;
    flex-direction: column-reverse;
    border-radius: 3px 3px 0 0;
    overflow: hidden;
    min-height: 1px;
    gap: 1px;
}
.hist-seg {
    width: 100%;
    flex: 1 1 0;
    min-height: 6px;
    cursor: pointer;
    touch-action: manipulation;
    background:
        linear-gradient(90deg, var(--agent-accent), rgba(255, 255, 255, 0.24) 8%, transparent 26%),
        linear-gradient(180deg, var(--schedule-laser-hot), var(--schedule-block-bg));
    transition: filter 0.1s, transform 0.1s;
}
.hist-timeline-axis {
    position: absolute;
    top: 8px;
    bottom: 22px;
    left: 0;
    width: 50px;
    color: var(--bs-secondary-color);
    font-size: 0.66rem;
    line-height: 1.15;
    display: flex;
    align-items: center;
    justify-content: flex-end;
    text-align: right;
    padding-right: 6px;
}
.hist-timeline-area {
    position: absolute;
    top: 8px;
    bottom: 22px;
    left: var(--hist-axis-offset);
    right: 0;
    z-index: 2;
}
.hist-event {
    position: absolute;
    height: 24px;
    min-width: var(--timeline-block-min-width);
    padding: 0;
    border-radius: 5px;
    border-left: 3px solid var(--agent-accent);
    color: #fff;
    overflow: hidden;
    text-decoration: none;
    background:
        radial-gradient(circle at 0% 50%, rgba(255, 255, 255, 0.2), transparent 42px),
        linear-gradient(135deg, var(--schedule-block-bg), var(--schedule-block-bg-2));
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.22), inset 0 1px 0 rgba(255, 255, 255, 0.12);
}
.hist-event::before {
    display: none;
}
.hist-event::after {
    content: "";
    position: absolute;
    top: 0;
    bottom: 0;
    left: 0;
    width: var(--past-pct, 0%);
    pointer-events: none;
    z-index: 1;
    background:
        linear-gradient(135deg, rgba(255, 255, 255, 0.16), rgba(0, 0, 0, 0.16)),
        repeating-linear-gradient(45deg, var(--schedule-concrete-line) 0 1px, transparent 1px 7px),
        repeating-linear-gradient(135deg, rgba(255, 255, 255, 0.06) 0 1px, transparent 1px 11px),
        linear-gradient(135deg, var(--schedule-concrete-bg), var(--schedule-concrete-bg-2));
}
.hist-event.is-future::after {
    display: none;
}
.hist-event.is-past {
    color: var(--schedule-concrete-ink);
    text-shadow: none;
    z-index: inherit;
}
.hist-event.is-error::after {
    background: var(--schedule-error-cracked);
    box-shadow: inset 0 0 14px rgba(54, 0, 8, 0.28);
}
.hist-event.is-error.is-past {
    color: var(--schedule-error-ink);
}
.hist-seg.is-past {
    background:
        repeating-linear-gradient(45deg, var(--schedule-concrete-line) 0 1px, transparent 1px 6px),
        linear-gradient(180deg, var(--schedule-concrete-bg), var(--schedule-concrete-bg-2));
    border-left: 5px solid var(--agent-accent);
    z-index: -1;
}
.hist-seg.is-error.is-past {
    background: var(--schedule-error-cracked);
    border-left-color: var(--schedule-error-hot);
}
.hist-seg:hover {
    filter: brightness(1.25);
    transform: scaleX(1.4);
    z-index: 5;
}
.hist-time-trail {
    position: absolute;
    top: 0;
    bottom: 18px;
    left: var(--hist-axis-offset);
    width: calc((100% - var(--hist-axis-offset)) * var(--now-pct, 0));
    z-index: 0;
    pointer-events: none;
    background:
        linear-gradient(90deg, rgba(54, 162, 255, 0), var(--schedule-flow-trail), var(--schedule-flow-trail-strong)),
        repeating-linear-gradient(90deg, transparent 0 18px, rgba(255, 255, 255, 0.06) 18px 19px);
    box-shadow: inset -18px 0 24px rgba(54, 162, 255, 0.16);
}
.hist-xaxis {
    position: absolute;
    left: var(--hist-axis-offset);
    right: 0;
    bottom: 0;
    height: 16px;
    pointer-events: none;
}
.hist-xaxis .xl {
    position: absolute;
    transform: translateX(-50%);
    font-size: 0.62rem;
    color: var(--bs-secondary-color);
    white-space: nowrap;
    line-height: 1;
}
.hist-xaxis .xl.major { font-weight: 600; color: var(--bs-body-color); }
.hist-xaxis .xl.edge-left { transform: translateX(0); }
.hist-xaxis .xl.edge-right { transform: translateX(-100%); }
.hist-yaxis {
    position: relative;
    font-size: 0.65rem;
    color: var(--bs-secondary-color);
    padding-right: 6px;
    padding-bottom: 18px;
    text-align: right;
    height: 100%;
}
.hist-yaxis span {
    position: absolute;
    right: 6px;
    transform: translateY(-50%);
}

.day-pills {
    display: flex;
    gap: 4px;
    flex-wrap: wrap;
}
.day-pill {
    padding: 5px 14px;
    border-radius: 999px;
    border: 1px solid var(--bs-border-color);
    background: var(--bs-body-bg);
    color: var(--bs-body-color);
    text-decoration: none;
    font-size: 0.85rem;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.12s;
}
.day-pill:hover {
    border-color: var(--schedule-laser);
    color: var(--schedule-laser);
}
.day-pill.active {
    background: linear-gradient(135deg, #243a6b, var(--schedule-laser));
    color: #fff;
    border-color: rgba(54, 162, 235, 0.55);
    box-shadow: 0 0 18px rgba(54, 162, 255, 0.2);
}
.day-pill.today {
    border-color: rgba(54, 162, 235, 0.7);
}
.day-pill .pill-count {
    opacity: 0.7;
    font-size: 0.75rem;
    margin-left: 4px;
}

.day-timeline {
    display: grid;
    grid-template-columns: 56px 1fr;
    gap: 8px;
    padding: 8px;
    background: var(--bs-body-bg);
    border-radius: 8px;
}
.day-hours {
    position: relative;
    font-size: 0.75rem;
    color: var(--bs-secondary-color);
}
.day-hours .hour-label {
    position: absolute;
    right: 6px;
    transform: translateY(-50%);
    padding: 2px 0;
    background: var(--bs-body-bg);
}
.day-hours .hour-label.edge-top { transform: translateY(0); }
.day-hours .hour-label.edge-bottom { transform: translateY(-100%); }
.day-hours .hour-label.half {
    font-size: 0.65rem;
    opacity: 0.55;
}
.day-col .hour-line.half {
    opacity: 0.2;
    border-top: 1px dashed var(--bs-border-color);
    background: transparent;
    height: 0;
}
.day-col {
    position: relative;
    border-left: 1px solid var(--bs-border-color);
    background: var(--schedule-grid-bg);
    border-radius: 4px;
    overflow: hidden;
    isolation: isolate;
}
.day-col .hour-line {
    position: absolute;
    left: 0;
    right: 0;
    height: 1px;
    background: var(--bs-border-color);
    opacity: 0.35;
}
.day-col .hour-line.major { opacity: 0.6; }
.current-time-line {
    position: absolute;
    left: 0;
    right: 0;
    height: 3px;
    border-top: 0;
    background: linear-gradient(90deg, rgba(54, 162, 255, 0), var(--schedule-laser-hot), var(--schedule-laser-core), var(--schedule-laser));
    z-index: 20;
    pointer-events: none;
    box-shadow:
        0 0 8px var(--schedule-laser-hot),
        0 0 22px rgba(54, 162, 255, 0.55),
        0 0 42px rgba(13, 202, 240, 0.22);
}
.current-time-line::before {
    content: "";
    position: absolute;
    left: -6px;
    top: -5px;
    width: 12px;
    height: 12px;
    border-radius: 50%;
    background: var(--schedule-laser-core);
    box-shadow:
        0 0 0 4px rgba(54, 162, 255, 0.15),
        0 0 22px var(--schedule-laser-hot),
        0 0 44px var(--schedule-laser);
}
.current-time-line .current-time-label {
    position: absolute;
    right: 8px;
    top: -8px;
    padding: 2px 8px;
    border-radius: 999px;
    background: linear-gradient(135deg, var(--schedule-laser), #243a6b);
    color: #fff;
    font-size: 0.68rem;
    line-height: 1.25;
    font-weight: 600;
    font-variant-numeric: tabular-nums;
    border: 1px solid rgba(255, 255, 255, 0.28);
    box-shadow: 0 0 18px rgba(54, 162, 255, 0.42), 0 1px 4px rgba(0, 0, 0, 0.25);
}
.time-flow-trail {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    z-index: 1;
    pointer-events: none;
    background:
        linear-gradient(180deg, rgba(54, 162, 255, 0), var(--schedule-flow-trail) 54%, var(--schedule-flow-trail-strong)),
        repeating-linear-gradient(135deg, transparent 0 18px, rgba(255, 255, 255, 0.035) 18px 19px);
    box-shadow: inset 0 -20px 28px rgba(54, 162, 255, 0.12);
}
.day-block {
    position: absolute;
    padding: 2px 8px;
    border-radius: 5px;
    color: #fff;
    overflow: hidden;
    cursor: pointer;
    border-left: 5px solid var(--agent-accent);
    transition: opacity 0.15s, transform 0.15s, filter 0.15s;
    text-decoration: none;
    background:
        radial-gradient(circle at 0% 50%, rgba(255, 255, 255, 0.22), transparent 44px),
        linear-gradient(135deg, var(--schedule-block-bg), var(--schedule-block-bg-2));
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.2), inset 0 1px 0 rgba(255, 255, 255, 0.12);
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 7px;
    z-index: 5;
    text-shadow: 0 1px 2px rgba(0, 0, 0, 0.62);
}
.day-block > * {
    position: relative;
    z-index: 2;
}
.day-block::before {
    content: "";
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: var(--agent-accent);
    box-shadow: 0 0 8px var(--agent-accent);
    flex: 0 0 auto;
    position: relative;
    z-index: 2;
}
.day-block::after {
    content: "";
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: var(--past-pct, 0%);
    pointer-events: none;
    z-index: 1;
    background:
        linear-gradient(135deg, rgba(255, 255, 255, 0.16), rgba(0, 0, 0, 0.16)),
        repeating-linear-gradient(45deg, var(--schedule-concrete-line) 0 1px, transparent 1px 7px),
        repeating-linear-gradient(135deg, rgba(255, 255, 255, 0.06) 0 1px, transparent 1px 11px),
        linear-gradient(135deg, var(--schedule-concrete-bg), var(--schedule-concrete-bg-2));
    box-shadow: inset 0 -10px 22px rgba(0, 0, 0, 0.14);
}
.day-block.is-future::after {
    display: none;
}
.day-block:hover {
    /* No transform: scale — it enlarged the text inside the block's fixed
       overflow:hidden box, which clipped descenders (g, p, y) that fit at
       rest (#171). Stronger box-shadow alone gives the lift cue. */
    z-index: 10;
    color: #fff;
    filter: brightness(1.08);
    box-shadow: 0 3px 12px rgba(0, 0, 0, 0.36), 0 0 22px rgba(54, 162, 255, 0.18);
}
.day-block.is-past:hover {
    color: var(--schedule-concrete-ink);
}
.day-block.is-past {
    color: var(--schedule-concrete-ink);
    text-shadow: none;
    box-shadow:
        inset 0 1px 0 rgba(255, 255, 255, 0.12),
        inset 0 -10px 22px rgba(0, 0, 0, 0.16),
        0 1px 3px rgba(0, 0, 0, 0.16);
}
.day-block.is-active {
    text-shadow: 0 1px 2px rgba(0, 0, 0, 0.78), 0 0 5px rgba(0, 0, 0, 0.42);
}
.day-block.is-error::after {
    background: var(--schedule-error-cracked);
    box-shadow: inset 0 -10px 22px rgba(54, 0, 8, 0.25);
}
.day-block.is-error.is-past {
    color: var(--schedule-error-ink);
}
.day-block.is-past::before {
    background: var(--agent-accent);
    box-shadow: none;
    opacity: 0.9;
}
.day-block.is-error.is-past::before {
    background: var(--schedule-error-hot);
}
.day-block.estimated {
    background-image:
        repeating-linear-gradient(45deg, rgba(255, 255, 255, 0.12), rgba(255, 255, 255, 0.12) 8px, transparent 8px, transparent 16px),
        radial-gradient(circle at 0% 50%, rgba(255, 255, 255, 0.22), transparent 44px),
        linear-gradient(135deg, var(--schedule-block-bg), var(--schedule-block-bg-2));
}
.day-block.estimated.is-past {
    background-image:
        repeating-linear-gradient(45deg, rgba(255, 255, 255, 0.12), rgba(255, 255, 255, 0.12) 8px, transparent 8px, transparent 16px),
        radial-gradient(circle at 0% 50%, rgba(255, 255, 255, 0.22), transparent 44px),
        linear-gradient(135deg, var(--schedule-block-bg), var(--schedule-block-bg-2));
}
.day-block .agent {
    font-weight: 700;
    font-size: 0.78rem;
    /* 1.2 (not 1.0) so descenders on letters like p/g/y aren't clipped
       by the day-block's overflow:hidden */
    line-height: 1.2;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    text-transform: uppercase;
    flex: 0 1 34%;
    max-width: 34%;
    min-width: 0;
}
.day-block .side {
    display: flex;
    flex-direction: row;
    align-items: center;
    justify-content: flex-start;
    text-align: left;
    line-height: 1.2;
    gap: 8px;
    flex: 1 1 auto;
    max-width: none;
    min-width: 0;
}
.day-block .side > div {
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    max-width: 100%;
}
.day-block .side .plan {
    flex: 1 1 auto;
    min-width: 0;
    font-weight: 700;
    font-size: 0.72rem;
    opacity: 0.98;
    text-transform: uppercase;
}
.day-block .side .when {
    flex: 0 1 auto;
    max-width: 48%;
    min-width: 0;
    font-size: 0.68rem;
    font-weight: 600;
    opacity: 0.92;
    font-variant-numeric: tabular-nums;
}
.dim {
    opacity: 0.12 !important;
    pointer-events: none;
}

/* Custom tooltip for histogram + blocks */
.sched-tooltip {
    position: fixed;
    z-index: 9999;
    background: rgba(30, 33, 38, 0.97);
    color: #fff;
    padding: 8px 12px;
    border-radius: 6px;
    font-size: 0.75rem;
    line-height: 1.4;
    max-width: 280px;
    box-shadow: 0 4px 16px rgba(0, 0, 0, 0.5);
    pointer-events: none;
    display: none;
    border: 1px solid rgba(255, 255, 255, 0.08);
}
.sched-tooltip .tt-title { font-weight: 600; margin-bottom: 4px; }
.sched-tooltip .tt-meta { opacity: 0.7; font-size: 0.7rem; margin-bottom: 6px; }
.sched-tooltip ul { margin: 0; padding-left: 16px; font-size: 0.72rem; }
.sched-tooltip.timeline-tooltip {
    max-width: min(440px, calc(100vw - 24px));
    padding: 14px 16px;
    font-size: 0.95rem;
    line-height: 1.55;
}
.sched-tooltip.timeline-tooltip .tt-title {
    font-size: 1.05rem;
    margin-bottom: 6px;
}
.sched-tooltip.timeline-tooltip .tt-title + .tt-title {
    margin-top: -2px;
}
.sched-tooltip.timeline-tooltip .tt-meta {
    font-size: 0.82rem;
    margin-bottom: 10px;
}
.sched-tooltip.timeline-tooltip .tt-hint {
    margin-top: 10px;
    opacity: 0.62;
    font-size: 0.78rem;
}

/* Context menu */
.sched-ctxmenu {
    position: fixed;
    z-index: 10000;
    background: var(--bs-body-bg);
    border: 1px solid var(--bs-border-color);
    border-radius: 8px;
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.4);
    min-width: 200px;
    padding: 4px;
    display: none;
}
.sched-ctxmenu button {
    display: flex;
    align-items: center;
    gap: 8px;
    width: 100%;
    background: transparent;
    border: none;
    padding: 8px 12px;
    text-align: left;
    font-size: 0.85rem;
    color: var(--bs-body-color);
    border-radius: 5px;
    cursor: pointer;
}
.sched-ctxmenu button:hover { background: var(--bs-tertiary-bg); }
.sched-ctxmenu button:disabled { opacity: 0.4; cursor: not-allowed; }
.sched-ctxmenu button i { width: 18px; text-align: center; }
.sched-ctxmenu .divider { height: 1px; background: var(--bs-border-color); margin: 4px 0; }

/* Compact repeated items under the timeline keep the same blue/accent system. */
.schedule-mini-card {
    border-left: 3px solid var(--agent-accent) !important;
    background:
        linear-gradient(90deg, rgba(54, 162, 255, 0.08), transparent 48%),
        var(--bs-body-bg);
}

/* Mobile: thin the x-axis labels and keep time-based block widths honest. */
@media (max-width: 767.98px) {
    .hist-xaxis .xl[data-hour]:not([data-hour="0"]):not([data-hour="4"]):not([data-hour="8"]):not([data-hour="12"]):not([data-hour="16"]):not([data-hour="20"]) {
        display: none;
    }
    .hist-event {
        --timeline-block-min-width: 0px;
        min-width: 0;
        border-left-width: 2px;
    }
    .hist-seg:hover {
        transform: none;
        filter: brightness(1.12);
    }
    .day-block {
        gap: 0;
        padding: 0 4px 0 0;
        min-width: 6px;
        border-left: 1px solid var(--agent-accent);
        touch-action: manipulation;
        -webkit-tap-highlight-color: transparent;
    }
    .day-block::before {
        align-self: stretch;
        width: 6px;
        height: auto;
        border-radius: 0;
        margin: 0 3px 0 0;
    }
    .day-block .agent {
        display: block;
        flex: 1 1 auto;
        max-width: none;
        min-width: 0;
        font-size: 0.62rem;
        line-height: 1;
    }
    .day-block .side {
        display: none;
    }
}

/* Tablet/medium: keep backup timeline blocks proportional without the desktop minimum. */
@media (min-width: 768px) and (max-width: 1199.98px) {
    .hist-container {
        --hist-axis-offset: 0px;
        --timeline-block-min-width: 1px;
    }
    .hist-timeline-axis {
        display: none;
    }
    .hist-event {
        min-width: var(--timeline-block-min-width, 1px);
    }
}

/* Phone-only: keep the backup timeline compact; tablets retain labels/axis. */
@media (max-width: 575.98px) {
    .hist-container {
        --hist-axis-offset: 0px;
    }
    .hist-current-time-line .current-time-label,
    .hist-timeline-axis {
        display: none;
    }
}
</style>

<div class="schedule-shell container-fluid py-3">
    <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
        <div class="text-muted small">Times shown in <?= htmlspecialchars($userTz) ?></div>
        <div class="d-flex align-items-center gap-3 flex-wrap">
            <div class="day-pills" id="day-pills">
                <?php foreach ($dayLabels as $idx => $label): ?>
                <?php $count = count($blocksByDay[$idx]); ?>
                <button type="button"
                        class="day-pill <?= $idx === $todayIdx ? 'today' : '' ?>"
                        data-day-idx="<?= $idx ?>">
                    <?= $idx === $todayIdx ? 'Today' : $label ?>
                    <?php if ($count > 0): ?><span class="pill-count"><?= $count ?></span><?php endif; ?>
                </button>
                <?php endforeach; ?>
            </div>
            <div class="d-flex align-items-center gap-2">
                <label class="form-label mb-0 small text-muted">Client:</label>
                <select id="agent-filter" class="form-select form-select-sm" style="width: auto;">
                    <option value="">All</option>
                    <?php foreach ($shownAgents as $aid => $aname): ?>
                    <option value="<?= (int) $aid ?>"><?= htmlspecialchars($aname) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
    </div>

    <?php if (empty($blocks) && empty($continuous) && empty($otherSchedules)): ?>
    <div class="card border-0 shadow-sm">
        <div class="card-body text-center py-5 text-muted">
            <i class="bi bi-calendar-x fs-1 d-block mb-2"></i>
            No enabled schedules found. Create a backup plan with a schedule to see it here.
        </div>
    </div>
    <?php else: ?>

    <!-- Compact day timeline. Blocks are positioned by start time and sized by
         the same estimated duration used by the main day view. -->
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-header card-head-gradient fw-semibold d-flex justify-content-between align-items-center">
            <span><i class="bi bi-bar-chart me-2"></i>Backup Timeline</span>
            <span class="text-muted small"><?= count($blocks) ?> <?= count($blocks) === 1 ? 'run' : 'runs' ?></span>
        </div>
        <div class="card-body py-3">
            <?php
                // Hour labels appear at the top-of-hour bucket (every 2 buckets).
                // "Major" labels (printed) at every 6 hours.
                $formatHourLabel = function (int $hour) use ($is24h): string {
                    if ($is24h) {
                        return sprintf('%02d:00', $hour);
                    }
                    if ($hour === 0) return '12 AM';
                    if ($hour === 12) return '12 PM';
                    return $hour < 12 ? "{$hour} AM" : ($hour - 12) . ' PM';
                };
            ?>
            <?php
                // Hour labels every 2 hours on the x-axis. Every 6 hours gets
                // bold weight as a "major" reference.
                $xLabelStep = 2;
            ?>
            <?php for ($dIdx = 0; $dIdx < 7; $dIdx++): ?>
            <?php
                $laneCountForDay = 1;
                foreach ($blocksByDay[$dIdx] as $timelineBlock) {
                    $laneCountForDay = max($laneCountForDay, (int) ($timelineBlock['lane_count'] ?? 1));
                }
                $timelineHeight = max(112, 46 + $laneCountForDay * 30);
            ?>
            <div class="hist-container"
                 data-day-idx="<?= $dIdx ?>"
                 style="<?= $dIdx === $todayIdx ? '' : 'display: none;' ?> height: <?= $timelineHeight ?>px;">

                <div class="hist-gridlines">
                    <?php for ($h = 1; $h < 24; $h++): ?>
                        <?php $leftPct = ($h / 24) * 100; ?>
                        <div class="vline" style="left: <?= $leftPct ?>%;"></div>
                    <?php endfor; ?>
                </div>
                <?php if ($dIdx === $todayIdx): ?>
                    <?php $nowLeftPct = ($currentMinuteOfDay / 1440) * 100; ?>
                    <div class="hist-time-trail"
                         style="--now-pct: <?= $nowLeftPct / 100 ?>;"></div>
                    <div class="hist-current-time-line <?= $nowLeftPct > 80 ? 'near-end' : '' ?>"
                         style="left: calc(var(--hist-axis-offset) + ((100% - var(--hist-axis-offset)) * <?= $nowLeftPct / 100 ?>));"
                         title="Current time in <?= htmlspecialchars($userTz) ?>">
                        <span class="current-time-label">Time now: <?= htmlspecialchars($currentTimeLabel) ?></span>
                    </div>
                <?php endif; ?>

                <div class="hist-timeline-axis">Runs</div>
                <div class="hist-timeline-area">
                    <?php foreach ($blocksByDay[$dIdx] as $b): ?>
                        <?php
                        $startPct = max(0, min(100, ($b['start_min'] / 1440) * 100));
                        $actualDurationPct = max(0.001, min(100, ($b['duration_min'] / 1440) * 100));
                        $durationPct = max(0.25, $actualDurationPct);
                        $laneTop = (int) ($b['lane'] ?? 0) * 30;
                        $timelinePastPct = bbs_schedule_progress_pct((int) $b['day_idx'], (int) $b['start_min'], (int) $b['duration_min'], $todayIdx, $currentMinuteOfDay);
                        $timelinePhase = bbs_day_block_phase($timelinePastPct);
                        $timelineWidthPct = ((int) $b['day_idx'] === $todayIdx && $timelinePhase !== 'future')
                            ? $actualDurationPct
                            : $durationPct;
                        $timelineIsError = !empty($b['has_error']);
                        // Today's lane normally allows shrinking to 0 so the
                        // bar visually contracts as the run finishes; failed
                        // blocks need to stay clickable regardless of how
                        // little time elapsed before they errored.
                        $timelineMinWidth = ((int) $b['day_idx'] === $todayIdx && $timelinePhase !== 'future' && !$timelineIsError)
                            ? '0px'
                            : null;
                        $timelineDurLabel = \BBS\Core\TimeHelper::duration((int) $b['duration_min'] * 60);
                        ?>
                        <div class="hist-seg hist-event is-<?= $timelinePhase ?> <?= $timelineIsError ? 'is-error' : '' ?>"
                             data-schedule-id="<?= $b['schedule_id'] ?>"
                             data-agent-id="<?= (int) $b['agent_id'] ?>"
                             data-plan-name="<?= htmlspecialchars($b['plan_name']) ?>"
                             data-agent-name="<?= htmlspecialchars($b['agent_name']) ?>"
                             data-time="<?= htmlspecialchars($b['time_label']) ?>"
                             data-duration="<?= htmlspecialchars($timelineDurLabel) ?>"
                             data-frequency="<?= htmlspecialchars($b['frequency']) ?>"
                             data-job-status="<?= htmlspecialchars($b['job_status'] ?? '') ?>"
                             data-failed-job-id="<?= (int) ($b['failed_job_id'] ?? 0) ?>"
                             style="top: <?= $laneTop ?>px; left: <?= round($startPct, 4) ?>%; width: max(var(--timeline-block-min-width), <?= round($timelineWidthPct, 4) ?>%); max-width: calc(100% - <?= round($startPct, 4) ?>%); --agent-accent: <?= bbs_agent_color((int) $b['agent_id']) ?>; --past-pct: <?= round($timelinePastPct, 2) ?>%;<?= $timelineMinWidth !== null ? ' --timeline-block-min-width: ' . $timelineMinWidth . ';' : '' ?>">
                        </div>
                    <?php endforeach; ?>
                    <?php if (empty($blocksByDay[$dIdx])): ?>
                    <div class="d-flex align-items-center text-muted small h-100" style="font-style: italic;">No scheduled runs.</div>
                    <?php endif; ?>
                </div>

                <!-- Dedicated x-axis row below bars, spans the full bar area
                     so edge labels can be aligned flush without clipping. -->
                <div class="hist-xaxis">
                    <?php for ($h = 0; $h <= 24; $h += $xLabelStep): ?>
                        <?php
                        $leftPct = ($h / 24) * 100;
                        $isMajor = $h % 6 === 0;
                        $edge = $h === 0 ? 'edge-left' : ($h === 24 ? 'edge-right' : '');
                        $label = $formatHourLabel($h === 24 ? 23 : $h);
                        // Skip 24 label if it overlaps with last major (23)
                        if ($h === 24) continue;
                        ?>
                        <span class="xl <?= $isMajor ? 'major' : '' ?> <?= $edge ?>"
                              data-hour="<?= $h ?>"
                              style="left: <?= $leftPct ?>%;"><?= $label ?></span>
                    <?php endfor; ?>
                </div>
            </div>
            <?php endfor; ?>
        </div>
    </div>

    <!-- Day timeline (day picker is in the page header, shared with histogram) -->
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-header card-head-gradient fw-semibold">
            <i class="bi bi-calendar-day me-2"></i>Day View
            <span class="text-muted small ms-2" id="day-view-label"></span>
        </div>
        <div class="card-body p-2">
            <div class="day-timeline">
                <div class="day-hours" style="height: <?= $gridHeight ?>px;">
                    <?php for ($h = 0; $h < 24; $h++): ?>
                    <div class="hour-label <?= $h === 0 ? 'edge-top' : '' ?>" style="top: <?= $h * $pxPerHour ?>px;">
                        <?= $formatHourLabel($h) ?>
                    </div>
                    <div class="hour-label half <?= $h === 23 ? 'edge-bottom' : '' ?>" style="top: <?= $h * $pxPerHour + $pxPerHour / 2 ?>px;">
                        <?php
                            if ($is24h) {
                                echo sprintf('%02d:30', $h);
                            } else {
                                $suffix = $h < 12 ? 'AM' : 'PM';
                                $h12 = $h % 12;
                                if ($h12 === 0) $h12 = 12;
                                echo "{$h12}:30 {$suffix}";
                            }
                        ?>
                    </div>
                    <?php endfor; ?>
                </div>
                <div class="day-col" id="day-col" style="height: <?= $gridHeight ?>px;">
                    <?php for ($h = 0; $h < 24; $h++): ?>
                    <div class="hour-line <?= $h % 6 === 0 ? 'major' : '' ?>" style="top: <?= $h * $pxPerHour ?>px;"></div>
                    <div class="hour-line half" style="top: <?= $h * $pxPerHour + $pxPerHour / 2 ?>px;"></div>
                    <?php endfor; ?>

                    <?php for ($dIdx = 0; $dIdx < 7; $dIdx++): ?>
                    <div class="day-content" data-day-idx="<?= $dIdx ?>" style="<?= $dIdx === $todayIdx ? '' : 'display: none;' ?>">
                        <?php if ($dIdx === $todayIdx): ?>
                        <div class="time-flow-trail"
                             style="height: <?= $currentMinuteOfDay * ($pxPerHour / 60) ?>px;"></div>
                        <div class="current-time-line"
                             style="top: <?= $currentMinuteOfDay * ($pxPerHour / 60) ?>px;"
                             title="Current time in <?= htmlspecialchars($userTz) ?>">
                            <span class="current-time-label">Time now: <?= htmlspecialchars($currentTimeLabel) ?></span>
                        </div>
                        <?php endif; ?>
                        <?php foreach ($blocksByDay[$dIdx] as $b): ?>
                            <?php
                            $top = $b['start_min'] * ($pxPerHour / 60);
                            $height = max($minBlockPx, $b['duration_min'] * ($pxPerHour / 60));
                            $laneWidth = 100 / $b['lane_count'];
                            $left = $b['lane'] * $laneWidth;
                            $color = bbs_agent_color($b['agent_id']);
                            $pastPct = bbs_day_block_progress_pct((int) $b['day_idx'], (int) $b['start_min'], (float) $height, $todayIdx, $currentMinuteOfDay, $pxPerHour);
                            $phase = bbs_day_block_phase($pastPct);
                            $isError = !empty($b['has_error']);
                            $durLabel = \BBS\Core\TimeHelper::duration((int) $b['duration_min'] * 60);
                            $title = sprintf(
                                "%s\nClient: %s\nStarts: %s (%s)\nEstimated duration: %s%s",
                                $b['plan_name'],
                                $b['agent_name'],
                                $b['time_label'],
                                ucfirst($b['frequency']),
                                $durLabel,
                                $b['estimated'] ? ' (no history)' : ''
                            );
                            ?>
                        <div class="day-block <?= $b['estimated'] ? 'estimated' : '' ?> is-<?= $phase ?> <?= $isError ? 'is-error' : '' ?>"
                             data-agent-id="<?= $b['agent_id'] ?>"
                             data-schedule-id="<?= $b['schedule_id'] ?>"
                             data-plan-id="<?= $b['plan_id'] ?>"
                             data-plan-name="<?= htmlspecialchars($b['plan_name']) ?>"
                             data-agent-name="<?= htmlspecialchars($b['agent_name']) ?>"
                             data-frequency="<?= htmlspecialchars($b['frequency']) ?>"
                             data-time="<?= htmlspecialchars($b['time_label']) ?>"
                             data-duration="<?= htmlspecialchars($durLabel) ?>"
                             data-estimated="<?= $b['estimated'] ? '1' : '0' ?>"
                             data-job-status="<?= htmlspecialchars($b['job_status'] ?? '') ?>"
                             data-failed-job-id="<?= (int) ($b['failed_job_id'] ?? 0) ?>"
                             style="top: <?= $top ?>px; height: <?= $height ?>px; left: calc(<?= $left ?>% + 4px); width: calc(<?= $laneWidth ?>% - 8px); --agent-accent: <?= $color ?>; --past-pct: <?= round($pastPct, 2) ?>%;">
                            <div class="agent"><?= htmlspecialchars($b['agent_name']) ?></div>
                            <div class="side">
                                <div class="plan"><?= htmlspecialchars($b['plan_name']) ?></div>
                                <div class="when"><?= htmlspecialchars($b['time_label']) ?> · ~<?= htmlspecialchars($durLabel) ?><?= $b['estimated'] ? ' est' : '' ?></div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        <?php if (empty($blocksByDay[$dIdx])): ?>
                        <div class="d-flex align-items-center justify-content-center text-muted" style="height: <?= $gridHeight ?>px; font-style: italic;">
                            No schedules for <?= $dayLabelsLong[$dIdx] ?>.
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endfor; ?>
                </div>
            </div>
        </div>
    </div>

    <?php if (!empty($continuous)): ?>
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-header card-head-gradient fw-semibold">
            <i class="bi bi-arrow-repeat me-1"></i>Continuous schedules
        </div>
        <div class="card-body">
            <div class="row g-2 small">
                <?php foreach ($continuous as $c): ?>
                <?php $s = $c['schedule']; ?>
                <div class="col-md-6 col-lg-4" data-agent-id="<?= (int) $s['agent_id'] ?>">
                    <a href="/clients/<?= (int) $s['agent_id'] ?>?tab=schedules" class="schedule-mini-card d-block p-2 rounded text-decoration-none border"
                       style="--agent-accent: <?= bbs_agent_color((int) $s['agent_id']) ?>;">
                        <div class="fw-semibold"><?= htmlspecialchars($s['plan_name']) ?></div>
                        <div class="text-muted">Runs every <?= htmlspecialchars($c['interval_label']) ?> · <?= htmlspecialchars($s['agent_name']) ?></div>
                    </a>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($otherSchedules)): ?>
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-header card-head-gradient fw-semibold">
            <i class="bi bi-calendar-month me-1"></i>Monthly schedules
        </div>
        <div class="card-body">
            <div class="row g-2 small">
                <?php foreach ($otherSchedules as $s): ?>
                <div class="col-md-6 col-lg-4" data-agent-id="<?= (int) $s['agent_id'] ?>">
                    <a href="/clients/<?= (int) $s['agent_id'] ?>?tab=schedules" class="schedule-mini-card d-block p-2 rounded text-decoration-none border"
                       style="--agent-accent: <?= bbs_agent_color((int) $s['agent_id']) ?>;">
                        <div class="fw-semibold"><?= htmlspecialchars($s['plan_name']) ?></div>
                        <div class="text-muted">
                            <?= htmlspecialchars($s['agent_name']) ?>
                            <?php if (!empty($s['next_run'])): ?>
                            · Next run <?= \BBS\Core\TimeHelper::format($s['next_run'], 'M j, g:i A') ?>
                            <?php endif; ?>
                        </div>
                    </a>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php endif; ?>
</div>

<!-- Shared tooltip (used by histogram + blocks) -->
<div id="sched-tooltip" class="sched-tooltip"></div>

<!-- Block context menu -->
<div id="sched-ctxmenu" class="sched-ctxmenu">
    <button type="button" id="ctx-retry" style="display: none;">
        <i class="bi bi-arrow-repeat"></i><span>Retry</span>
    </button>
    <button type="button" id="ctx-change-time">
        <i class="bi bi-clock"></i><span>Change Time</span>
    </button>
    <button type="button" id="ctx-edit-plan">
        <i class="bi bi-pencil-square"></i><span>Edit Plan</span>
    </button>
    <div class="divider"></div>
    <button type="button" id="ctx-disable">
        <i class="bi bi-pause-circle"></i><span>Disable Schedule</span>
    </button>
</div>

<!-- Change Time modal -->
<div class="modal fade" id="change-time-modal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-clock me-2"></i>Change Time
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3 small text-muted" id="ct-context"></div>

                <div id="ct-dow-section" class="mb-3" style="display: none;">
                    <label class="form-label small">
                        <i class="bi bi-calendar-event me-1"></i>Days of week
                    </label>
                    <div id="ct-dow" class="btn-group btn-group-sm d-flex flex-wrap">
                        <?php foreach ([1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 0 => 'Sun'] as $dv => $dl): ?>
                        <button type="button" class="btn btn-outline-primary ct-dow-btn" data-day="<?= $dv ?>"><?= $dl ?></button>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="mb-2">
                    <label class="form-label small">
                        <i class="bi bi-clock me-1"></i>Times
                        <span class="text-muted">(24-hour format, HH:MM)</span>
                    </label>
                    <div id="ct-times-list"></div>
                    <button type="button" id="ct-add-time" class="btn btn-sm btn-outline-secondary mt-1">
                        <i class="bi bi-plus-lg"></i> Add another time
                    </button>
                </div>
                <div id="ct-error" class="alert alert-danger small py-2 mb-0" style="display: none;"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" id="ct-save" class="btn btn-primary">
                    <i class="bi bi-check-lg me-1"></i>Save
                </button>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const scheduleMap = <?= json_encode($scheduleMap ?? []) ?>;
    const csrfToken   = <?= json_encode($csrfToken ?? '') ?>;
    const dayLabels   = <?= json_encode($dayLabelsLong) ?>;

    // ----------------- Day picker + filter ----------------------------------
    const pills = document.querySelectorAll('.day-pill');
    const dayContents = document.querySelectorAll('.day-content');
    const histContainers = document.querySelectorAll('.hist-container');
    const dayViewLabel = document.getElementById('day-view-label');
    const filter = document.getElementById('agent-filter');
    const today = <?= $todayIdx ?>;

    function showDay(idx) {
        pills.forEach(p => p.classList.toggle('active', Number(p.dataset.dayIdx) === idx));
        dayContents.forEach(c => c.style.display = (Number(c.dataset.dayIdx) === idx) ? '' : 'none');
        histContainers.forEach(c => c.style.display = (Number(c.dataset.dayIdx) === idx) ? '' : 'none');
        if (dayViewLabel) {
            dayViewLabel.textContent = idx === today ? '(Today · ' + dayLabels[idx] + ')' : dayLabels[idx];
        }
    }
    showDay(today);
    pills.forEach(p => p.addEventListener('click', () => showDay(Number(p.dataset.dayIdx))));

    if (filter) {
        filter.addEventListener('change', function () {
            const agentId = this.value;
            document.querySelectorAll('[data-agent-id]').forEach(function (el) {
                if (!agentId || el.dataset.agentId === agentId) {
                    el.classList.remove('dim');
                    if (el.classList.contains('col-md-6')) el.style.display = '';
                } else {
                    if (el.classList.contains('day-block') || el.classList.contains('hist-seg')) {
                        el.classList.add('dim');
                    } else {
                        el.style.display = 'none';
                    }
                }
            });
        });
    }

    // ----------------- Tooltip ---------------------------------------------
    const tooltip = document.getElementById('sched-tooltip');
    function showTooltip(html, ev) {
        tooltip.className = 'sched-tooltip';
        tooltip.innerHTML = html;
        tooltip.style.display = 'block';
        moveTooltip(ev);
    }
    function showTimelineTooltip(html, ev) {
        tooltip.className = 'sched-tooltip timeline-tooltip';
        tooltip.innerHTML = html;
        tooltip.style.display = 'block';
        moveTooltip(ev);
    }
    function moveTooltip(ev) {
        const pad = 12;
        let x = ev.clientX + pad, y = ev.clientY + pad;
        const rect = tooltip.getBoundingClientRect();
        if (x + rect.width > window.innerWidth - 8) x = ev.clientX - rect.width - pad;
        if (y + rect.height > window.innerHeight - 8) y = ev.clientY - rect.height - pad;
        tooltip.style.left = x + 'px';
        tooltip.style.top = y + 'px';
    }
    function hideTooltip() { tooltip.style.display = 'none'; }

    function esc(s) { const d = document.createElement('div'); d.textContent = s ?? ''; return d.innerHTML; }
    const mobileScheduleQuery = window.matchMedia('(hover: none), (pointer: coarse)');
    function isMobileScheduleMode() { return mobileScheduleQuery.matches; }
    function scheduleTooltipHtml(el) {
        return '<div class="tt-title">' + esc(el.dataset.planName) + '</div>' +
            '<div class="tt-title">' + esc(el.dataset.agentName) + '</div>' +
            '<div class="tt-meta">' + esc(el.dataset.frequency) + '</div>' +
            'Starts: <strong>' + esc(el.dataset.time) + '</strong><br>' +
            'Est. duration: <strong>' + esc(el.dataset.duration || '') + '</strong>' +
            (el.dataset.jobStatus === 'failed' ? '<br>Result: <strong>Error</strong>' : '') +
            (el.dataset.estimated === '1' ? ' <span style="opacity:.6">(no history — default)</span>' : '') +
            '<div class="tt-hint">' + (isMobileScheduleMode() && (el.classList.contains('day-block') || el.classList.contains('hist-seg')) ? 'Long press for options' : 'Click for options') + '</div>';
    }
    function showSchedulePopup(el, ev) {
        showTimelineTooltip(scheduleTooltipHtml(el), ev);
    }

    const dayBlockLongPressMs = 560;
    let dayBlockLongPressTimer = null;
    let suppressNextDayBlockClick = false;
    let dayBlockPressPoint = null;
    function clearDayBlockLongPress() {
        if (dayBlockLongPressTimer) {
            window.clearTimeout(dayBlockLongPressTimer);
            dayBlockLongPressTimer = null;
        }
    }
    function openScheduleContext(el, point) {
        ctxScheduleId = Number(el.dataset.scheduleId);
        ctxAgentId = Number(el.dataset.agentId);
        ctxFailedJobId = el.dataset.jobStatus === 'failed' ? Number(el.dataset.failedJobId || 0) : null;
        hideTooltip();
        openCtxMenu(point);
    }
    function suppressNextDayBlockTap() {
        suppressNextDayBlockClick = true;
        window.setTimeout(() => { suppressNextDayBlockClick = false; }, 1200);
    }
    const histSegLongPressMs = 560;
    let histSegLongPressTimer = null;
    let suppressNextHistSegClick = false;
    let histSegPressPoint = null;
    function clearHistSegLongPress() {
        if (histSegLongPressTimer) {
            window.clearTimeout(histSegLongPressTimer);
            histSegLongPressTimer = null;
        }
    }
    function suppressNextHistSegTap() {
        suppressNextHistSegClick = true;
        window.setTimeout(() => { suppressNextHistSegClick = false; }, 1200);
    }

    // Day-block hover tooltip
    document.querySelectorAll('.day-block').forEach(b => {
        b.addEventListener('mouseenter', ev => {
            if (isMobileScheduleMode()) return;
            showSchedulePopup(b, ev);
        });
        b.addEventListener('mousemove', moveTooltip);
        b.addEventListener('mouseleave', hideTooltip);
        b.addEventListener('pointerdown', ev => {
            if (!isMobileScheduleMode() || ev.pointerType === 'mouse') return;
            clearDayBlockLongPress();
            suppressNextDayBlockClick = false;
            dayBlockPressPoint = { clientX: ev.clientX, clientY: ev.clientY };
            dayBlockLongPressTimer = window.setTimeout(() => {
                dayBlockLongPressTimer = null;
                suppressNextDayBlockTap();
                openScheduleContext(b, dayBlockPressPoint);
            }, dayBlockLongPressMs);
        });
        b.addEventListener('pointermove', ev => {
            if (!dayBlockLongPressTimer || !dayBlockPressPoint) return;
            const dx = ev.clientX - dayBlockPressPoint.clientX;
            const dy = ev.clientY - dayBlockPressPoint.clientY;
            if (Math.hypot(dx, dy) > 10) clearDayBlockLongPress();
        });
        b.addEventListener('pointerup', clearDayBlockLongPress);
        b.addEventListener('pointercancel', clearDayBlockLongPress);
        b.addEventListener('pointerleave', clearDayBlockLongPress);
        b.addEventListener('contextmenu', ev => {
            if (!isMobileScheduleMode()) return;
            ev.preventDefault();
            suppressNextDayBlockTap();
            const point = (ev.clientX || ev.clientY)
                ? { clientX: ev.clientX, clientY: ev.clientY }
                : (dayBlockPressPoint || { clientX: window.innerWidth / 2, clientY: window.innerHeight / 2 });
            openScheduleContext(b, point);
        });
    });

    // Timeline blocks — each one = one schedule, width ~= estimated duration.
    // Hover shows a tooltip for that schedule; click opens the context menu
    // (same actions as the day-block click).
    document.querySelectorAll('.hist-seg').forEach(seg => {
        seg.addEventListener('mouseenter', ev => {
            if (isMobileScheduleMode()) return;
            showSchedulePopup(seg, ev);
        });
        seg.addEventListener('mousemove', moveTooltip);
        seg.addEventListener('mouseleave', hideTooltip);
        seg.addEventListener('pointerdown', ev => {
            if (!isMobileScheduleMode() || ev.pointerType === 'mouse') return;
            clearHistSegLongPress();
            suppressNextHistSegClick = false;
            histSegPressPoint = { clientX: ev.clientX, clientY: ev.clientY };
            histSegLongPressTimer = window.setTimeout(() => {
                histSegLongPressTimer = null;
                suppressNextHistSegTap();
                openScheduleContext(seg, histSegPressPoint);
            }, histSegLongPressMs);
        });
        seg.addEventListener('pointermove', ev => {
            if (!histSegLongPressTimer || !histSegPressPoint) return;
            const dx = ev.clientX - histSegPressPoint.clientX;
            const dy = ev.clientY - histSegPressPoint.clientY;
            if (Math.hypot(dx, dy) > 10) clearHistSegLongPress();
        });
        seg.addEventListener('pointerup', clearHistSegLongPress);
        seg.addEventListener('pointercancel', clearHistSegLongPress);
        seg.addEventListener('pointerleave', clearHistSegLongPress);
        seg.addEventListener('contextmenu', ev => {
            if (!isMobileScheduleMode()) return;
            ev.preventDefault();
            suppressNextHistSegTap();
            const point = (ev.clientX || ev.clientY)
                ? { clientX: ev.clientX, clientY: ev.clientY }
                : (histSegPressPoint || { clientX: window.innerWidth / 2, clientY: window.innerHeight / 2 });
            openScheduleContext(seg, point);
        });
        seg.addEventListener('click', ev => {
            ev.preventDefault();
            ev.stopPropagation();
            if (isMobileScheduleMode()) {
                if (suppressNextHistSegClick) {
                    suppressNextHistSegClick = false;
                    return;
                }
                closeCtxMenu();
                showSchedulePopup(seg, ev);
                return;
            }
            openScheduleContext(seg, ev);
        });
    });

    // ----------------- Context menu ----------------------------------------
    const ctx = document.getElementById('sched-ctxmenu');
    const ctxRetry = document.getElementById('ctx-retry');
    let ctxScheduleId = null;
    let ctxAgentId = null;
    let ctxFailedJobId = null;

    document.querySelectorAll('.day-block').forEach(b => {
        b.addEventListener('click', ev => {
            ev.preventDefault();
            if (isMobileScheduleMode()) {
                ev.stopPropagation();
                if (suppressNextDayBlockClick) {
                    suppressNextDayBlockClick = false;
                    return;
                }
                closeCtxMenu();
                showSchedulePopup(b, ev);
                return;
            }
            openScheduleContext(b, ev);
        });
    });

    function openCtxMenu(ev) {
        if (ctxRetry) {
            ctxRetry.style.display = ctxFailedJobId ? 'flex' : 'none';
        }
        ctx.style.display = 'block';
        let x = ev.clientX, y = ev.clientY;
        const rect = ctx.getBoundingClientRect();
        if (x + rect.width > window.innerWidth - 8) x = window.innerWidth - rect.width - 8;
        if (y + rect.height > window.innerHeight - 8) y = window.innerHeight - rect.height - 8;
        ctx.style.left = x + 'px';
        ctx.style.top = y + 'px';
    }
    function closeCtxMenu() { ctx.style.display = 'none'; }
    document.addEventListener('click', ev => {
        if (!ctx.contains(ev.target) && !ev.target.closest('.day-block') && !ev.target.closest('.hist-seg')) closeCtxMenu();
        if (isMobileScheduleMode() && !ev.target.closest('.day-block') && !ev.target.closest('.hist-seg')) hideTooltip();
    });
    document.addEventListener('keydown', ev => { if (ev.key === 'Escape') { closeCtxMenu(); hideTooltip(); } });

    ctxRetry.addEventListener('click', () => {
        if (!ctxFailedJobId) return;
        if (!confirm('Retry this failed job?')) return;
        const f = document.createElement('form');
        f.method = 'POST';
        f.action = '/queue/' + ctxFailedJobId + '/retry';
        const c = document.createElement('input');
        c.type = 'hidden'; c.name = 'csrf_token'; c.value = csrfToken;
        f.appendChild(c);
        document.body.appendChild(f);
        f.submit();
    });

    document.getElementById('ctx-edit-plan').addEventListener('click', () => {
        if (!ctxScheduleId) return;
        const sched = scheduleMap[ctxScheduleId];
        if (!sched) return;
        // Need the plan id for the deep-link — available via blocks since we
        // stashed it, but easier: find any day-block for this schedule and
        // read its data-plan-id.
        const blk = document.querySelector('.day-block[data-schedule-id="' + ctxScheduleId + '"]');
        const planId = blk ? blk.dataset.planId : null;
        const url = '/clients/' + ctxAgentId + '?tab=schedules' + (planId ? '&edit_plan=' + planId : '');
        window.location.href = url;
    });

    document.getElementById('ctx-change-time').addEventListener('click', () => {
        closeCtxMenu();
        openChangeTimeModal(ctxScheduleId);
    });

    document.getElementById('ctx-disable').addEventListener('click', () => {
        if (!ctxScheduleId) return;
        if (!confirm('Disable this schedule? It will stop running until re-enabled.')) return;
        const f = document.createElement('form');
        f.method = 'POST';
        f.action = '/schedules/' + ctxScheduleId + '/toggle';
        const c = document.createElement('input');
        c.type = 'hidden'; c.name = 'csrf_token'; c.value = csrfToken;
        f.appendChild(c);
        document.body.appendChild(f);
        f.submit();
    });

    // ----------------- Change Time modal -----------------------------------
    let activeScheduleId = null;
    const modalEl = document.getElementById('change-time-modal');
    const ctTimesList = document.getElementById('ct-times-list');
    const ctDowSection = document.getElementById('ct-dow-section');
    const ctDow = document.getElementById('ct-dow');
    ctDow.querySelectorAll('.ct-dow-btn').forEach(b => b.addEventListener('click', () => {
        b.classList.toggle('active');
        b.classList.toggle('btn-primary');
        b.classList.toggle('btn-outline-primary');
        b.blur();
    }));
    const ctContext = document.getElementById('ct-context');
    const ctError = document.getElementById('ct-error');

    // Lazily init Bootstrap's Modal controller so we fail gracefully if
    // Bootstrap JS didn't load, and also to avoid TDZ ordering problems.
    let _modal = null;
    function getModal() {
        if (_modal) return _modal;
        if (typeof bootstrap === 'undefined' || !bootstrap.Modal) {
            console.error('Bootstrap JS not loaded — falling back to manual modal show/hide');
            return {
                show: () => { modalEl.classList.add('show'); modalEl.style.display = 'block'; document.body.classList.add('modal-open'); },
                hide: () => { modalEl.classList.remove('show'); modalEl.style.display = 'none'; document.body.classList.remove('modal-open'); },
            };
        }
        _modal = new bootstrap.Modal(modalEl);
        return _modal;
    }

    function addTimeRow(value) {
        const row = document.createElement('div');
        row.className = 'input-group input-group-sm mb-1';
        row.innerHTML =
            '<span class="input-group-text"><i class="bi bi-clock"></i></span>' +
            '<input type="time" class="form-control ct-time-input" value="' + (value || '') + '">' +
            '<button type="button" class="btn btn-outline-danger remove-time" title="Remove">' +
            '<i class="bi bi-trash"></i></button>';
        row.querySelector('.remove-time').addEventListener('click', () => {
            if (ctTimesList.querySelectorAll('.ct-time-input').length > 1) {
                row.remove();
            }
        });
        ctTimesList.appendChild(row);
    }

    function openChangeTimeModal(scheduleId) {
        const s = scheduleMap[scheduleId];
        if (!s) return;
        activeScheduleId = scheduleId;
        ctError.style.display = 'none';
        ctTimesList.innerHTML = '';
        ctContext.innerHTML =
            '<i class="bi bi-hdd-network me-1"></i> <strong>' + esc(s.agent_name) + '</strong>' +
            ' · <i class="bi bi-journal me-1"></i>' + esc(s.plan_name) +
            ' · <span class="badge bg-secondary">' + esc(s.frequency) + '</span>';

        // Weekly schedules get the day picker, else hide it
        if (s.frequency === 'weekly') {
            ctDowSection.style.display = '';
            const on = (s.days_of_week && s.days_of_week.length) ? s.days_of_week : [s.day_of_week ?? 1];
            ctDow.querySelectorAll('.ct-dow-btn').forEach(b => {
                const active = on.includes(Number(b.dataset.day));
                b.classList.toggle('active', active);
                b.classList.toggle('btn-primary', active);
                b.classList.toggle('btn-outline-primary', !active);
            });
        } else {
            ctDowSection.style.display = 'none';
        }

        // Populate current times (comma-separated)
        const times = (s.times || '').split(',').map(t => t.trim()).filter(Boolean);
        if (times.length === 0) addTimeRow('');
        else times.forEach(t => addTimeRow(t));

        getModal().show();
    }

    document.getElementById('ct-add-time').addEventListener('click', () => addTimeRow(''));

    document.getElementById('ct-save').addEventListener('click', async () => {
        if (!activeScheduleId) return;
        const inputs = ctTimesList.querySelectorAll('.ct-time-input');
        const times = Array.from(inputs).map(i => i.value.trim()).filter(Boolean);
        if (times.length === 0) {
            ctError.textContent = 'At least one time is required.';
            ctError.style.display = '';
            return;
        }
        const body = { times: times };
        const s = scheduleMap[activeScheduleId];
        if (s && s.frequency === 'weekly') {
            body.day_of_week = [...ctDow.querySelectorAll('.ct-dow-btn.active')].map(b => Number(b.dataset.day));
            if (body.day_of_week.length === 0) {
                ctError.textContent = 'Pick at least one day.';
                ctError.style.display = '';
                return;
            }
        }

        try {
            const resp = await fetch('/schedules/' + activeScheduleId + '/time', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrfToken,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify(Object.assign(body, { csrf_token: csrfToken }))
            });
            const data = await resp.json();
            if (!resp.ok || data.error) {
                ctError.textContent = data.error || ('HTTP ' + resp.status);
                ctError.style.display = '';
                return;
            }
            getModal().hide();
            // Reload the page so blocks reposition. A later iteration can
            // mutate the DOM in place for a slicker feel.
            window.location.reload();
        } catch (e) {
            ctError.textContent = 'Network error: ' + e.message;
            ctError.style.display = '';
        }
    });
});
</script>
