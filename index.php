<?php
// Enable error reporting based on environment
require_once 'config.php';

$environment = Config::get('ENVIRONMENT', 'production');
if ($environment === 'development') {
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', 0);
    ini_set('log_errors', 1);
}

// -------------------- SECURE CONFIG --------------------
define('BOT_TOKEN', Config::get('BOT_TOKEN', ''));
define('CHANNEL_ID', Config::get('CHANNEL_ID', '-1003181705395'));
define('GROUP_CHANNEL_ID', Config::get('GROUP_CHANNEL_ID', '-1003083386043'));
define('BACKUP_CHANNEL_ID', Config::get('BACKUP_CHANNEL_ID', '-1002964109368'));
define('BOT_ID', Config::get('BOT_ID', '-8315381064'));
define('OWNER_ID', Config::get('OWNER_ID', '1080317415'));
define('APP_API_ID', Config::get('APP_API_ID', '21944581'));
define('APP_API_HASH', Config::get('APP_API_HASH', '7b1c174a5cd3466e25a976c39a791737'));
define('MAINTENANCE_MODE', Config::get('MAINTENANCE_MODE', 'false') === 'true');

define('CSV_FILE', Config::get('CSV_FILE', 'movies.csv'));
define('USERS_FILE', Config::get('USERS_FILE', 'users.json'));
define('STATS_FILE', Config::get('STATS_FILE', 'bot_stats.json'));
define('BACKUP_DIR', Config::get('BACKUP_DIR', 'backups/'));
define('CACHE_EXPIRY', (int)Config::get('CACHE_EXPIRY', 300));
define('ITEMS_PER_PAGE', (int)Config::get('ITEMS_PER_PAGE', 5));
// -------------------------------------------------------

// ==============================
// FILE UPLOAD BOT CONFIGURATION
// ==============================
define('MAX_FILE_SIZE', 2 * 1024 * 1024 * 1024);  // 2GB for normal Telegram users
define('FOUR_GB', 4 * 1024 * 1024 * 1024);
define('CHUNK_SIZE', 64 * 1024);
define('DEFAULT_WATERMARK', "@EntertainmentTadka786");
define('HARDCODED_THUMBNAIL', "thumb.png");
define('METADATA_FILE', "metadata.json");
define('RETRY_COUNT', 3);
define('VIDEO_WIDTH', 1280);
define('VIDEO_HEIGHT', 720);

// ==============================
// TEMPORARY MAINTENANCE MODE
// ==============================
if (MAINTENANCE_MODE) {
    $update = json_decode(file_get_contents('php://input'), true);
    if (isset($update['message'])) {
        $chat_id = $update['message']['chat']['id'];
        $maintenance_msg = "🛠️ <b>Bot Under Maintenance</b>\n\n";
        $maintenance_msg .= "We're temporarily unavailable for updates.\n";
        $maintenance_msg .= "Will be back in few days!\n\n";
        $maintenance_msg .= "Thanks for patience 🙏";
        sendMessage($chat_id, $maintenance_msg, null, 'HTML');
    }
    exit;
}

// File initialization
if (!file_exists(USERS_FILE)) {
    file_put_contents(USERS_FILE, json_encode(['users' => [], 'total_requests' => 0, 'message_logs' => []]));
    @chmod(USERS_FILE, 0600);
}

if (!file_exists(CSV_FILE)) {
    file_put_contents(CSV_FILE, "movie_name,message_id,date\n");
    @chmod(CSV_FILE, 0600);
}

if (!file_exists(STATS_FILE)) {
    file_put_contents(STATS_FILE, json_encode([
        'total_movies' => 0, 
        'total_users' => 0, 
        'total_searches' => 0, 
        'last_updated' => date('Y-m-d H:i:s')
    ]));
    @chmod(STATS_FILE, 0600);
}

if (!file_exists(BACKUP_DIR)) {
    @mkdir(BACKUP_DIR, 0755, true);
}

// memory caches
$movie_messages = array();
$movie_cache = array();
$waiting_users = array();

// File Upload Bot State
$file_bot_state = [
    'metadata' => [],
    'thumb_mode' => 'preview',
    'thumb_opacity' => 70,
    'thumb_textsize' => 18,
    'thumb_position' => 'top-right',
    'split' => false,
    'new_name' => null,
    'custom_thumb' => null
];

$file_queue = [];
$queue_processing = false;

// ==============================
// FILE UPLOAD BOT FUNCTIONS
// ==============================
function human_readable_size($size, $suffix = "B") {
    $units = ["", "K", "M", "G", "T"];
    foreach ($units as $u) {
        if ($size < 1024) {
            return sprintf("%.2f%s%s", $size, $u, $suffix);
        }
        $size /= 1024;
    }
    return sprintf("%.2fP%s", $size, $suffix);
}

function is_video_file($filename) {
    $video_ext = ['.mp4', '.mkv', '.avi', '.mov', '.wmv', '.flv', '.webm', '.m4v', '.3gp', '.ogg', '.mpeg', '.mpg', '.ts', '.vob', '.m4v'];
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    return in_array('.' . $ext, $video_ext);
}

function calc_checksum($file_path) {
    $md5 = md5_file($file_path);
    $sha1 = sha1_file($file_path);
    return [$md5, $sha1];
}

function split_file($source_path, $dest_dir, $part_size = null) {
    if ($part_size === null) {
        $part_size = MAX_FILE_SIZE;
    }
    
    $parts = [];
    $total = filesize($source_path);
    $num_parts = ceil($total / $part_size);
    
    $source_handle = fopen($source_path, "rb");
    if (!$source_handle) {
        throw new Exception("Cannot open source file: $source_path");
    }
    
    for ($idx = 0; $idx < $num_parts; $idx++) {
        $part_name = $dest_dir . "/" . basename($source_path) . ".part" . sprintf("%03d", $idx + 1);
        $parts[] = $part_name;
        
        $part_handle = fopen($part_name, "wb");
        if (!$part_handle) {
            fclose($source_handle);
            throw new Exception("Cannot create part file: $part_name");
        }
        
        $remaining = $part_size;
        while ($remaining > 0) {
            $chunk_size = min(CHUNK_SIZE, $remaining);
            $chunk = fread($source_handle, $chunk_size);
            if ($chunk === false || strlen($chunk) === 0) {
                break;
            }
            fwrite($part_handle, $chunk);
            $remaining -= strlen($chunk);
        }
        fclose($part_handle);
    }
    
    fclose($source_handle);
    return $parts;
}

function resize_video($input_path, $output_path) {
    try {
        // Just copy the original video without resizing
        if (!copy($input_path, $output_path)) {
            throw new Exception("Copy failed");
        }
        return true;
    } catch (Exception $e) {
        return false;
    }
}

function create_watermark_thumb($video_path, $tmp_dir, $opacity = 70, $text_size = 18, $position = "top-right") {
    try {
        // HIGH QUALITY FFmpeg thumbnail extraction
        $thumb_path = $tmp_dir . "/" . pathinfo($video_path, PATHINFO_FILENAME) . "_thumb.jpg";
        
        $cmd = [
            "ffmpeg", "-y",
            "-i", $video_path,
            "-ss", "00:00:05",
            "-vframes", "1",
            "-q:v", "1",
            "-vf", "scale=430:241:flags=lanczos",
            $thumb_path
        ];
        
        $result = shell_exec(implode(" ", $cmd) . " 2>&1");
        
        if (!file_exists($thumb_path)) {
            return null;
        }
        
        // HIGH QUALITY Watermark add karo using GD
        $img = imagecreatefromjpeg($thumb_path);
        if (!$img) {
            return null;
        }
        
        // Ensure exact dimensions
        $current_width = imagesx($img);
        $current_height = imagesy($img);
        
        if ($current_width != VIDEO_WIDTH || $current_height != VIDEO_HEIGHT) {
            $resized = imagecreatetruecolor(VIDEO_WIDTH, VIDEO_HEIGHT);
            imagecopyresampled($resized, $img, 0, 0, 0, 0, VIDEO_WIDTH, VIDEO_HEIGHT, $current_width, $current_height);
            imagedestroy($img);
            $img = $resized;
        }
        
        // Create transparent layer for text
        $txt = imagecreatetruecolor(VIDEO_WIDTH, VIDEO_HEIGHT);
        imagesavealpha($txt, true);
        $transparent = imagecolorallocatealpha($txt, 0, 0, 0, 127);
        imagefill($txt, 0, 0, $transparent);
        
        // Font loading
        $font_paths = [
            "arialbd.ttf", "arial.ttf", 
            "C:/Windows/Fonts/arialbd.ttf",
            "C:/Windows/Fonts/arial.ttf",
            "/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf",
            "/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf"
        ];
        
        $font = null;
        foreach ($font_paths as $font_path) {
            if (file_exists($font_path)) {
                $font = $font_path;
                break;
            }
        }
        
        // Calculate text position
        $bbox = imagettfbbox($text_size, 0, $font, DEFAULT_WATERMARK);
        $tw = $bbox[2] - $bbox[0];
        $th = $bbox[3] - $bbox[1];
        $margin = 10;
        
        if ($position == "top-left") {
            $x = $margin;
            $y = $margin + $th;
        } elseif ($position == "top-right") {
            $x = VIDEO_WIDTH - $tw - $margin;
            $y = $margin + $th;
        } elseif ($position == "bottom-left") {
            $x = $margin;
            $y = VIDEO_HEIGHT - $margin;
        } else {
            $x = VIDEO_WIDTH - $tw - $margin;
            $y = VIDEO_HEIGHT - $margin;
        }
        
        // Add text with shadow for better visibility
        $shadow_color = imagecolorallocatealpha($txt, 0, 0, 0, (int)(127 * $opacity / 100));
        $text_color = imagecolorallocatealpha($txt, 255, 255, 255, (int)(127 * $opacity / 100));
        
        imagettftext($txt, $text_size, 0, $x+1, $y+1, $shadow_color, $font, DEFAULT_WATERMARK);
        imagettftext($txt, $text_size, 0, $x, $y, $text_color, $font, DEFAULT_WATERMARK);
        
        // Merge images
        imagecopy($img, $txt, 0, 0, 0, 0, VIDEO_WIDTH, VIDEO_HEIGHT);
        
        // HIGH QUALITY Save (NO COMPRESSION)
        imagejpeg($img, $thumb_path, 95);
        
        // Clean up
        imagedestroy($img);
        imagedestroy($txt);
        
        return $thumb_path;
        
    } catch (Exception $e) {
        return null;
    }
}

