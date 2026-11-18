<?php
/**
 * ==============================================================================
 * КГЭУ Расписание — Бэкенд на чистом PHP + Шаблон Vue.js 3
 * Объединённое расписание: ПМ-1-24 и ЦК-ИЦС-3
 * Цвета по типам занятий: Лекция (зеленый), Лаба (фиолетовый), Практика (янтарный)
 * Количество точек в календаре = количеству пар в дне
 * ==============================================================================
 */

declare(strict_types=1);

// ------------------------------------------------------------------------------
// 1. ЗАПУСК ИЗ ТЕРМИНАЛА (CLI-РЕЖИМ)
// ------------------------------------------------------------------------------
if (php_sapi_name() === 'cli') {
    $host = getenv('HOST') ?: (PHP_OS_FAMILY === 'Darwin' ? 'localhost' : '0.0.0.0');
    $port = (int)(getenv('PORT') ?: 8000);
    $url = "http://{$host}:{$port}";

    echo "\033[1;32m========================================================\033[0m\n";
    echo "\033[1;36m🎓 Расписание КГЭУ: ПМ-1-24 + ЦК-ИЦС-3\033[0m\n";
    echo "⚡ Адрес приложения: {$url}\n";
    echo "🔄 API проксирование kabinet.kgeu.ru активно\n";
    echo "❌ Для остановки нажмите Ctrl + C\n";
    echo "\033[1;32m========================================================\033[0m\n\n";

    if (PHP_OS_FAMILY === 'Darwin') {
        @exec("open " . escapeshellarg($url) . " > /dev/null 2>&1 &");
    }

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
if ($path === '/manifest.json' && file_exists(__DIR__ . '/manifest.json')) {
    header("Content-Type: application/manifest+json; charset=utf-8");
    readfile(__DIR__ . '/manifest.json');
    exit;
}
// Отдача иконки приложения для Apple Touch / PWA / Favicon
if ((str_starts_with($path, '/apple-touch-icon') || str_starts_with($path, '/favicon')) && file_exists(__DIR__ . '/apple-touch-icon.png')) {
    header("Content-Type: image/png");
    header("Cache-Control: public, max-age=604800, immutable");
    $targetFile = __DIR__ . $path;
    if (file_exists($targetFile) && is_file($targetFile)) {
        readfile($targetFile);
    } else {
        readfile(__DIR__ . '/apple-touch-icon.png');
    }
    exit;
}

$dataDir = __DIR__ . '/data';
if (!is_dir($dataDir)) {
    @mkdir($dataDir, 0755, true);
}
$kgeuApiHost = 'https://kabinet.kgeu.ru';

// ------------------------------------------------------------------------------
// 3. REST API ДЛЯ СКАЧИВАНИЯ СЕМЕСТРА КГЭУ (/api/full-schedule)
// Объединяет расписание групп: ПМ-1-24 (14493) и ЦК-ИЦС-3 (15006)
// ------------------------------------------------------------------------------
if ($path === '/api/full-schedule') {
    header("Content-Type: application/json; charset=utf-8");
    parse_str($queryString, $queryParams);
    $forceRefresh = isset($queryParams['refresh']) && ($queryParams['refresh'] === '1' || $queryParams['refresh'] === 'true');
    $requestedGroup = $queryParams['idGroup'] ?? 'all';

    $groupsConfig = [
        14493 => 'ПМ-1-24',
        15006 => 'ЦК-ИЦС-3'
    ];

    $mondays = [
        "2026-08-31", "2026-09-07", "2026-09-14", "2026-09-21", "2026-09-28",
        "2026-10-05", "2026-10-12", "2026-10-19", "2026-10-26", "2026-11-02",
        "2026-11-09", "2026-11-16", "2026-11-23", "2026-11-30", "2026-12-07",
        "2026-12-14", "2026-12-21", "2026-12-28"
    ];

    $targetGroupIds = [];
    if ($requestedGroup === 'all' || !isset($groupsConfig[(int)$requestedGroup])) {
        $targetGroupIds = array_keys($groupsConfig);
    } else {
        $targetGroupIds = [(int)$requestedGroup];
    }

    $combinedPairs = [];
    $allGroupsMeta = [];

    foreach ($targetGroupIds as $gId) {
        $gName = $groupsConfig[$gId];
        $allGroupsMeta[] = ['id' => $gId, 'name' => $gName];
        $cacheFile = $dataDir . "/schedule_semester_{$gId}.json";

        $loadedFromCache = false;
        if (!$forceRefresh && file_exists($cacheFile) && (time() - filemtime($cacheFile) < 86400)) {
            $cachedContent = file_get_contents($cacheFile);
            $parsed = json_decode($cachedContent ?: '', true);
            if (!empty($parsed['data']['rasp'])) {
                foreach ($parsed['data']['rasp'] as $item) {
                    $item['groupId'] = $gId;
                    $item['groupName'] = $gName;
                    $combinedPairs[] = $item;
                }
                $loadedFromCache = true;
            }
        }

        if (!$loadedFromCache) {
            $mh = curl_multi_init();
            $handles = [];
            foreach ($mondays as $m) {
                $c = curl_init("{$kgeuApiHost}/api/Rasp?idGroup={$gId}&sdate={$m}");
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

            $groupItems = [];
            $info = null;
            foreach ($handles as $m => $c) {
                $content = curl_multi_getcontent($c);
                $parsed = json_decode($content ?: '', true);
                if (isset($parsed["data"]["rasp"])) {
                    foreach ($parsed["data"]["rasp"] as $item) {
                        $item["groupId"] = $gId;
                        $item["groupName"] = $gName;
                        $itemCode = $item["код"] ?? (getrandmax() . "_" . microtime(true));
                        $groupItems[$itemCode] = $item;
                    }
                }
                if ($info === null && isset($parsed["data"]["info"])) {
                    $info = $parsed["data"]["info"];
                }
                curl_multi_remove_handle($mh, $c);
            }
            curl_multi_close($mh);

            $raspList = array_values($groupItems);
            $cacheData = [
                "state" => 1,
                "msg" => "OK",
                "data" => [
                    "info" => $info,
                    "group" => $gName,
                    "groupId" => $gId,
                    "rasp" => $raspList
                ]
            ];
            file_put_contents($cacheFile, json_encode($cacheData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $combinedPairs = array_merge($combinedPairs, $raspList);
        }
    }

    usort($combinedPairs, function($a, $b) {
        $d = strcmp($a['дата'] ?? '', $b['дата'] ?? '');
        if ($d !== 0) return $d;
        return strcmp($a['начало'] ?? '', $b['начало'] ?? '');
    });

    $maxCacheMtime = 0;
    foreach ($targetGroupIds as $gId) {
        $cFile = $dataDir . "/schedule_semester_{$gId}.json";
        if (file_exists($cFile)) {
            $mtime = filemtime($cFile);
            if ($mtime > $maxCacheMtime) {
                $maxCacheMtime = $mtime;
            }
        }
    }
    $cachedAtIso = $maxCacheMtime > 0 ? date("c", $maxCacheMtime) : date("c");

    $response = [
        "state" => 1,
        "msg" => "OK",
        "data" => [
            "groups" => $allGroupsMeta,
            "rasp" => $combinedPairs,
            "totalPairs" => count($combinedPairs),
            "cachedAt" => $cachedAtIso
        ]
    ];

    echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// ------------------------------------------------------------------------------
// 4. РАЗМЕТКА VUE.JS 3 ПРИЛОЖЕНИЯ
// ------------------------------------------------------------------------------
?>
<!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
  <title>Расписание занятий КГЭУ — ПМ-1-24 и ЦК-ИЦС-3</title>
  
  <!-- Apple Web App (PWA) для запуска как отдельное приложение без браузера Safari -->
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
  <meta name="apple-mobile-web-app-title" content="Расписание">
  <meta name="theme-color" content="#4f46e5" media="(prefers-color-scheme: light)">
  <meta name="theme-color" content="#020617" media="(prefers-color-scheme: dark)">

  <!-- Иконка приложения для экрана "Домой" на iPhone / iPad -->
  <link rel="apple-touch-icon" href="/apple-touch-icon.png">
  <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon-180x180.png">
  <link rel="apple-touch-icon-precomposed" href="/apple-touch-icon-precomposed.png">
  <link rel="icon" type="image/png" sizes="180x180" href="/apple-touch-icon-180x180.png">
  <link rel="icon" type="image/png" sizes="512x512" href="/apple-touch-icon.png">
  <link rel="shortcut icon" type="image/png" href="/favicon.png">
  <link rel="manifest" href="/manifest.json">
  
  <!-- Tailwind CSS -->
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
  <link rel="stylesheet" href="style.css?v=<?= filemtime(__DIR__ . '/style.css') ?>">

  <!-- Vue.js 3 via CDN -->
  <script src="https://unpkg.com/vue@3/dist/vue.global.prod.js"></script>
  <style>
    [v-cloak] { display: none; }
  </style>
</head>
<body class="bg-slate-100 dark:bg-slate-950 text-slate-900 dark:text-slate-100 min-h-screen transition-colors duration-200">

  <!-- Корневой контейнер Vue 3 -->
  <div id="app" v-cloak class="max-w-7xl mx-auto px-3 sm:px-6 lg:px-8 py-3 sm:py-5 space-y-4 sm:space-y-5">

    <!-- ВЕРХНЯЯ ПЛАШКА: Дата, статус обновления, кнопка обновления, переключатель темы, фильтры групп -->
    <div class="bg-white dark:bg-slate-900 rounded-3xl p-4 sm:p-5 shadow-sm space-y-3">
      <div class="flex items-center justify-between gap-3">
        
        <!-- Название выбранного дня (например: Пятница — 06.11.2026) -->
        <h2 class="text-base sm:text-xl font-extrabold text-slate-900 dark:text-white capitalize leading-tight">
          {{ currentViewTitle }}
        </h2>

        <!-- Правая панель: время обновления, кнопка обновления, переключатель темы -->
        <div class="flex items-center gap-2 shrink-0">
          <span class="text-[11px] sm:text-xs text-slate-600 dark:text-slate-400 font-semibold whitespace-nowrap">
            {{ syncTimeText }}
          </span>

          <!-- Кнопка обновления расписания -->
          <button 
            type="button"
            @click="manualRefresh()" 
            title="Обновить расписание с сайта КГЭУ"
            class="p-2 sm:px-2.5 sm:py-1.5 rounded-xl text-xs font-semibold bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-200 hover:bg-slate-200 dark:hover:bg-slate-700 transition inline-flex items-center gap-1.5"
          >
            <svg class="w-3.5 h-3.5 text-indigo-600 dark:text-indigo-400" :class="{ 'animate-spin': isSyncing }" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
            </svg>
            <span class="hidden sm:inline">Обновить</span>
          </button>

          <!-- Переключатель темы (светлая / темная) -->
          <button 
            type="button"
            @click="toggleTheme()" 
            title="Переключить тему"
            class="p-2 rounded-xl text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 transition"
          >
            <svg v-if="isDark" class="w-4 h-4 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
            <svg v-else class="w-4 h-4 text-slate-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"/></svg>
          </button>
        </div>

      </div>

      <!-- Фильтр групп (Сегментированные табы на всю ширину: Все группы / ПМ-1-24 / ЦК-ИЦС-3) -->
      <div class="flex items-center gap-1 bg-slate-100 dark:bg-slate-800 p-1 rounded-2xl w-full">
        <button 
          type="button" 
          @click="selectGroupFilter('all')"
          :class="[
            'flex-1 text-center py-1.5 sm:py-2 px-2.5 sm:px-3 rounded-xl text-xs font-bold transition',
            activeGroupFilter === 'all' 
              ? 'bg-white dark:bg-slate-900 text-slate-900 dark:text-white shadow-sm' 
              : 'text-slate-500 hover:text-slate-800 dark:hover:text-slate-200'
          ]"
        >
          Все группы
        </button>

        <button 
          type="button" 
          @click="selectGroupFilter('14493')"
          :class="[
            'flex-1 text-center py-1.5 sm:py-2 px-2.5 sm:px-3 rounded-xl text-xs font-bold transition',
            activeGroupFilter === '14493' 
              ? 'bg-indigo-600 text-white shadow-sm shadow-indigo-500/20' 
              : 'text-slate-500 hover:text-slate-800 dark:hover:text-slate-200'
          ]"
        >
          ПМ-1-24
        </button>

        <button 
          type="button" 
          @click="selectGroupFilter('15006')"
          :class="[
            'flex-1 text-center py-1.5 sm:py-2 px-2.5 sm:px-3 rounded-xl text-xs font-bold transition',
            activeGroupFilter === '15006' 
              ? 'bg-indigo-600 text-white shadow-sm shadow-indigo-500/20' 
              : 'text-slate-500 hover:text-slate-800 dark:hover:text-slate-200'
          ]"
        >
          ЦК-ИЦС-3
        </button>
      </div>
    </div>

    <!-- Основная сетка: Календарь слева, Пары справа -->
    <div class="grid grid-cols-1 lg:grid-cols-12 gap-5 sm:gap-6 items-start">
      
      <!-- ЛЕВАЯ КОЛОНКА: Календарь -->
      <aside class="lg:col-span-5 xl:col-span-4 space-y-4">
        
        <div class="bg-white dark:bg-slate-900 rounded-3xl p-4 sm:p-5 shadow-sm">
          
          <!-- Шапка календаря с переключением месяца/недели и кнопкой со стрелкой -->
          <div class="flex items-center justify-between mb-3.5">
            <h3 class="font-extrabold text-sm sm:text-base text-slate-900 dark:text-white capitalize">
              {{ calendarMonthTitle }}
            </h3>
            
            <div class="flex items-center gap-1">
              <button type="button" @click="resetToToday()" class="px-2.5 py-1 text-[11px] font-bold rounded-lg text-indigo-600 dark:text-indigo-400 bg-indigo-50 dark:bg-indigo-950/60 hover:bg-indigo-100 dark:hover:bg-indigo-900/60 transition">
                Сегодня
              </button>
              <button type="button" @click="prevMonth()" :title="isCalendarCollapsed ? 'Предыдущая неделя' : 'Предыдущий месяц'" class="p-1.5 rounded-lg text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800 transition">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
              </button>
              <button type="button" @click="nextMonth()" :title="isCalendarCollapsed ? 'Следующая неделя' : 'Следующий месяц'" class="p-1.5 rounded-lg text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800 transition">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
              </button>

              <!-- Кнопка со стрелкой свернуть/развернуть календарь до одной недели -->
              <button 
                type="button" 
                @click="toggleCalendarCollapse()" 
                :title="isCalendarCollapsed ? 'Развернуть на весь месяц' : 'Свернуть до одной недели'"
                class="p-1.5 rounded-lg text-indigo-600 dark:text-indigo-400 bg-indigo-50 dark:bg-indigo-950/60 hover:bg-indigo-100 dark:hover:bg-indigo-900/60 transition ml-0.5"
              >
                <svg class="w-4 h-4 transition-transform duration-200" :class="{ 'rotate-180': isCalendarCollapsed }" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 15l7-7 7 7"/>
                </svg>
              </button>
            </div>
          </div>

          <!-- Заголовки дней недели (Пн — Вс) -->
          <div class="grid grid-cols-7 gap-1 text-center text-[11px] font-bold text-slate-600 dark:text-slate-400 mb-2">
            <span>Пн</span><span>Вт</span><span>Ср</span><span>Чт</span><span>Пт</span><span class="text-rose-600 dark:text-rose-400">Сб</span><span class="text-rose-600 dark:text-rose-400">Вс</span>
          </div>

          <!-- Реактивная сетка чисел (1 неделя при сворачивании или весь месяц) -->
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
                  'aspect-square rounded-xl p-1 flex flex-col items-center justify-between text-xs transition relative min-h-[46px]',
                  d.isSelected ? 'bg-indigo-600 text-white font-bold shadow-md shadow-indigo-500/30' : 'hover:bg-slate-100 dark:hover:bg-slate-800 text-slate-700 dark:text-slate-300',
                  (d.isToday && !d.isSelected) ? 'ring-2 ring-indigo-500 ring-offset-1 dark:ring-offset-slate-900 font-bold' : ''
                ]"
              >
                <span class="mt-0.5 leading-none">{{ d.dayNumber }}</span>
                
                <!-- Точки по количеству пар в дне (цвет соответствует типу пары) -->
                <div v-if="d.dots && d.dots.length > 0" class="flex items-center justify-center gap-0.5 mb-1 flex-wrap max-w-[34px]">
                  <span 
                    v-for="(dot, idx) in d.dots" 
                    :key="idx" 
                    :class="['w-1.5 h-1.5 rounded-full shrink-0', dot.colorClass]"
                    :title="dot.type"
                  ></span>
                </div>
                <div v-else class="h-2"></div>
              </button>
            </template>
          </div>

          <!-- Легенда и кнопка переключения недели/месяца со стрелкой -->
          <div class="mt-3.5 pt-2.5 border-t border-slate-100 dark:border-slate-800 flex items-center justify-between">
            <div class="flex items-center gap-2.5 text-[11px] text-slate-600 dark:text-slate-400 font-semibold">
              <span class="flex items-center gap-1"><span class="w-2 h-2 rounded-full bg-emerald-500"></span>Лекция</span>
              <span class="flex items-center gap-1"><span class="w-2 h-2 rounded-full bg-purple-500"></span>Лабораторная</span>
              <span class="flex items-center gap-1"><span class="w-2 h-2 rounded-full bg-amber-500"></span>Практика</span>
            </div>

            <!-- Кнопка со стрелкой внизу календаря -->
            <button 
              type="button" 
              @click="toggleCalendarCollapse()"
              class="inline-flex items-center gap-1 text-[11px] font-bold text-indigo-600 dark:text-indigo-400 hover:text-indigo-700 dark:hover:text-indigo-300 transition"
              :title="isCalendarCollapsed ? 'Развернуть на весь месяц' : 'Свернуть до одной недели'"
            >
              <span>{{ isCalendarCollapsed ? 'Месяц' : 'Неделя' }}</span>
              <svg class="w-3.5 h-3.5 transition-transform duration-200" :class="{ 'rotate-180': isCalendarCollapsed }" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
              </svg>
            </button>
          </div>

        </div>

      </aside>

      <!-- ПРАВАЯ КОЛОНКА: Сетка расписания пар -->
      <section class="lg:col-span-7 xl:col-span-8 space-y-3.5">
        
        <!-- Заглушка, если в этот день пар нет -->
        <div v-if="!displayedPairs || displayedPairs.length === 0" class="text-center py-16 px-6 bg-white dark:bg-slate-900 rounded-3xl shadow-sm">
          <div class="w-14 h-14 mx-auto mb-3 bg-indigo-50 dark:bg-indigo-950/40 rounded-2xl flex items-center justify-center text-indigo-500">
            <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 12H4M12 20V4"/></svg>
          </div>
          <h3 class="text-base font-bold text-slate-800 dark:text-slate-100 mb-1">
            В этот день пар нет
          </h3>
          <p class="text-xs text-slate-400">Свободный день или выходной</p>
        </div>

            <!-- Карточка пары с разделением по цветам и типам (Лекция, Лабораторная, Практика) -->
            <article 
              v-for="pair in displayedPairs" 
              :key="getPairKey(pair)"
              :class="[
                'schedule-card relative bg-white dark:bg-slate-900 rounded-2xl p-4 sm:p-5 shadow-sm transition-all flex flex-col justify-between overflow-hidden',
                isPairCurrent(pair.датаНачала, pair.датаОкончания) ? 'ring-2 ring-emerald-500' : ''
              ]"
            >
              <!-- Левая цветная полоса ТИПА ЗАНЯТИЯ: зеленый (лекция), фиолетовый (лаба), янтарный (практика) -->
              <div 
                :class="[
                  'absolute left-0 top-0 bottom-0 w-1.5',
                  parseDiscipline(pair.дисциплина).stripeClass
                ]"
              ></div>

              <div>
                <!-- Верхняя строка: Номер пары, Время, Статус "Сейчас", Группа, Подгруппа, ТИП ЗАНЯТИЯ -->
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

                  <!-- Метки: ГРУППА + Подгруппа + ТИП ЗАНЯТИЯ (Лекция / Лабораторная / Практика) -->
                  <div class="flex items-center gap-1.5 flex-wrap justify-end">
                    
                    <!-- Бейдж ГРУППЫ -->
                    <span class="px-2 py-0.5 rounded-md text-[10px] font-bold bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300">
                      {{ pair.groupName || (String(pair.groupId) === '15006' ? 'ЦК-ИЦС-3' : 'ПМ-1-24') }}
                    </span>

                    <span v-if="pair.номерПодгруппы && pair.номерПодгруппы > 0" class="px-2 py-0.5 rounded-md text-[10px] font-medium bg-blue-500/10 text-blue-600 dark:text-blue-400">
                      {{ pair.номерПодгруппы }} п/г
                    </span>

                    <!-- Бейдж ТИПА: Лекция (зеленый), Лабораторная (фиолетовый), Практика (янтарный) -->
                    <span :class="['px-2.5 py-0.5 rounded-md text-[11px] font-bold', parseDiscipline(pair.дисциплина).badgeClass]">
                      {{ parseDiscipline(pair.дисциплина).type }}
                    </span>
                  </div>
                </div>

                <!-- Чистое название предмета -->
                <h4 class="text-sm sm:text-base font-bold text-slate-900 dark:text-white leading-snug pl-2">
                  {{ parseDiscipline(pair.дисциплина).title }}
                </h4>
              </div>

              <!-- Преподаватель и Аудитория -->
              <div class="pt-3 mt-3 border-t border-slate-100/70 dark:border-slate-800/60 pl-2 flex items-center justify-between text-xs text-slate-600 dark:text-slate-300">
                <div class="flex items-center gap-1.5 truncate pr-3">
                  <div class="w-5 h-5 rounded-full bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 flex items-center justify-center shrink-0 text-[10px] font-bold">
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

            </article>
          </div>

        </section>

      </div>
    </main>

  </div> <!-- /#app -->

  <!-- Подключение клиентской логики на Vue.js 3 -->
  <script src="app.js?v=<?= filemtime(__DIR__ . '/app.js') ?>"></script>
</body>
</html>
