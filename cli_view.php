<?php
/**
 * Консольный просмотрщик расписания КГЭУ на чистом PHP.
 * Запуск:
 *   php cli_view.php
 */

declare(strict_types=1);

$defaultIdGroup = 14493;
$defaultDate = "2026-10-09";
$apiUrl = "https://kabinet.kgeu.ru/api/Rasp?idGroup={$defaultIdGroup}&sdate={$defaultDate}";
$dataFile = __DIR__ . "/data/schedule_sample.json";

function cleanDiscipline(string $name): array {
    $prefixMap = [
        "лек" => ["Лекция", "\033[92m"],       // Зеленый
        "лаб" => ["Лабораторная", "\033[95m"], // Фиолетовый
        "пр"  => ["Практика", "\033[93m"],     // Желтый
        "зач" => ["Зачет", "\033[91m"],        // Красный
        "экз" => ["Экзамен", "\033[91m"]       // Красный
    ];

    foreach ($prefixMap as $prefix => [$typeName, $color]) {
        if (str_starts_with($name, $prefix . " ")) {
            return [$typeName, trim(substr($name, strlen($prefix) + 1)), $color];
        }
    }

    return ["Занятие", $name, "\033[94m"];
}

function loadData(string $apiUrl, string $dataFile): ?array {
    // Попытка загрузить напрямую с API КГЭУ
    if (function_exists('curl_init')) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $apiUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "User-Agent: Mozilla/5.0",
            "Accept: application/json"
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($resp !== false && $code === 200) {
            $json = json_decode($resp, true);
            if (is_array($json)) {
                return $json;
            }
        }
    }

    // Резервный вариант: локальный файл-сэмпл
    if (file_exists($dataFile)) {
        $content = file_get_contents($dataFile);
        if ($content !== false) {
            $json = json_decode($content, true);
            if (is_array($json)) {
                return $json;
            }
        }
    }

    return null;
}

$payload = loadData($apiUrl, $dataFile);
if (!$payload) {
    echo "Ошибка загрузки данных расписания.\n";
    exit(1);
}

$data = $payload["data"] ?? [];
$rasp = $data["rasp"] ?? [];
$info = $data["info"] ?? [];

$group = $info["group"]["name"] ?? "ПМ-1-24";
$year = $info["year"] ?? "2026-2027";
$week = $info["curWeekNumber"] ?? "?";
$numNed = ($info["curNumNed"] ?? 0) === 2 ? "Четная" : "Нечетная";

echo "\033[1m" . str_repeat("=", 65) . "\033[0m\n";
echo "\033[1;36m🎓 РАСПИСАНИЕ КГЭУ | Группа: {$group} | {$year} уч. год\033[0m\n";
echo "📅 Неделя {$week} ({$numNed}) | Всего пар в сетке: " . count($rasp) . "\n";
echo "\033[1m" . str_repeat("=", 65) . "\033[0m\n\n";

// Группировка по датам
$days = [];
foreach ($rasp as $item) {
    $dateKey = explode("T", $item["дата"] ?? "")[0];
    $days[$dateKey][] = $item;
}

foreach ($days as $dateStr => $items) {
    $dayName = $items[0]["день_недели"] ?? "";
    $formattedDate = $dateStr;
    try {
        $dt = new DateTime($dateStr);
        $formattedDate = $dt->format("d.m.Y");
    } catch (Exception $e) {
    }

    echo "\033[1;33m📌 " . mb_strtoupper($dayName) . " ({$formattedDate})\033[0m\n";
    echo str_repeat("-", 65) . "\n";

    usort($items, fn($a, $b) => strcmp($a["начало"] ?? "", $b["начало"] ?? ""));

    foreach ($items as $it) {
        $start = $it["начало"] ?? "??:??";
        $end = $it["конец"] ?? "??:??";
        $timeRange = "{$start} - {$end}";
        $num = $it["номерЗанятия"] ?? "-";

        [$typeName, $title, $color] = cleanDiscipline($it["дисциплина"] ?? "");
        $teacher = $it["преподаватель"] ?? ($it["фиоПреподавателя"] ?? "—");
        $post = !empty($it["должность"]) ? "({$it['должность']})" : "";
        $room = trim((string)($it["аудитория"] ?? "—"));

        $reset = "\033[0m";
        // Выравнивание типа занятия по центру 14 символов
        $typePadded = function_exists('mb_str_pad') 
            ? mb_str_pad($typeName, 14, " ", STR_PAD_BOTH)
            : str_pad($typeName, 14, " ", STR_PAD_BOTH);

        echo "  \033[1m[{$num} пара]\033[0m  {$timeRange}  {$color}[{$typePadded}]{$reset} \033[1m{$title}\033[0m\n";
        echo "           Аудитория: \033[36;1m{$room}\033[0m | Преподаватель: {$teacher} {$post}\n\n";
    }

    echo "\n";
}