function download_telegram_file($file_id, $destination) {
    global $BOT_TOKEN;
    $url = "https://api.telegram.org/bot{$BOT_TOKEN}/getFile?file_id=$file_id";
    $response = json_decode(file_get_contents($url), true);
    
    if (!$response || !$response['ok']) {
        throw new Exception("Cannot get file path");
    }
    
    $file_path = $response['result']['file_path'];
    $download_url = "https://api.telegram.org/file/bot{$BOT_TOKEN}/$file_path";
    
    $file_content = file_get_contents($download_url);
    if ($file_content === false) {
        throw new Exception("Cannot download file");
    }
    
    file_put_contents($destination, $file_content);
}

function send_telegram_video($video_path, $caption, $thumb_path = null, $duration = 0, $chat_id = null) {
    if ($chat_id === null) {
        $chat_id = OWNER_ID;
    }
    
    $url = "https://api.telegram.org/bot" . BOT_TOKEN . "/sendVideo";
    
    $post_data = [
        'chat_id' => $chat_id,
        'caption' => $caption,
        'duration' => $duration,
        'width' => VIDEO_WIDTH,
        'height' => VIDEO_HEIGHT,
        'supports_streaming' => true,
        'video' => new CURLFile(realpath($video_path))
    ];
    
    if ($thumb_path && file_exists($thumb_path)) {
        $post_data['thumb'] = new CURLFile(realpath($thumb_path));
    }
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    
    $result = curl_exec($ch);
    curl_close($ch);
    
    return $result;
}

function send_telegram_document($document_path, $caption, $thumb_path = null, $chat_id = null) {
    if ($chat_id === null) {
        $chat_id = OWNER_ID;
    }
    
    $url = "https://api.telegram.org/bot" . BOT_TOKEN . "/sendDocument";
    
    $post_data = [
        'chat_id' => $chat_id,
        'caption' => $caption,
        'document' => new CURLFile(realpath($document_path))
    ];
    
    if ($thumb_path && file_exists($thumb_path)) {
        $post_data['thumb'] = new CURLFile(realpath($thumb_path));
    }
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    
    $result = curl_exec($ch);
    curl_close($ch);
    
    return $result;
}

function send_telegram_photo($photo_path, $caption, $chat_id = null) {
    if ($chat_id === null) {
        $chat_id = OWNER_ID;
    }
    
    $url = "https://api.telegram.org/bot" . BOT_TOKEN . "/sendPhoto";
    
    $post_data = [
        'chat_id' => $chat_id,
        'caption' => $caption,
        'photo' => new CURLFile(realpath($photo_path))
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    
    $result = curl_exec($ch);
    curl_close($ch);
    
    return $result;
}

// ==============================
// Stats
// ==============================
function update_stats($field, $increment = 1) {
    if (!file_exists(STATS_FILE)) return;
    $stats = json_decode(file_get_contents(STATS_FILE), true);
    $stats[$field] = ($stats[$field] ?? 0) + $increment;
    $stats['last_updated'] = date('Y-m-d H:i:s');
    file_put_contents(STATS_FILE, json_encode($stats, JSON_PRETTY_PRINT));
}

function get_stats() {
    if (!file_exists(STATS_FILE)) return [];
    return json_decode(file_get_contents(STATS_FILE), true);
}

// ==============================
// Caching / CSV loading
// ==============================
function load_and_clean_csv($filename = CSV_FILE) {
    global $movie_messages;
    if (!file_exists($filename)) {
        file_put_contents($filename, "movie_name,message_id,date\n");
        return [];
    }

    $data = [];
    $handle = fopen($filename, "r");
    if ($handle !== FALSE) {
        $header = fgetcsv($handle);
        while (($row = fgetcsv($handle)) !== FALSE) {
            if (count($row) >= 3 && (!empty(trim($row[0])))) {
                $movie_name = trim($row[0]);
                $message_id_raw = isset($row[1]) ? trim($row[1]) : '';
                $date = isset($row[2]) ? trim($row[2]) : '';
                $video_path = isset($row[3]) ? trim($row[3]) : '';

                $entry = [
                    'movie_name' => $movie_name,
                    'message_id_raw' => $message_id_raw,
                    'date' => $date,
                    'video_path' => $video_path
                ];
                if (is_numeric($message_id_raw)) {
                    $entry['message_id'] = intval($message_id_raw);
                } else {
                    $entry['message_id'] = null;
                }

                $data[] = $entry;

                $movie = strtolower($movie_name);
                if (!isset($movie_messages[$movie])) $movie_messages[$movie] = [];
                $movie_messages[$movie][] = $entry;
            }
        }
        fclose($handle);
    }

    $stats = json_decode(file_get_contents(STATS_FILE), true);
    $stats['total_movies'] = count($data);
    $stats['last_updated'] = date('Y-m-d H:i:s');
    file_put_contents(STATS_FILE, json_encode($stats, JSON_PRETTY_PRINT));

    $handle = fopen($filename, "w");
    fputcsv($handle, array('movie_name','message_id','date','video_path'));
    foreach ($data as $row) {
        fputcsv($handle, [$row['movie_name'], $row['message_id_raw'], $row['date'], $row['video_path']]);
    }
    fclose($handle);

    return $data;
}

function get_cached_movies() {
    global $movie_cache;
    if (!empty($movie_cache) && (time() - $movie_cache['timestamp']) < CACHE_EXPIRY) {
        return $movie_cache['data'];
    }
    $movie_cache = [
        'data' => load_and_clean_csv(),
        'timestamp' => time()
    ];
    return $movie_cache['data'];
}

function load_movies_from_csv() {
    return get_cached_movies();
}

// ==============================
// Telegram API helpers
// ==============================
function apiRequest($method, $params = array(), $is_multipart = false) {
    $url = "https://api.telegram.org/bot" . BOT_TOKEN . "/" . $method;
    if ($is_multipart) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $res = curl_exec($ch);
        if ($res === false) {
            error_log("CURL ERROR: " . curl_error($ch));
        }
        curl_close($ch);
    } else {
        $options = array(
            'http' => array(
                'method' => 'POST',
                'content' => http_build_query($params),
                'header' => "Content-Type: application/x-www-form-urlencoded\r\n"
            )
        );
        $context = stream_context_create($options);
        $result = @file_get_contents($url, false, $context);
        if ($result === false) {
            error_log("apiRequest failed for method $method");
        }
        return $result;
    }
}

function sendMessage($chat_id, $text, $reply_markup = null, $parse_mode = null) {
    $data = [
        'chat_id' => $chat_id,
        'text' => $text
    ];
    if ($reply_markup) $data['reply_markup'] = json_encode($reply_markup);
    if ($parse_mode) $data['parse_mode'] = $parse_mode;
    $result = apiRequest('sendMessage', $data);
    return json_decode($result, true);
}

function copyMessage($chat_id, $from_chat_id, $message_id) {
    return apiRequest('copyMessage', [
        'chat_id' => $chat_id,
        'from_chat_id' => $from_chat_id,
        'message_id' => $message_id
    ]);
}

function forwardMessage($chat_id, $from_chat_id, $message_id) {
    $result = apiRequest('forwardMessage', [
        'chat_id' => $chat_id,
        'from_chat_id' => $from_chat_id,
        'message_id' => $message_id
    ]);
    return $result;
}

function answerCallbackQuery($callback_query_id, $text = null) {
    $data = ['callback_query_id' => $callback_query_id];
    if ($text) $data['text'] = $text;
    apiRequest('answerCallbackQuery', $data);
}

