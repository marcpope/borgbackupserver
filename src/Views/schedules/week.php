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
    $hue = ($id * 137) % 360;
    return "hsl({$hue}, 55%, 45%)";
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
.hist-container {
    position: relative;
    height: 170px;
    display: grid;
    column-gap: 1px;
    align-items: end;
    padding-bottom: 4px;
}
.hist-gridlines {
    position: absolute;
    top: 0;
    bottom: 18px; /* match bar-wrap padding-bottom so we don't draw over the x-labels */
    left: 56px;   /* start after the yaxis column */
    right: 0;
    pointer-events: none;
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
    border-left: 2px solid var(--bs-danger);
    z-index: 3;
    pointer-events: none;
}
.hist-current-time-line::before {
    content: "";
    position: absolute;
    left: -5px;
    top: -5px;
    width: 10px;
    height: 10px;
    border-radius: 50%;
    background: var(--bs-danger);
}
.hist-current-time-line .current-time-label {
    position: absolute;
    top: 2px;
    left: 8px;
    padding: 1px 6px;
    border-radius: 4px;
    background: var(--bs-danger);
    color: #fff;
    font-size: 0.68rem;
    line-height: 1.25;
    font-weight: 600;
    font-variant-numeric: tabular-nums;
    white-space: nowrap;
    box-shadow: 0 1px 4px rgba(0, 0, 0, 0.25);
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
    z-index: 1;
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
    transition: filter 0.1s, transform 0.1s;
}
.hist-seg:hover {
    filter: brightness(1.25);
    transform: scaleX(1.4);
    z-index: 5;
}
.hist-xaxis {
    position: absolute;
    left: 56px;
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
    padding: 4px 14px;
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
    border-color: var(--bs-primary);
    color: var(--bs-primary);
}
.day-pill.active {
    background: var(--bs-primary);
    color: #fff;
    border-color: var(--bs-primary);
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
    background: var(--bs-tertiary-bg);
    border-radius: 4px;
    overflow: hidden;
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
    height: 0;
    border-top: 2px solid var(--bs-danger);
    z-index: 20;
    pointer-events: none;
}
.current-time-line::before {
    content: "";
    position: absolute;
    left: -5px;
    top: -5px;
    width: 10px;
    height: 10px;
    border-radius: 50%;
    background: var(--bs-danger);
}
.current-time-line .current-time-label {
    position: absolute;
    right: 8px;
    top: -11px;
    padding: 1px 6px;
    border-radius: 4px;
    background: var(--bs-danger);
    color: #fff;
    font-size: 0.68rem;
    line-height: 1.25;
    font-weight: 600;
    font-variant-numeric: tabular-nums;
    box-shadow: 0 1px 4px rgba(0, 0, 0, 0.25);
}
.day-block {
    position: absolute;
    padding: 1px 8px;
    border-radius: 5px;
    color: #fff;
    overflow: hidden;
    cursor: pointer;
    border-left: 4px solid rgba(0, 0, 0, 0.35);
    transition: opacity 0.15s, transform 0.15s;
    text-decoration: none;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.2);
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 6px;
}
.day-block:hover {
    /* No transform: scale — it enlarged the text inside the block's fixed
       overflow:hidden box, which clipped descenders (g, p, y) that fit at
       rest (#171). Stronger box-shadow alone gives the lift cue. */
    z-index: 10;
    color: #fff;
    box-shadow: 0 3px 10px rgba(0, 0, 0, 0.4);
}
.day-block.estimated {
    background-image: repeating-linear-gradient(
        45deg,
        transparent,
        transparent 8px,
        rgba(255, 255, 255, 0.12) 8px,
        rgba(255, 255, 255, 0.12) 16px
    );
}
.day-block .agent {
    font-weight: 600;
    font-size: 0.74rem;
    /* 1.2 (not 1.0) so descenders on letters like p/g/y aren't clipped
       by the day-block's overflow:hidden */
    line-height: 1.2;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    flex: 1 1 auto;
    min-width: 0;
}
.day-block .side {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    justify-content: center;
    text-align: right;
    line-height: 1.2;
    flex: 0 0 auto;
    max-width: 60%;
    min-width: 0;
}
.day-block .side > div {
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    max-width: 100%;
}
.day-block .side .plan { font-weight: 500; font-size: 0.6rem; opacity: 0.95; }
.day-block .side .when { font-size: 0.58rem; opacity: 0.8; font-variant-numeric: tabular-nums; }
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

