<?php
/**
 * Локальный сервер для расписания КГЭУ на чистом PHP (без зависимостей).
 * Запуск:
 *   php server.php
 * или:
 *   php -S localhost:8000 server.php
 */

declare(strict_types=1);

// Если файл запущен напрямую из консоли через `php server.php`
if (php_sapi_name() === 'cli') {
    $host = getenv('HOST') ?: (PHP_OS_FAMILY === 'Darwin' ? 'localhost' : '0.0.0.0');
    $port = (int)(getenv('PORT') ?: 8000);
    $url = "http://{$host}:{$port}";

    echo "\033[1;32m========================================================\033[0m\n";
    echo "\033[1;36m🎓 Сервер расписания КГЭУ запущен: {$url}\033[0m\n";
    echo "⚡ Кэширование отключено (no-cache для мгновенных правок)\n";
    echo "🔄 Проксирование запросов к kabinet.kgeu.ru активно\n";
    echo "📚 Подгрузка полного семестра включена (/api/full-schedule)\n";
    echo "❌ Для остановки нажмите Ctrl + C\n";
    echo "\033[1;32m========================================================\033[0m\n\n";

    // Автоматическое открытие в браузере
    if (PHP_OS_FAMILY === 'Darwin') {
        @exec("open " . escapeshellarg($url) . " > /dev/null 2>&1 &");
    } elseif (PHP_OS_FAMILY === 'Windows') {
        @exec("start " . escapeshellarg($url));
    } elseif (PHP_OS_FAMILY === 'Linux') {
        @exec("xdg-open " . escapeshellarg($url) . " > /dev/null 2>&1 &");
    }

    passthru(PHP_BINARY . " -S {$host}:{$port} " . escapeshellarg(__FILE__));
    exit;
}

// -------------------------------------------------------------
// Режим HTTP-роутера (встроенный сервер `cli-server`)
// -------------------------------------------------------------

$uri = $_SERVER['REQUEST_URI'] ?? '/';
$parsedUrl = parse_url($uri);
$path = $parsedUrl['path'] ?? '/';
$queryString = $parsedUrl['query'] ?? '';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Базовые заголовки CORS и запрета кэширования
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

$baseDir = __DIR__;
$dataDir = $baseDir . '/data';
if (!is_dir($dataDir)) {
    @mkdir($dataDir, 0755, true);
}
$todoFile = $dataDir . '/todo_state.json';
$kgeuApiHost = 'https://kabinet.kgeu.ru';