function editMessage($chat_id, $message_obj, $new_text, $reply_markup = null) {
    if (is_array($message_obj) && isset($message_obj['message_id'])) {
        $data = [
            'chat_id' => $chat_id,
            'message_id' => $message_obj['message_id'],
            'text' => $new_text
        ];
        if ($reply_markup) $data['reply_markup'] = json_encode($reply_markup);
        apiRequest('editMessageText', $data);
    }
}

// ==============================
// DELIVERY LOGIC - FIXED (CHANNEL NAME & VIEWS WILL SHOW)
// ==============================
function deliver_item_to_chat($chat_id, $item) {
    if (!empty($item['message_id']) && is_numeric($item['message_id'])) {
        // FORWARD use karo - channel name & views dikhenge
        $result = json_decode(forwardMessage($chat_id, CHANNEL_ID, $item['message_id']), true);
        
        if ($result && $result['ok']) {
            return true;
        } else {
            // Agar forward fail ho, toh copy as fallback
            copyMessage($chat_id, CHANNEL_ID, $item['message_id']);
            return true;
        }
    }

    // Agar message_id nahi hai toh simple text bhejo
    $text = "🎬 " . ($item['movie_name'] ?? 'Unknown') . "\n";
    $text .= "Ref: " . ($item['message_id_raw'] ?? 'N/A') . "\n";
    $text .= "Date: " . ($item['date'] ?? 'N/A') . "\n";
    sendMessage($chat_id, $text, null, 'HTML');
    return false;
}

// ==============================
// Pagination helpers
// ==============================
function get_all_movies_list() {
    $all = get_cached_movies();
    return $all;
}

function paginate_movies(array $all, int $page): array {
    $total = count($all);
    if ($total === 0) {
        return [
            'total' => 0,
            'total_pages' => 1, 
            'page' => 1,
            'slice' => []
        ];
    }
    
    $total_pages = (int)ceil($total / ITEMS_PER_PAGE);
    $page = max(1, min($page, $total_pages)); // Boundary check
    $start = ($page - 1) * ITEMS_PER_PAGE;
    
    return [
        'total' => $total,
        'total_pages' => $total_pages,
        'page' => $page,
        'slice' => array_slice($all, $start, ITEMS_PER_PAGE)
    ];
}

function forward_page_movies($chat_id, array $page_movies) {
    $total = count($page_movies);
    if ($total === 0) return;
    
    // Progress message bhejo
    $progress_msg = sendMessage($chat_id, "⏳ Forwarding {$total} movies...");
    
    $i = 1;
    $success_count = 0;
    
    foreach ($page_movies as $m) {
        $success = deliver_item_to_chat($chat_id, $m);
        if ($success) $success_count++;
        
        // Har 3 movies ke baad progress update karo
        if ($i % 3 === 0) {
            editMessage($chat_id, $progress_msg, "⏳ Forwarding... ({$i}/{$total})");
        }
        
        usleep(500000); // 0.5 second delay
        $i++;
    }
    
    // Final progress update
    editMessage($chat_id, $progress_msg, "✅ Successfully forwarded {$success_count}/{$total} movies");
}

function build_totalupload_keyboard(int $page, int $total_pages): array {
    $kb = ['inline_keyboard' => []];
    
    // Navigation buttons - better spacing
    $nav_row = [];
    if ($page > 1) {
        $nav_row[] = ['text' => '⬅️ Previous', 'callback_data' => 'tu_prev_' . ($page - 1)];
    }
    
    // Page indicator as button (non-clickable)
    $nav_row[] = ['text' => "📄 $page/$total_pages", 'callback_data' => 'current_page'];
    
    if ($page < $total_pages) {
        $nav_row[] = ['text' => 'Next ➡️', 'callback_data' => 'tu_next_' . ($page + 1)];
    }
    
    if (!empty($nav_row)) {
        $kb['inline_keyboard'][] = $nav_row;
    }
    
    // Action buttons - separate row
    $action_row = [];
    $action_row[] = ['text' => '🎬 Send This Page', 'callback_data' => 'tu_view_' . $page];
    $action_row[] = ['text' => '🛑 Stop', 'callback_data' => 'tu_stop'];
    
    $kb['inline_keyboard'][] = $action_row;
    
    // Quick jump buttons for first/last pages
    if ($total_pages > 5) {
        $jump_row = [];
        if ($page > 1) {
            $jump_row[] = ['text' => '⏮️ First', 'callback_data' => 'tu_prev_1'];
        }
        if ($page < $total_pages) {
            $jump_row[] = ['text' => 'Last ⏭️', 'callback_data' => 'tu_next_' . $total_pages];
        }
        if (!empty($jump_row)) {
            $kb['inline_keyboard'][] = $jump_row;
        }
    }
    
    return $kb;
}

// ==============================
// /totalupload controller - IMPROVED
// ==============================
function totalupload_controller($chat_id, $page = 1) {
    $all = get_all_movies_list();
    if (empty($all)) {
        sendMessage($chat_id, "📭 Koi movies nahi mili! Pehle kuch movies add karo.");
        return;
    }
    
    $pg = paginate_movies($all, (int)$page);
    
    // Pehle current page ki movies forward karo
    forward_page_movies($chat_id, $pg['slice']);
    
    // Better formatted message
    $title = "🎬 <b>Total Uploads</b>\n\n";
    $title .= "📊 <b>Statistics:</b>\n";
    $title .= "• Total Movies: <b>{$pg['total']}</b>\n";
    $title .= "• Current Page: <b>{$pg['page']}/{$pg['total_pages']}</b>\n";
    $title .= "• Showing: <b>" . count($pg['slice']) . " movies</b>\n\n";
    
    // Current page ki movies list show karo
    $title .= "📋 <b>Current Page Movies:</b>\n";
    $i = 1;
    foreach ($pg['slice'] as $movie) {
        $movie_name = htmlspecialchars($movie['movie_name'] ?? 'Unknown');
        $title .= "$i. {$movie_name}\n";
        $i++;
    }
    
    $title .= "\n📍 Use buttons to navigate or resend current page";
    
    $kb = build_totalupload_keyboard($pg['page'], $pg['total_pages']);
    sendMessage($chat_id, $title, $kb, 'HTML');
}

// ==============================
// Append movie
// ==============================
function append_movie($movie_name, $message_id_raw, $date = null, $video_path = '') {
    if (empty(trim($movie_name))) return;
    if ($date === null) $date = date('d-m-Y');
    $entry = [$movie_name, $message_id_raw, $date, $video_path];
    $handle = fopen(CSV_FILE, "a");
    fputcsv($handle, $entry);
    fclose($handle);

    global $movie_messages, $movie_cache, $waiting_users;
    $movie = strtolower(trim($movie_name));
    $item = [
        'movie_name' => $movie_name,
        'message_id_raw' => $message_id_raw,
        'date' => $date,
        'video_path' => $video_path,
        'message_id' => is_numeric($message_id_raw) ? intval($message_id_raw) : null
    ];
    if (!isset($movie_messages[$movie])) $movie_messages[$movie] = [];
    $movie_messages[$movie][] = $item;
    $movie_cache = [];

    foreach ($waiting_users as $query => $users) {
        if (strpos($movie, $query) !== false) {
            foreach ($users as $user_data) {
                list($user_chat_id, $user_id) = $user_data;
                deliver_item_to_chat($user_chat_id, $item);
                sendMessage($user_chat_id, "✅ '$query' ab channel me add ho gaya!");
            }
            unset($waiting_users[$query]);
        }
    }

    update_stats('total_movies', 1);
}

// ==============================
// Search & language & points
// ==============================
function smart_search($query) {
    global $movie_messages;
    $query_lower = strtolower(trim($query));
    $results = array();
    foreach ($movie_messages as $movie => $entries) {
        $score = 0;
        if ($movie == $query_lower) $score = 100;
        elseif (strpos($movie, $query_lower) !== false) $score = 80 - (strlen($movie) - strlen($query_lower));
        else {
            similar_text($movie, $query_lower, $similarity);
            if ($similarity > 60) $score = $similarity;
        }
        if ($score > 0) $results[$movie] = ['score'=>$score,'count'=>count($entries)];
    }
    uasort($results, function($a,$b){return $b['score'] - $a['score'];});
    return array_slice($results,0,10);
}

function detect_language($text) {
    $hindi_keywords = ['फिल्म','मूवी','डाउनलोड','हिंदी'];
    $english_keywords = ['movie','download','watch','print'];
    $h=0;$e=0;
    foreach ($hindi_keywords as $k) if (strpos($text,$k)!==false) $h++;
    foreach ($english_keywords as $k) if (stripos($text,$k)!==false) $e++;
    return $h>$e ? 'hindi' : 'english';
}

