<?php
/**
 * КГЭУ Расписание — Полностью на чистом PHP (Server-Side Rendering).
 * Без JavaScript и без Python.
 *
 * Запуск:
 *   php index.php
 * или:
 *   php -S 0.0.0.0:8000 index.php
 */

declare(strict_types=1);

// -------------------------------------------------------------
// 1. Если скрипт запущен напрямую из консоли через `php index.php`
// -------------------------------------------------------------
if (php_sapi_name() === 'cli') {
    $host = getenv('HOST') ?: (PHP_OS_FAMILY === 'Darwin' ? 'localhost' : '0.0.0.0');
    $port = (int)(getenv('PORT') ?: 8000);
    $url = "http://{$host}:{$port}";

    echo "\033[1;32m========================================================\033[0m\n";
    echo "\033[1;36m🎓 Расписание КГЭУ (100% Чистый PHP): {$url}\033[0m\n";
    echo "⚡ Server-Side Rendering (без JS, без Python)\n";
    echo "❌ Для остановки нажмите Ctrl + C\n";
    echo "\033[1;32m========================================================\033[0m\n\n";

    if (PHP_OS_FAMILY === 'Darwin') {
        @exec("open " . escapeshellarg($url) . " > /dev/null 2>&1 &");
    }

    passthru(PHP_BINARY . " -S {$host}:{$port} " . escapeshellarg(__FILE__));
    exit;
}

// -------------------------------------------------------------
// 2. Раздача статики (style.css и фавиконки)
// -------------------------------------------------------------
$reqPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if ($reqPath === '/style.css' && file_exists(__DIR__ . '/style.css')) {
    header("Content-Type: text/css; charset=utf-8");
    header("Cache-Control: no-cache, no-store, must-revalidate");
    readfile(__DIR__ . '/style.css');
    exit;
}

// -------------------------------------------------------------
// 3. Конфигурация и пути к данным
// -------------------------------------------------------------
$groupId = 14493;
$dataDir = __DIR__ . '/data';
if (!is_dir($dataDir)) {
    @mkdir($dataDir, 0755, true);
}
$cacheFile = $dataDir . "/schedule_semester_{$groupId}.json";
$todoFile = $dataDir . '/todo_state.json';
$sampleFile = $dataDir . '/schedule_sample.json';

// Хронология лабораторных работ семестра
$subjectLabTimeline = [
    'Исследование операций' => [
        'unit' => 'Лабораторная работа',
        'dates' => ['2026-09-03', '2026-09-16', '2026-09-30', '2026-10-14', '2026-10-28', '2026-11-11', '2026-11-25', '2026-12-09']
    ],
    'Маркетинг ИТ-продуктов' => [
        'unit' => 'Лабораторная работа',
        'dates' => ['2026-09-10', '2026-09-24', '2026-10-08', '2026-10-22', '2026-11-05', '2026-11-19', '2026-12-03', '2026-12-17']
    ],
    'Сетевое планирование и управление ИТ-проектами' => [
        'unit' => 'Лабораторная работа',
        'dates' => ['2026-09-11', '2026-09-25', '2026-10-09', '2026-10-23', '2026-11-06', '2026-11-20', '2026-12-04', '2026-12-18']
    ],
    'Интерфейс программных продуктов' => [
        'unit' => 'Лабораторная работа',
        'dates' => ['2026-09-12', '2026-09-26', '2026-10-10', '2026-10-24', '2026-11-07', '2026-11-21', '2026-12-05', '2026-12-19']
    ],
    'Web-программирование' => [
        'unit' => 'Лабораторная работа',
        'dates' => ['2026-09-15', '2026-09-29', '2026-10-13', '2026-10-27', '2026-11-10', '2026-11-24', '2026-12-08']
    ],
    'Статистика' => [
        'unit' => 'Лабораторная работа',
        'dates' => ['2026-09-15', '2026-09-29', '2026-10-13', '2026-10-27', '2026-11-10', '2026-11-24', '2026-12-08']
    ],
    'Оценка эффективности ИТ-проектов' => [
        'unit' => 'Кейс',
        'dates' => ['2026-09-18', '2026-10-02', '2026-10-16', '2026-10-30', '2026-11-13', '2026-11-27', '2026-12-11']
    ]
];

// -------------------------------------------------------------
// 4. Функции работы с состоянием TODO и заметок
// -------------------------------------------------------------
function loadTodoState(string $file): array {
    $default = [
        'checkboxes' => [],
        'customNotes' => [],
        'editedDefaults' => [],
        'deletedDefaults' => []
    ];
    if (!file_exists($file)) {
        return $default;
    }
    $raw = @file_get_contents($file);
    $data = json_decode($raw ?: '', true);
    if (!is_array($data)) {
        return $default;
    }
    if (isset($data['checkboxes']) || isset($data['customNotes'])) {
        return [
            'checkboxes' => (array)($data['checkboxes'] ?? []),
            'customNotes' => array_values((array)($data['customNotes'] ?? [])),
            'editedDefaults' => (array)($data['editedDefaults'] ?? []),
            'deletedDefaults' => array_values((array)($data['deletedDefaults'] ?? []))
        ];
    }
    // Обратная совместимость с плоским JSON
    $migrated = $default;
    foreach ($data as $k => $v) {
        $migrated['checkboxes'][$k] = (bool)$v;
    }
    return $migrated;
}

function saveTodoState(string $file, array $state): void {
    file_put_contents(
        $file,
        json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );
}

$todoState = loadTodoState($todoFile);

// -------------------------------------------------------------
// 5. Обработка действий (POST и GET действия без JavaScript)
// -------------------------------------------------------------
function redirectBack(): void {
    $params = $_GET;
    unset(
        $params['toggle_todo'],
        $params['toggle_custom'],
        $params['delete_custom'],
        $params['delete_default'],
        $params['toggle_theme'],
        $params['refresh']
    );
    $url = strtok($_SERVER['REQUEST_URI'] ?? '/', '?');
    if (!empty($params)) {
        $url .= '?' . http_build_query($params);
    }
    header("Location: {$url}");
    exit;
}

// Смена темы (Dark/Light)
if (isset($_GET['toggle_theme'])) {
    $curTheme = $_COOKIE['theme'] ?? 'dark';
    $newTheme = ($curTheme === 'dark') ? 'light' : 'dark';
    setcookie('theme', $newTheme, time() + 86400 * 365, '/');
    redirectBack();
}

