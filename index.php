<?php
/**
 * КГЭУ Расписание — Серверная часть на чистом PHP + динамический интерфейс (SPA).
 * Бэкенд на 100% PHP, в браузере мгновенные переключения без перезагрузок.
 *
 * Запуск:
 *   php index.php
 * или:
 *   php -S 0.0.0.0:8000 index.php
 */

declare(strict_types=1);

// -------------------------------------------------------------
// 1. Запуск из консоли через `php index.php`
// -------------------------------------------------------------
if (php_sapi_name() === 'cli') {
    $host = getenv('HOST') ?: (PHP_OS_FAMILY === 'Darwin' ? 'localhost' : '0.0.0.0');
    $port = (int)(getenv('PORT') ?: 8000);
    $url = "http://{$host}:{$port}";

    echo "\033[1;32m========================================================\033[0m\n";
    echo "\033[1;36m🎓 Расписание КГЭУ (PHP Бэкенд + SPA): {$url}\033[0m\n";
    echo "⚡ Мгновенные переключения без перезагрузок\n";
    echo "🔄 Проксирование и кэширование семестра КГЭУ активно\n";
    echo "❌ Для остановки нажмите Ctrl + C\n";
    echo "\033[1;32m========================================================\033[0m\n\n";

    if (PHP_OS_FAMILY === 'Darwin') {
        @exec("open " . escapeshellarg($url) . " > /dev/null 2>&1 &");
    }

    passthru(PHP_BINARY . " -S {$host}:{$port} " . escapeshellarg(__FILE__));
    exit;
}

// -------------------------------------------------------------
// 2. Обработка статических файлов и API
// -------------------------------------------------------------
$uri = $_SERVER['REQUEST_URI'] ?? '/';
$parsedUrl = parse_url($uri);
$path = $parsedUrl['path'] ?? '/';
$queryString = $parsedUrl['query'] ?? '';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Cache-Control: no-cache, no-store, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