function send_multilingual_response($chat_id, $message_type, $language) {
    $responses = [
        'hindi'=>[
            'welcome' => "🎬 Boss, kis movie ki talash hai?",
            'found' => "✅ Mil gayi! Movie forward ho rahi hai...",
            'not_found' => "😔 Yeh movie abhi available nahi hai!\n\n📝 Aap ise request kar sakte hain: @EntertainmentTadka0786\n\n🔔 Jab bhi yeh add hogi, main automatically bhej dunga!",
            'searching' => "🔍 Dhoondh raha hoon... Zara wait karo"
        ],
        'english'=>[
            'welcome' => "🎬 Boss, which movie are you looking for?",
            'found' => "✅ Found it! Forwarding the movie...",
            'not_found' => "😔 This movie isn't available yet!\n\n📝 You can request it here: @EntertainmentTadka0786\n\n🔔 I'll send it automatically once it's added!",
            'searching' => "🔍 Searching... Please wait"
        ]
    ];
    sendMessage($chat_id, $responses[$language][$message_type]);
}

function update_user_points($user_id, $action) {
    $points_map = ['search'=>1,'found_movie'=>5,'daily_login'=>10];
    $users_data = json_decode(file_get_contents(USERS_FILE), true);
    if (!isset($users_data['users'][$user_id]['points'])) $users_data['users'][$user_id]['points'] = 0;
    $users_data['users'][$user_id]['points'] += ($points_map[$action] ?? 0);
    $users_data['users'][$user_id]['last_activity'] = date('Y-m-d H:i:s');
    file_put_contents(USERS_FILE, json_encode($users_data, JSON_PRETTY_PRINT));
}

function advanced_search($chat_id, $query, $user_id = null) {
    global $movie_messages, $waiting_users;
    $q = strtolower(trim($query));
    
    // 1. Minimum length check
    if (strlen($q) < 2) {
        sendMessage($chat_id, "❌ Please enter at least 2 characters for search");
        return;
    }
    
    // 2. STRONGER INVALID KEYWORDS FILTER
    $invalid_keywords = [
        // Technical words
        'vlc', 'audio', 'track', 'change', 'open', 'kar', 'me', 'hai',
        'how', 'what', 'problem', 'issue', 'help', 'solution', 'fix',
        'error', 'not working', 'download', 'play', 'video', 'sound',
        'subtitle', 'quality', 'hd', 'full', 'part', 'scene',
        
        // Common group chat words
        'hi', 'hello', 'hey', 'good', 'morning', 'night', 'bye',
        'thanks', 'thank', 'ok', 'okay', 'yes', 'no', 'maybe',
        'who', 'when', 'where', 'why', 'how', 'can', 'should',
        
        // Hindi common words
        'kaise', 'kya', 'kahan', 'kab', 'kyun', 'kon', 'kisne',
        'hai', 'hain', 'ho', 'raha', 'raha', 'rah', 'tha', 'thi',
        'mere', 'apne', 'tumhare', 'hamare', 'sab', 'log', 'group'
    ];
    
    // 3. SMART WORD ANALYSIS
    $query_words = explode(' ', $q);
    $total_words = count($query_words);
    
    $invalid_count = 0;
    foreach ($query_words as $word) {
        if (in_array($word, $invalid_keywords)) {
            $invalid_count++;
        }
    }
    
    // 4. STRICTER THRESHOLD - 50% se zyada invalid words ho toh block
    if ($invalid_count > 0 && ($invalid_count / $total_words) > 0.5) {
        $help_msg = "🎬 Please enter a movie name!\n\n";
        $help_msg .= "🔍 Examples of valid movie names:\n";
        $help_msg .= "• kgf\n• pushpa\n• avengers\n• hindi movie\n• spider-man\n\n";
        $help_msg .= "❌ Technical queries like 'vlc', 'audio track', etc. are not movie names.\n\n";
        $help_msg .= "📢 Join: @EntertainmentTadka786\n";
        $help_msg .= "💬 Help: @EntertainmentTadka0786";
        sendMessage($chat_id, $help_msg, null, 'HTML');
        return;
    }
    
    // 5. MOVIE NAME PATTERN VALIDATION
    $movie_pattern = '/^[a-zA-Z0-9\s\-\.\,\&\+\(\)\:\'\"]+$/';
    if (!preg_match($movie_pattern, $query)) {
        sendMessage($chat_id, "❌ Invalid movie name format. Only letters, numbers, and basic punctuation allowed.");
        return;
    }
    
    $found = smart_search($q);
    if (!empty($found)) {
        $msg = "🔍 Found " . count($found) . " movies for '$query':\n\n";
        $i=1;
        foreach ($found as $movie=>$data) {
            $msg .= "$i. $movie (" . $data['count'] . " entries)\n";
            $i++; if ($i>15) break;
        }
        sendMessage($chat_id, $msg);
        $keyboard = ['inline_keyboard'=>[]];
        foreach (array_slice(array_keys($found),0,5) as $movie) {
            $keyboard['inline_keyboard'][] = [[ 'text'=>"🎬 ".ucwords($movie), 'callback_data'=>$movie ]];
        }
        sendMessage($chat_id, "🚀 Top matches:", $keyboard);
        if ($user_id) update_user_points($user_id, 'found_movie');
    } else {
        $lang = detect_language($query);
        send_multilingual_response($chat_id, 'not_found', $lang);
        if (!isset($waiting_users[$q])) $waiting_users[$q] = [];
        $waiting_users[$q][] = [$chat_id, $user_id ?? $chat_id];
    }
    update_stats('total_searches', 1);
    if ($user_id) update_user_points($user_id, 'search');
}

// ==============================
// Admin stats - SYNTAX ERROR FIXED
// ==============================
function admin_stats($chat_id) {
    $stats = get_stats();
    $users_data = json_decode(file_get_contents(USERS_FILE), true);
    $total_users = count($users_data['users'] ?? []);
    
    $msg = "📊 Bot Statistics\n\n";
    $msg .= "🎬 Total Movies: " . ($stats['total_movies'] ?? 0) . "\n";
    $msg .= "👥 Total Users: " . $total_users . "\n";
    $msg .= "🔍 Total Searches: " . ($stats['total_searches'] ?? 0) . "\n";
    $msg .= "🕒 Last Updated: " . ($stats['last_updated'] ?? 'N/A') . "\n\n";
    
    $csv_data = load_and_clean_csv();
    $recent = array_slice($csv_data, -5);
    $msg .= "📈 Recent Uploads:\n";
    foreach ($recent as $r) {
        $msg .= "• " . $r['movie_name'] . " (" . $r['date'] . ")\n";
    }
    
    sendMessage($chat_id, $msg, null, 'HTML');
}

// ==============================
// FILE UPLOAD BOT COMMAND HANDLERS
// ==============================
function handle_file_upload_commands($message) {
    global $file_bot_state;
    
    $user_id = $message['from']['id'];
    $text = $message['text'] ?? '';
    
    // Check if user is owner
    if ($user_id != OWNER_ID) {
        sendMessage($user_id, "❌ Access denied. You are not authorized to use file upload features.");
        return;
    }
    
    $command = explode(' ', $text)[0];
    
    switch ($command) {
        case '/upload_help':
            handle_upload_help($message);
            break;
            
        case '/setname':
            handle_set_name($message);
            break;
            
        case '/clearname':
            handle_clear_name($message);
            break;
            
        case '/split_on':
            handle_split_on($message);
            break;
            
        case '/split_off':
            handle_split_off($message);
            break;
            
        case '/upload_status':
            handle_upload_status($message);
            break;
            
        case '/metadata':
            handle_metadata($message);
            break;
            
        case '/setthumb':
            handle_set_thumbnail($message);
            break;
            
        case '/view_thumb':
            handle_view_thumbnail($message);
            break;
            
        case '/del_thumb':
            handle_delete_thumbnail($message);
            break;
            
        default:
            // Check if it's a file for upload
            if (isset($message['document']) || isset($message['video']) || isset($message['audio'])) {
                handle_file_upload($message);
            }
            break;
    }
}

function handle_upload_help($message) {
    global $file_bot_state;
    
    $thumb_status = file_exists(HARDCODED_THUMBNAIL) ? "✅ EXISTS" : "❌ NOT FOUND";
    $custom_thumb_status = isset($file_bot_state["custom_thumb"]) && $file_bot_state["custom_thumb"] ? "✅ SET" : "❌ NOT SET";
    
    $help_text = "**📁 File Upload Bot v8.2**\n\n"
        . "**FIXED: HIGH QUALITY THUMBNAILS (NO BLUR)**\n\n"
        . "**Commands:**\n"
        . "• `/setname <filename.ext>` - Set new filename\n"
        . "• `/clearname` - Clear set filename\n"
        . "• `/split_on` - Enable 4GB split\n"
        . "• `/split_off` - Disable 4GB split\n"
        . "• `/upload_status` - Show current settings\n"
        . "• `/metadata key=value` - Set custom metadata\n"
        . "• `/setthumb` - Set custom thumbnail\n"
        . "• `/view_thumb` - View current thumbnail\n"
        . "• `/del_thumb` - Delete custom thumbnail\n\n"
        . "**Video & Thumbnail Dimensions:** " . VIDEO_WIDTH . "x" . VIDEO_HEIGHT . "\n"
        . "**Max File Size:** 4GB (Telegram)\n"
        . "**Default Thumb:** $thumb_status\n"
        . "**Custom Thumb:** $custom_thumb_status\n"
        . "**Thumbnail Quality:** HIGH (No Blur)";
        
    sendMessage($message['chat']['id'], $help_text, null, 'HTML');
}

