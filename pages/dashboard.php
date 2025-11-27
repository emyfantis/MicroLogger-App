<?php
// Dashboard page for MicrobiologyApp.
// Shows key statistics, recent activity and incubation calendar.

// Initialize dashboard error variable. If something goes wrong in the DB queries,
// this will hold a human-readable message to show on the UI.
$dashError = null;

// Base structure for all dashboard stats with default values.
// These will be updated with real numbers from the database.
$stats = [
    'total_rows'           => 0, // Total number of microbiology log rows stored.
    'today_rows'           => 0, // Number of rows whose table_date is today.
    'last7_rows'           => 0, // Number of rows in the last 7 days (including today).
    'distinct_products'    => 0, // Count of distinct products ever logged.
    'out_last30_total'     => 0, // Total out-of-spec results (last 30 days).
    'out_last30_entero'    => 0, // Out-of-spec for Enterobacteriaceae in last 30 days.
    'out_last30_yeasts'    => 0, // Out-of-spec for Yeasts / Moulds in last 30 days.
    'out_last30_bacillus'  => 0, // Out-of-spec for Bacillus in last 30 days.
];

// Incubation profiles → hours until reading
$INCUBATION_PROFILES = [
    'enterobacteriacea' => ['label' => 'Enterobacteriacea',              'hours' => 24],
    'tmc_30'            => ['label' => 'Total mesophilic count 30°C',    'hours' => 3 * 24],
    'yeasts_molds'      => ['label' => 'Yeasts / molds',                 'hours' => 5 * 24],
    'bacillus'          => ['label' => 'Bacillus',                       'hours' => 26],
];

// Holds the "recent tables" rows for the dashboard list (grouped by table_name & date).
$recentTables = [];

// Incubation calendar events per day (Y-m-d => [events...])
$incubationCalendar = [];