// -------------------------------------------------------------
// 1. Эндпоинты TODO / Заметок (/api/todo, /api/todo-state)
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
                // Если файл уже в расширенном формате:
                if (isset($parsed['checkboxes']) || isset($parsed['customNotes'])) {
                    $state['checkboxes'] = $parsed['checkboxes'] ?? [];
                    $state['customNotes'] = $parsed['customNotes'] ?? $parsed['custom_notes'] ?? [];
                    $state['editedDefaults'] = $parsed['editedDefaults'] ?? $parsed['edited_defaults'] ?? [];
                    $state['deletedDefaults'] = $parsed['deletedDefaults'] ?? $parsed['deleted_defaults'] ?? [];
                } else {
                    // Обратная совместимость: если файл плоский { "key": bool }
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
            echo json_encode(['error' => 'Invalid JSON body'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // Загружаем текущее состояние
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
                if (isset($parsed['checkboxes']) || isset($parsed['customNotes'])) {
                    $current['checkboxes'] = $parsed['checkboxes'] ?? [];
                    $current['customNotes'] = $parsed['customNotes'] ?? $parsed['custom_notes'] ?? [];
                    $current['editedDefaults'] = $parsed['editedDefaults'] ?? $parsed['edited_defaults'] ?? [];
                    $current['deletedDefaults'] = $parsed['deletedDefaults'] ?? $parsed['deleted_defaults'] ?? [];
                } else {
                    foreach ($parsed as $k => $v) {
                        $current['checkboxes'][$k] = (bool)$v;
                    }
                }
            }
        }

        // Обновляем структуру
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

        // Если прилетел старый плоский формат { "ключ": bool }
        foreach ($input as $k => $v) {
            if (!in_array($k, ['checkboxes', 'customNotes', 'custom_notes', 'editedDefaults', 'edited_defaults', 'deletedDefaults', 'deleted_defaults'])) {
                $current['checkboxes'][$k] = (bool)$v;
            }
        }

        file_put_contents(
            $todoFile,
            json_encode($current, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        echo json_encode(['status' => 'ok']);
        exit;
    }

    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// -------------------------------------------------------------
// 2. Полное расписание на весь семестр (/api/full-schedule)
// -------------------------------------------------------------
if (
    $path === '/api/full-schedule' ||
    $path === '/api/schedule/full' ||
    $path === '/api/schedule/semester'
) {
    header("Content-Type: application/json; charset=utf-8");

    parse_str($queryString, $queryParams);
    $idGroup = (int)($queryParams['idGroup'] ?? 14493);
    $forceRefresh = isset($queryParams['refresh']) && ($queryParams['refresh'] === '1' || $queryParams['refresh'] === 'true');
    $semesterCacheFile = $dataDir . "/schedule_semester_{$idGroup}.json";

    // Если есть свежий кэш (до 24 ч) и не запрошено принудительное обновление
    if (!$forceRefresh && file_exists($semesterCacheFile) && (time() - filemtime($semesterCacheFile) < 86400)) {
        $cached = file_get_contents($semesterCacheFile);
        if ($cached !== false && strlen($cached) > 200) {
            echo $cached;
            exit;
        }
    }

    // Собираем расписание за все недели семестра
    try {
        $datesUrl = "{$kgeuApiHost}/api/GetRaspDates?idGroup={$idGroup}";
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $datesUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ["User-Agent: Mozilla/5.0", "Accept: application/json"]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 6);
        $datesResp = curl_exec($ch);
        $datesData = json_decode($datesResp ?: '', true)["data"]["dates"] ?? [];

        // Вычисляем все понедельники учебного семестра
        $mondays = [];
        foreach ($datesData as $d) {
            $ts = strtotime($d);
            $monday = date("Y-m-d", strtotime("monday this week", $ts));
            $mondays[$monday] = true;
        }
        $mondays = array_keys($mondays);
        sort($mondays);

        if (empty($mondays)) {
            // Резервный список понедельников осеннего семестра
            $mondays = [
                "2026-08-31", "2026-09-07", "2026-09-14", "2026-09-21", "2026-09-28",
                "2026-10-05", "2026-10-12", "2026-10-19", "2026-10-26", "2026-11-02",
                "2026-11-09", "2026-11-16", "2026-11-23", "2026-11-30", "2026-12-07",
                "2026-12-14", "2026-12-21", "2026-12-28"
            ];
        }

        // Параллельная загрузка через curl_multi
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
        echo json_encode(["error" => $e->getMessage(), "state" => 0], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// -------------------------------------------------------------
// 3. Стандартное проксирование API КГЭУ (/api/Rasp, /api/GetRaspDates)
// -------------------------------------------------------------
if (
    $path === '/api/Rasp' || $path === '/api/kgeu/Rasp' ||
    $path === '/api/GetRaspDates' || $path === '/api/kgeu/GetRaspDates'
) {
    header("Content-Type: application/json; charset=utf-8");

    $endpoint = str_contains($path, 'GetRaspDates') ? '/api/GetRaspDates' : '/api/Rasp';
    $targetUrl = $kgeuApiHost . $endpoint . ($queryString !== '' ? '?' . $queryString : '');

    $headers = [
        "User-Agent: Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36",
        "Accept: application/json",
        "Accept-Language: ru,en;q=0.9"
    ];

    if (function_exists('curl_init')) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $targetUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);

        if ($response === false) {
            http_response_code(500);
            echo json_encode(['error' => $curlError ?: 'cURL request failed', 'state' => 0], JSON_UNESCAPED_UNICODE);
            exit;
        }

        http_response_code($httpCode >= 200 && $httpCode < 400 ? $httpCode : 500);
        echo $response;
        exit;
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => implode("\r\n", $headers) . "\r\n",
            'timeout' => 10,
            'ignore_errors' => true
        ]
    ]);
    $response = @file_get_contents($targetUrl, false, $context);
    if ($response === false) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to reach KGEU API', 'state' => 0], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo $response;
    exit;
}

// -------------------------------------------------------------
// 4. Раздача статических файлов (HTML, CSS, JS, JSON и т.д.)
// -------------------------------------------------------------
$requestedPath = rawurldecode($path);
if ($requestedPath === '/' || $requestedPath === '') {
    $requestedPath = '/index.html';
}

$filePath = realpath($baseDir . $requestedPath);
$realBaseDir = realpath($baseDir);

// Защита от Directory Traversal: файл должен находиться строго внутри директории проекта
if ($filePath && $realBaseDir && str_starts_with($filePath, $realBaseDir) && is_file($filePath)) {
    $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
    $mimes = [
        'html' => 'text/html; charset=utf-8',
        'css'  => 'text/css; charset=utf-8',
        'js'   => 'application/javascript; charset=utf-8',
        'json' => 'application/json; charset=utf-8',
        'svg'  => 'image/svg+xml',
        'png'  => 'image/png',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'ico'  => 'image/x-icon',
        'woff' => 'font/woff',
        'woff2'=> 'font/woff2',
        'ttf'  => 'font/ttf',
        'ics'  => 'text/calendar; charset=utf-8',
    ];

    header("Content-Type: " . ($mimes[$ext] ?? 'application/octet-stream'));
    readfile($filePath);
    exit;
}

// 5. Если ничего не найдено
http_response_code(404);
header("Content-Type: text/plain; charset=utf-8");
echo "404 Not Found";