function handle_set_name($message) {
    global $file_bot_state;
    
    $args = explode(' ', $message['text'], 2);
    if (count($args) < 2) {
        sendMessage($message['chat']['id'], "❌ Usage: `/setname <filename.ext>`", null, 'HTML');
        return;
    }
    
    $file_bot_state["new_name"] = trim($args[1]);
    sendMessage($message['chat']['id'], "✅ Name set: `{$args[1]}`", null, 'HTML');
}

function handle_clear_name($message) {
    global $file_bot_state;
    
    $file_bot_state["new_name"] = null;
    sendMessage($message['chat']['id'], "✅ Name cleared.");
}

function handle_split_on($message) {
    global $file_bot_state;
    
    $file_bot_state["split"] = true;
    sendMessage($message['chat']['id'], "✅ 4GB split ENABLED");
}

function handle_split_off($message) {
    global $file_bot_state;
    
    $file_bot_state["split"] = false;
    sendMessage($message['chat']['id'], "✅ 4GB split DISABLED");
}

function handle_upload_status($message) {
    global $file_bot_state;
    
    $name = $file_bot_state["new_name"] ?? "❌ Not set";
    $split = isset($file_bot_state["split"]) && $file_bot_state["split"] ? "✅ ON" : "❌ OFF";
    $thumb = isset($file_bot_state["custom_thumb"]) && $file_bot_state["custom_thumb"] ? "✅ SET" : "❌ NOT SET";
    $md = $file_bot_state["metadata"] ?? [];
    
    $md_text = "";
    foreach ($md as $k => $v) {
        $md_text .= "\n• $k: `$v`";
    }
    $md_text = $md_text ?: "None";
    
    $status_text = "**🤖 File Upload Status**\n\n"
        . "• **Filename:** `$name`\n"
        . "• **2GB Split:** $split\n"
        . "• **Video & Thumbnail Dimensions:** " . VIDEO_WIDTH . "x" . VIDEO_HEIGHT . "\n"
        . "• **Custom Thumb:** $thumb\n"
        . "• **Thumbnail Quality:** HIGH (No Blur)\n\n"
        . "**Metadata:**\n$md_text";
        
    sendMessage($message['chat']['id'], $status_text, null, 'HTML');
}

function handle_metadata($message) {
    global $file_bot_state;
    
    $args = explode(' ', $message['text'], 2);
    if (count($args) < 2) {
        sendMessage($message['chat']['id'], "❌ Usage: `/metadata key=value`\n\nExample: `/metadata title=Movie quality=1080p year=2024`", null, 'HTML');
        return;
    }
    
    if (!isset($file_bot_state["metadata"])) {
        $file_bot_state["metadata"] = [];
    }
    
    $pairs = explode(' ', $args[1]);
    $changes = [];
    
    foreach ($pairs as $pair) {
        if (strpos($pair, '=') !== false) {
            list($k, $v) = explode('=', $pair, 2);
            $k = trim(strtolower($k));
            $v = trim($v);
            $file_bot_state["metadata"][$k] = $v;
            $changes[] = "• `$k` = `$v`";
        }
    }
    
    if ($changes) {
        sendMessage($message['chat']['id'], "✅ Metadata Updated\n" . implode("\n", $changes), null, 'HTML');
    } else {
        sendMessage($message['chat']['id'], "❌ No valid key=value pairs found!", null, 'HTML');
    }
}

function handle_set_thumbnail($message) {
    global $file_bot_state;
    
    try {
        $thumb_path = "custom_thumb.jpg";
        
        // Check if message has photo or document
        if (isset($message['photo'])) {
            $photo = end($message['photo']);
            $file_id = $photo['file_id'];
            download_telegram_file($file_id, $thumb_path);
        } elseif (isset($message['document']) && strpos($message['document']['mime_type'], 'image/') === 0) {
            $file_id = $message['document']['file_id'];
            download_telegram_file($file_id, $thumb_path);
        } else {
            sendMessage($message['chat']['id'], "❌ Send a photo or image file with `/setthumb`", null, 'HTML');
            return;
        }
        
        // HIGH QUALITY Resize to VIDEO DIMENSIONS
        $img = imagecreatefromstring(file_get_contents($thumb_path));
        if (!$img) {
            throw new Exception("Cannot process image");
        }
        
        $target_width = VIDEO_WIDTH;
        $target_height = VIDEO_HEIGHT;
        
        $orig_width = imagesx($img);
        $orig_height = imagesy($img);
        $img_ratio = $orig_width / $orig_height;
        $target_ratio = $target_width / $target_height;
        
        if ($img_ratio > $target_ratio) {
            // Image is wider - crop width
            $new_height = $target_height;
            $new_width = (int)($target_height * $img_ratio);
            $resized = imagecreatetruecolor($new_width, $new_height);
            imagecopyresampled($resized, $img, 0, 0, 0, 0, $new_width, $new_height, $orig_width, $orig_height);
            
            // Crop width to target
            $left = (int)(($new_width - $target_width) / 2);
            $cropped = imagecreatetruecolor($target_width, $target_height);
            imagecopy($cropped, $resized, 0, 0, $left, 0, $target_width, $target_height);
            
            imagedestroy($resized);
            imagedestroy($img);
            $img = $cropped;
        } else {
            // Image is taller - crop height
            $new_width = $target_width;
            $new_height = (int)($target_width / $img_ratio);
            $resized = imagecreatetruecolor($new_width, $new_height);
            imagecopyresampled($resized, $img, 0, 0, 0, 0, $new_width, $new_height, $orig_width, $orig_height);
            
            // Crop height to target
            $top = (int)(($new_height - $target_height) / 2);
            $cropped = imagecreatetruecolor($target_width, $target_height);
            imagecopy($cropped, $resized, 0, 0, 0, $top, $target_width, $target_height);
            
            imagedestroy($resized);
            imagedestroy($img);
            $img = $cropped;
        }
        
        // HIGH QUALITY Save (NO COMPRESSION)
        imagejpeg($img, $thumb_path, 95);
        imagedestroy($img);
        
        $file_bot_state["custom_thumb"] = $thumb_path;
        
        $size = getimagesize($thumb_path);
        sendMessage(
            $message['chat']['id'],
            "✅ HIGH QUALITY Custom thumbnail set!\nSize: {$size[0]}×{$size[1]}\nQuality: 95% (No Blur)\n\n**Video & Thumbnail same dimensions: " . VIDEO_WIDTH . "x" . VIDEO_HEIGHT . "**", 
            null, 'HTML'
        );
        
    } catch (Exception $e) {
        sendMessage($message['chat']['id'], "❌ Error setting thumbnail: `{$e->getMessage()}`", null, 'HTML');
    }
}

function handle_view_thumbnail($message) {
    global $file_bot_state;
    
    $custom_thumb_path = $file_bot_state["custom_thumb"] ?? null;
    
    if (!$custom_thumb_path || !file_exists($custom_thumb_path)) {
        sendMessage($message['chat']['id'], "❌ No custom thumbnail set!", null, 'HTML');
        return;
    }
    
    $size = filesize($custom_thumb_path);
    $img_info = getimagesize($custom_thumb_path);
    $dimensions = "{$img_info[0]}×{$img_info[1]}";
    
    send_telegram_photo(
        $custom_thumb_path,
        "**📷 HIGH QUALITY Custom Thumbnail**\nSize: " . human_readable_size($size) . "\nDimensions: $dimensions\nQuality: 95% (No Blur)\n\n**Video & Thumbnail same dimensions: " . VIDEO_WIDTH . "x" . VIDEO_HEIGHT . "**",
        $message['chat']['id']
    );
}

function handle_delete_thumbnail($message) {
    global $file_bot_state;
    
    $custom_thumb_path = $file_bot_state["custom_thumb"] ?? null;
    
    if (!$custom_thumb_path) {
        sendMessage($message['chat']['id'], "❌ No custom thumbnail to delete!", null, 'HTML');
        return;
    }
    
    try {
        if (file_exists($custom_thumb_path)) {
            unlink($custom_thumb_path);
        }
        $file_bot_state["custom_thumb"] = null;
        sendMessage($message['chat']['id'], "✅ Custom thumbnail deleted!");
    } catch (Exception $e) {
        sendMessage($message['chat']['id'], "❌ Error deleting thumbnail: `{$e->getMessage()}`", null, 'HTML');
    }
}

// ==============================
// FILE UPLOAD PROCESSING
// ==============================
function handle_file_upload($message) {
    global $file_queue, $queue_processing;
    
    $file_queue[] = $message;
    process_upload_queue();
}

