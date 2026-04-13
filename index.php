<?php
error_reporting(0);
ini_set('display_errors', 0);
set_time_limit(0);

$ACCESS_KEY = getenv("PROXY_KEY") ?: "TvZaTak"; 
// Устанавливаем лимит скорости (например, 1.5 Мбайт/с = около 12 Мбит/с). 
// Этого хватит для Full HD, но остановит бешеный расход.
$SPEED_LIMIT = 1.5 * 1024 * 1024; 

if (!isset($_GET['key']) || $_GET['key'] !== $ACCESS_KEY) {
    header('HTTP/1.1 403 Forbidden');
    die("Access Denied");
}

$target_url = $_GET['url'] ?? '';
if (empty($target_url)) die("No URL");

$scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? "https" : "http";
$proxy_self = $scheme . "://" . $_SERVER['HTTP_HOST'] . $_SERVER['PHP_SELF'] . "?key=" . urlencode($ACCESS_KEY) . "&url=";

$path_info = pathinfo(parse_url($target_url, PHP_URL_PATH));
$extension = strtolower($path_info['extension']);

if ($extension === 'm3u8' || strpos($target_url, 'm3u8') !== false) {
    if (!ob_start("ob_gzhandler")) ob_start(); 
    $ch = curl_init($target_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0');
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
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
    header('Cache-Control: public, max-age=5');
    echo implode("\n", $new_content);
    ob_end_flush();

} else {
    $ctype = ($extension === 'ts') ? 'video/mp2t' : 'video/mpeg';
    header("Content-Type: $ctype");
    header('Cache-Control: public, max-age=3600'); 
    header('Access-Control-Allow-Origin: *');
    
    @ini_set('zlib.output_compression', 'Off');
    while (ob_get_level()) ob_end_clean();

    $ch = curl_init($target_url);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0');
    
    // Передаем данные с контролем скорости
    curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($ch, $data) use ($SPEED_LIMIT) {
        $start = microtime(true);
        echo $data;
        flush();
        
        if (connection_aborted()) return 0;

        // Рассчитываем паузу для ограничения скорости
        $length = strlen($data);
        $duration = microtime(true) - $start;
        $waitTime = ($length / $SPEED_LIMIT) - $duration;
        if ($waitTime > 0) usleep($waitTime * 1000000);
        
        return $length;
    });

    curl_exec($ch);
    curl_close($ch);
}