try {
    // Get PDO connection from the global DB helper.
    $pdo = db();

    // Total number of rows in microbiology_logs.
    $stmt = $pdo->query("SELECT COUNT(*) AS c FROM microbiology_logs");
    $stats['total_rows'] = (int)$stmt->fetchColumn();

    // Number of entries for today (based on table_date = CURDATE()).
    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS c 
        FROM microbiology_logs 
        WHERE table_date = CURDATE()
    ");
    $stmt->execute();
    $stats['today_rows'] = (int)$stmt->fetchColumn();

    // Number of entries for the last 7 days (including today).
    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS c
        FROM microbiology_logs
        WHERE table_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
    ");
    $stmt->execute();
    $stats['last7_rows'] = (int)$stmt->fetchColumn();

    // Count distinct products that have ever appeared in the logs.
    $stmt = $pdo->query("
        SELECT COUNT(DISTINCT product) AS c
        FROM microbiology_logs
        WHERE product IS NOT NULL AND product <> ''
    ");
    $stats['distinct_products'] = (int)$stmt->fetchColumn();

    // Out-of-spec findings for the last 30 days.
    // Thresholds:
    //   Enterobacteriaceae >= 1
    //   Yeasts / Moulds    > 40
    //   Bacillus           >= 1
    $stmt = $pdo->query("
        SELECT 
          SUM(CASE WHEN enterobacteriacea IS NOT NULL AND enterobacteriacea >= 1 THEN 1 ELSE 0 END) AS out_entero,
          SUM(CASE WHEN yeasts_molds      IS NOT NULL AND yeasts_molds      >  40 THEN 1 ELSE 0 END) AS out_yeasts,
          SUM(CASE WHEN bacillus          IS NOT NULL AND bacillus          >= 1 THEN 1 ELSE 0 END) AS out_bacillus
        FROM microbiology_logs
        WHERE table_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    ");
    // If no rows are returned, fall back to zeros to avoid undefined index notices.
    $rowOut = $stmt->fetch(PDO::FETCH_ASSOC) ?: [
        'out_entero'   => 0,
        'out_yeasts'   => 0,
        'out_bacillus' => 0,
    ];

    // Store out-of-spec counts per parameter and total.
    $stats['out_last30_entero']   = (int)$rowOut['out_entero'];
    $stats['out_last30_yeasts']   = (int)$rowOut['out_yeasts'];
    $stats['out_last30_bacillus'] = (int)$rowOut['out_bacillus'];
    $stats['out_last30_total']    =
        $stats['out_last30_entero'] +
        $stats['out_last30_yeasts'] +
        $stats['out_last30_bacillus'];

    // Last 5 tables: group by table_name + table_date + table_description.
    // Each group represents one logical log sheet, with the number of rows it contains.
    $stmt = $pdo->query("
        SELECT 
            table_name,
            table_date,
            table_description,
            COUNT(*) AS rows_count
        FROM microbiology_logs
        GROUP BY table_name, table_date, table_description
        ORDER BY table_date DESC, table_name ASC
        LIMIT 5
    ");
    $recentTables = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ===== Incubation calendar events (next 7 days) =====
    // We fetch 1 row per (table_name, table_date, table_description, incubation_profile string)
    $stmt = $pdo->query("
        SELECT
            table_name,
            table_date,
            table_description,
            incubation_profile,
            MIN(created_at) AS created_at
        FROM microbiology_logs
        WHERE incubation_profile IS NOT NULL
          AND incubation_profile <> ''
        GROUP BY table_name, table_date, table_description, incubation_profile
    ");

    $sheets = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // From today 00:00 until today + 6 days, 23:59:59
    $today    = new DateTimeImmutable('today');
    $endLimit = $today->modify('+6 days')->setTime(23, 59, 59);

    foreach ($sheets as $s) {
        if (empty($s['incubation_profile'])) {
            continue;
        }
        if (empty($s['created_at'])) {
            continue;
        }

        try {
            $createdAt = new DateTimeImmutable($s['created_at']);
        } catch (Exception $e) {
            continue;
        }

        // Split the string "enterobacteriacea,yeasts_molds" into tokens
        $tokens = array_filter(array_map('trim', explode(',', $s['incubation_profile'])));

        foreach ($tokens as $profileKey) {
            if (!isset($INCUBATION_PROFILES[$profileKey])) {
                continue;
            }

            $hours = (int)$INCUBATION_PROFILES[$profileKey]['hours'];
            $dueAt = $createdAt->modify('+' . $hours . ' hours');

            // Only keep events from today until +6 days
            if ($dueAt < $today || $dueAt > $endLimit) {
                continue;
            }

            $dayKey = $dueAt->format('Y-m-d');

            if (!isset($incubationCalendar[$dayKey])) {
                $incubationCalendar[$dayKey] = [];
            }

            $incubationCalendar[$dayKey][] = [
                'table_name'    => $s['table_name'],
                'table_date'    => $s['table_date'],
                'description'   => $s['table_description'],
                'profile_key'   => $profileKey,
                'profile_label' => $INCUBATION_PROFILES[$profileKey]['label'],
                'due_at'        => $dueAt,
            ];
        }
    }

    // Sort events per day by time
    foreach ($incubationCalendar as &$events) {
        usort($events, function ($a, $b) {
            return $a['due_at'] <=> $b['due_at'];
        });
    }
    unset($events);

} catch (Throwable $e) {
    // On any DB or runtime error, log it to the error log and show a generic message.
    error_log('[dashboard] DB error: ' . $e->getMessage());
    $dashError = 'Temporary database error. Some dashboard data may be unavailable.';
}

// Build the list of days for the calendar (today + 6 days)
$todayForCalendar = new DateTimeImmutable('today');
$calendarDays = [];
for ($i = 0; $i < 7; $i++) {
    $d = $todayForCalendar->modify("+$i days");
    $calendarDays[] = [
        'key'   => $d->format('Y-m-d'),
        'label' => $d->format('D d-m-Y'), // e.g. Mon 01-12-2025
    ];
}

// Maximum number of checks per day (to build grid rows)
$maxEventsPerDay = 0;
foreach ($calendarDays as $day) {
    $dayKey  = $day['key'];
    $events  = $incubationCalendar[$dayKey] ?? [];
    $count   = count($events);
    if ($count > $maxEventsPerDay) {
        $maxEventsPerDay = $count;
    }
}
?>

<div class="content-section">

  <!-- ====== Section heading with icon ====== -->
  <div class="section-heading">
    <div class="section-heading__main">
      <div class="section-heading__icon section-heading__icon--dashboard section-heading__icon--animated">
        <svg
          width="18"
          height="18"
          viewBox="0 0 24 24"
          xmlns="http://www.w3.org/2000/svg"
          aria-hidden="true"
        >
          <!-- Simple “analytics” icon for the dashboard -->
          <rect x="3" y="11" width="3" height="9" rx="1.2" fill="white" />
          <rect x="10.5" y="8" width="3" height="12" rx="1.2" fill="white" opacity="0.9" />
          <rect x="18" y="5" width="3" height="15" rx="1.2" fill="white" opacity="0.8" />
          <path
            d="M4 7L9 10L14 7L19 9"
            fill="none"
            stroke="white"
            stroke-width="1.3"
            stroke-linecap="round"
            stroke-linejoin="round"
            opacity="0.9"
          />
        </svg>
      </div>
      <div>
        <h2>Dashboard</h2>
        <p class="content-section__subtitle">
          Overview of your microbiology entries, recent activity and quick actions.
        </p>
      </div>
    </div>

    <div class="section-heading__meta">
      <span class="badge badge--ok">Live</span>
      <div style="margin-top:4px; font-size:0.75rem;">
        Out-of-spec (last 30 days): <?= number_format($stats['out_last30_total']) ?>
      </div>
    </div>
  </div>

  <!-- ====== Error alert (if something goes wrong with the DB) ====== -->
  <?php if ($dashError): ?>
    <div class="alert" style="margin-bottom:12px; background:#fff7ed; border-color:#fed7aa;">
      <p style="font-size:0.85rem; color:#9a3412; margin:0;">
        <?= htmlspecialchars($dashError) ?>
      </p>
    </div>
  <?php endif; ?>

  <!-- ====== KPIs / Summary tiles ====== -->
  <div class="kpi-grid" style="margin-bottom:14px;">
    <div class="kpi-card">
      <div class="kpi-card__label">Total data entries</div>
      <div class="kpi-card__value">
        <?= number_format($stats['total_rows']) ?>
      </div>
      <div class="kpi-card__description">
        All measurements stored in the system.
      </div>
    </div>

    <div class="kpi-card">
      <div class="kpi-card__label">Today</div>
      <div class="kpi-card__value">
        <?= number_format($stats['today_rows']) ?>
      </div>
      <div class="kpi-card__description">
        Entries logged with today’s date.
      </div>
    </div>

    <div class="kpi-card">
      <div class="kpi-card__label">Last 7 days</div>
      <div class="kpi-card__value">
        <?= number_format($stats['last7_rows']) ?>
      </div>
      <div class="kpi-card__description">
        Entries created over the last week.
      </div>
    </div>

    <div class="kpi-card">
      <div class="kpi-card__label">Products monitored</div>
      <div class="kpi-card__value">
        <?= number_format($stats['distinct_products']) ?>
      </div>
      <div class="kpi-card__description">
        Unique products with microbiology logs.
      </div>
    </div>
  </div>

  <!-- Out-of-spec block summary -->
  <div class="kpi-grid" style="margin-bottom:18px;">
    <div class="kpi-card kpi-card--alert">
      <div class="kpi-card__label">Out-of-spec results (last 30 days)</div>
      <div class="kpi-card__value">
        <?= number_format($stats['out_last30_total']) ?>
      </div>
      <div class="kpi-card__description">
        Enterobacteriaceae: <?= (int)$stats['out_last30_entero'] ?> ·
        Yeasts &amp; moulds: <?= (int)$stats['out_last30_yeasts'] ?> ·
        Bacillus cereus: <?= (int)$stats['out_last30_bacillus'] ?>
      </div>
    </div>
  </div>

  <!-- ====== Quick actions ====== -->
  <div class="card card--static" style="margin-bottom:16px;">
    <div class="section-heading" style="margin-bottom:6px;">
      <div class="section-heading__main">
        <div class="section-heading__icon">
          <svg
            width="18"
            height="18"
            viewBox="0 0 24 24"
            xmlns="http://www.w3.org/2000/svg"
            aria-hidden="true"
          >
            <!-- Small “lightning + flask” icon for quick actions -->
            <path
              d="M11 3L7 11h4l-1 7 5-9h-4l2-6z"
              fill="white"
              opacity="0.95"
            />
            <path
              d="M16.5 9.5L18 8l2 2-1.5 1.5M16 15a3 3 0 106 0 3 3 0 00-6 0z"
              fill="none"
              stroke="white"
              stroke-width="1.2"
              stroke-linecap="round"
              stroke-linejoin="round"
              opacity="0.9"
            />
          </svg>
        </div>
        <div>
          <h3 style="font-size:0.92rem; margin:0 0 2px;">Quick actions</h3>
          <p class="content-section__subtitle" style="margin:0;">
            Jump directly to the most common tasks.
          </p>
        </div>
      </div>
    </div>

    <!-- Quick links to common pages: create, documents, export, statistics -->
    <div style="display:flex; flex-wrap:wrap; gap:8px; margin-top:4px;">
      <a
        href="app.php?page=create"
        class="button button--primary"
        style="text-decoration:none; padding:7px 14px; font-size:0.84rem; display:inline-flex; align-items:center; gap:6px;"
      >
        <span>➕</span>
        <span>New microbiology log</span>
      </a>

      <a
        href="app.php?page=documents"
        class="button button--ghost"
        style="text-decoration:none; padding:7px 14px; font-size:0.84rem; display:inline-flex; align-items:center; gap:6px;"
      >
        <span>📋</span>
        <span>View / Edit Logs</span>
      </a>

      <a
        href="app.php?page=data_show"
        class="button button--ghost"
        style="text-decoration:none; padding:7px 14px; font-size:0.84rem; display:inline-flex; align-items:center; gap:6px;"
      >
        <span>📚</span>
        <span>Documents / Export</span>
      </a>

      <a
        href="app.php?page=statistics"
        class="button button--ghost"
        style="text-decoration:none; padding:7px 14px; font-size:0.84rem; display:inline-flex; align-items:center; gap:6px;"
      >
        <span>🧬</span>
        <span>Statistics</span>
      </a>
    </div>
  </div>

  <!-- ====== Recent tables ====== -->
  <div class="table-card table-card--static">
    <div class="section-heading" style="margin-bottom:8px;">
      <div class="section-heading__main">
        <div class="section-heading__icon section-heading__icon--docs">
          <svg
            width="18"
            height="18"
            viewBox="0 0 24 24"
            xmlns="http://www.w3.org/2000/svg"
            aria-hidden="true"
          >
            <!-- Simple “document” icon for recent tables list -->
            <path
              d="M7 3h7l4 4v11a2 2 0 01-2 2H7a2 2 0 01-2-2V5a2 2 0 012-2z"
              fill="white"
              opacity="0.9"
            />
            <path
              d="M14 3v4h4"
              fill="none"
              stroke="#1d4ed8"
              stroke-width="1.2"
              stroke-linecap="round"
              stroke-linejoin="round"
              opacity="0.95"
            />
            <path
              d="M9 11.5h6M9 14h4"
              fill="none"
              stroke="#1d4ed8"
              stroke-width="1.1"
              stroke-linecap="round"
              opacity="0.9"
            />
          </svg>
        </div>
        <div>
          <div class="table-card__title">
            <span>Recent tables</span>
            <span class="dot"></span>
          </div>
          <div class="doc-results-meta" style="font-size:0.78rem; color:#6b7280;">
            Last 5 microbiology tables grouped by table name &amp; date.
          </div>
        </div>
      </div>
      <div class="section-heading__meta">
        <span style="font-size:0.75rem; color:#6b7280;">
          Latest activity snapshot
        </span>
      </div>
    </div>

    <div class="table-wrapper">
      <table class="data-table">
        <thead>
          <tr>
            <th>Table name</th>
            <th>Date</th>
            <th>Description</th>
            <th style="text-align:center;">Rows</th>
            <th style="text-align:center;">Open</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($recentTables)): ?>
            <!-- Empty state when there are no logs yet -->
            <tr>
              <td colspan="5" style="text-align:center; font-size:0.8rem; color:#9ca3af; padding:10px;">
                No logs have been created yet.
              </td>
            </tr>
          <?php else: ?>
            <?php foreach ($recentTables as $t): ?>
              <?php
                  // Format the table_date (Y-m-d) as d-m-Y for display.
                  $dispDate = '';
                  if (!empty($t['table_date'])) {
                      $dt = DateTime::createFromFormat('Y-m-d', $t['table_date']);
                      if ($dt) {
                          $dispDate = $dt->format('d-m-Y');
                      }
                  }
                  // Flag to indicate if this entry belongs to today.
                  $isToday = ($t['table_date'] === date('Y-m-d'));
              ?>
              <tr>
                <td><?= htmlspecialchars($t['table_name']) ?></td>
                <td>
                  <?= htmlspecialchars($dispDate) ?>
                  <?php if ($isToday): ?>
                    <span class="badge badge--ok" style="margin-left:6px;">
                      Today
                    </span>
                  <?php endif; ?>
                </td>
                <td><?= htmlspecialchars($t['table_description'] ?? '') ?></td>
                <td style="text-align:center;"><?= (int)$t['rows_count'] ?></td>
                <td style="text-align:center;">
                  <!-- Link to Documents page with filters for this specific table -->
                  <a
                    href="app.php?page=documents&filter_date=<?= urlencode($t['table_date']) ?>&filter_table_name=<?= urlencode($t['table_name']) ?>"
                    class="button button--sm button--ghost"
                    style="text-decoration:none; padding:4px 10px; font-size:0.78rem;"
                  >
                    View
                  </a>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- ====== Incubation calendar (next 7 days) ====== -->
  <div class="card card--static" style="margin-top:16px;">
    <div class="section-heading" style="margin-bottom:6px;">
      <div class="section-heading__main">
        <div class="section-heading__icon section-heading__icon--stats section-heading__icon--animated">
          <svg
            width="18"
            height="18"
            viewBox="0 0 24 24"
            xmlns="http://www.w3.org/2000/svg"
            aria-hidden="true"
          >
            <!-- Calendar icon -->
            <rect x="4" y="6" width="16" height="13" rx="2" fill="white" opacity="0.95" />
            <path
              d="M4 9h16M9 4v4M15 4v4"
              fill="none"
              stroke="#1d4ed8"
              stroke-width="1.2"
              stroke-linecap="round"
              stroke-linejoin="round"
              opacity="0.95"
            />
          </svg>
        </div>
        <div>
          <h3 style="font-size:0.9rem; margin:0 0 2px;">Incubation calendar (next 7 days)</h3>
          <p class="content-section__subtitle" style="margin:0;">
            Each column is a day; inside you see all microbiology checks due for that date.
          </p>
        </div>
      </div>
    </div>

    <div class="table-wrapper">
      <table class="data-table">
        <thead>
          <tr>
            <?php foreach ($calendarDays as $day): ?>
              <?php
                $dayKey    = $day['key'];
                $dayLabel  = $day['label'];
                $isToday   = ($dayKey === date('Y-m-d'));
              ?>
              <th style="text-align:center; vertical-align:middle;">
                <div style="font-weight:600; font-size:0.82rem;">
                  <?= htmlspecialchars($dayLabel) ?>
                </div>
                <?php if ($isToday): ?>
                  <div style="margin-top:2px;">
                    <span class="badge badge--ok">Today</span>
                  </div>
                <?php endif; ?>
              </th>
            <?php endforeach; ?>
          </tr>
        </thead>
        <tbody>
          <?php if ($maxEventsPerDay === 0): ?>
            <tr>
              <td colspan="<?= count($calendarDays) ?>" style="text-align:center; padding:10px;">
                <span style="font-size:0.8rem; color:#9ca3af;">
                  No checks scheduled for the next 7 days.
                </span>
              </td>
            </tr>
          <?php else: ?>
            <?php for ($rowIndex = 0; $rowIndex < $maxEventsPerDay; $rowIndex++): ?>
              <tr>
                <?php foreach ($calendarDays as $day): ?>
                  <?php
                    $dayKey   = $day['key'];
                    $events   = $incubationCalendar[$dayKey] ?? [];
                    $ev       = $events[$rowIndex] ?? null;
                  ?>
                  <td style="vertical-align:top; font-size:0.8rem; padding:6px 6px;">
                    <?php if ($ev): ?>
                      <?php
                        $dueTime = $ev['due_at']->format('H:i');
                        $tableDateStr = '';
                        if (!empty($ev['table_date'])) {
                            $dt = DateTime::createFromFormat('Y-m-d', $ev['table_date']);
                            if ($dt) {
                                $tableDateStr = $dt->format('d-m-Y');
                            }
                        }
                      ?>
                      <div style="
                        border-radius:6px;
                        border:1px solid rgba(148,163,184,0.35);
                        padding:4px 6px;
                        margin-bottom:4px;
                        background:var(--surface-subtle, #f9fafb);
                      ">
                        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:2px;">
                          <span style="font-weight:600; font-size:0.78rem;">
                            <?= htmlspecialchars($dueTime) ?>
                          </span>
                          <span class="badge" style="font-size:0.7rem;">
                            <?= htmlspecialchars($ev['profile_label']) ?>
                          </span>
                        </div>
                        <div style="font-weight:500; font-size:0.78rem; margin-bottom:1px;">
                          <?= htmlspecialchars($ev['table_name']) ?>
                        </div>
                        <?php if ($tableDateStr): ?>
                          <div style="font-size:0.72rem; color:#6b7280;">
                            Log date: <?= htmlspecialchars($tableDateStr) ?>
                          </div>
                        <?php endif; ?>
                        <?php if (!empty($ev['description'])): ?>
                          <div style="font-size:0.72rem; color:#6b7280; margin-top:1px;">
                            <?= htmlspecialchars($ev['description']) ?>
                          </div>
                        <?php endif; ?>
                      </div>
                    <?php else: ?>
                      <!-- Empty slot: leave cell blank for a clean grid -->
                      &nbsp;
                    <?php endif; ?>
                  </td>
                <?php endforeach; ?>
              </tr>
            <?php endfor; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

</div>