function process_upload_queue() {
    global $file_queue, $queue_processing;
    
    if ($queue_processing || empty($file_queue)) {
        return;
    }
    
    $queue_processing = true;
    
    while (!empty($file_queue)) {
        $message = array_shift($file_queue);
        process_single_file($message);
    }
    
    $queue_processing = false;
}

function process_single_file($message) {
    global $file_bot_state;
    
    $tmp_dir = sys_get_temp_dir() . "/rename_bot_" . uniqid();
    mkdir($tmp_dir, 0755, true);
    
    $new_name = $file_bot_state["new_name"] ?? null;
    $do_split = $file_bot_state["split"] ?? false;
    $metadata = $file_bot_state["metadata"] ?? [];
    $custom_thumb_path = $file_bot_state["custom_thumb"] ?? null;
    
    try {
        // Get original file name
        if (isset($message['document'])) {
            $orig_name = $message['document']['file_name'] ?? 'file';
            $file_id = $message['document']['file_id'];
        } elseif (isset($message['video'])) {
            $orig_name = $message['video']['file_name'] ?? 'video.mp4';
            $file_id = $message['video']['file_id'];
        } elseif (isset($message['audio'])) {
            $orig_name = $message['audio']['file_name'] ?? 'audio';
            $file_id = $message['audio']['file_id'];
        } else {
            throw new Exception("Unsupported file type");
        }
        
        $download_path = $tmp_dir . "/" . $orig_name;
        sendMessage($message['chat']['id'], "📥 Downloading `$orig_name`\n`[0%]`", null, 'HTML');
        
        // Download file
        download_telegram_file($file_id, $download_path);
        
        // Rename if new name is set
        if ($new_name) {
            $target_path = $tmp_dir . "/" . $new_name;
            rename($download_path, $target_path);
            $file_to_process = $target_path;
        } else {
            $file_to_process = $download_path;
        }

        // Split if enabled and file > Telegram max limit
        $files_to_upload = [$file_to_process];
        if ($do_split && filesize($file_to_process) > MAX_FILE_SIZE) {
            sendMessage($message['chat']['id'], "🔪 Splitting file >2GB for Telegram...", null, 'HTML');
            $files_to_upload = split_file($file_to_process, $tmp_dir, MAX_FILE_SIZE);
        }

        $total_parts = count($files_to_upload);
        
        // Process each file/part
        foreach ($files_to_upload as $idx => $p) {
            $part_num = $idx + 1;
            $thumb_path = null;
            $caption = "";
            $duration = 0;
            $is_video = is_video_file($p);
            $final_video_path = $p;  // Default to original file
            
            if ($is_video) {
                // Get video info using ffprobe
                $ffprobe_cmd = "ffprobe -v quiet -print_format json -show_format -show_streams \"$p\"";
                $ffprobe_output = shell_exec($ffprobe_cmd);
                $video_info = json_decode($ffprobe_output, true);
                
                if ($video_info && isset($video_info['streams'])) {
                    foreach ($video_info['streams'] as $stream) {
                        if ($stream['codec_type'] == 'video') {
                            $duration = (int)($video_info['format']['duration'] ?? 0);
                            break;
                        }
                    }
                }
                
                // Process video
                sendMessage($message['chat']['id'], "🔄 Processing video...", null, 'HTML');
                $resized_path = $tmp_dir . "/" . pathinfo($p, PATHINFO_FILENAME) . "_resized.mp4";
                
                if (resize_video($p, $resized_path)) {
                    $final_video_path = $resized_path;
                } else {
                    $final_video_path = $p;
                }
                
                // Create HIGH QUALITY thumbnail with same dimensions
                $thumb_path = create_watermark_thumb(
                    $final_video_path, $tmp_dir,
                    $file_bot_state["thumb_opacity"],
                    $file_bot_state["thumb_textsize"], 
                    $file_bot_state["thumb_position"]
                );
                
                $caption = "**" . basename($p) . "**\n**Size:** " . human_readable_size(filesize($p)) . "\n**Duration:** {$duration}s\n**Dimensions:** " . VIDEO_WIDTH . "x" . VIDEO_HEIGHT;
                
            } else {
                $caption = "**" . basename($p) . "**\n**Size:** " . human_readable_size(filesize($p));
            }
            
            // Add metadata to caption
            if ($metadata) {
                $caption .= "\n\n**Metadata:**";
                foreach ($metadata as $k => $v) {
                    $caption .= "\n• **" . ucfirst($k) . ":** `$v`";
                }
            }
            
            // Add checksum to caption
            list($md5, $sha1) = calc_checksum($p);
            $caption .= "\n\n**Checksum:**\n**MD5:** `$md5`\n**SHA1:** `$sha1`";
            
            if ($total_parts > 1) {
                $caption .= "\n**Part:** $part_num/$total_parts";
            }

            // Upload with retry
            for ($attempt = 1; $attempt <= RETRY_COUNT; $attempt++) {
                try {
                    sendMessage($message['chat']['id'], "📤 Uploading `" . basename($p) . "` ($part_num/$total_parts)\nAttempt $attempt\n`[0%]`", null, 'HTML');
                    
                    // Use custom thumbnail if available
                    $final_thumb = null;
                    if ($custom_thumb_path && file_exists($custom_thumb_path)) {
                        $final_thumb = $custom_thumb_path;
                    } elseif ($thumb_path && file_exists($thumb_path)) {
                        $final_thumb = $thumb_path;
                    }
                    
                    if ($is_video) {
                        $result = send_telegram_video($final_video_path, $caption, $final_thumb, $duration);
                    } else {
                        $result = send_telegram_document($p, $caption, $final_thumb);
                    }
                    
                    // Check if upload was successful
                    $result_data = json_decode($result, true);
                    if ($result_data && $result_data['ok']) {
                        break;
                    } else {
                        throw new Exception("Upload failed: " . ($result_data['description'] ?? 'Unknown error'));
                    }
                    
                } catch (Exception $e) {
                    if ($attempt == RETRY_COUNT) {
                        sendMessage($message['chat']['id'], "❌ Upload Failed after " . RETRY_COUNT . " attempts:\n`{$e->getMessage()}`", null, 'HTML');
                    } else {
                        sendMessage($message['chat']['id'], "⚠️ Retrying ($attempt/" . RETRY_COUNT . ")", null, 'HTML');
                        sleep(2);  // Wait before retry
                    }
                }
            }

            // Cleanup uploaded file and temp files
            try {
                if (file_exists($p)) {
                    unlink($p);
                }
                if (isset($resized_path) && file_exists($resized_path)) {
                    unlink($resized_path);
                }
                if ($thumb_path && file_exists($thumb_path)) {
                    unlink($thumb_path);
                }
            } catch (Exception $e) {
                // Ignore cleanup errors
            }
        }

        sendMessage($message['chat']['id'], "✅ Processing Complete!\nAll files uploaded successfully.\nTemp files cleaned.", null, 'HTML');
        
    } catch (Exception $e) {
        sendMessage($message['chat']['id'], "❌ Error\n`{$e->getMessage()}`", null, 'HTML');
    } finally {
        // Cleanup temp directory
        try {
            rrmdir($tmp_dir);
        } catch (Exception $e) {
            // Ignore cleanup errors
        }
    }
}

function rrmdir($dir) {
    if (is_dir($dir)) {
        $objects = scandir($dir);
        foreach ($objects as $object) {
            if ($object != "." && $object != "..") {
                if (is_dir($dir . "/" . $object)) {
                    rrmdir($dir . "/" . $object);
                } else {
                    unlink($dir . "/" . $object);
                }
            }
        }
        rmdir($dir);
    }
}

// ==============================
// Show CSV Data
// ==============================
function show_csv_data($chat_id, $show_all = false) {
    if (!file_exists(CSV_FILE)) {
        sendMessage($chat_id, "❌ CSV file not found.");
        return;
    }
    
    $handle = fopen(CSV_FILE, "r");
    if ($handle === FALSE) {
        sendMessage($chat_id, "❌ Error opening CSV file.");
        return;
    }
    
    fgetcsv($handle);
    
    $movies = [];
    while (($row = fgetcsv($handle)) !== FALSE) {
        if (count($row) >= 3) {
            $movies[] = $row;
        }
    }
    fclose($handle);
    
    if (empty($movies)) {
        sendMessage($chat_id, "📊 CSV file is empty.");
        return;
    }
    
    $movies = array_reverse($movies);
    
    $limit = $show_all ? count($movies) : 10;
    $movies = array_slice($movies, 0, $limit);
    
    $message = "📊 CSV Movie Database\n\n";
    $message .= "📁 Total Movies: " . count($movies) . "\n";
    if (!$show_all) {
        $message .= "🔍 Showing latest 10 entries\n";
        $message .= "📋 Use '/checkcsv all' for full list\n\n";
    } else {
        $message .= "📋 Full database listing\n\n";
    }
    
    $i = 1;
    foreach ($movies as $movie) {
        $movie_name = $movie[0] ?? 'N/A';
        $message_id = $movie[1] ?? 'N/A';
        $date = $movie[2] ?? 'N/A';
        
        $message .= "$i. 🎬 " . htmlspecialchars($movie_name) . "\n";
        $message .= "   📝 ID: $message_id\n";
        $message .= "   📅 Date: $date\n\n";
        
        $i++;
        
        if (strlen($message) > 3000) {
            sendMessage($chat_id, $message, null, 'HTML');
            $message = "📊 Continuing...\n\n";
        }
    }
    
    $message .= "💾 File: " . CSV_FILE . "\n";
    $message .= "⏰ Last Updated: " . date('Y-m-d H:i:s', filemtime(CSV_FILE));
    
    sendMessage($chat_id, $message, null, 'HTML');
}