if ($method === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Раздача CSS и JS
if ($path === '/style.css' && file_exists(__DIR__ . '/style.css')) {
    header("Content-Type: text/css; charset=utf-8");
    readfile(__DIR__ . '/style.css');
    exit;
}
if ($path === '/app.js' && file_exists(__DIR__ . '/app.js')) {
    header("Content-Type: application/javascript; charset=utf-8");
    readfile(__DIR__ . '/app.js');
    exit;
}

$dataDir = __DIR__ . '/data';
if (!is_dir($dataDir)) {
    @mkdir($dataDir, 0755, true);
}
$todoFile = $dataDir . '/todo_state.json';
$kgeuApiHost = 'https://kabinet.kgeu.ru';

// -------------------------------------------------------------
// 3. API заметок и TODO (/api/todo)
// -------------------------------------------------------------
if ($path === '/api/todo' || $path === '/api/todo-state') {
    header("Content-Type: application/json; charset=utf-8");

    if ($method === 'GET') {
        $state = [
            'checkboxes' => [],
            'customNotes' => [],
            'editedDefaults' => [],
            'deletedDefaults' => []
        ];

        if (file_exists($todoFile)) {
            $raw = file_get_contents($todoFile);
            $parsed = json_decode($raw ?: '', true);
            if (is_array($parsed)) {
                if (isset($parsed['checkboxes']) || isset($parsed['customNotes'])) {
                    $state['checkboxes'] = $parsed['checkboxes'] ?? [];
                    $state['customNotes'] = $parsed['customNotes'] ?? [];
                    $state['editedDefaults'] = $parsed['editedDefaults'] ?? [];
                    $state['deletedDefaults'] = $parsed['deletedDefaults'] ?? [];
                } else {
                    foreach ($parsed as $k => $v) {
                        $state['checkboxes'][$k] = (bool)$v;
                    }
                }
            }
        }

        echo json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($method === 'POST') {
        $rawBody = file_get_contents('php://input');
        $input = json_decode($rawBody, true);

        if (!is_array($input)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid JSON'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $current = [
            'checkboxes' => [],
            'customNotes' => [],
            'editedDefaults' => [],
            'deletedDefaults' => []
        ];

        if (file_exists($todoFile)) {
            $raw = file_get_contents($todoFile);
            $parsed = json_decode($raw ?: '', true);
            if (is_array($parsed)) {
                $current['checkboxes'] = $parsed['checkboxes'] ?? [];
                $current['customNotes'] = $parsed['customNotes'] ?? [];
                $current['editedDefaults'] = $parsed['editedDefaults'] ?? [];
                $current['deletedDefaults'] = $parsed['deletedDefaults'] ?? [];
            }
        }

        if (isset($input['checkboxes'])) {
            $current['checkboxes'] = array_merge($current['checkboxes'], (array)$input['checkboxes']);
        }
        if (isset($input['customNotes'])) {
            $current['customNotes'] = array_values((array)$input['customNotes']);
        }
        if (isset($input['editedDefaults'])) {
            $current['editedDefaults'] = array_merge($current['editedDefaults'], (array)$input['editedDefaults']);
        }
        if (isset($input['deletedDefaults'])) {
            $current['deletedDefaults'] = array_values(array_unique((array)$input['deletedDefaults']));
        }

        file_put_contents(
            $todoFile,
            json_encode($current, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        echo json_encode(['status' => 'ok']);
        exit;
    }
}

// -------------------------------------------------------------
// 4. API полного расписания на семестр (/api/full-schedule)
// -------------------------------------------------------------
if ($path === '/api/full-schedule') {
    header("Content-Type: application/json; charset=utf-8");
    parse_str($queryString, $queryParams);
    $idGroup = (int)($queryParams['idGroup'] ?? 14493);
    $forceRefresh = isset($queryParams['refresh']) && ($queryParams['refresh'] === '1' || $queryParams['refresh'] === 'true');
    $semesterCacheFile = $dataDir . "/schedule_semester_{$idGroup}.json";

    if (!$forceRefresh && file_exists($semesterCacheFile) && (time() - filemtime($semesterCacheFile) < 86400)) {
        $cached = file_get_contents($semesterCacheFile);
        if ($cached !== false && strlen($cached) > 200) {
            echo $cached;
            exit;
        }
    }

    try {
        $datesUrl = "{$kgeuApiHost}/api/GetRaspDates?idGroup={$idGroup}";
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $datesUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ["User-Agent: Mozilla/5.0", "Accept: application/json"]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 6);
        $datesResp = curl_exec($ch);
        $datesData = json_decode($datesResp ?: '', true)["data"]["dates"] ?? [];

        $mondays = [];
        foreach ($datesData as $d) {
            $ts = strtotime($d);
            $monday = date("Y-m-d", strtotime("monday this week", $ts));
            $mondays[$monday] = true;
        }
        $mondays = array_keys($mondays);
        sort($mondays);

        if (empty($mondays)) {
            $mondays = [
                "2026-08-31", "2026-09-07", "2026-09-14", "2026-09-21", "2026-09-28",
                "2026-10-05", "2026-10-12", "2026-10-19", "2026-10-26", "2026-11-02",
                "2026-11-09", "2026-11-16", "2026-11-23", "2026-11-30", "2026-12-07",
                "2026-12-14", "2026-12-21", "2026-12-28"
            ];
        }

        $mh = curl_multi_init();
        $handles = [];
        foreach ($mondays as $m) {
            $c = curl_init("{$kgeuApiHost}/api/Rasp?idGroup={$idGroup}&sdate={$m}");
            curl_setopt($c, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($c, CURLOPT_HTTPHEADER, ["User-Agent: Mozilla/5.0", "Accept: application/json"]);
            curl_setopt($c, CURLOPT_TIMEOUT, 10);
            curl_multi_add_handle($mh, $c);
            $handles[$m] = $c;
        }

        $running = null;
        do {
            curl_multi_exec($mh, $running);
            curl_multi_select($mh);
        } while ($running > 0);

        $allItems = [];
        $info = null;
        foreach ($handles as $m => $c) {
            $content = curl_multi_getcontent($c);
            $parsed = json_decode($content ?: '', true);
            if (isset($parsed["data"]["rasp"])) {
                foreach ($parsed["data"]["rasp"] as $item) {
                    $itemCode = $item["код"] ?? (getrandmax() . '_' . microtime(true));
                    $allItems[$itemCode] = $item;
                }
            }
            if (!$info && isset($parsed["data"]["info"])) {
                $info = $parsed["data"]["info"];
            }
            curl_multi_remove_handle($mh, $c);
        }
        curl_multi_close($mh);

        $output = [
            "data" => [
                "info" => $info,
                "rasp" => array_values($allItems),
                "totalWeeks" => count($mondays),
                "cachedAt" => date("c")
            ]
        ];

        $encoded = json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        file_put_contents($semesterCacheFile, $encoded);
        echo $encoded;
        exit;
    } catch (\Throwable $e) {
        if (file_exists($semesterCacheFile)) {
            echo file_get_contents($semesterCacheFile);
            exit;
        }
        http_response_code(500);
        echo json_encode(["error" => $e->getMessage()]);
        exit;
    }
}

// -------------------------------------------------------------
// 5. Проксирование запросов недели (/api/Rasp)
// -------------------------------------------------------------
if ($path === '/api/Rasp' || $path === '/api/GetRaspDates') {
    header("Content-Type: application/json; charset=utf-8");
    $targetUrl = $kgeuApiHost . $path . ($queryString !== '' ? '?' . $queryString : '');

    $headers = [
        "User-Agent: Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36",
        "Accept: application/json"
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $targetUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $response = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

    http_response_code($httpCode >= 200 && $httpCode < 400 ? $httpCode : 500);
    echo $response ?: '{}';
    exit;
}

// -------------------------------------------------------------
// 6. Главная страница (HTML + динамический SPA интерфейс)
// -------------------------------------------------------------
?>
<!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Расписание занятий КГЭУ</title>
  
  <!-- Tailwind CSS via CDN -->
  <script src="https://cdn.tailwindcss.com"></script>
  <script>
    tailwind.config = {
      darkMode: 'class',
      theme: {
        extend: {
          colors: {
            brand: { 50: '#eef2ff', 100: '#e0e7ff', 500: '#6366f1', 600: '#4f46e5', 700: '#4338ca' }
          }
        }
      }
    }
  </script>
  <link rel="stylesheet" href="style.css?v=6.0">
</head>
<body class="bg-slate-100 dark:bg-slate-950 text-slate-900 dark:text-slate-100 min-h-screen pt-16 transition-colors duration-200">

  <!-- Фиксированная верхняя шапка -->
  <header class="app-header bg-white/95 dark:bg-slate-900/95 backdrop-blur-md shadow-sm">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-14 flex items-center justify-between gap-4">
      
      <div>
        <h1 class="text-base font-extrabold text-slate-900 dark:text-white leading-none tracking-tight">
          Расписание КГЭУ <span class="text-xs font-semibold text-indigo-500 font-mono">(PHP)</span>
        </h1>
      </div>

      <div class="flex items-center gap-3">
        <span id="sync-status" class="text-xs text-slate-500 dark:text-slate-400 font-medium">
          Загрузка...
        </span>

        <button 
          id="refresh-btn"
          onclick="manualRefresh()" 
          title="Синхронизировать с сайтом kabinet.kgeu.ru"
          class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl text-xs font-semibold bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-200 hover:bg-slate-200 dark:hover:bg-slate-700 transition"
        >
          <svg id="refresh-icon" class="w-3.5 h-3.5 text-indigo-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
          </svg>
          <span class="hidden sm:inline">Обновить</span>
        </button>

        <button 
          onclick="toggleTheme()" 
          title="Сменить тему" 
          class="p-2 rounded-xl text-slate-500 hover:text-slate-800 dark:text-slate-400 dark:hover:text-slate-100 bg-slate-100 dark:bg-slate-800 transition"
        >
          <svg class="w-4 h-4 block dark:hidden" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"/></svg>
          <svg class="w-4 h-4 hidden dark:block" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
        </button>
      </div>

    </div>
  </header>

  <!-- Основной контент -->
  <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
    <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">
      
      <!-- ЛЕВАЯ КОЛОНКА: Календарь -->
      <aside class="lg:col-span-5 xl:col-span-4 space-y-5">
        
        <div class="bg-white dark:bg-slate-900 rounded-3xl p-5 shadow-sm">
          
          <div class="flex items-center justify-between mb-4">
            <h3 id="calendar-month-title" class="font-bold text-sm text-slate-900 dark:text-white capitalize">
              Загрузка месяца...
            </h3>
            
            <div class="flex items-center gap-1">
              <button onclick="resetToToday()" class="px-2.5 py-1 text-[11px] font-semibold rounded-lg text-indigo-600 dark:text-indigo-400 bg-indigo-50 dark:bg-indigo-950/60 hover:bg-indigo-100 dark:hover:bg-indigo-900/60 transition">
                Сегодня
              </button>
              <button onclick="prevMonth()" title="Предыдущий месяц" class="p-1 rounded-lg text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800 transition">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
              </button>
              <button onclick="nextMonth()" title="Следующий месяц" class="p-1 rounded-lg text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800 transition">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
              </button>
            </div>
          </div>

          <!-- Дни недели календаря (Пн - Вс) -->
          <div class="grid grid-cols-7 gap-1 text-center text-[11px] font-bold text-slate-400 mb-2">
            <span>Пн</span><span>Вт</span><span>Ср</span><span>Чт</span><span>Пт</span><span class="text-rose-400">Сб</span><span class="text-rose-400">Вс</span>
          </div>

          <!-- Сетка чисел месяца с цветными точками всех пар дня -->
          <div id="calendar-grid" class="grid grid-cols-7 gap-1">
            <!-- Генерируется мгновенно на клиенте -->
          </div>

          <!-- Кнопка "Показать неделю" -->
          <div class="mt-4 pt-3">
            <button 
              id="toggle-week-btn"
              onclick="toggleWeekView()" 
              class="w-full py-2 px-3 rounded-xl text-xs font-bold transition flex items-center justify-center gap-2 bg-indigo-50 dark:bg-indigo-950/50 text-indigo-600 dark:text-indigo-400 hover:bg-indigo-100 dark:hover:bg-indigo-900/50"
            >
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
              <span id="toggle-week-btn-text">Показать всю неделю</span>
            </button>
          </div>

        </div>

      </aside>

      <!-- ПРАВАЯ КОЛОНКА: Расписание занятий на день / неделю -->
      <section class="lg:col-span-7 xl:col-span-8 space-y-4">
        
        <!-- Заголовочная карточка правого блока (День недели и дата БЕЗ обрезания в ...) -->
        <div class="bg-white dark:bg-slate-900 rounded-3xl p-5 shadow-sm flex flex-col sm:flex-row sm:items-center justify-between gap-3">
          <h2 id="current-view-title" class="text-lg sm:text-xl font-extrabold text-slate-900 dark:text-white capitalize leading-tight">
            Понедельник — 18.11.2026
          </h2>

          <div class="flex items-center gap-2 shrink-0">
            
            <!-- Выпадающая кнопка-плашка "Фильтры" -->
            <div id="filters-container" class="relative">
              <button 
                id="filters-btn"
                type="button"
                onclick="toggleFiltersDropdown()" 
                class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl text-xs font-semibold bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-200 hover:bg-slate-200 dark:hover:bg-slate-700 transition"
              >
                <svg class="w-3.5 h-3.5 text-indigo-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"/></svg>
                <span id="current-filter-label">Фильтры</span>
                <svg class="w-3 h-3 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
              </button>

              <!-- Отдельная всплывающая плашка со списком типов -->
              <div id="filters-dropdown" class="hidden absolute right-0 z-30 mt-2 w-52 origin-top-right rounded-2xl bg-white dark:bg-slate-900 p-2 shadow-2xl border border-slate-100 dark:border-slate-800">
                <div class="text-[10px] font-bold uppercase tracking-wider text-slate-400 px-3 py-1.5">
                  Тип занятий
                </div>
                <div class="space-y-1 text-xs font-semibold">
                  <button type="button" onclick="setTypeFilter('all')" class="w-full flex items-center justify-between px-3 py-2 rounded-xl text-slate-700 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 transition">
                    <span>Все типы</span>
                    <span id="filter-check-all" class="filter-check-mark text-indigo-500 font-bold">✓</span>
                  </button>
                  <button type="button" onclick="setTypeFilter('lecture')" class="w-full flex items-center justify-between px-3 py-2 rounded-xl text-slate-700 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 transition">
                    <span class="flex items-center gap-2"><span class="w-2 h-2 rounded-full bg-emerald-500"></span><span>Лекции</span></span>
                    <span id="filter-check-lecture" class="filter-check-mark hidden text-indigo-500 font-bold">✓</span>
                  </button>
                  <button type="button" onclick="setTypeFilter('lab')" class="w-full flex items-center justify-between px-3 py-2 rounded-xl text-slate-700 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 transition">
                    <span class="flex items-center gap-2"><span class="w-2 h-2 rounded-full bg-purple-500"></span><span>Лабораторные</span></span>
                    <span id="filter-check-lab" class="filter-check-mark hidden text-indigo-500 font-bold">✓</span>
                  </button>
                  <button type="button" onclick="setTypeFilter('practice')" class="w-full flex items-center justify-between px-3 py-2 rounded-xl text-slate-700 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 transition">
                    <span class="flex items-center gap-2"><span class="w-2 h-2 rounded-full bg-amber-500"></span><span>Практики</span></span>
                    <span id="filter-check-practice" class="filter-check-mark hidden text-indigo-500 font-bold">✓</span>
                  </button>
                </div>
              </div>
            </div>

            <!-- Кнопка "Заметка на день" (ровно один плюс из иконки) -->
            <button 
              type="button"
              onclick="openAddDayNoteModal()" 
              class="shrink-0 inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl text-xs font-semibold bg-indigo-50 dark:bg-indigo-950/60 text-indigo-600 dark:text-indigo-400 hover:bg-indigo-100 dark:hover:bg-indigo-900/60 transition"
            >
              <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
              <span>Заметка на день</span>
            </button>
          </div>
        </div>

        <!-- Контейнер заметок на выбранный день -->
        <div id="day-notes-container" class="hidden"></div>

        <!-- Контейнер карточек расписания -->
        <div id="schedule-cards-container" class="space-y-3">
          <!-- Генерируется мгновенно на клиенте -->
        </div>

      </section>

    </div>
  </main>

  <!-- Модальное окно создания / редактирования заметки -->
  <div id="note-modal" class="fixed inset-0 z-50 hidden bg-slate-900/50 backdrop-blur-sm flex items-center justify-center p-4">
    <div class="bg-white dark:bg-slate-900 rounded-3xl p-6 w-full max-w-md shadow-2xl border border-slate-100 dark:border-slate-800 transition-all">
      <div class="flex items-center justify-between mb-4">
        <h3 id="note-modal-title" class="text-base font-bold text-slate-900 dark:text-white">
          Заметка / задача
        </h3>
        <button type="button" onclick="closeNoteModal()" class="p-1.5 rounded-xl text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-800 transition">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
        </button>
      </div>

      <form id="note-modal-form" onsubmit="handleNoteModalSubmit(event)" class="space-y-4">
        <input type="hidden" id="note-modal-mode" value="add">
        <input type="hidden" id="note-modal-target-type" value="pair">
        <input type="hidden" id="note-modal-id" value="">
        <input type="hidden" id="note-modal-pairkey" value="">
        <input type="hidden" id="note-modal-date" value="">

        <div>
          <label for="note-modal-input" class="block text-xs font-semibold text-slate-500 dark:text-slate-400 mb-1.5">
            Текст задачи или лабораторной
          </label>
          <input 
            type="text" 
            id="note-modal-input" 
            required 
            placeholder="Новая заметка на день..."
            class="w-full px-4 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-900 dark:text-white text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 transition"
          >
        </div>

        <div class="flex items-center justify-end gap-2 pt-2">
          <button 
            type="button" 
            onclick="closeNoteModal()" 
            class="px-4 py-2 rounded-xl text-xs font-semibold text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 transition"
          >
            Отмена
          </button>
          <button 
            type="submit" 
            class="px-4 py-2 rounded-xl text-xs font-semibold bg-indigo-600 hover:bg-indigo-700 text-white transition shadow-sm shadow-indigo-500/20"
          >
            Сохранить
          </button>
        </div>
      </form>
    </div>
  </div>

  <script src="app.js?v=6.0"></script>
</body>
</html>
