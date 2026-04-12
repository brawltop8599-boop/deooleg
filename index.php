<?php
$ACCESS_KEY = "TvZaTak"; 

if (!isset($_GET['key']) || $_GET['key'] !== $ACCESS_KEY) {
    header('HTTP/1.1 403 Forbidden');
    die("Error: Access Denied");
}

$target_url = $_GET['url'] ?? '';
if (empty($target_url)) die("Usage: index.php?key=12345&url=LINK");

// Определяем базовый URL для сборки ссылок внутри плейлиста
$scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? "https" : "http";
$proxy_self = $scheme . "://" . $_SERVER['HTTP_HOST'] . $_SERVER['PHP_SELF'] . "?key=" . urlencode($ACCESS_KEY) . "&url=";

if (strpos($target_url, 'm3u8') !== false) {
    // Обработка плейлиста (текст)
    $ch = curl_init($target_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0');
    $content = curl_exec($ch);
    curl_close($ch);

    $base_url = substr($target_url, 0, strrpos($target_url, '/') + 1);
    $lines = explode("\n", $content);
    $new_content = [];

    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line)) continue;
        if ($line[0] === '#') {
            $new_content[] = $line;
        } else {
            // Собираем полный URL и оборачиваем в прокси
            $full_url = (strpos($line, 'http') === 0) ? $line : $base_url . $line;
            $new_content[] = $proxy_self . urlencode($full_url);
        }
    }
    header('Content-Type: application/x-mpegURL');
    echo implode("\n", $new_content);

} else {
    // Обработка видео-чанка (бинарные данные) - ПОТОКОВЫЙ РЕЖИМ
    header('Content-Type: video/mp2t');
    
    $ch = curl_init($target_url);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0');
    
    // Функция обратного вызова для передачи данных по частям
    curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($ch, $data) {
        echo $data;
        return strlen($data);
    });

    curl_exec($ch);
    curl_close($ch);
}