// ==============================
// Backups & daily digest
// ==============================
function auto_backup() {
    $backup_files = [CSV_FILE, USERS_FILE, STATS_FILE];
    $backup_dir = BACKUP_DIR . date('Y-m-d');
    if (!file_exists($backup_dir)) mkdir($backup_dir, 0755, true);
    foreach ($backup_files as $f) if (file_exists($f)) copy($f, $backup_dir . '/' . basename($f) . '.bak');
    $old = glob(BACKUP_DIR . '*', GLOB_ONLYDIR);
    if (count($old) > 7) {
        usort($old, function($a,$b){return filemtime($a)-filemtime($b);});
        foreach (array_slice($old, 0, count($old)-7) as $d) {
            $files = glob($d . '/*'); foreach ($files as $ff) @unlink($ff); @rmdir($d);
        }
    }
}

function send_daily_digest() {
    $yesterday = date('d-m-Y', strtotime('-1 day'));
    $y_movies = [];
    $h = fopen(CSV_FILE, "r");
    if ($h !== FALSE) {
        fgetcsv($h);
        while (($r = fgetcsv($h)) !== FALSE) {
            if (count($r)>=3 && $r[2] == $yesterday) $y_movies[] = $r[0];
        }
        fclose($h);
    }
    if (!empty($y_movies)) {
        $users_data = json_decode(file_get_contents(USERS_FILE), true);
        foreach ($users_data['users'] as $uid => $ud) {
            $msg = "📅 Daily Movie Digest\n\n";
            $msg .= "📢 Join our channel: @EntertainmentTadka786\n\n";
            $msg .= "🎬 Yesterday's Uploads (" . $yesterday . "):\n";
            foreach (array_slice($y_movies,0,10) as $m) $msg .= "• " . $m . "\n";
            if (count($y_movies)>10) $msg .= "• ... and " . (count($y_movies)-10) . " more\n";
            $msg .= "\n🔥 Total: " . count($y_movies) . " movies";
            sendMessage($uid, $msg, null, 'HTML');
        }
    }
}

// ==============================
// Other commands
// ==============================
function check_date($chat_id) {
    if (!file_exists(CSV_FILE)) { sendMessage($chat_id, "⚠️ Abhi tak koi data save nahi hua."); return; }
    $date_counts = [];
    $h=fopen(CSV_FILE,'r'); if ($h!==FALSE) {
        fgetcsv($h);
        while (($r=fgetcsv($h))!==FALSE) if (count($r)>=3) { $d=$r[2]; if(!isset($date_counts[$d])) $date_counts[$d]=0; $date_counts[$d]++; }
        fclose($h);
    }
    krsort($date_counts);
    $msg = "📅 Movies Upload Record\n\n";
    $total_days=0; $total_movies=0;
    foreach ($date_counts as $date=>$count) { $msg .= "➡️ $date: $count movies\n"; $total_days++; $total_movies += $count; }
    $msg .= "\n📊 Summary:\n";
    $msg .= "• Total Days: $total_days\n• Total Movies: $total_movies\n• Average per day: " . round($total_movies / max(1,$total_days),2);
    sendMessage($chat_id,$msg,null,'HTML');
}

function total_uploads($chat_id, $page = 1) {
    totalupload_controller($chat_id, $page);
}

function test_csv($chat_id) {
    if (!file_exists(CSV_FILE)) { sendMessage($chat_id,"⚠️ CSV file not found."); return; }
    $h = fopen(CSV_FILE,'r');
    if ($h!==FALSE) {
        fgetcsv($h);
        $i=1; $msg="";
        while (($r=fgetcsv($h))!==FALSE) {
            if (count($r)>=3) {
                $line = "$i. {$r[0]} | ID/Ref: {$r[1]} | Date: {$r[2]}\n";
                if (strlen($msg) + strlen($line) > 4000) { sendMessage($chat_id,$msg); $msg=""; }
                $msg .= $line; $i++;
            }
        }
        fclose($h);
        if (!empty($msg)) sendMessage($chat_id,$msg);
    }
}

// ==============================
// Group Message Filter
// ==============================
function is_valid_movie_query($text) {
    $text = strtolower(trim($text));
    
    // Skip commands
    if (strpos($text, '/') === 0) {
        return true; // Commands allow karo
    }
    
    // Skip very short messages
    if (strlen($text) < 3) {
        return false;
    }
    
    // Common group chat phrases block karo
    $invalid_phrases = [
        'good morning', 'good night', 'hello', 'hi ', 'hey ', 'thank you', 'thanks',
        'welcome', 'bye', 'see you', 'ok ', 'okay', 'yes', 'no', 'maybe',
        'how are you', 'whats up', 'anyone', 'someone', 'everyone',
        'problem', 'issue', 'help', 'question', 'doubt', 'query'
    ];
    
    foreach ($invalid_phrases as $phrase) {
        if (strpos($text, $phrase) !== false) {
            return false;
        }
    }
    
    // Movie-like patterns allow karo
    $movie_patterns = [
        'movie', 'film', 'video', 'download', 'watch', 'hd', 'full', 'part',
        'series', 'episode', 'season', 'bollywood', 'hollywood'
    ];
    
    foreach ($movie_patterns as $pattern) {
        if (strpos($text, $pattern) !== false) {
            return true;
        }
    }
    
    // Agar koi specific movie jaisa lagta hai (3+ characters, spaces, numbers allowed)
    if (preg_match('/^[a-zA-Z0-9\s\-\.\,]{3,}$/', $text)) {
        return true;
    }
    
    return false;
}

