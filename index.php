<?php
// Скрываем ошибки от пользователей
error_reporting(0);
ini_set('display_errors', 0);

// Тайм-аут выполнения скрипта (0 = бесконечно, важно для потока)
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

if (strpos($target_url, 'm3u8') !== false) {
    // ОБРАБОТКА ПЛЕЙЛИСТА
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
    echo implode("\n", $new_content);

} else {
    // ПОТОКОВОЕ ПРОКСИРОВАНИЕ ВИДЕО
    header('Content-Type: video/mp2t');
    header('Cache-Control: no-cache');
    
    // Отключаем буферизацию PHP
    if (function_exists('apache_setenv')) {
        @apache_setenv('no-gz', 1);
    }
    @ini_set('zlib.output_compression', 'Off');
    @ob_end_clean();

    $ch = curl_init($target_url);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
    curl_setopt($ch, CURLOPT_BUFFERSIZE, 128000); // Оптимальный буфер для видео

    // Передаем данные сразу пользователю
    curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($ch, $data) {
        echo $data;
        @flush(); // Принудительно отправляем в сеть
        return strlen($data);
    });

    curl_exec($ch);
    curl_close($ch);
}