// Переключение чекбокса стандартной лабы
if (isset($_GET['toggle_todo'])) {
    $key = (string)$_GET['toggle_todo'];
    $todoState['checkboxes'][$key] = !($todoState['checkboxes'][$key] ?? false);
    saveTodoState($todoFile, $todoState);
    redirectBack();
}

// Переключение чекбокса кастомной задачи
if (isset($_GET['toggle_custom'])) {
    $id = (string)$_GET['toggle_custom'];
    foreach ($todoState['customNotes'] as &$item) {
        if (($item['id'] ?? '') === $id) {
            $item['completed'] = !($item['completed'] ?? false);
            break;
        }
    }
    saveTodoState($todoFile, $todoState);
    redirectBack();
}

// Удаление кастомной задачи
if (isset($_GET['delete_custom'])) {
    $delId = (string)$_GET['delete_custom'];
    $todoState['customNotes'] = array_values(array_filter(
        $todoState['customNotes'],
        fn($it) => ($it['id'] ?? '') !== $delId
    ));
    saveTodoState($todoFile, $todoState);
    redirectBack();
}

// Удаление (скрытие) стандартной лабы
if (isset($_GET['delete_default'])) {
    $key = (string)$_GET['delete_default'];
    if (!in_array($key, $todoState['deletedDefaults'], true)) {
        $todoState['deletedDefaults'][] = $key;
    }
    saveTodoState($todoFile, $todoState);
    redirectBack();
}

// Добавление новой задачи (POST форма)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_note') {
    $text = trim((string)($_POST['text'] ?? ''));
    $pairKey = trim((string)($_POST['pair_key'] ?? ''));
    $date = trim((string)($_POST['date'] ?? ''));

    if ($text !== '') {
        $todoState['customNotes'][] = [
            'id' => 'note_' . time() . '_' . bin2hex(random_bytes(2)),
            'pairKey' => $pairKey !== '' ? $pairKey : null,
            'date' => $date !== '' ? $date : date('Y-m-d'),
            'text' => $text,
            'completed' => false
        ];
        saveTodoState($todoFile, $todoState);
    }
    redirectBack();
}

// Редактирование задачи (POST форма)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit_note') {
    $id = trim((string)($_POST['id'] ?? ''));
    $text = trim((string)($_POST['text'] ?? ''));
    $type = trim((string)($_POST['type'] ?? 'custom'));

    if ($text !== '' && $id !== '') {
        if ($type === 'default_lab') {
            $todoState['editedDefaults'][$id] = $text;
        } else {
            foreach ($todoState['customNotes'] as &$item) {
                if (($item['id'] ?? '') === $id) {
                    $item['text'] = $text;
                    break;
                }
            }
        }
        saveTodoState($todoFile, $todoState);
    }
    redirectBack();
}

