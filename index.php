<?php
// Скрываем ошибки
error_reporting(0);
ini_set('display_errors', 0);
set_time_limit(0);

$ACCESS_KEY = getenv("PROXY_KEY") ?: "TvZaTak"; 

if (!isset($_GET['key']) || $_GET['key'] !== $ACCESS_KEY) {
    header('HTTP/1.1 403 Forbidden');
    die("Access Denied");
}

$target_url = $_GET['url'] ?? '';
if (empty($target_url)) die("No URL");

$scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? "https" : "http";
$proxy_self = $scheme . "://" . $_SERVER['HTTP_HOST'] . $_SERVER['PHP_SELF'] . "?key=" . urlencode($ACCESS_KEY) . "&url=";

// ОПРЕДЕЛЯЕМ ТИП КОНТЕНТА
$is_m3u8 = (strpos($target_url, 'm3u8') !== false);

if ($is_m3u8) {
    // 1. ОБРАБОТКА ПЛЕЙЛИСТА (Текст хорошо сжимается)
    if (!ob_start("ob_gzhandler")) ob_start(); // Включаем Gzip сжатие для текста

    $ch = curl_init($target_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
    $content = curl_exec($ch);
    curl_close($ch);

    $base_url = substr($target_url, 0, strrpos($target_url, '/') + 1);
    $lines = explode("\n", $content);
    $new_content = [];

    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line)) continue;
        if (strpos($line, '#') === 0) {
            $new_content[] = $line;
        } else {
            $full_url = (strpos($line, 'http') === 0) ? $line : $base_url . $line;
            $new_content[] = $proxy_self . urlencode($full_url);
        }
    }
    header('Content-Type: application/x-mpegURL');
    // Кэшируем плейлист на короткое время (60 сек), чтобы Cloudflare не частил с запросами
    header('Cache-Control: public, max-age=60'); 
    echo implode("\n", $new_content);
    ob_end_flush();

} else {
    // 2. ПОТОКОВОЕ ПРОКСИРОВАНИЕ ВИДЕО (.ts сегменты)
    header('Content-Type: video/mp2t');
    
    // ВАЖНО: Разрешаем Cloudflare кэшировать видео-сегменты на 24 часа.
    // Если 10 человек смотрят один канал, Render отдаст сегмент 1 раз, а Cloudflare — 10 раз.
    header('Cache-Control: public, max-age=86400'); 
    header_remove('Pragma');

    // Отключаем буферизацию PHP для мгновенного потока
    @ini_set('zlib.output_compression', 'Off');
    while (ob_get_level()) ob_end_clean();

    $ch = curl_init($target_url);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
    curl_setopt($ch, CURLOPT_BUFFERSIZE, 256000); 

    // Передаем данные чанками
    curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($ch, $data) {
        echo $data;
        flush(); 
        return strlen($data);
    });

    curl_exec($ch);
    curl_close($ch);
}
