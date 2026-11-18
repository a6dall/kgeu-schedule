<?php
/**
 * ==============================================================================
 * КГЭУ Расписание — Бэкенд на чистом PHP + Шаблон Vue.js 3
 * ==============================================================================
 * 
 * Назначение этого файла:
 *  1. Запуск встроенного веб-сервера PHP при вызове `php index.php` (для Mac и Docker).
 *  2. Раздача статики (стили style.css, клиентский код app.js).
 *  3. REST API эндпоинты для фронтенда:
 *     - GET  /api/full-schedule : скачивает и кэширует всё расписание семестра через cURL
 *     - GET  /api/todo          : отдаёт сохраненные заметки и статус чекбоксов
 *     - POST /api/todo          : сохраняет заметки в data/todo_state.json
 *  4. Отдача HTML-разметки с реактивными директивами Vue.js 3 (v-for, v-if, @click).
 */

declare(strict_types=1);

// ------------------------------------------------------------------------------
// 1. ЗАПУСК ИЗ ТЕРМИНАЛА (CLI-РЕЖИМ)
// ------------------------------------------------------------------------------
if (php_sapi_name() === 'cli') {
    // Читаем переменные окружения HOST и PORT (по умолчанию 0.0.0.0 или localhost:8000)
    $host = getenv('HOST') ?: (PHP_OS_FAMILY === 'Darwin' ? 'localhost' : '0.0.0.0');
    $port = (int)(getenv('PORT') ?: 8000);
    $url = "http://{$host}:{$port}";

    echo "\033[1;32m========================================================\033[0m\n";
    echo "\033[1;36m🎓 Сервер расписания КГЭУ (Чистый PHP + Vue.js 3)\033[0m\n";
    echo "⚡ Адрес приложения: {$url}\n";
    echo "🔄 API проксирование kabinet.kgeu.ru активно\n";
    echo "❌ Для остановки нажмите Ctrl + C\n";
    echo "\033[1;32m========================================================\033[0m\n\n";

    // Автоматическое открытие в браузере Safari/Chrome на macOS
    if (PHP_OS_FAMILY === 'Darwin') {
        @exec("open " . escapeshellarg($url) . " > /dev/null 2>&1 &");
    }

    // Запускаем встроенный сервер PHP и передаем управление текущему файлу
    passthru(PHP_BINARY . " -S {$host}:{$port} " . escapeshellarg(__FILE__));
    exit;
}

// ------------------------------------------------------------------------------
// 2. ОБРАБОТКА HTTP-ЗАПРОСОВ (РОУТЕР ВНУТРИ PHP)
// ------------------------------------------------------------------------------
$uri = $_SERVER['REQUEST_URI'] ?? '/';
$parsedUrl = parse_url($uri);
$path = $parsedUrl['path'] ?? '/';
$queryString = $parsedUrl['query'] ?? '';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Базовые заголовки: CORS (разрешаем запросы) и Cache-Control (отключаем кэш для мгновенных правок)
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

// Отдача статических файлов (CSS и JavaScript)
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

// Папка с данными и файлами кэша
$dataDir = __DIR__ . '/data';
if (!is_dir($dataDir)) {
    @mkdir($dataDir, 0755, true);
}
$todoFile = $dataDir . '/todo_state.json';
$kgeuApiHost = 'https://kabinet.kgeu.ru';