// ==============================
// Main update processing (webhook)
// ==============================
$update = json_decode(file_get_contents('php://input'), true);
if ($update) {
    get_cached_movies();

    // Channel post handling
    if (isset($update['channel_post'])) {
        $message = $update['channel_post'];
        $message_id = $message['message_id'];
        $chat_id = $message['chat']['id'];

        if ($chat_id == CHANNEL_ID) {
            $text = '';

            if (isset($message['caption'])) {
                $text = $message['caption'];
            }
            elseif (isset($message['text'])) {
                $text = $message['text'];
            }
            elseif (isset($message['document'])) {
                $text = $message['document']['file_name'];
            }
            else {
                $text = 'Uploaded Media - ' . date('d-m-Y H:i');
            }

            if (!empty(trim($text))) {
                append_movie($text, $message_id, date('d-m-Y'), '');
            }
        }
    }

    if (isset($update['message'])) {
        $message = $update['message'];
        $chat_id = $message['chat']['id'];
        $user_id = $message['from']['id'];
        $text = isset($message['text']) ? $message['text'] : '';
        $chat_type = $message['chat']['type'] ?? 'private';

        // GROUP MESSAGE FILTERING
        if ($chat_type !== 'private') {
            // Group mein sirf valid movie queries allow karo
            if (strpos($text, '/') === 0) {
                // Commands allow karo
            } else {
                // Random group messages check karo
                if (!is_valid_movie_query($text)) {
                    // Invalid message hai, ignore karo
                    return;
                }
            }
        }

        $users_data = json_decode(file_get_contents(USERS_FILE), true);
        if (!isset($users_data['users'][$user_id])) {
            $users_data['users'][$user_id] = [
                'first_name' => $message['from']['first_name'] ?? '',
                'last_name' => $message['from']['last_name'] ?? '',
                'username' => $message['from']['username'] ?? '',
                'joined' => date('Y-m-d H:i:s'),
                'last_active' => date('Y-m-d H:i:s'),
                'points' => 0
            ];
            $users_data['total_requests'] = ($users_data['total_requests'] ?? 0) + 1;
            file_put_contents(USERS_FILE, json_encode($users_data, JSON_PRETTY_PRINT));
            update_stats('total_users', 1);
        }
        $users_data['users'][$user_id]['last_active'] = date('Y-m-d H:i:s');
        file_put_contents(USERS_FILE, json_encode($users_data, JSON_PRETTY_PRINT));

        if (strpos($text, '/') === 0) {
            $parts = explode(' ', $text);
            $command = $parts[0];
            
            // Check if it's a file upload command
            if (in_array($command, ['/upload_help', '/setname', '/clearname', '/split_on', '/split_off', '/upload_status', '/metadata', '/setthumb', '/view_thumb', '/del_thumb'])) {
                handle_file_upload_commands($message);
            }
            // Original movie bot commands
            elseif ($command == '/checkdate') check_date($chat_id);
            elseif ($command == '/totalupload' || $command == '/totaluploads' || $command == '/TOTALUPLOAD') totalupload_controller($chat_id, 1);
            elseif ($command == '/testcsv') test_csv($chat_id);
            elseif ($command == '/checkcsv') {
                $show_all = (isset($parts[1]) && strtolower($parts[1]) == 'all');
                show_csv_data($chat_id, $show_all);
            }
            elseif ($command == '/start') {
                $welcome = "🎬 Welcome to Entertainment Tadka!\n\n";
                $welcome .= "🤖 <b>Dual Mode Bot</b>\n\n";
                $welcome .= "🎥 <b>Movie Search Bot:</b>\n";
                $welcome .= "• Simply type any movie name\n";
                $welcome .= "• Use English or Hindi\n";
                $welcome .= "• Partial names also work\n\n";
                $welcome .= "📁 <b>File Upload Bot:</b>\n";
                $welcome .= "• Use /upload_help for file upload features\n";
                $welcome .= "• Rename, 4GB split, high quality thumbnails\n";
                $welcome .= "• Metadata & custom thumbnails\n\n";
                $welcome .= "🔍 Examples:\n";
                $welcome .= "• kgf\n• pushpa\n• avengers\n• hindi movie\n\n";
                $welcome .= "📢 Join: @EntertainmentTadka786\n";
                $welcome .= "💬 Request/Help: @EntertainmentTadka0786";
                sendMessage($chat_id, $welcome, null, 'HTML');
                update_user_points($user_id, 'daily_login');
            }
            elseif ($command == '/stats' && $user_id == OWNER_ID) admin_stats($chat_id);
            elseif ($command == '/help') {
                $help = "🤖 Entertainment Tadka Bot\n\n📢 Join our channel: @EntertainmentTadka786\n\n📋 Available Commands:\n/start, /checkdate, /totalupload, /testcsv, /checkcsv, /help\n\n📁 File Upload Commands:\n/upload_help, /setname, /split_on, /metadata, /setthumb\n\n🔍 Simply type any movie name to search!";
                sendMessage($chat_id, $help, null, 'HTML');
            }
        } else if (!empty(trim($text))) {
            $lang = detect_language($text);
            send_multilingual_response($chat_id, 'searching', $lang);
            advanced_search($chat_id, $text, $user_id);
        }
        
        // Handle file uploads (documents, videos, audio)
        if (isset($message['document']) || isset($message['video']) || isset($message['audio'])) {
            handle_file_upload($message);
        }
    }

    if (isset($update['callback_query'])) {
        $query = $update['callback_query'];
        $message = $query['message'];
        $chat_id = $message['chat']['id'];
        $data = $query['data'];

        global $movie_messages;
        $movie_lower = strtolower($data);
        if (isset($movie_messages[$movie_lower])) {
            $entries = $movie_messages[$movie_lower];
            $cnt = 0;
            foreach ($entries as $entry) {
                deliver_item_to_chat($chat_id, $entry);
                usleep(200000);
                $cnt++;
            }
            sendMessage($chat_id, "✅ '$data' ke $cnt messages forward/send ho gaye!\n\n📢 Join our channel: @EntertainmentTadka786");
            answerCallbackQuery($query['id'], "🎬 $cnt items sent!");
        }
        elseif (strpos($data, 'tu_prev_') === 0) {
            $page = (int)str_replace('tu_prev_','', $data);
            totalupload_controller($chat_id, $page);
            answerCallbackQuery($query['id'], "Page $page");
        }
        elseif (strpos($data, 'tu_next_') === 0) {
            $page = (int)str_replace('tu_next_','', $data);
            totalupload_controller($chat_id, $page);
            answerCallbackQuery($query['id'], "Page $page");
        }
        elseif (strpos($data, 'tu_view_') === 0) {
            $page = (int)str_replace('tu_view_','', $data);
            $all = get_all_movies_list();
            $pg = paginate_movies($all, $page);
            forward_page_movies($chat_id, $pg['slice']);
            answerCallbackQuery($query['id'], "Re-sent current page movies");
        }
        elseif ($data === 'tu_stop') {
            sendMessage($chat_id, "✅ Pagination stopped. Type /totalupload to start again.");
            answerCallbackQuery($query['id'], "Stopped");
        }
        elseif ($data === 'current_page') {
            answerCallbackQuery($query['id'], "You're on this page");
        }
        elseif (strpos($data, 'uploads_page_') === 0) {
            $page = intval(str_replace('uploads_page_', '', $data));
            total_uploads($chat_id, $page);
            answerCallbackQuery($query['id'], "Page $page loaded");
        }
        else {
            sendMessage($chat_id, "❌ Movie not found: " . $data);
            answerCallbackQuery($query['id'], "❌ Movie not available");
        }
    }

    if (date('H:i') == '00:00') auto_backup();
    if (date('H:i') == '08:00') send_daily_digest();
}

// Manual save test function
if (isset($_GET['test_save'])) {
    function manual_save_to_csv($movie_name, $message_id) {
        $entry = [$movie_name, $message_id, date('d-m-Y'), ''];
        $handle = fopen(CSV_FILE, "a");
        if ($handle !== FALSE) {
            fputcsv($handle, $entry);
            fclose($handle);
            @chmod(CSV_FILE, 0600);
            return true;
        }
        return false;
    }
    
    manual_save_to_csv("Metro In Dino (2025)", 1924);
    manual_save_to_csv("Metro In Dino 2025 WebRip 480p x265 HEVC 10bit Hindi ESubs", 1925);
    manual_save_to_csv("Metro In Dino (2025) Hindi 720p HEVC HDRip x265 AAC 5.1 ESubs", 1926);
    manual_save_to_csv("Metro In Dino (2025) Hindi 720p HDRip x264 AAC 5.1 ESubs", 1927);
    manual_save_to_csv("Metro In Dino (2025) Hindi 1080p HDRip x264 AAC 5.1 ESubs", 1928);
    
    echo "✅ All 5 movies manually save ho gayi!<br>";
    echo "📊 <a href='?check_csv=1'>Check CSV</a> | ";
    echo "<a href='?setwebhook=1'>Reset Webhook</a>";
    exit;
}

// Check CSV content
if (isset($_GET['check_csv'])) {
    echo "<h3>CSV Content:</h3>";
    if (file_exists(CSV_FILE)) {
        $lines = file(CSV_FILE);
        foreach ($lines as $line) {
            echo htmlspecialchars($line) . "<br>";
        }
    } else {
        echo "❌ CSV file not found!";
    }
    exit;
}

if (php_sapi_name() === 'cli' || isset($_GET['setwebhook'])) {
    $webhook_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";
    $result = apiRequest('setWebhook', ['url' => $webhook_url]);
    echo "<h1>Webhook Setup</h1>";
    echo "<p>Result: " . htmlspecialchars($result) . "</p>";
    echo "<p>Webhook URL: " . htmlspecialchars($webhook_url) . "</p>";
    $bot_info = json_decode(apiRequest('getMe'), true);
    if ($bot_info && isset($bot_info['ok']) && $bot_info['ok']) {
        echo "<h2>Bot Info</h2>";
        echo "<p>Name: " . htmlspecialchars($bot_info['result']['first_name']) . "</p>";
        echo "<p>Username: @" . htmlspecialchars($bot_info['result']['username']) . "</p>";
        echo "<p>Channel: @EntertainmentTadka786</p>";
    }
    exit;
}

if (!isset($update) || !$update) {
    $stats = get_stats();
    $users_data = json_decode(file_get_contents(USERS_FILE), true);
    echo "<h1>🎬 Entertainment Tadka Bot</h1>";
    echo "<p><strong>Telegram Channel:</strong> @EntertainmentTadka786</p>";
    echo "<p><strong>Status:</strong> ✅ Running</p>";
    echo "<p><strong>Total Movies:</strong> " . ($stats['total_movies'] ?? 0) . "</p>";
    echo "<p><strong>Total Users:</strong> " . count($users_data['users'] ?? []) . "</p>";
    echo "<p><strong>Total Searches:</strong> " . ($stats['total_searches'] ?? 0) . "</p>";
    echo "<h3>🚀 Quick Setup</h3>";
    echo "<p><a href='?setwebhook=1'>Set Webhook Now</a></p>";
    echo "<h3>📋 Available Commands</h3>";
    echo "<ul>";
    echo "<li><code>/start</code> - Welcome message</li>";
    echo "<li><code>/checkdate</code> - Date-wise stats</li>";
    echo "<li><code>/totalupload</code> - Upload statistics</li>";
    echo "<li><code>/testcsv</code> - View all movies</li>";
    echo "<li><code>/checkcsv</code> - Check CSV data</li>";
    echo "<li><code>/help</code> - Help message</li>";
    echo "<li><code>/stats</code> - Admin statistics</li>";
    echo "<li><code>/upload_help</code> - File upload features</li>";
    echo "</ul>";
}
?>
