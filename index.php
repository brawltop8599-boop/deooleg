<?php
// Полное скрытие ошибок для стабильности потока
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

// Определяем расширение файла для правильных заголовков
$path_info = pathinfo(parse_url($target_url, PHP_URL_PATH));
$extension = strtolower($path_info['extension']);

if ($extension === 'm3u8' || strpos($target_url, 'm3u8') !== false) {
    // 1. ОБРАБОТКА ПЛЕЙЛИСТА
    if (!ob_start("ob_gzhandler")) ob_start(); 

    $ch = curl_init($target_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
    curl_setopt($ch, CURLOPT_TIMEOUT, 10); // Чтобы не висело, если источник упал
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
            // Исправляем путь к сегментам
            $full_url = (strpos($line, 'http') === 0) ? $line : $base_url . $line;
            $new_content[] = $proxy_self . urlencode($full_url);
        }
    }
    header('Content-Type: application/x-mpegURL');
    header('Cache-Control: public, max-age=5'); // Плейлисты обновляются часто
    echo implode("\n", $new_content);
    ob_end_flush();

} else {
    // 2. ПОТОКОВОЕ ПРОКСИРОВАНИЕ ВИДЕО (.ts, .mp4 и др.)
    // Устанавливаем тип контента в зависимости от расширения
    $ctype = ($extension === 'ts') ? 'video/mp2t' : 'video/mpeg';
    header("Content-Type: $ctype");
    
    // ВАЖНО: Кеширование на стороне клиента (браузера)
    // Поможет, если пользователь перематывает видео назад — Render не будет тратить трафик повторно
    header('Cache-Control: public, max-age=3600'); 
    header('Access-Control-Allow-Origin: *'); // Разрешаем всем плеерам
    header_remove('Pragma');

    // Отключаем всё сжатие и буферизацию для видео
    @ini_set('zlib.output_compression', 'Off');
    while (ob_get_level()) ob_end_clean();

    $ch = curl_init($target_url);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
    curl_setopt($ch, CURLOPT_BUFFERSIZE, 524288); // Увеличили буфер до 512KB для стабильности

    curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($ch, $data) {
        echo $data;
        if (connection_aborted()) return 0; // Останавливаем закачку, если юзер закрыл плеер
        flush(); 
        return strlen($data);
    });

    curl_exec($ch);
    curl_close($ch);
}