// -------------------------------------------------------------
// 6. Загрузка расписания КГЭУ на весь семестр
// -------------------------------------------------------------
function fetchFullSchedule(int $groupId, string $cacheFile, bool $forceRefresh = false): array {
    if (!$forceRefresh && file_exists($cacheFile) && (time() - filemtime($cacheFile) < 86400)) {
        $content = @file_get_contents($cacheFile);
        $data = json_decode($content ?: '', true);
        if (!empty($data['data']['rasp'])) {
            return $data['data'];
        }
    }

    try {
        $datesUrl = "https://kabinet.kgeu.ru/api/GetRaspDates?idGroup={$groupId}";
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $datesUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ["User-Agent: Mozilla/5.0", "Accept: application/json"]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 6);
        $resp = curl_exec($ch);
        $parsed = json_decode($resp ?: '', true);
        $dates = $parsed['data']['dates'] ?? [];

        $mondays = [];
        foreach ($dates as $d) {
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
            $c = curl_init("https://kabinet.kgeu.ru/api/Rasp?idGroup={$groupId}&sdate={$m}");
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
        foreach ($handles as $c) {
            $body = curl_multi_getcontent($c);
            $json = json_decode($body ?: '', true);
            if (!empty($json['data']['rasp'])) {
                foreach ($json['data']['rasp'] as $item) {
                    $allItems[$item['код'] ?? microtime(true)] = $item;
                }
            }
            if (!$info && !empty($json['data']['info'])) {
                $info = $json['data']['info'];
            }
            curl_multi_remove_handle($mh, $c);
        }
        curl_multi_close($mh);

        $result = [
            'info' => $info,
            'rasp' => array_values($allItems),
            'totalWeeks' => count($mondays),
            'cachedAt' => date('c')
        ];

        file_put_contents(
            $cacheFile,
            json_encode(['data' => $result], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        return $result;
    } catch (\Throwable $e) {
        if (file_exists($cacheFile)) {
            $content = @file_get_contents($cacheFile);
            $data = json_decode($content ?: '', true);
            return $data['data'] ?? [];
        }
        return [];
    }
}

$isForceRefresh = isset($_GET['refresh']) && $_GET['refresh'] === '1';
$scheduleData = fetchFullSchedule($groupId, $cacheFile, $isForceRefresh);
$allPairs = $scheduleData['rasp'] ?? [];

// Если список пуст, используем локальный сэмпл
if (empty($allPairs) && file_exists($sampleFile)) {
    $sampleRaw = @file_get_contents($sampleFile);
    $sampleJson = json_decode($sampleRaw ?: '', true);
    $allPairs = $sampleJson['data']['rasp'] ?? [];
}

// Группировка всех пар по датам: [ 'YYYY-MM-DD' => [ items... ] ]
$pairsByDate = [];
foreach ($allPairs as $p) {
    if (empty($p['дата'])) continue;
    $dateKey = explode('T', $p['дата'])[0];
    $pairsByDate[$dateKey][] = $p;
}

// -------------------------------------------------------------
// 7. Вспомогательные функции парсинга и дат
// -------------------------------------------------------------
function parseDiscipline(string $rawName): array {
    $trimmed = trim($rawName);
    if (str_starts_with($trimmed, 'лек ')) {
        return [
            'type' => 'Лекция',
            'typeKey' => 'lecture',
            'title' => trim(substr($trimmed, 4)),
            'badgeClass' => 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400'
        ];
    }
    if (str_starts_with($trimmed, 'лаб ')) {
        return [
            'type' => 'Лабораторная',
            'typeKey' => 'lab',
            'title' => trim(substr($trimmed, 4)),
            'badgeClass' => 'bg-purple-500/10 text-purple-600 dark:text-purple-400'
        ];
    }
    if (str_starts_with($trimmed, 'пр ')) {
        return [
            'type' => 'Практика',
            'typeKey' => 'practice',
            'title' => trim(substr($trimmed, 3)),
            'badgeClass' => 'bg-amber-500/10 text-amber-600 dark:text-amber-400'
        ];
    }
    if (str_starts_with($trimmed, 'зач ')) {
        return [
            'type' => 'Зачет',
            'typeKey' => 'exam',
            'title' => trim(substr($trimmed, 4)),
            'badgeClass' => 'bg-red-500/10 text-red-600 dark:text-red-400'
        ];
    }
    if (str_starts_with($trimmed, 'экз ')) {
        return [
            'type' => 'Экзамен',
            'typeKey' => 'exam',
            'title' => trim(substr($trimmed, 4)),
            'badgeClass' => 'bg-rose-500/10 text-rose-600 dark:text-rose-400'
        ];
    }
    return [
        'type' => 'Занятие',
        'typeKey' => 'other',
        'title' => $trimmed,
        'badgeClass' => 'bg-indigo-500/10 text-indigo-600 dark:text-indigo-400'
    ];
}

function getPairKey(array $item): string {
    $d = explode('T', $item['дата'] ?? '')[0];
    $num = $item['номерЗанятия'] ?? '';
    $time = trim($item['начало'] ?? '');
    $sub = $item['номерПодгруппы'] ?? 0;
    $disc = mb_strtolower(trim($item['дисциплина'] ?? ''));
    return "{$d}_{$num}_{$time}_{$sub}_{$disc}";
}

function getLabInfo(string $subjectTitle, string $dateStr, array $timeline, array $todoState): ?array {
    $titleLow = mb_strtolower($subjectTitle);
    $matched = null;
    foreach ($timeline as $subKey => $conf) {
        $keyLow = mb_strtolower($subKey);
        if (str_contains($titleLow, $keyLow) || str_contains($keyLow, $titleLow)) {
            $matched = $subKey;
            break;
        }
        if (str_contains($titleLow, 'сетевое') && str_contains($keyLow, 'сетевое')) { $matched = $subKey; break; }
        if (str_contains($titleLow, 'маркетинг') && str_contains($keyLow, 'маркетинг')) { $matched = $subKey; break; }
        if (str_contains($titleLow, 'интерфейс') && str_contains($keyLow, 'интерфейс')) { $matched = $subKey; break; }
        if (str_contains($titleLow, 'web') && str_contains($keyLow, 'web')) { $matched = $subKey; break; }
        if (str_contains($titleLow, 'оценка') && str_contains($keyLow, 'оценка')) { $matched = $subKey; break; }
        if (str_contains($titleLow, 'исследование') && str_contains($keyLow, 'исследование')) { $matched = $subKey; break; }
        if (str_contains($titleLow, 'статистик') && str_contains($keyLow, 'статистик')) { $matched = $subKey; break; }
    }
    if (!$matched) return null;

    $dates = $timeline[$matched]['dates'] ?? [];
    $unit = $timeline[$matched]['unit'] ?? 'Лабораторная работа';
    $idx = array_search($dateStr, $dates, true);
    if ($idx !== false) {
        $labNum = $idx + 1;
    } else {
        $count = 0;
        foreach ($dates as $d) {
            if ($d <= $dateStr) $count++;
        }
        $labNum = $count > 0 ? $count : 1;
    }

    $todoKey = "{$matched}_{$labNum}";
    $isChecked = !empty($todoState['checkboxes'][$todoKey]);
    $customTitle = $todoState['editedDefaults'][$todoKey] ?? null;

    $label = $customTitle ?: "{$unit} №{$labNum}";
    $label = trim((string)preg_replace('/^\s*TODO:\s*/iu', '', $label));

    return [
        'todoKey' => $todoKey,
        'label' => $label,
        'isChecked' => $isChecked
    ];
}

function getWeekDates(string $dateStr): array {
    $ts = strtotime($dateStr);
    $mondayTs = strtotime('monday this week', $ts);
    $week = [];
    for ($i = 0; $i < 7; $i++) {
        $week[] = date('Y-m-d', strtotime("+{$i} days", $mondayTs));
    }
    return $week;
}

function formatCustomDateTitle(string $dateStr): string {
    $days = ['Воскресенье', 'Понедельник', 'Вторник', 'Среда', 'Четверг', 'Пятница', 'Суббота'];
    $ts = strtotime($dateStr);
    $dayName = $days[(int)date('w', $ts)];
    $d = date('d', $ts);
    $m = date('m', $ts);
    $y = date('Y', $ts);
    return "{$dayName} — {$d}.{$m}.{$y}";
}

// -------------------------------------------------------------
// 8. Параметры текущего запроса (Тема, Дата, Режим, Фильтр)
// -------------------------------------------------------------
$theme = $_COOKIE['theme'] ?? 'dark';
$realToday = date('Y-m-d');
$selectedDate = isset($_GET['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date']) ? $_GET['date'] : $realToday;
$viewMode = (isset($_GET['view']) && $_GET['view'] === 'week') ? 'week' : 'day';
$typeFilter = $_GET['type'] ?? 'all';
$editNoteId = $_GET['edit_note'] ?? null;
$editDefaultKey = $_GET['edit_default'] ?? null;

// Месяц для левого календаря (YYYY-MM)
if (isset($_GET['month']) && preg_match('/^\d{4}-\d{2}$/', $_GET['month'])) {
    $calendarMonth = $_GET['month'];
} else {
    $calendarMonth = date('Y-m', strtotime($selectedDate));
}

// Навигация по месяцам календаря
$calTs = strtotime($calendarMonth . '-01');
$prevMonth = date('Y-m', strtotime('-1 month', $calTs));
$nextMonth = date('Y-m', strtotime('+1 month', $calTs));
$monthNameRu = [
    1 => 'Январь', 2 => 'Февраль', 3 => 'Март', 4 => 'Апрель', 5 => 'Май', 6 => 'Июнь',
    7 => 'Июль', 8 => 'Август', 9 => 'Сентябрь', 10 => 'Октябрь', 11 => 'Ноябрь', 12 => 'Декабрь'
][(int)date('n', $calTs)] . ' ' . date('Y', $calTs);

// Вспомогательная функция формирования URL
function buildUrl(array $overrides = []): string {
    $params = array_merge($_GET, $overrides);
    unset(
        $params['toggle_todo'],
        $params['toggle_custom'],
        $params['delete_custom'],
        $params['delete_default'],
        $params['toggle_theme'],
        $params['refresh']
    );
    // Удаляем пустые
    foreach ($params as $k => $v) {
        if ($v === null) unset($params[$k]);
    }
    return '?' . http_build_query($params);
}

// Подготовка списка пар для отображения
$displayPairs = [];
if ($viewMode === 'day') {
    $displayPairs = $pairsByDate[$selectedDate] ?? [];
} else {
    $weekDays = getWeekDates($selectedDate);
    foreach ($weekDays as $wd) {
        if (!empty($pairsByDate[$wd])) {
            $displayPairs = array_merge($displayPairs, $pairsByDate[$wd]);
        }
    }
}

// Фильтр по типам пар
if ($typeFilter !== 'all') {
    $displayPairs = array_filter($displayPairs, function($p) use ($typeFilter) {
        $disc = parseDiscipline($p['дисциплина'] ?? '');
        return $disc['typeKey'] === $typeFilter;
    });
}

// Заметки на выбранный день (не привязанные к паре)
$dayGeneralNotes = array_filter(
    $todoState['customNotes'] ?? [],
    fn($n) => ($n['date'] ?? '') === $selectedDate && empty($n['pairKey'])
);

?>
<!DOCTYPE html>
<html lang="ru" class="<?= $theme === 'dark' ? 'dark' : '' ?>">
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
  <link rel="stylesheet" href="style.css?v=5.0">
</head>
<body class="bg-slate-100 dark:bg-slate-950 text-slate-900 dark:text-slate-100 min-h-screen pt-16 transition-colors duration-200">

  <!-- Фиксированная верхняя шапка -->
  <header class="app-header bg-white/95 dark:bg-slate-900/95 backdrop-blur-md shadow-sm">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-14 flex items-center justify-between gap-4">
      
      <div>
        <h1 class="text-base font-extrabold text-slate-900 dark:text-white leading-none tracking-tight">
          Расписание КГЭУ <span class="text-xs font-semibold text-indigo-500 font-mono">(Pure PHP)</span>
        </h1>
      </div>

      <div class="flex items-center gap-3">
        <!-- Кнопка обновить расписание через API -->
        <a 
          href="<?= buildUrl(['refresh' => 1]) ?>" 
          title="Синхронизировать с сайтом kabinet.kgeu.ru"
          class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl text-xs font-semibold bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-200 hover:bg-slate-200 dark:hover:bg-slate-700 transition"
        >
          <svg class="w-3.5 h-3.5 text-indigo-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
          </svg>
          <span class="hidden sm:inline">Обновить</span>
        </a>

        <!-- Переключатель темы (Dark / Light) -->
        <a 
          href="<?= buildUrl(['toggle_theme' => 1]) ?>" 
          title="Сменить тему" 
          class="p-2 rounded-xl text-slate-500 hover:text-slate-800 dark:text-slate-400 dark:hover:text-slate-100 bg-slate-100 dark:bg-slate-800 transition"
        >
          <?php if ($theme === 'dark'): ?>
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
          <?php else: ?>
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"/></svg>
          <?php endif; ?>
        </a>
      </div>

    </div>
  </header>

  <!-- Основной контент -->
  <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
    <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">
      
      <!-- ЛЕВАЯ КОЛОНКА: Календарь + Фильтры -->
      <aside class="lg:col-span-5 xl:col-span-4 space-y-5">
        
        <!-- Календарь -->
        <div class="bg-white dark:bg-slate-900 rounded-3xl p-5 shadow-sm">
          
          <div class="flex items-center justify-between mb-4">
            <h3 class="font-bold text-sm text-slate-900 dark:text-white capitalize">
              <?= htmlspecialchars($monthNameRu) ?>
            </h3>
            
            <div class="flex items-center gap-1">
              <a href="<?= buildUrl(['date' => $realToday, 'month' => date('Y-m', strtotime($realToday))]) ?>" class="px-2.5 py-1 text-[11px] font-semibold rounded-lg text-indigo-600 dark:text-indigo-400 bg-indigo-50 dark:bg-indigo-950/60 hover:bg-indigo-100 dark:hover:bg-indigo-900/60 transition">
                Сегодня
              </a>
              <a href="<?= buildUrl(['month' => $prevMonth]) ?>" title="Предыдущий месяц" class="p-1 rounded-lg text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800 transition">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
              </a>
              <a href="<?= buildUrl(['month' => $nextMonth]) ?>" title="Следующий месяц" class="p-1 rounded-lg text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800 transition">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
              </a>
            </div>
          </div>

          <!-- Дни недели -->
          <div class="grid grid-cols-7 gap-1 text-center text-[11px] font-bold text-slate-400 mb-2">
            <span>Пн</span><span>Вт</span><span>Ср</span><span>Чт</span><span>Пт</span><span class="text-rose-400">Сб</span><span class="text-rose-400">Вс</span>
          </div>

          <!-- Сетка чисел месяца на чистом PHP -->
          <div class="grid grid-cols-7 gap-1">
            <?php
              $firstDayTs = strtotime($calendarMonth . '-01');
              $daysInMonth = (int)date('t', $firstDayTs);
              $startDayOfWeek = (int)date('N', $firstDayTs); // 1 (Пн) - 7 (Вс)
              $weekDatesSelected = ($viewMode === 'week') ? getWeekDates($selectedDate) : [];

              // Пустые ячейки до начала месяца
              for ($empty = 1; $empty < $startDayOfWeek; $empty++):
            ?>
              <div class="aspect-square"></div>
            <?php endfor; ?>

            <?php for ($d = 1; $d <= $daysInMonth; $d++): 
                $dayStr = sprintf('%s-%02d', $calendarMonth, $d);
                $isSelected = ($dayStr === $selectedDate);
                $isWeekHigh = ($viewMode === 'week' && in_array($dayStr, $weekDatesSelected, true));
                $isToday = ($dayStr === $realToday);
                $dayPairs = $pairsByDate[$dayStr] ?? [];

                $typesSet = [];
                foreach ($dayPairs as $dp) {
                    $pDisc = parseDiscipline($dp['дисциплина'] ?? '');
                    $typesSet[$pDisc['typeKey']] = true;
                }

                $bgClass = 'hover:bg-slate-100 dark:hover:bg-slate-800 text-slate-700 dark:text-slate-300';
                if ($isSelected) {
                    $bgClass = 'bg-indigo-600 text-white font-bold shadow-md shadow-indigo-500/30';
                } elseif ($isWeekHigh) {
                    $bgClass = 'bg-indigo-50 dark:bg-indigo-950/40 text-indigo-700 dark:text-indigo-300 font-semibold';
                }
                $todayRing = ($isToday && !$isSelected) ? 'ring-2 ring-indigo-500 ring-offset-1 dark:ring-offset-slate-900 font-bold' : '';
            ?>
              <a 
                href="<?= buildUrl(['date' => $dayStr, 'view' => 'day']) ?>"
                class="aspect-square rounded-xl p-1 flex flex-col items-center justify-center text-xs transition relative <?= $bgClass ?> <?= $todayRing ?>"
              >
                <span><?= $d ?></span>
                <?php if (!empty($typesSet)): ?>
                  <div class="flex items-center justify-center gap-0.5 mt-0.5">
                    <?php if (isset($typesSet['lecture'])): ?><span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span><?php endif; ?>
                    <?php if (isset($typesSet['lab'])): ?><span class="w-1.5 h-1.5 rounded-full bg-purple-500"></span><?php endif; ?>
                    <?php if (isset($typesSet['practice'])): ?><span class="w-1.5 h-1.5 rounded-full bg-amber-500"></span><?php endif; ?>
                    <?php if (isset($typesSet['exam'])): ?><span class="w-1.5 h-1.5 rounded-full bg-rose-500"></span><?php endif; ?>
                  </div>
                <?php endif; ?>
              </a>
            <?php endfor; ?>
          </div>

          <!-- Кнопка переключения День / Неделя -->
          <div class="mt-4 pt-3">
            <a 
              href="<?= buildUrl(['view' => ($viewMode === 'week' ? 'day' : 'week')]) ?>"
              class="w-full py-2 px-3 rounded-xl text-xs font-bold transition flex items-center justify-center gap-2 <?= $viewMode === 'week' ? 'bg-indigo-600 text-white' : 'bg-indigo-50 dark:bg-indigo-950/50 text-indigo-600 dark:text-indigo-400 hover:bg-indigo-100' ?>"
            >
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
              <span><?= $viewMode === 'week' ? 'Показать один день' : 'Показать всю неделю' ?></span>
            </a>
          </div>

        </div>

      </aside>

      <!-- ПРАВАЯ КОЛОНКА: Расписание занятий и Заметки -->
      <section class="lg:col-span-7 xl:col-span-8 space-y-4">
        
        <!-- Заголовок дня и кнопки действий -->
        <div class="bg-white dark:bg-slate-900 rounded-3xl p-5 shadow-sm flex flex-col sm:flex-row sm:items-center justify-between gap-3">
          <h2 class="text-lg sm:text-xl font-extrabold text-slate-900 dark:text-white capitalize leading-tight">
            <?php if ($viewMode === 'day'): ?>
              <?= htmlspecialchars(formatCustomDateTitle($selectedDate)) ?>
            <?php else: 
                $wd = getWeekDates($selectedDate);
            ?>
              Вся неделя — <?= date('d.m', strtotime($wd[0])) ?> – <?= date('d.m.Y', strtotime($wd[6])) ?>
            <?php endif; ?>
          </h2>

          <div class="flex items-center gap-2 shrink-0">
            <!-- Отдельная всплывающая плашка с фильтрами -->
            <details class="relative">
              <summary class="cursor-pointer list-none inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl text-xs font-semibold bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-200 hover:bg-slate-200 dark:hover:bg-slate-700 transition select-none">
                <svg class="w-3.5 h-3.5 text-indigo-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"/></svg>
                <span>
                  <?php
                    $filterLabels = [
                      'all' => 'Фильтры',
                      'lecture' => 'Лекции',
                      'lab' => 'Лабораторные',
                      'practice' => 'Практики'
                    ];
                    echo $filterLabels[$typeFilter] ?? 'Фильтры';
                  ?>
                </span>
                <svg class="w-3 h-3 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
              </summary>

              <!-- Плашка с выбором типов -->
              <div class="absolute right-0 z-30 mt-2 w-52 origin-top-right rounded-2xl bg-white dark:bg-slate-900 p-2 shadow-2xl border border-slate-100 dark:border-slate-800">
                <div class="text-[10px] font-bold uppercase tracking-wider text-slate-400 px-3 py-1.5">
                  Тип занятий
                </div>
                <div class="space-y-1">
                  <a href="<?= buildUrl(['type' => 'all']) ?>" class="flex items-center justify-between px-3 py-2 rounded-xl text-xs font-semibold transition <?= $typeFilter === 'all' ? 'bg-indigo-600 text-white' : 'text-slate-700 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800' ?>">
                    <span>Все типы</span>
                    <?php if ($typeFilter === 'all'): ?><span>✓</span><?php endif; ?>
                  </a>
                  <a href="<?= buildUrl(['type' => 'lecture']) ?>" class="flex items-center justify-between px-3 py-2 rounded-xl text-xs font-semibold transition <?= $typeFilter === 'lecture' ? 'bg-indigo-600 text-white' : 'text-slate-700 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800' ?>">
                    <span class="flex items-center gap-2"><span class="w-2 h-2 rounded-full bg-emerald-500"></span><span>Лекции</span></span>
                    <?php if ($typeFilter === 'lecture'): ?><span>✓</span><?php endif; ?>
                  </a>
                  <a href="<?= buildUrl(['type' => 'lab']) ?>" class="flex items-center justify-between px-3 py-2 rounded-xl text-xs font-semibold transition <?= $typeFilter === 'lab' ? 'bg-indigo-600 text-white' : 'text-slate-700 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800' ?>">
                    <span class="flex items-center gap-2"><span class="w-2 h-2 rounded-full bg-purple-500"></span><span>Лабораторные</span></span>
                    <?php if ($typeFilter === 'lab'): ?><span>✓</span><?php endif; ?>
                  </a>
                  <a href="<?= buildUrl(['type' => 'practice']) ?>" class="flex items-center justify-between px-3 py-2 rounded-xl text-xs font-semibold transition <?= $typeFilter === 'practice' ? 'bg-indigo-600 text-white' : 'text-slate-700 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800' ?>">
                    <span class="flex items-center gap-2"><span class="w-2 h-2 rounded-full bg-amber-500"></span><span>Практики</span></span>
                    <?php if ($typeFilter === 'practice'): ?><span>✓</span><?php endif; ?>
                  </a>
                </div>
              </div>
            </details>

            <!-- Кнопка "Заметка на день" (ровно один плюс из иконки) -->
            <a 
              href="#add-day-note" 
              class="shrink-0 inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl text-xs font-semibold bg-indigo-50 dark:bg-indigo-950/60 text-indigo-600 dark:text-indigo-400 hover:bg-indigo-100 dark:hover:bg-indigo-900/60 transition"
            >
              <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
              <span>Заметка на день</span>
            </a>
          </div>
        </div>

        <!-- Блок заметок на этот день -->
        <div class="bg-white dark:bg-slate-900 rounded-2xl p-4 shadow-sm border border-indigo-100/60 dark:border-indigo-950/40">
          <div class="flex items-center justify-between mb-2 pb-2 border-b border-slate-100 dark:border-slate-800">
            <span class="text-xs font-bold text-slate-800 dark:text-slate-200 flex items-center gap-2">
              <span class="w-2 h-2 rounded-full bg-indigo-500"></span>
              <span>Заметки и задачи на день (<?= count($dayGeneralNotes) ?>)</span>
            </span>
          </div>

          <?php if (!empty($dayGeneralNotes)): ?>
            <div class="space-y-1 mb-3">
              <?php foreach ($dayGeneralNotes as $dn): 
                  $isDone = !empty($dn['completed']);
                  $isEditingThis = ($editNoteId === ($dn['id'] ?? ''));
              ?>
                <?php if ($isEditingThis): ?>
                  <!-- Инлайн редактирование заметки дня -->
                  <form method="POST" class="flex items-center gap-2 py-1">
                    <input type="hidden" name="action" value="edit_note">
                    <input type="hidden" name="id" value="<?= htmlspecialchars($dn['id']) ?>">
                    <input type="hidden" name="type" value="custom">
                    <input type="text" name="text" value="<?= htmlspecialchars($dn['text'] ?? '') ?>" required class="flex-1 px-3 py-1 text-xs rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800">
                    <button type="submit" class="px-2.5 py-1 text-xs bg-indigo-600 text-white rounded-lg">Сохранить</button>
                    <a href="<?= buildUrl(['edit_note' => null]) ?>" class="px-2 py-1 text-xs text-slate-400">Отмена</a>
                  </form>
                <?php else: ?>
                  <div class="flex items-center justify-between group py-1 px-2 rounded-xl hover:bg-slate-50 dark:hover:bg-slate-800/60 transition">
                    <a href="<?= buildUrl(['toggle_custom' => $dn['id']]) ?>" class="flex items-center gap-2 flex-1 min-w-0 pr-2">
                      <span class="w-4 h-4 rounded border flex items-center justify-center text-xs <?= $isDone ? 'bg-indigo-600 border-indigo-600 text-white' : 'border-slate-300 dark:border-slate-700' ?>">
                        <?= $isDone ? '✓' : '' ?>
                      </span>
                      <span class="text-xs font-medium truncate <?= $isDone ? 'line-through text-slate-400 dark:text-slate-500' : 'text-slate-800 dark:text-slate-200' ?>">
                        <?= htmlspecialchars($dn['text'] ?? '') ?>
                      </span>
                    </a>
                    <div class="flex items-center gap-1 opacity-80 group-hover:opacity-100 transition">
                      <a href="<?= buildUrl(['edit_note' => $dn['id']]) ?>" title="Редактировать" class="p-1 text-slate-400 hover:text-indigo-600">✏️</a>
                      <a href="<?= buildUrl(['delete_custom' => $dn['id']]) ?>" title="Удалить" class="p-1 text-slate-400 hover:text-rose-600">🗑️</a>
                    </div>
                  </div>
                <?php endif; ?>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>

          <!-- Форма добавления заметки на день -->
          <form id="add-day-note" method="POST" class="flex items-center gap-2 pt-1">
            <input type="hidden" name="action" value="add_note">
            <input type="hidden" name="date" value="<?= htmlspecialchars($selectedDate) ?>">
            <input type="hidden" name="pair_key" value="">
            <input 
              type="text" 
              name="text" 
              required 
              placeholder="Новая заметка на день..." 
              class="flex-1 px-3 py-1.5 rounded-xl border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 text-xs focus:outline-none focus:ring-1 focus:ring-indigo-500"
            >
            <button type="submit" class="px-3 py-1.5 text-xs font-semibold bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl transition">
              Добавить
            </button>
          </form>
        </div>

        <!-- Контейнер карточек расписания -->
        <div class="space-y-3.5">
          <?php if (empty($displayPairs)): ?>
            <div class="text-center py-16 px-6 bg-white dark:bg-slate-900 rounded-3xl shadow-sm">
              <div class="w-14 h-14 mx-auto mb-3 bg-indigo-50 dark:bg-indigo-950/40 rounded-2xl flex items-center justify-center text-indigo-500">
                <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 12H4M12 20V4"/></svg>
              </div>
              <h3 class="text-base font-bold text-slate-800 dark:text-slate-100 mb-1">
                <?= $viewMode === 'day' ? 'В этот день пар нет' : 'Занятий по выбранным фильтрам не найдено' ?>
              </h3>
              <p class="text-xs text-slate-400">Свободный день или выходной</p>
            </div>
          <?php else: ?>
            <?php 
              // Сортируем пары по дате и времени начала
              usort($displayPairs, function($a, $b) {
                  $cmpD = strcmp($a['дата'] ?? '', $b['дата'] ?? '');
                  if ($cmpD !== 0) return $cmpD;
                  return strcmp($a['начало'] ?? '', $b['начало'] ?? '');
              });

              $lastRenderedDate = null;
              foreach ($displayPairs as $item):
                  $disc = parseDiscipline($item['дисциплина'] ?? '');
                  $itemDate = explode('T', $item['дата'] ?? '')[0];
                  $pairKey = getPairKey($item);
                  $cleanRoom = trim((string)($item['аудитория'] ?? '—'));
                  $teacher = $item['преподаватель'] ?? ($item['фиоПреподавателя'] ?? '—');
                  $post = !empty($item['должность']) ? "({$item['должность']})" : '';

                  // Проверяем текущее время (живая пара)
                  $isCurrent = false;
                  if ($itemDate === $realToday && !empty($item['датаНачала']) && !empty($item['датаОкончания'])) {
                      $nowTs = time();
                      $sTs = strtotime($item['датаНачала']);
                      $eTs = strtotime($item['датаОкончания']);
                      if ($nowTs >= $sTs && $nowTs <= $eTs) $isCurrent = true;
                  }

                  // Автоматическая лаба
                  $labInfo = ($disc['typeKey'] === 'lab') 
                      ? getLabInfo($disc['title'], $itemDate, $subjectLabTimeline, $todoState) 
                      : null;
                  $isDefaultLabDeleted = $labInfo && in_array($labInfo['todoKey'], $todoState['deletedDefaults'], true);

                  // Кастомные задачи для этой конкретной пары
                  $pairTasks = array_filter(
                      $todoState['customNotes'] ?? [],
                      fn($n) => ($n['pairKey'] ?? '') === $pairKey
                  );
            ?>

              <?php if ($viewMode === 'week' && $lastRenderedDate !== $itemDate): 
                  $lastRenderedDate = $itemDate;
              ?>
                <div class="pt-4 pb-1">
                  <span class="font-extrabold text-base text-slate-900 dark:text-white">
                    <?= htmlspecialchars(formatCustomDateTitle($itemDate)) ?>
                  </span>
                </div>
              <?php endif; ?>

              <article class="schedule-card relative bg-white dark:bg-slate-900 rounded-2xl p-4 sm:p-5 shadow-sm flex flex-col justify-between overflow-hidden <?= $isCurrent ? 'ring-2 ring-emerald-500' : '' ?>">
                <!-- Цветная полоска слева -->
                <div class="absolute left-0 top-0 bottom-0 w-1.5 <?= 
                  $disc['typeKey'] === 'lecture' ? 'bg-emerald-500' :
                  ($disc['typeKey'] === 'lab' ? 'bg-purple-500' :
                  ($disc['typeKey'] === 'practice' ? 'bg-amber-500' : 'bg-indigo-500'))
                ?>"></div>

                <div>
                  <!-- Верхняя строчка -->
                  <div class="flex items-center justify-between gap-2 mb-2 pl-2">
                    <div class="flex items-center gap-2">
                      <span class="inline-flex items-center justify-center w-6 h-6 rounded-lg bg-slate-100 dark:bg-slate-800 text-xs font-bold text-slate-700 dark:text-slate-200">
                        <?= htmlspecialchars((string)($item['номерЗанятия'] ?? '—')) ?>
                      </span>
                      <span class="font-mono text-xs sm:text-sm font-semibold text-slate-800 dark:text-slate-200">
                        <?= htmlspecialchars($item['начало'] ?? '') ?> – <?= htmlspecialchars($item['конец'] ?? '') ?>
                      </span>
                      <?php if ($isCurrent): ?>
                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-bold bg-emerald-500/15 text-emerald-600 dark:text-emerald-400">
                          <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-ping"></span>
                          Сейчас
                        </span>
                      <?php endif; ?>
                    </div>

                    <div class="flex items-center gap-1.5">
                      <?php if (!empty($item['номерПодгруппы'])): ?>
                        <span class="px-2 py-0.5 rounded-md text-[11px] font-medium bg-blue-500/10 text-blue-600 dark:text-blue-400">
                          <?= $item['номерПодгруппы'] ?> п/г
                        </span>
                      <?php endif; ?>
                      <span class="px-2.5 py-0.5 rounded-md text-[11px] font-bold <?= $disc['badgeClass'] ?>">
                        <?= $disc['type'] ?>
                      </span>
                    </div>
                  </div>

                  <!-- Название предмета -->
                  <h4 class="text-sm sm:text-base font-bold text-slate-900 dark:text-white leading-snug pl-2">
                    <?= htmlspecialchars($disc['title']) ?>
                  </h4>
                </div>

                <!-- Преподаватель и Аудитория -->
                <div class="pt-3 mt-3 border-t border-slate-100/70 dark:border-slate-800/60 pl-2 flex items-center justify-between text-xs text-slate-600 dark:text-slate-300">
                  <div class="flex items-center gap-1.5 truncate pr-3">
                    <div class="w-5 h-5 rounded-full bg-indigo-50 dark:bg-indigo-950/60 text-indigo-600 dark:text-indigo-400 flex items-center justify-center shrink-0 text-[10px] font-bold">
                      👤
                    </div>
                    <span class="font-medium text-slate-900 dark:text-slate-200 truncate">
                      <?= htmlspecialchars($teacher) ?>
                    </span>
                    <?php if ($post): ?><span class="opacity-60 text-[11px]"><?= htmlspecialchars($post) ?></span><?php endif; ?>
                  </div>

                  <div class="shrink-0 font-mono font-bold text-slate-800 dark:text-slate-200 bg-slate-100 dark:bg-slate-800 px-2.5 py-1 rounded-lg text-xs">
                    <?= htmlspecialchars($cleanRoom) ?>
                  </div>
                </div>

                <!-- БЛОК ЗАДАЧ И ЛАБОРАТОРНЫХ -->
                <div class="mt-3 pt-2.5 border-t border-slate-100/70 dark:border-slate-800/60 pl-2">
                  <div class="flex items-center justify-between mb-1.5">
                    <span class="text-[11px] font-bold text-slate-400 uppercase tracking-wider">
                      Задачи и лабы
                    </span>
                  </div>

                  <div class="space-y-1">
                    <!-- Автоматическая лаба по хронологии -->
                    <?php if ($labInfo && !$isDefaultLabDeleted): 
                        $isLabDone = $labInfo['isChecked'];
                        $isEditingLab = ($editDefaultKey === $labInfo['todoKey']);
                    ?>
                      <?php if ($isEditingLab): ?>
                        <form method="POST" class="flex items-center gap-2 py-1">
                          <input type="hidden" name="action" value="edit_note">
                          <input type="hidden" name="id" value="<?= htmlspecialchars($labInfo['todoKey']) ?>">
                          <input type="hidden" name="type" value="default_lab">
                          <input type="text" name="text" value="<?= htmlspecialchars($labInfo['label']) ?>" required class="flex-1 px-3 py-1 text-xs rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800">
                          <button type="submit" class="px-2.5 py-1 text-xs bg-indigo-600 text-white rounded-lg">Сохранить</button>
                          <a href="<?= buildUrl(['edit_default' => null]) ?>" class="px-2 py-1 text-xs text-slate-400">Отмена</a>
                        </form>
                      <?php else: ?>
                        <div class="flex items-center justify-between group py-1 px-2 rounded-xl hover:bg-slate-50 dark:hover:bg-slate-800/60 transition">
                          <a href="<?= buildUrl(['toggle_todo' => $labInfo['todoKey']]) ?>" class="flex items-center gap-2.5 flex-1 min-w-0 pr-2">
                            <span class="w-4 h-4 rounded border flex items-center justify-center text-xs <?= $isLabDone ? 'bg-indigo-600 border-indigo-600 text-white' : 'border-slate-300 dark:border-slate-700' ?>">
                              <?= $isLabDone ? '✓' : '' ?>
                            </span>
                            <span class="text-xs font-semibold truncate <?= $isLabDone ? 'line-through text-slate-400 dark:text-slate-500' : 'text-slate-700 dark:text-slate-200' ?>">
                              <?= htmlspecialchars($labInfo['label']) ?>
                            </span>
                          </a>
                          
                          <div class="flex items-center gap-1 shrink-0">
                            <span class="text-[10px] font-bold px-1.5 py-0.5 rounded <?= $isLabDone ? 'bg-emerald-500/10 text-emerald-600' : 'bg-purple-500/10 text-purple-600' ?>">
                              <?= $isLabDone ? 'Сдано ✓' : 'К сдаче' ?>
                            </span>
                            <a href="<?= buildUrl(['edit_default' => $labInfo['todoKey']]) ?>" title="Редактировать" class="p-1 text-slate-400 hover:text-indigo-600">✏️</a>
                            <a href="<?= buildUrl(['delete_default' => $labInfo['todoKey']]) ?>" title="Удалить" class="p-1 text-slate-400 hover:text-rose-600">🗑️</a>
                          </div>
                        </div>
                      <?php endif; ?>
                    <?php endif; ?>

                    <!-- Добавленные пользователем задачи для этой пары -->
                    <?php foreach ($pairTasks as $pt): 
                        $isPtDone = !empty($pt['completed']);
                        $isEditingPt = ($editNoteId === ($pt['id'] ?? ''));
                    ?>
                      <?php if ($isEditingPt): ?>
                        <form method="POST" class="flex items-center gap-2 py-1">
                          <input type="hidden" name="action" value="edit_note">
                          <input type="hidden" name="id" value="<?= htmlspecialchars($pt['id']) ?>">
                          <input type="hidden" name="type" value="custom">
                          <input type="text" name="text" value="<?= htmlspecialchars($pt['text'] ?? '') ?>" required class="flex-1 px-3 py-1 text-xs rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800">
                          <button type="submit" class="px-2.5 py-1 text-xs bg-indigo-600 text-white rounded-lg">Сохранить</button>
                          <a href="<?= buildUrl(['edit_note' => null]) ?>" class="px-2 py-1 text-xs text-slate-400">Отмена</a>
                        </form>
                      <?php else: ?>
                        <div class="flex items-center justify-between group py-1 px-2 rounded-xl hover:bg-slate-50 dark:hover:bg-slate-800/60 transition">
                          <a href="<?= buildUrl(['toggle_custom' => $pt['id']]) ?>" class="flex items-center gap-2.5 flex-1 min-w-0 pr-2">
                            <span class="w-4 h-4 rounded border flex items-center justify-center text-xs <?= $isPtDone ? 'bg-indigo-600 border-indigo-600 text-white' : 'border-slate-300 dark:border-slate-700' ?>">
                              <?= $isPtDone ? '✓' : '' ?>
                            </span>
                            <span class="text-xs font-semibold truncate <?= $isPtDone ? 'line-through text-slate-400 dark:text-slate-500' : 'text-slate-700 dark:text-slate-200' ?>">
                              <?= htmlspecialchars($pt['text'] ?? '') ?>
                            </span>
                          </a>
                          <div class="flex items-center gap-1 shrink-0">
                            <a href="<?= buildUrl(['edit_note' => $pt['id']]) ?>" title="Редактировать" class="p-1 text-slate-400 hover:text-indigo-600">✏️</a>
                            <a href="<?= buildUrl(['delete_custom' => $pt['id']]) ?>" title="Удалить" class="p-1 text-slate-400 hover:text-rose-600">🗑️</a>
                          </div>
                        </div>
                      <?php endif; ?>
                    <?php endforeach; ?>
                  </div>

                  <!-- Форма быстрого добавления задачи к этой паре -->
                  <form method="POST" class="mt-2 flex items-center gap-2">
                    <input type="hidden" name="action" value="add_note">
                    <input type="hidden" name="pair_key" value="<?= htmlspecialchars($pairKey) ?>">
                    <input type="hidden" name="date" value="<?= htmlspecialchars($itemDate) ?>">
                    <input 
                      type="text" 
                      name="text" 
                      required 
                      placeholder="+ Добавить задачу или тему отчета..." 
                      class="flex-1 px-3 py-1 rounded-xl border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 text-xs focus:outline-none focus:ring-1 focus:ring-indigo-500"
                    >
                    <button type="submit" class="px-2.5 py-1 text-xs font-semibold bg-indigo-50 dark:bg-indigo-950/60 text-indigo-600 dark:text-indigo-400 hover:bg-indigo-100 rounded-xl transition">
                      +
                    </button>
                  </form>
                </div>

              </article>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>

      </section>

    </div>
  </main>

</body>
</html>