// ------------------------------------------------------------------------------
// 3. REST API ДЛЯ ЗАМЕТОК И ЧЕКБОКСОВ (/api/todo)
// ------------------------------------------------------------------------------
if ($path === '/api/todo' || $path === '/api/todo-state') {
    header("Content-Type: application/json; charset=utf-8");

    // GET /api/todo: отдаёт текущее сохраненное состояние заметок и чекбоксов
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

    // POST /api/todo: сохраняет изменения от Vue.js в файл data/todo_state.json
    if ($method === 'POST') {
        $rawBody = file_get_contents('php://input');
        $input = json_decode($rawBody, true);

        if (!is_array($input)) {
            http_response_code(400);
            echo json_encode(['error' => 'Неверный JSON'], JSON_UNESCAPED_UNICODE);
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

// ------------------------------------------------------------------------------
// 4. REST API ДЛЯ СКАЧИВАНИЯ ВСЕГО СЕМЕСТРА КГЭУ (/api/full-schedule)
// ------------------------------------------------------------------------------
if ($path === '/api/full-schedule') {
    header("Content-Type: application/json; charset=utf-8");
    parse_str($queryString, $queryParams);
    $idGroup = (int)($queryParams['idGroup'] ?? 14493);
    $forceRefresh = isset($queryParams['refresh']) && ($queryParams['refresh'] === '1' || $queryParams['refresh'] === 'true');
    $semesterCacheFile = $dataDir . "/schedule_semester_{$idGroup}.json";

    // Если есть свежий кэш (до 24 часов) и не запрошено принудительное обновление — отдаем мгновенно
    if (!$forceRefresh && file_exists($semesterCacheFile) && (time() - filemtime($semesterCacheFile) < 86400)) {
        $cached = file_get_contents($semesterCacheFile);
        if ($cached !== false && strlen($cached) > 200) {
            echo $cached;
            exit;
        }
    }

    // Собираем расписание всех недель семестра с kabinet.kgeu.ru параллельно через curl_multi
    try {
        $datesUrl = "{$kgeuApiHost}/api/GetRaspDates?idGroup={$idGroup}";
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $datesUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ["User-Agent: Mozilla/5.0", "Accept: application/json"]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 6);
        $datesResp = curl_exec($ch);
        $datesData = json_decode($datesResp ?: '', true)["data"]["dates"] ?? [];

        // Вычисляем даты всех понедельников учебного года
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

        // Запуск параллельной выгрузки 18 недель за 0.3 секунды
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

// ------------------------------------------------------------------------------
// 5. РАЗМЕТКА VUE.JS 3 ПРИЛОЖЕНИЯ
// ------------------------------------------------------------------------------
?>
<!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Расписание занятий КГЭУ</title>
  
  <!-- Tailwind CSS для красивого адаптивного оформления -->
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
  <link rel="stylesheet" href="style.css?v=7.0">

  <!-- Vue.js 3 via CDN (быстрая библиотека реактивного UI без нужды в Node.js) -->
  <script src="https://unpkg.com/vue@3/dist/vue.global.prod.js"></script>
  <style>
    /* Прячем нескомпилированные теги Vue до момента монтирования */
    [v-cloak] { display: none; }
  </style>
</head>
<body class="bg-slate-100 dark:bg-slate-950 text-slate-900 dark:text-slate-100 min-h-screen pt-16 transition-colors duration-200">

  <!-- Корневой контейнер Vue 3 -->
  <div id="app" v-cloak>

    <!-- Фиксированная верхняя шапка -->
    <header class="app-header bg-white/95 dark:bg-slate-900/95 backdrop-blur-md shadow-sm">
      <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-14 flex items-center justify-between gap-4">
        
        <div>
          <h1 class="text-base font-extrabold text-slate-900 dark:text-white leading-none tracking-tight">
            Расписание КГЭУ <span class="text-xs font-semibold text-indigo-500 font-mono">(Vue + PHP)</span>
          </h1>
        </div>

        <div class="flex items-center gap-3">
          <!-- Статус обновления -->
          <span class="text-xs text-slate-500 dark:text-slate-400 font-medium">
            {{ syncTimeText }}
          </span>

          <!-- Кнопка принудительного обновления расписания -->
          <button 
            type="button"
            @click="manualRefresh()" 
            title="Синхронизировать с сайтом kabinet.kgeu.ru"
            class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl text-xs font-semibold bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-200 hover:bg-slate-200 dark:hover:bg-slate-700 transition"
          >
            <svg class="w-3.5 h-3.5 text-indigo-500" :class="{ 'animate-spin': isSyncing }" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
            </svg>
            <span class="hidden sm:inline">Обновить</span>
          </button>

          <!-- Переключатель темы (Dark / Light) -->
          <button 
            type="button"
            @click="toggleTheme()" 
            title="Сменить тему оформления" 
            class="p-2 rounded-xl text-slate-500 hover:text-slate-800 dark:text-slate-400 dark:hover:text-slate-100 bg-slate-100 dark:bg-slate-800 transition"
          >
            <!-- Иконка луны (в светлой теме) -->
            <svg v-if="!isDark" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"/>
            </svg>
            <!-- Иконка солнца (в тёмной теме) -->
            <svg v-else class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"/>
            </svg>
          </button>
        </div>

      </div>
    </header>

    <!-- Основной контент страницы -->
    <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
      <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">
        
        <!-- ЛЕВАЯ КОЛОНКА: Календарь -->
        <aside class="lg:col-span-5 xl:col-span-4 space-y-5">
          
          <div class="bg-white dark:bg-slate-900 rounded-3xl p-5 shadow-sm">
            
            <!-- Шапка календаря с переключением месяца -->
            <div class="flex items-center justify-between mb-4">
              <h3 class="font-bold text-sm text-slate-900 dark:text-white capitalize">
                {{ calendarMonthTitle }}
              </h3>
              
              <div class="flex items-center gap-1">
                <button type="button" @click="resetToToday()" class="px-2.5 py-1 text-[11px] font-semibold rounded-lg text-indigo-600 dark:text-indigo-400 bg-indigo-50 dark:bg-indigo-950/60 hover:bg-indigo-100 dark:hover:bg-indigo-900/60 transition">
                  Сегодня
                </button>
                <button type="button" @click="prevMonth()" title="Предыдущий месяц" class="p-1 rounded-lg text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800 transition">
                  <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                </button>
                <button type="button" @click="nextMonth()" title="Следующий месяц" class="p-1 rounded-lg text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800 transition">
                  <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                </button>
              </div>
            </div>

            <!-- Заголовки дней недели (Пн — Вс) -->
            <div class="grid grid-cols-7 gap-1 text-center text-[11px] font-bold text-slate-400 mb-2">
              <span>Пн</span><span>Вт</span><span>Ср</span><span>Чт</span><span>Пт</span><span class="text-rose-400">Сб</span><span class="text-rose-400">Вс</span>
            </div>

            <!-- Реактивная сетка чисел месяца -->
            <div class="grid grid-cols-7 gap-1">
              <template v-for="d in calendarDays" :key="d.key || d.dateStr">
                <!-- Пустой слот до первого числа -->
                <div v-if="d.isEmpty" class="aspect-square"></div>

                <!-- Кнопка дня календаря -->
                <button 
                  v-else 
                  type="button"
                  @click="selectCalendarDate(d.dateStr)"
                  :class="[
                    'aspect-square rounded-xl p-1 flex flex-col items-center justify-center text-xs transition relative',
                    d.isSelected ? 'bg-indigo-600 text-white font-bold shadow-md shadow-indigo-500/30' : 
                    (d.isWeekHighlighted ? 'bg-indigo-50 dark:bg-indigo-950/40 text-indigo-700 dark:text-indigo-300 font-semibold' : 'hover:bg-slate-100 dark:hover:bg-slate-800 text-slate-700 dark:text-slate-300'),
                    (d.isToday && !d.isSelected) ? 'ring-2 ring-indigo-500 ring-offset-1 dark:ring-offset-slate-900 font-bold' : ''
                  ]"
                >
                  <span>{{ d.dayNumber }}</span>
                  <!-- Цветные точки наличия занятий -->
                  <div v-if="d.hasLecture || d.hasLab || d.hasPractice || d.hasExam" class="flex items-center justify-center gap-0.5 mt-0.5">
                    <span v-if="d.hasLecture" class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
                    <span v-if="d.hasLab" class="w-1.5 h-1.5 rounded-full bg-purple-500"></span>
                    <span v-if="d.hasPractice" class="w-1.5 h-1.5 rounded-full bg-amber-500"></span>
                    <span v-if="d.hasExam" class="w-1.5 h-1.5 rounded-full bg-rose-500"></span>
                  </div>
                </button>
              </template>
            </div>

            <!-- Кнопка переключения "Один день / Вся неделя" -->
            <div class="mt-4 pt-3">
              <button 
                type="button"
                @click="toggleWeekView()" 
                :class="[
                  'w-full py-2 px-3 rounded-xl text-xs font-bold transition flex items-center justify-center gap-2',
                  currentViewMode === 'week' ? 'bg-indigo-600 text-white' : 'bg-indigo-50 dark:bg-indigo-950/50 text-indigo-600 dark:text-indigo-400 hover:bg-indigo-100 dark:hover:bg-indigo-900/50'
                ]"
              >
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                <span>{{ currentViewMode === 'week' ? 'Показать один день' : 'Показать всю неделю' }}</span>
              </button>
            </div>

          </div>

        </aside>

        <!-- ПРАВАЯ КОЛОНКА: Сетка расписания пар -->
        <section class="lg:col-span-7 xl:col-span-8 space-y-4">
          
          <!-- Шапка правого блока: Полный заголовок дня (БЕЗ обрезания в ...) + Кнопка фильтров + Заметка на день -->
          <div class="bg-white dark:bg-slate-900 rounded-3xl p-5 shadow-sm flex flex-col sm:flex-row sm:items-center justify-between gap-3">
            <h2 class="text-lg sm:text-xl font-extrabold text-slate-900 dark:text-white capitalize leading-tight">
              {{ currentViewTitle }}
            </h2>

            <div class="flex items-center gap-2 shrink-0">
              
              <!-- Раскрывающаяся кнопка-плашка "Фильтры" -->
              <div id="filters-container" class="relative">
                <button 
                  type="button"
                  @click.stop="toggleFiltersDropdown()" 
                  class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl text-xs font-semibold bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-200 hover:bg-slate-200 dark:hover:bg-slate-700 transition"
                >
                  <svg class="w-3.5 h-3.5 text-indigo-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"/></svg>
                  <span>{{ currentFilterLabel }}</span>
                  <svg class="w-3 h-3 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                </button>

                <!-- Отдельная выпадающая плашка фильтров -->
                <div v-show="isFiltersOpen" class="absolute right-0 z-30 mt-2 w-52 origin-top-right rounded-2xl bg-white dark:bg-slate-900 p-2 shadow-2xl border border-slate-100 dark:border-slate-800">
                  <div class="text-[10px] font-bold uppercase tracking-wider text-slate-400 px-3 py-1.5">
                    Тип занятий
                  </div>
                  <div class="space-y-1 text-xs font-semibold">
                    <button type="button" @click="selectFilter('all')" class="w-full flex items-center justify-between px-3 py-2 rounded-xl text-slate-700 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 transition">
                      <span>Все типы</span>
                      <span v-if="activeTypeFilter === 'all'" class="text-indigo-500 font-bold">✓</span>
                    </button>
                    <button type="button" @click="selectFilter('lecture')" class="w-full flex items-center justify-between px-3 py-2 rounded-xl text-slate-700 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 transition">
                      <span class="flex items-center gap-2"><span class="w-2 h-2 rounded-full bg-emerald-500"></span><span>Лекции</span></span>
                      <span v-if="activeTypeFilter === 'lecture'" class="text-indigo-500 font-bold">✓</span>
                    </button>
                    <button type="button" @click="selectFilter('lab')" class="w-full flex items-center justify-between px-3 py-2 rounded-xl text-slate-700 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 transition">
                      <span class="flex items-center gap-2"><span class="w-2 h-2 rounded-full bg-purple-500"></span><span>Лабораторные</span></span>
                      <span v-if="activeTypeFilter === 'lab'" class="text-indigo-500 font-bold">✓</span>
                    </button>
                    <button type="button" @click="selectFilter('practice')" class="w-full flex items-center justify-between px-3 py-2 rounded-xl text-slate-700 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 transition">
                      <span class="flex items-center gap-2"><span class="w-2 h-2 rounded-full bg-amber-500"></span><span>Практики</span></span>
                      <span v-if="activeTypeFilter === 'practice'" class="text-indigo-500 font-bold">✓</span>
                    </button>
                  </div>
                </div>
              </div>

              <!-- Кнопка "Заметка на день" (ровно один плюс из SVG иконки) -->
              <button 
                type="button"
                @click="openAddDayNoteModal()" 
                class="shrink-0 inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl text-xs font-semibold bg-indigo-50 dark:bg-indigo-950/60 text-indigo-600 dark:text-indigo-400 hover:bg-indigo-100 dark:hover:bg-indigo-900/60 transition"
              >
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                <span>Заметка на день</span>
              </button>
            </div>
          </div>

          <!-- Блок общих заметок на текущий день -->
          <div v-if="dayGeneralNotes.length > 0" class="bg-white dark:bg-slate-900 rounded-2xl p-4 shadow-sm border border-indigo-100/60 dark:border-indigo-950/40">
            <div class="flex items-center justify-between mb-2 pb-2 border-b border-slate-100 dark:border-slate-800">
              <span class="text-xs font-bold text-slate-800 dark:text-slate-200 flex items-center gap-2">
                <span class="w-2 h-2 rounded-full bg-indigo-500"></span>
                <span>Заметки на этот день ({{ dayGeneralNotes.length }})</span>
              </span>
            </div>
            <div class="space-y-1.5">
              <div v-for="note in dayGeneralNotes" :key="note.id" class="flex items-center justify-between group py-1 px-2 rounded-xl hover:bg-slate-50 dark:hover:bg-slate-800/60 transition">
                <label class="flex items-center gap-2.5 cursor-pointer select-none flex-1 min-w-0 pr-2">
                  <input 
                    type="checkbox" 
                    :checked="note.completed"
                    @change="toggleCustomNoteCheckbox(note.id, $event.target.checked)"
                    class="w-4 h-4 rounded text-indigo-600 accent-indigo-600 cursor-pointer transition shrink-0"
                  >
                  <span :class="['text-xs font-medium truncate', note.completed ? 'line-through text-slate-400 dark:text-slate-500' : 'text-slate-800 dark:text-slate-200']">
                    {{ cleanTaskTitle(note.text) }}
                  </span>
                </label>
                <div class="flex items-center gap-1 opacity-80 group-hover:opacity-100 transition shrink-0">
                  <button type="button" @click="openEditCustomNoteModal(note)" title="Редактировать" class="p-1 rounded text-slate-400 hover:text-indigo-600">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/></svg>
                  </button>
                  <button type="button" @click="deleteCustomNote(note.id)" title="Удалить" class="p-1 rounded text-slate-400 hover:text-rose-600">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                  </button>
                </div>
              </div>
            </div>
          </div>

          <!-- Список карточек расписания -->
          <div class="space-y-3.5">
            <!-- Заглушка, если в этот день пар нет -->
            <div v-if="displayedPairs.length === 0" class="text-center py-16 px-6 bg-white dark:bg-slate-900 rounded-3xl shadow-sm">
              <div class="w-14 h-14 mx-auto mb-3 bg-indigo-50 dark:bg-indigo-950/40 rounded-2xl flex items-center justify-center text-indigo-500">
                <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 12H4M12 20V4"/></svg>
              </div>
              <h3 class="text-base font-bold text-slate-800 dark:text-slate-100 mb-1">
                {{ currentViewMode === 'day' ? 'В этот день пар нет' : 'Занятий по выбранным фильтрам не найдено' }}
              </h3>
              <p class="text-xs text-slate-400">Свободный день или выходной</p>
            </div>

            <!-- Карточка пары -->
            <article 
              v-for="pair in displayedPairs" 
              :key="getPairKey(pair)"
              :class="[
                'schedule-card relative bg-white dark:bg-slate-900 rounded-2xl p-4 sm:p-5 shadow-sm transition-all flex flex-col justify-between overflow-hidden',
                isPairCurrent(pair.датаНачала, pair.датаОкончания) ? 'ring-2 ring-emerald-500' : ''
              ]"
            >
              <!-- Левая цветная полоса типа пары -->
              <div 
                :class="[
                  'absolute left-0 top-0 bottom-0 w-1.5',
                  parseDiscipline(pair.дисциплина).typeKey === 'lecture' ? 'bg-emerald-500' :
                  (parseDiscipline(pair.дисциплина).typeKey === 'lab' ? 'bg-purple-500' :
                  (parseDiscipline(pair.дисциплина).typeKey === 'practice' ? 'bg-amber-500' : 'bg-indigo-500'))
                ]"
              ></div>

              <div>
                <!-- Верхняя строка: Номер пары, Время, Статус, Подгруппа, Тип -->
                <div class="flex items-center justify-between gap-2 mb-2 pl-2">
                  <div class="flex items-center gap-2">
                    <span class="inline-flex items-center justify-center w-6 h-6 rounded-lg bg-slate-100 dark:bg-slate-800 text-xs font-bold text-slate-700 dark:text-slate-200">
                      {{ pair.номерЗанятия || '—' }}
                    </span>
                    <span class="font-mono text-xs sm:text-sm font-semibold text-slate-800 dark:text-slate-200">
                      {{ pair.начало || '' }} – {{ pair.конец || '' }}
                    </span>
                    <span v-if="isPairCurrent(pair.датаНачала, pair.датаОкончания)" class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-bold bg-emerald-500/15 text-emerald-600 dark:text-emerald-400">
                      <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-ping"></span>
                      Сейчас
                    </span>
                  </div>

                  <div class="flex items-center gap-1.5">
                    <span v-if="pair.номерПодгруппы && pair.номерПодгруппы > 0" class="px-2 py-0.5 rounded-md text-[11px] font-medium bg-blue-500/10 text-blue-600 dark:text-blue-400">
                      {{ pair.номерПодгруппы }} п/г
                    </span>
                    <span :class="['px-2.5 py-0.5 rounded-md text-[11px] font-bold', parseDiscipline(pair.дисциплина).badgeClass]">
                      {{ parseDiscipline(pair.дисциплина).type }}
                    </span>
                  </div>
                </div>

                <!-- Название предмета -->
                <h4 class="text-sm sm:text-base font-bold text-slate-900 dark:text-white leading-snug pl-2">
                  {{ parseDiscipline(pair.дисциплина).title }}
                </h4>
              </div>

              <!-- Преподаватель и Аудитория -->
              <div class="pt-3 mt-3 border-t border-slate-100/70 dark:border-slate-800/60 pl-2 flex items-center justify-between text-xs text-slate-600 dark:text-slate-300">
                <div class="flex items-center gap-1.5 truncate pr-3">
                  <div class="w-5 h-5 rounded-full bg-indigo-50 dark:bg-indigo-950/60 text-indigo-600 dark:text-indigo-400 flex items-center justify-center shrink-0 text-[10px] font-bold">
                    👤
                  </div>
                  <span class="font-medium text-slate-900 dark:text-slate-200 truncate">
                    {{ pair.преподаватель || pair.фиоПреподавателя || '—' }}
                  </span>
                  <span v-if="pair.должность" class="opacity-60 text-[11px]">({{ pair.должность }})</span>
                </div>

                <div class="shrink-0 font-mono font-bold text-slate-800 dark:text-slate-200 bg-slate-100 dark:bg-slate-800 px-2.5 py-1 rounded-lg text-xs">
                  {{ (pair.аудитория || '—').trim() }}
                </div>
              </div>

              <!-- Раздел задач и лабораторных работ к паре -->
              <div class="mt-3 pt-2.5 border-t border-slate-100/70 dark:border-slate-800/60 pl-2">
                <div class="flex items-center justify-between mb-1.5">
                  <span class="text-[11px] font-bold text-slate-400 uppercase tracking-wider">
                    Задачи и лабы
                  </span>
                  <button 
                    type="button"
                    @click="openAddPairNoteModal(getPairKey(pair), pair.дата ? pair.дата.split('T')[0] : selectedDate)" 
                    class="text-[11px] font-semibold text-indigo-600 dark:text-indigo-400 hover:text-indigo-700 flex items-center gap-1 px-1.5 py-0.5 rounded hover:bg-indigo-50 dark:hover:bg-indigo-950/50 transition"
                  >
                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                    <span>Добавить</span>
                  </button>
                </div>

                <div class="space-y-1">
                  <!-- Стандартная лаба по расписанию (БЕЗ TODO:) -->
                  <template v-if="getLabInfo(parseDiscipline(pair.дисциплина).title, pair.дата ? pair.дата.split('T')[0] : selectedDate) && !getLabInfo(parseDiscipline(pair.дисциплина).title, pair.дата ? pair.дата.split('T')[0] : selectedDate).isDeleted">
                    <div class="flex items-center justify-between group py-1 px-2 rounded-xl hover:bg-slate-50 dark:hover:bg-slate-800/60 transition">
                      <label class="flex items-center gap-2.5 cursor-pointer select-none flex-1 min-w-0 pr-2">
                        <input 
                          type="checkbox" 
                          :checked="getLabInfo(parseDiscipline(pair.дисциплина).title, pair.дата ? pair.дата.split('T')[0] : selectedDate).isChecked"
                          @change="toggleTodoCheckbox(getLabInfo(parseDiscipline(pair.дисциплина).title, pair.дата ? pair.дата.split('T')[0] : selectedDate).todoKey, $event.target.checked)"
                          class="w-4 h-4 rounded text-indigo-600 focus:ring-0 accent-indigo-600 cursor-pointer transition shrink-0"
                        >
                        <span :class="['text-xs font-semibold truncate transition', getLabInfo(parseDiscipline(pair.дисциплина).title, pair.дата ? pair.дата.split('T')[0] : selectedDate).isChecked ? 'line-through text-slate-400 dark:text-slate-500' : 'text-slate-700 dark:text-slate-200']">
                          {{ getLabInfo(parseDiscipline(pair.дисциплина).title, pair.дата ? pair.дата.split('T')[0] : selectedDate).label }}
                        </span>
                      </label>
                      
                      <div class="flex items-center gap-1 shrink-0">
                        <span :class="['text-[10px] font-bold px-1.5 py-0.5 rounded', getLabInfo(parseDiscipline(pair.дисциплина).title, pair.дата ? pair.дата.split('T')[0] : selectedDate).isChecked ? 'bg-emerald-500/10 text-emerald-600' : 'bg-purple-500/10 text-purple-600']">
                          {{ getLabInfo(parseDiscipline(pair.дисциплина).title, pair.дата ? pair.дата.split('T')[0] : selectedDate).isChecked ? 'Сдано ✓' : 'К сдаче' }}
                        </span>
                        <button 
                          type="button" 
                          @click="openEditDefaultLabModal(getLabInfo(parseDiscipline(pair.дисциплина).title, pair.дата ? pair.дата.split('T')[0] : selectedDate).todoKey, getLabInfo(parseDiscipline(pair.дисциплина).title, pair.дата ? pair.дата.split('T')[0] : selectedDate).label)" 
                          title="Переименовать" 
                          class="p-1 rounded text-slate-400 hover:text-indigo-600"
                        >
                          <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/></svg>
                        </button>
                        <button 
                          type="button" 
                          @click="deleteDefaultLab(getLabInfo(parseDiscipline(pair.дисциплина).title, pair.дата ? pair.дата.split('T')[0] : selectedDate).todoKey)" 
                          title="Удалить" 
                          class="p-1 rounded text-slate-400 hover:text-rose-600"
                        >
                          <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                        </button>
                      </div>
                    </div>
                  </template>

                  <!-- Кастомные задачи пары -->
                  <div 
                    v-for="task in getCustomTasksForPair(pair)" 
                    :key="task.id"
                    class="flex items-center justify-between group py-1 px-2 rounded-xl hover:bg-slate-50 dark:hover:bg-slate-800/60 transition"
                  >
                    <label class="flex items-center gap-2.5 cursor-pointer select-none flex-1 min-w-0 pr-2">
                      <input 
                        type="checkbox" 
                        :checked="task.completed"
                        @change="toggleCustomNoteCheckbox(task.id, $event.target.checked)"
                        class="w-4 h-4 rounded text-indigo-600 focus:ring-0 accent-indigo-600 cursor-pointer transition shrink-0"
                      >
                      <span :class="['text-xs font-semibold truncate transition', task.completed ? 'line-through text-slate-400 dark:text-slate-500' : 'text-slate-700 dark:text-slate-200']">
                        {{ cleanTaskTitle(task.text) }}
                      </span>
                    </label>
                    <div class="flex items-center gap-1 shrink-0">
                      <button type="button" @click="openEditCustomNoteModal(task)" title="Редактировать" class="p-1 rounded text-slate-400 hover:text-indigo-600">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/></svg>
                      </button>
                      <button type="button" @click="deleteCustomNote(task.id)" title="Удалить" class="p-1 rounded text-slate-400 hover:text-rose-600">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                      </button>
                    </div>
                  </div>
                </div>

              </div>

            </article>
          </div>

        </section>

      </div>
    </main>

    <!-- Модальное окно создания / редактирования заметки -->
    <div v-show="modal.isOpen" class="fixed inset-0 z-50 bg-slate-900/50 backdrop-blur-sm flex items-center justify-center p-4">
      <div class="bg-white dark:bg-slate-900 rounded-3xl p-6 w-full max-w-md shadow-2xl border border-slate-100 dark:border-slate-800 transition-all">
        <div class="flex items-center justify-between mb-4">
          <h3 class="text-base font-bold text-slate-900 dark:text-white">
            {{ modal.title }}
          </h3>
          <button type="button" @click="closeModal()" class="p-1.5 rounded-xl text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-800 transition">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
          </button>
        </div>

        <form @submit.prevent="handleModalSubmit()" class="space-y-4">
          <div>
            <label class="block text-xs font-semibold text-slate-500 dark:text-slate-400 mb-1.5">
              Текст задачи или лабораторной
            </label>
            <input 
              type="text" 
              v-model="modal.text" 
              required 
              placeholder="Новая заметка на день..."
              class="w-full px-4 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-900 dark:text-white text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 transition"
            >
          </div>

          <div class="flex items-center justify-end gap-2 pt-2">
            <button 
              type="button" 
              @click="closeModal()" 
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

  </div> <!-- /#app -->

  <!-- Подключение клиентской логики на Vue.js 3 -->
  <script src="app.js?v=7.0"></script>
</body>
</html>