/* Accent header for the primary schedule cards — subdued navy gradient */
.sched-accent-header {
    background: linear-gradient(135deg, #1e293b 0%, #243a6b 50%, #2b4d8c 100%) !important;
    color: #fff !important;
    border-bottom: 1px solid rgba(0, 0, 0, 0.25);
}
.sched-accent-header .text-muted { color: rgba(255, 255, 255, 0.7) !important; }
.sched-accent-header i { color: #9ec5fe; }

/* Mobile: thin the x-axis labels so they don't crash together, and drop
   the per-block plan/time column so the client name can breathe. */
@media (max-width: 767.98px) {
    .hist-xaxis .xl[data-hour]:not([data-hour="0"]):not([data-hour="4"]):not([data-hour="8"]):not([data-hour="12"]):not([data-hour="16"]):not([data-hour="20"]) {
        display: none;
    }
    .day-block .side { display: none; }
    .day-block .agent { flex: 1 1 100%; text-align: left; }
}
</style>

<div class="container-fluid py-3">
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

    <!-- Histogram: hour-of-day load for the selected day. 30-minute buckets
         so 6:00 and 6:30 are distinguishable. Each 1-unit segment = one
         schedule, hoverable and clickable. -->
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-header sched-accent-header fw-semibold d-flex justify-content-between align-items-center">
            <span><i class="bi bi-bar-chart me-2"></i>Load By Hour</span>
            <span class="text-muted small">Peak: <?= $histMax ?> <?= $histMax === 1 ? 'schedule' : 'schedules' ?></span>
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
                $yTicks = bbs_histogram_ticks($histMax);
                // Hour labels every 2 hours on the x-axis. Every 6 hours gets
                // bold weight as a "major" reference.
                $xLabelStep = 2;
            ?>
            <?php for ($dIdx = 0; $dIdx < 7; $dIdx++): ?>
            <div class="hist-container"
                 data-day-idx="<?= $dIdx ?>"
                 style="<?= $dIdx === $todayIdx ? '' : 'display: none;' ?> grid-template-columns: 56px repeat(<?= $histBucketCount ?>, 1fr);">

                <!-- Background grid: horizontal lines at y-tick positions, vertical lines at each hour -->
                <div class="hist-gridlines">
                    <?php foreach ($yTicks as $tick): ?>
                        <?php if ($tick === 0) continue; // bottom is already the axis baseline ?>
                        <?php $topPct = $histMax > 0 ? (1 - $tick / $histMax) * 100 : 100; ?>
                        <div class="hline" style="top: <?= $topPct ?>%;"></div>
                    <?php endforeach; ?>
                    <?php for ($h = 1; $h < 24; $h++): ?>
                        <?php $leftPct = ($h / 24) * 100; ?>
                        <div class="vline" style="left: <?= $leftPct ?>%;"></div>
                    <?php endfor; ?>
                </div>
                <?php if ($dIdx === $todayIdx): ?>
                    <?php $nowLeftPct = ($currentMinuteOfDay / 1440) * 100; ?>
                    <div class="hist-current-time-line <?= $nowLeftPct > 80 ? 'near-end' : '' ?>"
                         style="left: calc(56px + ((100% - 56px) * <?= $nowLeftPct / 100 ?>));"
                         title="Current time in <?= htmlspecialchars($userTz) ?>">
                        <span class="current-time-label">Now time: <?= htmlspecialchars($currentTimeLabel) ?></span>
                    </div>
                <?php endif; ?>

                <div class="hist-yaxis">
                    <?php foreach ($yTicks as $tick): ?>
                        <?php
                        $topPct = $histMax > 0 ? (1 - $tick / $histMax) * 100 : 100;
                        // Slight nudge at extremes to keep labels inside the chart box
                        $extraStyle = $tick === 0 ? 'transform: translateY(-100%);' : ($tick === $histMax ? 'transform: translateY(0);' : '');
                        ?>
                        <span style="top: <?= $topPct ?>%; <?= $extraStyle ?>"><?= $tick ?></span>
                    <?php endforeach; ?>
                </div>

                <?php for ($b = 0; $b < $histBucketCount; $b++): ?>
                <?php
                    $bar = $histograms[$dIdx][$b];
                    $total = $bar['total'];
                    $barHeightPct = $histMax > 0 ? ($total / $histMax) * 100 : 0;
                    $hour = $b; // 60-minute buckets — bucket index equals hour
                ?>
                <div class="hist-bar-wrap" data-bucket="<?= $b ?>" data-minute="<?= $hour * 60 ?>">
                    <div class="hist-bar" style="height: <?= $barHeightPct ?>%;">
                        <?php foreach ($bar['schedules'] as $sch): ?>
                        <div class="hist-seg"
                             data-schedule-id="<?= $sch['schedule_id'] ?>"
                             data-agent-id="<?= (int) $sch['agent_id'] ?>"
                             data-plan-name="<?= htmlspecialchars($sch['plan_name']) ?>"
                             data-agent-name="<?= htmlspecialchars($sch['agent_name']) ?>"
                             data-time="<?= htmlspecialchars($sch['time']) ?>"
                             data-frequency="<?= htmlspecialchars($sch['frequency']) ?>"
                             style="background: <?= bbs_agent_color((int) $sch['agent_id']) ?>;"></div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endfor; ?>

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
        <div class="card-header sched-accent-header fw-semibold">
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
                        <div class="current-time-line"
                             style="top: <?= $currentMinuteOfDay * ($pxPerHour / 60) ?>px;"
                             title="Current time in <?= htmlspecialchars($userTz) ?>">
                            <span class="current-time-label">Now time: <?= htmlspecialchars($currentTimeLabel) ?></span>
                        </div>
                        <?php endif; ?>
                        <?php foreach ($blocksByDay[$dIdx] as $b): ?>
                            <?php
                            $top = $b['start_min'] * ($pxPerHour / 60);
                            $height = max($minBlockPx, $b['duration_min'] * ($pxPerHour / 60));
                            $laneWidth = 100 / $b['lane_count'];
                            $left = $b['lane'] * $laneWidth;
                            $color = bbs_agent_color($b['agent_id']);
                            $durLabel = $b['duration_min'] >= 60
                                ? floor($b['duration_min'] / 60) . 'h ' . ($b['duration_min'] % 60) . 'm'
                                : $b['duration_min'] . 'm';
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
                        <div class="day-block <?= $b['estimated'] ? 'estimated' : '' ?>"
                             data-agent-id="<?= $b['agent_id'] ?>"
                             data-schedule-id="<?= $b['schedule_id'] ?>"
                             data-plan-id="<?= $b['plan_id'] ?>"
                             data-plan-name="<?= htmlspecialchars($b['plan_name']) ?>"
                             data-agent-name="<?= htmlspecialchars($b['agent_name']) ?>"
                             data-frequency="<?= htmlspecialchars($b['frequency']) ?>"
                             data-time="<?= htmlspecialchars($b['time_label']) ?>"
                             data-duration="<?= htmlspecialchars($durLabel) ?>"
                             data-estimated="<?= $b['estimated'] ? '1' : '0' ?>"
                             style="top: <?= $top ?>px; height: <?= $height ?>px; left: calc(<?= $left ?>% + 4px); width: calc(<?= $laneWidth ?>% - 8px); background: <?= $color ?>;">
                            <div class="agent"><?= htmlspecialchars($b['agent_name']) ?></div>
                            <div class="side">
                                <div class="plan"><?= htmlspecialchars($b['plan_name']) ?></div>
                                <div class="when"><?= htmlspecialchars($b['time_label']) ?> · <?= htmlspecialchars($durLabel) ?><?= $b['estimated'] ? ' est' : '' ?></div>
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
        <div class="card-header bg-body fw-semibold">
            <i class="bi bi-arrow-repeat me-1"></i>Continuous schedules
        </div>
        <div class="card-body">
            <div class="row g-2 small">
                <?php foreach ($continuous as $c): ?>
                <?php $s = $c['schedule']; ?>
                <div class="col-md-6 col-lg-4" data-agent-id="<?= (int) $s['agent_id'] ?>">
                    <a href="/clients/<?= (int) $s['agent_id'] ?>?tab=schedules" class="d-block p-2 rounded text-decoration-none border"
                       style="border-left: 3px solid <?= bbs_agent_color((int) $s['agent_id']) ?> !important;">
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
        <div class="card-header bg-body fw-semibold">
            <i class="bi bi-calendar-month me-1"></i>Monthly schedules
        </div>
        <div class="card-body">
            <div class="row g-2 small">
                <?php foreach ($otherSchedules as $s): ?>
                <div class="col-md-6 col-lg-4" data-agent-id="<?= (int) $s['agent_id'] ?>">
                    <a href="/clients/<?= (int) $s['agent_id'] ?>?tab=schedules" class="d-block p-2 rounded text-decoration-none border"
                       style="border-left: 3px solid <?= bbs_agent_color((int) $s['agent_id']) ?> !important;">
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
                        <i class="bi bi-calendar-event me-1"></i>Day of week
                    </label>
                    <select id="ct-dow" class="form-select form-select-sm">
                        <option value="1">Monday</option>
                        <option value="2">Tuesday</option>
                        <option value="3">Wednesday</option>
                        <option value="4">Thursday</option>
                        <option value="5">Friday</option>
                        <option value="6">Saturday</option>
                        <option value="0">Sunday</option>
                    </select>
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

    // Day-block hover tooltip
    document.querySelectorAll('.day-block').forEach(b => {
        b.addEventListener('mouseenter', ev => {
            const html = '<div class="tt-title">' + esc(b.dataset.planName) + '</div>' +
                '<div class="tt-meta">' + esc(b.dataset.agentName) + ' · ' + esc(b.dataset.frequency) + '</div>' +
                'Starts: <strong>' + esc(b.dataset.time) + '</strong><br>' +
                'Est. duration: <strong>' + esc(b.dataset.duration) + '</strong>' +
                (b.dataset.estimated === '1' ? ' <span style="opacity:.6">(no history — default)</span>' : '') +
                '<div style="margin-top:6px;opacity:.6;font-size:.7rem;">Click for options</div>';
            showTooltip(html, ev);
        });
        b.addEventListener('mousemove', moveTooltip);
        b.addEventListener('mouseleave', hideTooltip);
    });

    // Histogram segments — each one = one schedule firing in that hour.
    // Hover shows a tooltip for that schedule; click opens the context menu
    // (same actions as the day-block click).
    document.querySelectorAll('.hist-seg').forEach(seg => {
        seg.addEventListener('mouseenter', ev => {
            const html = '<div class="tt-title">' + esc(seg.dataset.planName) + '</div>' +
                '<div class="tt-meta">' + esc(seg.dataset.agentName) + ' · ' + esc(seg.dataset.frequency) + '</div>' +
                'Starts: <strong>' + esc(seg.dataset.time) + '</strong>' +
                '<div style="margin-top:6px;opacity:.6;font-size:.7rem;">Click for options</div>';
            showTooltip(html, ev);
        });
        seg.addEventListener('mousemove', moveTooltip);
        seg.addEventListener('mouseleave', hideTooltip);
        seg.addEventListener('click', ev => {
            ev.preventDefault();
            ev.stopPropagation();
            ctxScheduleId = Number(seg.dataset.scheduleId);
            ctxAgentId = Number(seg.dataset.agentId);
            hideTooltip();
            openCtxMenu(ev);
        });
    });

    // ----------------- Context menu ----------------------------------------
    const ctx = document.getElementById('sched-ctxmenu');
    let ctxScheduleId = null;
    let ctxAgentId = null;

    document.querySelectorAll('.day-block').forEach(b => {
        b.addEventListener('click', ev => {
            ev.preventDefault();
            ctxScheduleId = Number(b.dataset.scheduleId);
            ctxAgentId = Number(b.dataset.agentId);
            hideTooltip();
            openCtxMenu(ev);
        });
    });

    function openCtxMenu(ev) {
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
    });
    document.addEventListener('keydown', ev => { if (ev.key === 'Escape') { closeCtxMenu(); hideTooltip(); } });

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
            ctDow.value = String(s.day_of_week ?? 1);
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
        if (s && s.frequency === 'weekly') body.day_of_week = Number(ctDow.value);

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
