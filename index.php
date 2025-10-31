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
define('CHANNEL_1_ID', Config::get('CHANNEL_1_ID', '-1003181705395'));
define('CHANNEL_2_ID', Config::get('CHANNEL_2_ID', '-1002964109368'));
define('CHANNEL_3_ID', Config::get('CHANNEL_3_ID', '-1003083386043'));
define('PRIVATE_CHANNEL_ID', Config::get('PRIVATE_CHANNEL_ID', '-1002337293281'));
define('GROUP_ID', Config::get('GROUP_ID', '-1003083386043'));
define('BOT_ID', Config::get('BOT_ID', '-8315381064'));
define('OWNER_ID', Config::get('OWNER_ID', '1080317415'));
define('APP_API_ID', Config::get('APP_API_ID', '21944581'));
define('APP_API_HASH', Config::get('APP_API_HASH', '7b1c174a5cd3466e25a976c39a791737'));
define('MAINTENANCE_MODE', Config::get('MAINTENANCE_MODE', 'false') === 'true');

define('CSV_FILE_1', Config::get('CSV_FILE_1', 'movies_channel1.csv'));
define('CSV_FILE_2', Config::get('CSV_FILE_2', 'movies_channel2.csv'));
define('CSV_FILE_3', Config::get('CSV_FILE_3', 'movies_channel3.csv'));
define('PRIVATE_CSV', Config::get('PRIVATE_CSV', 'movies_private.csv'));
define('COMBINED_CSV', Config::get('COMBINED_CSV', 'movies_combined.csv'));
define('USERS_FILE', Config::get('USERS_FILE', 'users.json'));
define('STATS_FILE', Config::get('STATS_FILE', 'bot_stats.json'));
define('BACKUP_DIR', Config::get('BACKUP_DIR', 'backups/'));
define('CACHE_EXPIRY', (int)Config::get('CACHE_EXPIRY', 300));
define('ITEMS_PER_PAGE', (int)Config::get('ITEMS_PER_PAGE', 5));

// New configuration constants
define('FAVORITES_FILE', 'user_favorites.json');
define('DOWNLOAD_STATS', 'download_stats.json');
define('MOVIE_REQUESTS', 'movie_requests.json');
define('USER_PREFERENCES', 'user_preferences.json');
define('RATE_LIMIT_FILE', 'rate_limits.json');
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

// Channel display names
$channel_names = [
    CHANNEL_1_ID => '🎬 Entertainment Tadka',
    CHANNEL_2_ID => '💾 ET Backup', 
    CHANNEL_3_ID => '🎭 Theater Prints',
    PRIVATE_CHANNEL_ID => '🔒 Private Channel'
];

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

// Rate limiting variables
$user_requests = [];
$user_last_request = [];

// File initialization
$csv_files = [CSV_FILE_1, CSV_FILE_2, CSV_FILE_3, PRIVATE_CSV, COMBINED_CSV];
foreach ($csv_files as $csv_file) {
    if (!file_exists($csv_file)) {
        file_put_contents($csv_file, "movie_name,message_id,date,video_path,channel_id\n");
        @chmod($csv_file, 0600);
    }
}

$additional_files = [USERS_FILE, STATS_FILE, FAVORITES_FILE, DOWNLOAD_STATS, MOVIE_REQUESTS, USER_PREFERENCES, RATE_LIMIT_FILE];
foreach ($additional_files as $file) {
    if (!file_exists($file)) {
        if ($file === USERS_FILE) {
            file_put_contents($file, json_encode(['users' => [], 'total_requests' => 0, 'message_logs' => []]));
        } elseif ($file === STATS_FILE) {
            file_put_contents($file, json_encode([
                'total_movies' => 0, 
                'total_users' => 0, 
                'total_searches' => 0,
                'total_downloads' => 0,
                'last_updated' => date('Y-m-d H:i:s')
            ]));
        } else {
            file_put_contents($file, json_encode([]));
        }
        @chmod($file, 0600);
    }
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
// RATE LIMITING SYSTEM
// ==============================
function check_rate_limit($user_id, $action = 'message') {
    global $user_requests, $user_last_request;
    
    $current_time = time();
    $window = 60; // 1 minute window
    
    // Initialize user data if not exists
    if (!isset($user_requests[$user_id])) {
        $user_requests[$user_id] = [];
        $user_last_request[$user_id] = $current_time;
    }
    
    // Initialize action counter
    if (!isset($user_requests[$user_id][$action])) {
        $user_requests[$user_id][$action] = 0;
    }
    
    // Reset counter if window passed
    if ($current_time - $user_last_request[$user_id] > $window) {
        $user_requests[$user_id] = [];
        $user_last_request[$user_id] = $current_time;
    }
    
    // Different limits for different actions
    $limits = [
        'message' => 15,    // 15 messages per minute
        'search' => 10,     // 10 searches per minute
        'download' => 20,   // 20 downloads per minute
        'callback' => 30    // 30 callbacks per minute
    ];
    
    $limit = $limits[$action] ?? 15;
    
    if ($user_requests[$user_id][$action] >= $limit) {
        return false;
    }
    
    $user_requests[$user_id][$action]++;
    return true;
}

function get_rate_limit_message($action) {
    $messages = [
        'message' => "⏳ Please wait a bit before sending more messages.",
        'search' => "🔍 Too many searches! Please wait 1 minute.",
        'download' => "📥 Download limit exceeded. Please wait.",
        'callback' => "⚡ Too many actions! Slow down please."
    ];
    
    return $messages[$action] ?? "⏳ Rate limit exceeded. Please wait.";
}

// ==============================
// ADVANCED SEARCH SYSTEM
// ==============================
function advanced_search_with_filters($chat_id, $query, $filters = [], $user_id = null) {
    if (!check_rate_limit($user_id, 'search')) {
        sendMessage($chat_id, get_rate_limit_message('search'));
        return [];
    }
    
    $all_movies = get_all_movies_list();
    $filtered_movies = [];
    
    foreach ($all_movies as $movie) {
        $matches = true;
        $movie_text = strtolower($movie['movie_name']);
        
        // Text search
        if (!empty($query) && strpos($movie_text, strtolower($query)) === false) {
            $matches = false;
        }
        
        // Year filter
        if ($matches && !empty($filters['year'])) {
            $year_found = false;
            foreach ((array)$filters['year'] as $year) {
                if (strpos($movie_text, $year) !== false) {
                    $year_found = true;
                    break;
                }
            }
            if (!$year_found) $matches = false;
        }
        
        // Quality filter
        if ($matches && !empty($filters['quality'])) {
            $quality_found = false;
            foreach ((array)$filters['quality'] as $quality) {
                if (strpos($movie_text, $quality) !== false) {
                    $quality_found = true;
                    break;
                }
            }
            if (!$quality_found) $matches = false;
        }
        
        // Language filter
        if ($matches && !empty($filters['language'])) {
            $lang_found = false;
            foreach ((array)$filters['language'] as $lang) {
                if (strpos($movie_text, strtolower($lang)) !== false) {
                    $lang_found = true;
                    break;
                }
            }
            if (!$lang_found) $matches = false;
        }
        
        if ($matches) {
            $filtered_movies[] = $movie;
        }
    }
    
    return $filtered_movies;
}

function show_search_filters($chat_id, $query) {
    $keyboard = [
        'inline_keyboard' => [
            [
                ['text' => '🎬 Search All', 'callback_data' => "search_all:{$query}"]
            ],
            [
                ['text' => '📅 Year 2024', 'callback_data' => "search_filter:{$query}:year:2024"],
                ['text' => '📅 Year 2023', 'callback_data' => "search_filter:{$query}:year:2023"],
                ['text' => '📅 Year 2022', 'callback_data' => "search_filter:{$query}:year:2022"]
            ],
            [
                ['text' => '🖥️ 1080p', 'callback_data' => "search_filter:{$query}:quality:1080p"],
                ['text' => '📱 720p', 'callback_data' => "search_filter:{$query}:quality:720p"],
                ['text' => '💾 480p', 'callback_data' => "search_filter:{$query}:quality:480p"]
            ],
            [
                ['text' => '🔤 Hindi', 'callback_data' => "search_filter:{$query}:language:Hindi"],
                ['text' => '🔤 English', 'callback_data' => "search_filter:{$query}:language:English"],
                ['text' => '🔤 Tamil', 'callback_data' => "search_filter:{$query}:language:Tamil"]
            ],
            [
                ['text' => '🎭 Action', 'callback_data' => "search_filter:{$query}:genre:Action"],
                ['text' => '😂 Comedy', 'callback_data' => "search_filter:{$query}:genre:Comedy"],
                ['text' => '💖 Romance', 'callback_data' => "search_filter:{$query}:genre:Romance"]
            ]
        ]
    ];
    
    $message = "🔍 <b>Advanced Search Filters</b>\n\n";
    $message .= "🎬 <b>Movie:</b> {$query}\n\n";
    $message .= "📊 Select filters to refine your search:";
    
    sendMessage($chat_id, $message, $keyboard, 'HTML');
}

function handle_search_with_filters($chat_id, $query, $filters, $user_id) {
    $results = advanced_search_with_filters($chat_id, $query, $filters, $user_id);
    
    if (empty($results)) {
        $filter_text = implode(', ', array_values($filters));
        sendMessage($chat_id, "❌ No movies found for '{$query}' with filters: {$filter_text}");
        return;
    }
    
    $message = "✅ <b>Filtered Search Results</b>\n\n";
    $message .= "🎬 <b>Query:</b> {$query}\n";
    $message .= "📊 <b>Filters:</b> " . implode(', ', array_values($filters)) . "\n";
    $message .= "📈 <b>Found:</b> " . count($results) . " movies\n\n";
    
    $i = 1;
    foreach (array_slice($results, 0, 8) as $movie) {
        $message .= "{$i}. " . htmlspecialchars($movie['movie_name']) . "\n";
        $i++;
    }
    
    if (count($results) > 8) {
        $message .= "... and " . (count($results) - 8) . " more\n";
    }
    
    // Create inline keyboard for results
    $keyboard = ['inline_keyboard' => []];
    $temp_row = [];
    
    foreach (array_slice($results, 0, 6) as $movie) {
        $display_name = shorten_movie_name($movie['movie_name'], 15);
        $temp_row[] = ['text' => "🎬 " . $display_name, 'callback_data' => $movie['movie_name']];
        
        if (count($temp_row) == 2) {
            $keyboard['inline_keyboard'][] = $temp_row;
            $temp_row = [];
        }
    }
    
    if (!empty($temp_row)) {
        $keyboard['inline_keyboard'][] = $temp_row;
    }
    
    // Add action buttons
    $keyboard['inline_keyboard'][] = [
        ['text' => '🔍 New Search', 'callback_data' => 'new_search'],
        ['text' => '📋 Browse All', 'callback_data' => 'tu_prev_1']
    ];
    
    sendMessage($chat_id, $message, $keyboard, 'HTML');
}

// ==============================
// DOWNLOAD TRACKING SYSTEM
// ==============================
function track_download($user_id, $movie_name) {
    if (!check_rate_limit($user_id, 'download')) {
        return false;
    }
    
    $stats = json_decode(file_get_contents(DOWNLOAD_STATS), true) ?? [
        'total_downloads' => 0,
        'movie_downloads' => [],
        'user_downloads' => [],
        'daily_downloads' => [],
        'user_history' => []
    ];
    
    $today = date('Y-m-d');
    
    // Update total downloads
    $stats['total_downloads']++;
    
    // Update movie downloads
    if (!isset($stats['movie_downloads'][$movie_name])) {
        $stats['movie_downloads'][$movie_name] = 0;
    }
    $stats['movie_downloads'][$movie_name]++;
    
    // Update user downloads
    if (!isset($stats['user_downloads'][$user_id])) {
        $stats['user_downloads'][$user_id] = 0;
    }
    $stats['user_downloads'][$user_id]++;
    
    // Update daily downloads
    if (!isset($stats['daily_downloads'][$today])) {
        $stats['daily_downloads'][$today] = 0;
    }
    $stats['daily_downloads'][$today]++;
    
    // Update user history
    if (!isset($stats['user_history'][$user_id])) {
        $stats['user_history'][$user_id] = [];
    }
    $stats['user_history'][$user_id][] = [
        'movie' => $movie_name,
        'timestamp' => time(),
        'date' => date('Y-m-d H:i:s')
    ];
    
    // Keep only last 50 downloads per user
    if (count($stats['user_history'][$user_id]) > 50) {
        $stats['user_history'][$user_id] = array_slice($stats['user_history'][$user_id], -50);
    }
    
    file_put_contents(DOWNLOAD_STATS, json_encode($stats, JSON_PRETTY_PRINT));
    
    // Update user download count in users file
    $users_data = json_decode(file_get_contents(USERS_FILE), true);
    if (isset($users_data['users'][$user_id])) {
        $users_data['users'][$user_id]['download_count'] = ($users_data['users'][$user_id]['download_count'] ?? 0) + 1;
        file_put_contents(USERS_FILE, json_encode($users_data, JSON_PRETTY_PRINT));
    }
    
    return true;
}

function get_popular_movies($limit = 10) {
    $stats = json_decode(file_get_contents(DOWNLOAD_STATS), true) ?? ['movie_downloads' => []];
    
    arsort($stats['movie_downloads']);
    return array_slice($stats['movie_downloads'], 0, $limit, true);
}

function get_user_download_history($user_id, $limit = 10) {
    $stats = json_decode(file_get_contents(DOWNLOAD_STATS), true) ?? ['user_history' => []];
    
    $user_history = $stats['user_history'][$user_id] ?? [];
    return array_slice($user_history, -$limit);
}

function show_popular_movies($chat_id) {
    $popular = get_popular_movies(10);
    
    if (empty($popular)) {
        sendMessage($chat_id, "📊 <b>Popular Movies</b>\n\nNo download data available yet.");
        return;
    }
    
    $message = "🔥 <b>Most Popular Movies</b>\n\n";
    
    $i = 1;
    foreach ($popular as $movie => $downloads) {
        $stars = $downloads > 100 ? "⭐⭐⭐⭐⭐" : ($downloads > 50 ? "⭐⭐⭐⭐" : ($downloads > 20 ? "⭐⭐⭐" : "⭐"));
        $message .= "{$i}. <b>" . htmlspecialchars($movie) . "</b>\n   📥 {$downloads} downloads {$stars}\n\n";
        $i++;
    }
    
    $message .= "━━━━━━━━━━━━━━━━━━━━\n";
    $message .= "📈 Based on download statistics";
    
    sendMessage($chat_id, $message, null, 'HTML');
}

function show_download_history($chat_id, $user_id) {
    $history = get_user_download_history($user_id, 10);
    
    if (empty($history)) {
        sendMessage($chat_id, "📥 <b>Your Download History</b>\n\nNo downloads yet!\n\nStart searching for movies to build your history.");
        return;
    }
    
    $message = "📥 <b>Your Recent Downloads</b>\n\n";
    
    $i = 1;
    foreach (array_reverse($history) as $download) {
        $time_ago = time_ago(strtotime($download['date']));
        $message .= "{$i}. <b>" . htmlspecialchars($download['movie']) . "</b>\n   ⏰ {$time_ago}\n\n";
        $i++;
    }
    
    $keyboard = [
        'inline_keyboard' => [
            [
                ['text' => '🚀 Download Again', 'callback_data' => 'download_recent'],
                ['text' => '📊 View Stats', 'callback_data' => 'view_stats']
            ]
        ]
    ];
    
    sendMessage($chat_id, $message, $keyboard, 'HTML');
}

function time_ago($timestamp) {
    $diff = time() - $timestamp;
    
    if ($diff < 60) return "Just now";
    if ($diff < 3600) return floor($diff / 60) . " minutes ago";
    if ($diff < 86400) return floor($diff / 3600) . " hours ago";
    if ($diff < 2592000) return floor($diff / 86400) . " days ago";
    return floor($diff / 2592000) . " months ago";
}

// ==============================
// FAVORITES SYSTEM
// ==============================
function add_to_favorites($user_id, $movie_name) {
    $favorites = json_decode(file_get_contents(FAVORITES_FILE), true) ?? [];
    
    if (!isset($favorites[$user_id])) {
        $favorites[$user_id] = [];
    }
    
    // Check if already in favorites
    foreach ($favorites[$user_id] as $fav) {
        if ($fav['movie'] === $movie_name) {
            return false; // Already in favorites
        }
    }
    
    // Add to favorites with timestamp
    $favorites[$user_id][] = [
        'movie' => $movie_name,
        'added' => date('Y-m-d H:i:s'),
        'timestamp' => time()
    ];
    
    file_put_contents(FAVORITES_FILE, json_encode($favorites, JSON_PRETTY_PRINT));
    return true;
}

function remove_from_favorites($user_id, $movie_name) {
    $favorites = json_decode(file_get_contents(FAVORITES_FILE), true) ?? [];
    
    if (!isset($favorites[$user_id])) {
        return false;
    }
    
    $initial_count = count($favorites[$user_id]);
    $favorites[$user_id] = array_filter($favorites[$user_id], function($fav) use ($movie_name) {
        return $fav['movie'] !== $movie_name;
    });
    
    // Re-index array
    $favorites[$user_id] = array_values($favorites[$user_id]);
    
    file_put_contents(FAVORITES_FILE, json_encode($favorites, JSON_PRETTY_PRINT));
    return count($favorites[$user_id]) < $initial_count;
}

function get_user_favorites($user_id) {
    $favorites = json_decode(file_get_contents(FAVORITES_FILE), true) ?? [];
    return $favorites[$user_id] ?? [];
}

function is_movie_in_favorites($user_id, $movie_name) {
    $favorites = get_user_favorites($user_id);
    
    foreach ($favorites as $fav) {
        if ($fav['movie'] === $movie_name) {
            return true;
        }
    }
    
    return false;
}

function show_favorites($chat_id, $user_id) {
    $favorites = get_user_favorites($user_id);
    
    if (empty($favorites)) {
        $message = "⭐ <b>Your Favorites</b>\n\n";
        $message .= "No favorite movies yet!\n\n";
        $message .= "💡 <b>How to add favorites:</b>\n";
        $message .= "1. Search for a movie\n";
        $message .= "2. Click the '⭐ Add to Favorites' button\n";
        $message .= "3. Access them quickly here!";
        
        sendMessage($chat_id, $message, null, 'HTML');
        return;
    }
    
    $message = "⭐ <b>Your Favorite Movies</b>\n\n";
    
    $i = 1;
    foreach (array_slice($favorites, 0, 10) as $fav) {
        $time_ago = time_ago($fav['timestamp']);
        $message .= "{$i}. <b>" . htmlspecialchars($fav['movie']) . "</b>\n   ⭐ Added {$time_ago}\n\n";
        $i++;
    }
    
    if (count($favorites) > 10) {
        $message .= "... and " . (count($favorites) - 10) . " more favorites\n\n";
    }
    
    $keyboard = [
        'inline_keyboard' => [
            [
                ['text' => '🚀 Download All', 'callback_data' => 'download_favorites'],
                ['text' => '🗑️ Clear All', 'callback_data' => 'clear_favorites']
            ],
            [
                ['text' => '📥 Download Recent', 'callback_data' => 'download_recent_fav']
            ]
        ]
    ];
    
    sendMessage($chat_id, $message, $keyboard, 'HTML');
}

function download_favorites($chat_id, $user_id) {
    $favorites = get_user_favorites($user_id);
    $all_movies = get_all_movies_list();
    
    if (empty($favorites)) {
        sendMessage($chat_id, "❌ No favorites to download!");
        return;
    }
    
    $progress_msg = sendMessage($chat_id, "⏳ Downloading your favorites...\n✅ 0/" . count($favorites) . " completed");
    
    $success_count = 0;
    $i = 1;
    
    foreach ($favorites as $fav) {
        $movie_name = $fav['movie'];
        
        // Find movie in database
        foreach ($all_movies as $movie) {
            if ($movie['movie_name'] === $movie_name) {
                $success = deliver_item_premium($chat_id, $movie, $user_id);
                if ($success) {
                    $success_count++;
                    track_download($user_id, $movie_name);
                }
                break;
            }
        }
        
        // Update progress every 2 downloads
        if ($i % 2 === 0) {
            editMessage($chat_id, $progress_msg, "⏳ Downloading your favorites...\n✅ {$success_count}/" . count($favorites) . " completed");
        }
        
        usleep(500000); // 0.5 second delay
        $i++;
    }
    
    editMessage($chat_id, $progress_msg, "✅ Favorites Download Complete!\n📥 Successfully downloaded {$success_count}/" . count($favorites) . " movies");
}

// ==============================
// MOVIE REQUEST SYSTEM
// ==============================
function add_movie_request($user_id, $movie_name) {
    $requests = json_decode(file_get_contents(MOVIE_REQUESTS), true) ?? [];
    
    if (!isset($requests[$movie_name])) {
        $requests[$movie_name] = [
            'count' => 0,
            'users' => [],
            'first_requested' => date('Y-m-d H:i:s'),
            'last_requested' => date('Y-m-d H:i:s'),
            'status' => 'pending' // pending, approved, completed
        ];
    }
    
    // Check if user already requested
    if (in_array($user_id, $requests[$movie_name]['users'])) {
        return ['success' => false, 'message' => 'already_requested'];
    }
    
    // Add user to request
    $requests[$movie_name]['count']++;
    $requests[$movie_name]['users'][] = $user_id;
    $requests[$movie_name]['last_requested'] = date('Y-m-d H:i:s');
    
    file_put_contents(MOVIE_REQUESTS, json_encode($requests, JSON_PRETTY_PRINT));
    return ['success' => true, 'message' => 'added', 'total_requests' => $requests[$movie_name]['count']];
}

function get_top_requests($limit = 10) {
    $requests = json_decode(file_get_contents(MOVIE_REQUESTS), true) ?? [];
    
    // Sort by request count
    uasort($requests, function($a, $b) {
        return $b['count'] - $a['count'];
    });
    
    return array_slice($requests, 0, $limit, true);
}

function mark_request_completed($movie_name) {
    $requests = json_decode(file_get_contents(MOVIE_REQUESTS), true) ?? [];
    
    if (isset($requests[$movie_name])) {
        $requests[$movie_name]['status'] = 'completed';
        $requests[$movie_name]['completed_at'] = date('Y-m-d H:i:s');
        
        file_put_contents(MOVIE_REQUESTS, json_encode($requests, JSON_PRETTY_PRINT));
        
        // Notify users who requested this movie
        notify_request_completed($movie_name, $requests[$movie_name]['users']);
        
        return true;
    }
    
    return false;
}

function notify_request_completed($movie_name, $user_ids) {
    $message = "🎉 <b>MOVIE REQUEST FULFILLED!</b>\n\n";
    $message .= "✅ <b>{$movie_name}</b> is now available!\n\n";
    $message .= "🚀 Search for it or check /recent to download.";
    
    foreach ($user_ids as $user_id) {
        try {
            sendMessage($user_id, $message, null, 'HTML');
            usleep(100000); // 0.1 second delay
        } catch (Exception $e) {
            // Skip failed notifications
        }
    }
}

function show_top_requests($chat_id, $limit = 10) {
    $requests = get_top_requests($limit);
    
    if (empty($requests)) {
        sendMessage($chat_id, "📋 <b>Movie Requests</b>\n\nNo movie requests yet!\n\nUse /request [movie name] to request a movie.");
        return;
    }
    
    $message = "📋 <b>Most Requested Movies</b>\n\n";
    
    $i = 1;
    foreach ($requests as $movie => $data) {
        $status_emoji = $data['status'] === 'completed' ? '✅' : '⏳';
        $message .= "{$i}. <b>{$movie}</b>\n   📊 {$data['count']} requests {$status_emoji}\n\n";
        $i++;
    }
    
    $keyboard = [
        'inline_keyboard' => [
            [
                ['text' => '📝 Make Request', 'callback_data' => 'make_request'],
                ['text' => '🔄 Refresh', 'callback_data' => 'refresh_requests']
            ]
        ]
    ];
    
    sendMessage($chat_id, $message, $keyboard, 'HTML');
}

function handle_movie_request($chat_id, $user_id, $movie_name) {
    if (strlen($movie_name) < 3) {
        sendMessage($chat_id, "❌ Please enter a valid movie name (at least 3 characters).");
        return;
    }
    
    if (strlen($movie_name) > 100) {
        sendMessage($chat_id, "❌ Movie name too long. Please keep it under 100 characters.");
        return;
    }
    
    $result = add_movie_request($user_id, $movie_name);
    
    if ($result['success']) {
        if ($result['message'] === 'added') {
            $message = "✅ <b>Request Added Successfully!</b>\n\n";
            $message .= "🎬 <b>{$movie_name}</b>\n";
            $message .= "📊 Total Requests: {$result['total_requests']}\n\n";
            $message .= "📋 We'll notify you when this movie is available!\n";
            $message .= "View all requests: /requests";
        } else {
            $message = "ℹ️ You've already requested this movie!\n\n";
            $message .= "View all requests: /requests";
        }
    } else {
        $message = "❌ Failed to add request. Please try again.";
    }
    
    sendMessage($chat_id, $message, null, 'HTML');
}

// ==============================
// NOTIFICATION SYSTEM
// ==============================
function set_user_preference($user_id, $key, $value) {
    $prefs = json_decode(file_get_contents(USER_PREFERENCES), true) ?? [];
    
    if (!isset($prefs[$user_id])) {
        $prefs[$user_id] = [];
    }
    
    $prefs[$user_id][$key] = $value;
    file_put_contents(USER_PREFERENCES, json_encode($prefs, JSON_PRETTY_PRINT));
}

function get_user_preference($user_id, $key, $default = null) {
    $prefs = json_decode(file_get_contents(USER_PREFERENCES), true) ?? [];
    return $prefs[$user_id][$key] ?? $default;
}

function notify_users_about_new_movie($movie_name) {
    $users_data = json_decode(file_get_contents(USERS_FILE), true);
    $prefs = json_decode(file_get_contents(USER_PREFERENCES), true) ?? [];
    
    $notification = "🎬 <b>NEW MOVIE AVAILABLE!</b>\n\n";
    $notification .= "📢 <b>{$movie_name}</b> has been added to our collection!\n\n";
    $notification .= "🔍 Search for it or use /recent to see latest additions.";
    
    $sent_count = 0;
    $total_users = count($users_data['users']);
    
    foreach ($users_data['users'] as $user_id => $user) {
        $wants_notifications = get_user_preference($user_id, 'notifications', true);
        
        if ($wants_notifications) {
            try {
                sendMessage($user_id, $notification, null, 'HTML');
                $sent_count++;
                usleep(50000); // 0.05 second delay
            } catch (Exception $e) {
                // Skip failed notifications
            }
        }
    }
    
    return $sent_count;
}

function show_user_settings($chat_id, $user_id) {
    $notifications = get_user_preference($user_id, 'notifications', true);
    $daily_digest = get_user_preference($user_id, 'daily_digest', false);
    $language = get_user_preference($user_id, 'language', 'english');
    
    $settings = "⚙️ <b>Your Settings</b>\n\n";
    $settings .= "🔔 <b>Notifications:</b> " . ($notifications ? "✅ ON" : "❌ OFF") . "\n";
    $settings .= "🌐 <b>Language:</b> " . ucfirst($language) . "\n";
    $settings .= "📊 <b>Daily Digest:</b> " . ($daily_digest ? "✅ ON" : "❌ OFF") . "\n\n";
    
    $settings .= "━━━━━━━━━━━━━━━━━━━━\n";
    $settings .= "🔧 <b>Quick Settings:</b>";
    
    $keyboard = [
        'inline_keyboard' => [
            [
                ['text' => ($notifications ? '🔔 Notifications ON' : '🔕 Notifications OFF'), 'callback_data' => 'toggle_notifications']
            ],
            [
                ['text' => '📊 ' . ($daily_digest ? 'Daily Digest ON' : 'Daily Digest OFF'), 'callback_data' => 'toggle_digest'],
                ['text' => '🌐 Language', 'callback_data' => 'change_language']
            ],
            [
                ['text' => '📈 View Stats', 'callback_data' => 'view_pref_stats'],
                ['text' => '🔄 Reset', 'callback_data' => 'reset_preferences']
            ]
        ]
    ];
    
    sendMessage($chat_id, $settings, $keyboard, 'HTML');
}

function toggle_notifications($chat_id, $user_id) {
    $current = get_user_preference($user_id, 'notifications', true);
    $new_value = !$current;
    set_user_preference($user_id, 'notifications', $new_value);
    
    $status = $new_value ? "✅ ON" : "❌ OFF";
    $message = "🔔 <b>Notifications {$status}</b>\n\n";
    $message .= $new_value ? 
        "You will receive notifications about new movies and updates." :
        "You will NOT receive any notifications from the bot.";
    
    sendMessage($chat_id, $message, null, 'HTML');
}

function toggle_daily_digest($chat_id, $user_id) {
    $current = get_user_preference($user_id, 'daily_digest', false);
    $new_value = !$current;
    set_user_preference($user_id, 'daily_digest', $new_value);
    
    $status = $new_value ? "✅ ON" : "❌ OFF";
    $message = "📊 <b>Daily Digest {$status}</b>\n\n";
    $message .= $new_value ? 
        "You will receive daily updates about new movies and popular content." :
        "Daily digest emails are now disabled.";
    
    sendMessage($chat_id, $message, null, 'HTML');
}

// ==============================
// MULTI-CHANNEL CSV MANAGEMENT
// ==============================
function get_channel_csv($channel_id) {
    $channel_csv_map = [
        CHANNEL_1_ID => CSV_FILE_1,
        CHANNEL_2_ID => CSV_FILE_2, 
        CHANNEL_3_ID => CSV_FILE_3,
        PRIVATE_CHANNEL_ID => PRIVATE_CSV
    ];
    return $channel_csv_map[$channel_id] ?? CSV_FILE_1;
}

function append_movie_to_channel($movie_name, $message_id_raw, $channel_id, $date = null, $video_path = '') {
    if (empty(trim($movie_name))) return;
    
    if ($date === null) $date = date('d-m-Y');
    $csv_file = get_channel_csv($channel_id);
    
    // Ensure CSV file exists
    if (!file_exists($csv_file)) {
        file_put_contents($csv_file, "movie_name,message_id,date,video_path,channel_id\n");
        @chmod($csv_file, 0600);
    }
    
    $entry = [$movie_name, $message_id_raw, $date, $video_path, $channel_id];
    $handle = fopen($csv_file, "a");
    fputcsv($handle, $entry);
    fclose($handle);
    
    // Also update combined CSV
    update_combined_csv();
    
    // Update global caches
    update_global_caches($movie_name, $message_id_raw, $date, $video_path, $channel_id);
    
    // Check if this movie was requested and mark as completed
    $requests = json_decode(file_get_contents(MOVIE_REQUESTS), true) ?? [];
    if (isset($requests[$movie_name]) && $requests[$movie_name]['status'] === 'pending') {
        mark_request_completed($movie_name);
    }
    
    // Send notification to users
    $notified_count = notify_users_about_new_movie($movie_name);
    
    update_stats('total_movies', 1);
}

function update_combined_csv() {
    $all_movies = [];
    $csv_files = [CSV_FILE_1, CSV_FILE_2, CSV_FILE_3, PRIVATE_CSV];
    
    foreach ($csv_files as $csv_file) {
        if (!file_exists($csv_file)) continue;
        
        $handle = fopen($csv_file, "r");
        if ($handle !== FALSE) {
            $header = fgetcsv($handle);
            while (($row = fgetcsv($handle)) !== FALSE) {
                if (count($row) >= 3 && (!empty(trim($row[0])))) {
                    $all_movies[] = $row;
                }
            }
            fclose($handle);
        }
    }
    
    // Sort by date (assuming date is in column 2)
    usort($all_movies, function($a, $b) {
        return strtotime($b[2] ?? '') - strtotime($a[2] ?? '');
    });
    
    // Write combined CSV
    $handle = fopen(COMBINED_CSV, "w");
    fputcsv($handle, ['movie_name','message_id','date','video_path','channel_id']);
    foreach ($all_movies as $movie) {
        fputcsv($handle, $movie);
    }
    fclose($handle);
}

function update_global_caches($movie_name, $message_id_raw, $date, $video_path, $channel_id) {
    global $movie_messages, $movie_cache, $waiting_users;
    
    $movie = strtolower(trim($movie_name));
    $item = [
        'movie_name' => $movie_name,
        'message_id_raw' => $message_id_raw,
        'date' => $date,
        'video_path' => $video_path,
        'channel_id' => $channel_id,
        'message_id' => is_numeric($message_id_raw) ? intval($message_id_raw) : null
    ];
    
    if (!isset($movie_messages[$movie])) $movie_messages[$movie] = [];
    $movie_messages[$movie][] = $item;
    $movie_cache = [];

    // Notify waiting users
    foreach ($waiting_users as $query => $users) {
        if (strpos($movie, $query) !== false) {
            foreach ($users as $user_data) {
                list($user_chat_id, $user_id) = $user_data;
                deliver_item_premium($user_chat_id, $item, $user_id);
                sendMessage($user_chat_id, "✅ '$query' ab channel me add ho gaya!\n\n📢 Join: @EntertainmentTadka786\n💬 Help: @EntertainmentTadka7860");
            }
            unset($waiting_users[$query]);
        }
    }
}

// ==============================
// ENHANCED DELIVERY WITH TRACKING
// ==============================
function deliver_item_premium($chat_id, $item, $user_id = null) {
    $channel_id = $item['channel_id'] ?? CHANNEL_1_ID;
    
    if (!empty($item['message_id']) && is_numeric($item['message_id'])) {
        // Copy message with premium caption
        $premium_caption = generate_dynamic_premium_caption(
            $item['movie_name'],
            "1080p HEVC WEB-DL",
            "Hindi 2.0 + Telugu 5.1", 
            "English Subtitles",
            "MP4"
        );
        
        $result = copyMessage($chat_id, $channel_id, $item['message_id'], $premium_caption, 'HTML');
        $result_data = json_decode($result, true);
        
        if ($result_data && $result_data['ok']) {
            // Track download if user_id provided
            if ($user_id) {
                track_download($user_id, $item['movie_name']);
            }
            return true;
        }
    }

    // Fallback: Send premium text
    $text = "✨ <b>" . strtoupper($item['movie_name'] ?? 'MOVIE') . "</b> ✨\n\n";
    $text .= "📅 <b>Date:</b> " . ($item['date'] ?? 'N/A') . "\n";
    $text .= "🔗 <b>Reference:</b> " . ($item['message_id_raw'] ?? 'N/A') . "\n\n";
    $text .= "━━━━━━━━━━━━━━━━━━━━\n";
    $text .= "🎯 <b>Main:</b> @EntertainmentTadka786\n";
    $text .= "📩 <b>Request:</b> @EntertainmentTadka7860";
    
    sendMessage($chat_id, $text, null, 'HTML');
    
    // Track download if user_id provided
    if ($user_id) {
        track_download($user_id, $item['movie_name']);
    }
    
    return false;
}

function generate_dynamic_premium_caption($movie_name, $quality, $audio, $subs, $format) {
    $caption = "✨ " . strtoupper($movie_name) . " ✨\n\n";
    $caption .= "🎞️ " . $quality . "\n";
    $caption .= "🔊 " . $audio . "\n";
    $caption .= "📄 " . $subs . "\n";
    $caption .= "💿 " . $format . "\n\n";
    $caption .= "━━━━━━━━━━━━━━━━━━━━\n";
    $caption .= "🎯 𝗠𝗮𝗶𝗻: @EntertainmentTadka786\n";
    $caption .= "📩 𝗥𝗲𝗾𝘂𝗲𝘀𝘁: @EntertainmentTadka7860\n";
    $caption .= "🛡️ 𝗕𝗮𝗰𝗸𝘂𝗽: @ETBackup";
    
    return $caption;
}

// ==============================
// USER MANAGEMENT FUNCTIONS
// ==============================
function show_user_profile($chat_id, $user_id) {
    $users_data = json_decode(file_get_contents(USERS_FILE), true);
    $user = $users_data['users'][$user_id] ?? null;
    
    if (!$user) {
        sendMessage($chat_id, "❌ User profile not found!");
        return;
    }
    
    $profile = "👤 <b>YOUR PROFILE</b>\n\n";
    $profile .= "🆔 <b>User ID:</b> <code>{$user_id}</code>\n";
    $profile .= "📛 <b>Name:</b> {$user['first_name']}";
    if (!empty($user['last_name'])) $profile .= " {$user['last_name']}";
    $profile .= "\n";
    if (!empty($user['username'])) $profile .= "📱 <b>Username:</b> @{$user['username']}\n";
    $profile .= "⭐ <b>Points:</b> {$user['points']}\n";
    $profile .= "📅 <b>Joined:</b> " . date('d M Y', strtotime($user['joined'])) . "\n";
    $profile .= "🕒 <b>Last Active:</b> " . date('d M Y H:i', strtotime($user['last_active'])) . "\n\n";
    
    $profile .= "━━━━━━━━━━━━━━━━━━━━\n";
    $profile .= "🎯 <b>Commands:</b>\n";
    $profile .= "• /mystats - Your statistics\n";
    $profile .= "• /leaderboard - Top users\n";
    $profile .= "• /recent - Latest movies";
    
    sendMessage($chat_id, $profile, null, 'HTML');
}

function show_user_stats($chat_id, $user_id) {
    $users_data = json_decode(file_get_contents(USERS_FILE), true);
    $user = $users_data['users'][$user_id] ?? null;
    $stats = get_stats();
    
    if (!$user) {
        sendMessage($chat_id, "❌ User data not found!");
        return;
    }
    
    $user_stats = "📊 <b>YOUR STATISTICS</b>\n\n";
    $user_stats .= "⭐ <b>Points:</b> {$user['points']}\n";
    
    // Calculate user rank
    $all_users = $users_data['users'];
    uasort($all_users, function($a, $b) {
        return ($b['points'] ?? 0) - ($a['points'] ?? 0);
    });
    $user_ids = array_keys($all_users);
    $rank = array_search($user_id, $user_ids) + 1;
    
    $user_stats .= "🏆 <b>Rank:</b> #{$rank} of " . count($all_users) . " users\n";
    $user_stats .= "🔍 <b>Searches:</b> " . ($user['search_count'] ?? 0) . "\n";
    $user_stats .= "🎬 <b>Movies Found:</b> " . ($user['movies_found'] ?? 0) . "\n";
    $user_stats .= "📥 <b>Downloads:</b> " . ($user['download_count'] ?? 0) . "\n\n";
    
    $user_stats .= "━━━━━━━━━━━━━━━━━━━━\n";
    $user_stats .= "📈 <b>Global Stats:</b>\n";
    $user_stats .= "• Total Movies: " . ($stats['total_movies'] ?? 0) . "\n";
    $user_stats .= "• Total Users: " . count($all_users) . "\n";
    $user_stats .= "• Total Searches: " . ($stats['total_searches'] ?? 0) . "\n";
    $user_stats .= "• Total Downloads: " . ($stats['total_downloads'] ?? 0);
    
    sendMessage($chat_id, $user_stats, null, 'HTML');
}

function show_leaderboard($chat_id) {
    $users_data = json_decode(file_get_contents(USERS_FILE), true);
    $all_users = $users_data['users'] ?? [];
    
    if (empty($all_users)) {
        sendMessage($chat_id, "❌ No users data available!");
        return;
    }
    
    // Sort by points
    uasort($all_users, function($a, $b) {
        return ($b['points'] ?? 0) - ($a['points'] ?? 0);
    });
    
    $leaderboard = "🏆 <b>TOP USERS LEADERBOARD</b>\n\n";
    
    $i = 1;
    foreach (array_slice($all_users, 0, 10, true) as $uid => $user) {
        $name = $user['first_name'] ?? 'User';
        if (!empty($user['username'])) {
            $name = "@" . $user['username'];
        }
        
        $medal = $i == 1 ? "🥇" : ($i == 2 ? "🥈" : ($i == 3 ? "🥉" : "🔸"));
        $points = $user['points'] ?? 0;
        $leaderboard .= "{$medal} <b>{$name}</b> - {$points} points\n";
        $i++;
    }
    
    $leaderboard .= "\n━━━━━━━━━━━━━━━━━━━━\n";
    $leaderboard .= "⭐ <b>Earn points by:</b>\n";
    $leaderboard .= "🔍 Each search: +1 point\n";
    $leaderboard .= "🎬 Each movie found: +5 points";
    
    sendMessage($chat_id, $leaderboard, null, 'HTML');
}

// ==============================
// MOVIE MANAGEMENT FUNCTIONS
// ==============================
function show_recent_movies($chat_id, $limit = 10) {
    $all_movies = get_all_movies_list();
    
    if (empty($all_movies)) {
        sendMessage($chat_id, "❌ No movies available in database!");
        return;
    }
    
    $recent_movies = array_slice($all_movies, -$limit);
    $recent_movies = array_reverse($recent_movies);
    
    $message = "🎬 <b>RECENTLY ADDED MOVIES</b>\n\n";
    
    $i = 1;
    foreach ($recent_movies as $movie) {
        $movie_name = htmlspecialchars($movie['movie_name'] ?? 'Unknown');
        $date = $movie['date'] ?? 'N/A';
        $message .= "{$i}. <b>{$movie_name}</b>\n   📅 {$date}\n\n";
        $i++;
    }
    
    $message .= "━━━━━━━━━━━━━━━━━━━━\n";
    $message .= "🔍 Use /search [name] to find specific movies";
    
    sendMessage($chat_id, $message, null, 'HTML');
}

function suggest_random_movie($chat_id) {
    $all_movies = get_all_movies_list();
    
    if (empty($all_movies)) {
        sendMessage($chat_id, "❌ No movies available in database!");
        return;
    }
    
    $random_movie = $all_movies[array_rand($all_movies)];
    
    $suggestion = "🎲 <b>RANDOM MOVIE SUGGESTION</b>\n\n";
    $suggestion .= "🎬 <b>" . htmlspecialchars($random_movie['movie_name']) . "</b>\n";
    $suggestion .= "📅 <b>Date:</b> " . ($random_movie['date'] ?? 'N/A') . "\n\n";
    
    $suggestion .= "━━━━━━━━━━━━━━━━━━━━\n";
    $suggestion .= "⬇️ Click button below to download this movie!";
    
    $keyboard = [
        'inline_keyboard' => [
            [
                ['text' => '🚀 Download This Movie', 'callback_data' => $random_movie['movie_name']]
            ],
            [
                ['text' => '🎲 Another Random', 'callback_data' => 'random_movie'],
                ['text' => '📋 Browse All', 'callback_data' => 'tu_prev_1']
            ]
        ]
    ];
    
    sendMessage($chat_id, $suggestion, $keyboard, 'HTML');
}

// ==============================
// UTILITY FUNCTIONS
// ==============================
function update_favorite_button($chat_id, $message_id, $movie_name, $is_favorite) {
    $favorite_button = $is_favorite ? 
        ['text' => '❌ Remove Favorite', 'callback_data' => "remove_fav:{$movie_name}"] :
        ['text' => '⭐ Add to Favorites', 'callback_data' => "add_fav:{$movie_name}"];
    
    $keyboard = [
        'inline_keyboard' => [
            [$favorite_button],
            [
                ['text' => '🔍 New Search', 'callback_data' => 'new_search'],
                ['text' => '📋 Browse All', 'callback_data' => 'tu_prev_1']
            ]
        ]
    ];
    
    // Try to edit message reply markup
    try {
        apiRequest('editMessageReplyMarkup', [
            'chat_id' => $chat_id,
            'message_id' => $message_id,
            'reply_markup' => json_encode($keyboard)
        ]);
    } catch (Exception $e) {
        // Ignore if can't edit
    }
}

function clear_all_favorites($chat_id, $user_id) {
    $favorites = json_decode(file_get_contents(FAVORITES_FILE), true) ?? [];
    
    if (isset($favorites[$user_id]) && !empty($favorites[$user_id])) {
        $count = count($favorites[$user_id]);
        $favorites[$user_id] = [];
        file_put_contents(FAVORITES_FILE, json_encode($favorites, JSON_PRETTY_PRINT));
        
        sendMessage($chat_id, "🗑️ <b>Favorites Cleared!</b>\n\nRemoved {$count} movies from your favorites.");
    } else {
        sendMessage($chat_id, "ℹ️ No favorites to clear!");
    }
}

function download_recent_favorites($chat_id, $user_id) {
    $favorites = get_user_favorites($user_id);
    $recent_favorites = array_slice($favorites, -5); // Last 5 favorites
    
    if (empty($recent_favorites)) {
        sendMessage($chat_id, "❌ No recent favorites to download!");
        return;
    }
    
    $all_movies = get_all_movies_list();
    $progress_msg = sendMessage($chat_id, "⏳ Downloading recent favorites...");
    
    $success_count = 0;
    foreach ($recent_favorites as $fav) {
        $movie_name = $fav['movie'];
        
        // Find movie in database
        foreach ($all_movies as $movie) {
            if ($movie['movie_name'] === $movie_name) {
                $success = deliver_item_premium($chat_id, $movie, $user_id);
                if ($success) $success_count++;
                break;
            }
        }
        usleep(500000); // 0.5 second delay
    }
    
    editMessage($chat_id, $progress_msg, "✅ Recent Favorites Downloaded!\n📥 Successfully downloaded {$success_count}/5 movies");
}

function download_recent_history($chat_id, $user_id) {
    $history = get_user_download_history($user_id, 5);
    
    if (empty($history)) {
        sendMessage($chat_id, "❌ No download history found!");
        return;
    }
    
    $all_movies = get_all_movies_list();
    $progress_msg = sendMessage($chat_id, "⏳ Downloading recent history...");
    
    $success_count = 0;
    foreach (array_reverse($history) as $download) {
        $movie_name = $download['movie'];
        
        // Find movie in database
        foreach ($all_movies as $movie) {
            if ($movie['movie_name'] === $movie_name) {
                $success = deliver_item_premium($chat_id, $movie, $user_id);
                if ($success) $success_count++;
                break;
            }
        }
        usleep(500000); // 0.5 second delay
    }
    
    editMessage($chat_id, $progress_msg, "✅ Recent History Downloaded!\n📥 Successfully downloaded {$success_count}/5 movies");
}

function show_download_stats($chat_id, $user_id) {
    $stats = json_decode(file_get_contents(DOWNLOAD_STATS), true) ?? [];
    $user_downloads = $stats['user_downloads'][$user_id] ?? 0;
    $total_downloads = $stats['total_downloads'] ?? 0;
    
    $user_rank = "N/A";
    if (!empty($stats['user_downloads'])) {
        arsort($stats['user_downloads']);
        $user_ids = array_keys($stats['user_downloads']);
        $rank = array_search($user_id, $user_ids);
        $user_rank = $rank !== false ? "#" . ($rank + 1) : "N/A";
    }
    
    $message = "📊 <b>Your Download Statistics</b>\n\n";
    $message .= "📥 <b>Total Downloads:</b> {$user_downloads}\n";
    $message .= "🏆 <b>Rank:</b> {$user_rank}\n";
    $message .= "🌐 <b>Global Downloads:</b> {$total_downloads}\n\n";
    
    // Weekly activity
    $weekly_downloads = get_weekly_activity($user_id);
    $message .= "📈 <b>Weekly Activity:</b> {$weekly_downloads} downloads\n\n";
    
    $message .= "━━━━━━━━━━━━━━━━━━━━\n";
    $message .= "💡 <b>Keep downloading to improve your rank!</b>";
    
    sendMessage($chat_id, $message, null, 'HTML');
}

function get_weekly_activity($user_id) {
    $stats = json_decode(file_get_contents(DOWNLOAD_STATS), true) ?? ['user_history' => []];
    $user_history = $stats['user_history'][$user_id] ?? [];
    
    $week_ago = time() - (7 * 24 * 60 * 60);
    $weekly_downloads = 0;
    
    foreach ($user_history as $download) {
        if ($download['timestamp'] > $week_ago) {
            $weekly_downloads++;
        }
    }
    
    return $weekly_downloads;
}

function reset_user_preferences($chat_id, $user_id) {
    $prefs = json_decode(file_get_contents(USER_PREFERENCES), true) ?? [];
    
    if (isset($prefs[$user_id])) {
        unset($prefs[$user_id]);
        file_put_contents(USER_PREFERENCES, json_encode($prefs, JSON_PRETTY_PRINT));
    }
    
    sendMessage($chat_id, "🔄 <b>Preferences Reset!</b>\n\nAll your settings have been reset to default values.");
}

function show_language_options($chat_id, $user_id) {
    $keyboard = [
        'inline_keyboard' => [
            [
                ['text' => '🇬🇧 English', 'callback_data' => 'set_lang:english'],
                ['text' => '🇮🇳 Hindi', 'callback_data' => 'set_lang:hindi']
            ],
            [
                ['text' => '🔙 Back to Settings', 'callback_data' => 'back_to_settings']
            ]
        ]
    ];
    
    $message = "🌐 <b>Language Settings</b>\n\n";
    $message .= "Select your preferred language:\n\n";
    $message .= "• 🇬🇧 English - Default language\n";
    $message .= "• 🇮🇳 Hindi - Hindi interface\n\n";
    $message .= "💡 <i>This affects bot messages and responses.</i>";
    
    sendMessage($chat_id, $message, $keyboard, 'HTML');
}

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
function load_and_clean_csv($filename = COMBINED_CSV) {
    global $movie_messages;
    
    if (!file_exists($filename)) {
        file_put_contents($filename, "movie_name,message_id,date,video_path,channel_id\n");
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
                $channel_id = isset($row[4]) ? trim($row[4]) : CHANNEL_1_ID;

                $entry = [
                    'movie_name' => $movie_name,
                    'message_id_raw' => $message_id_raw,
                    'date' => $date,
                    'video_path' => $video_path,
                    'channel_id' => $channel_id
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
    fputcsv($handle, array('movie_name','message_id','date','video_path','channel_id'));
    foreach ($data as $row) {
        fputcsv($handle, [$row['movie_name'], $row['message_id_raw'], $row['date'], $row['video_path'], $row['channel_id']]);
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

function copyMessage($chat_id, $from_chat_id, $message_id, $caption = null, $parse_mode = null) {
    $data = [
        'chat_id' => $chat_id,
        'from_chat_id' => $from_chat_id,
        'message_id' => $message_id
    ];
    
    if ($caption) {
        $data['caption'] = $caption;
    }
    
    if ($parse_mode) {
        $data['parse_mode'] = $parse_mode;
    }
    
    return apiRequest('copyMessage', $data);
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

function editMessage($chat_id, $message_obj, $new_text, $reply_markup = null, $parse_mode = null) {
    if (is_array($message_obj) && isset($message_obj['message_id'])) {
        $data = [
            'chat_id' => $chat_id,
            'message_id' => $message_obj['message_id'],
            'text' => $new_text
        ];
        if ($reply_markup) $data['reply_markup'] = json_encode($reply_markup);
        if ($parse_mode) $data['parse_mode'] = $parse_mode;
        apiRequest('editMessageText', $data);
    }
}

function deleteMessage($chat_id, $message_id) {
    return apiRequest('deleteMessage', [
        'chat_id' => $chat_id,
        'message_id' => $message_id
    ]);
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
        $success = deliver_item_premium($chat_id, $m);
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
function totalupload_controller($chat_id, $page = 1, $message_id = null) {
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
    
    if ($message_id) {
        // Edit existing message
        editMessage($chat_id, ['message_id' => $message_id], $title, $kb, 'HTML');
    } else {
        // Send new message
        sendMessage($chat_id, $title, $kb, 'HTML');
    }
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
            'not_found' => "😔 Yeh movie abhi available nahi hai!\n\n📝 Aap ise request kar sakte hain: @EntertainmentTadka7860\n💾 Backups check karo: @ETBackup\n\n🔔 Jab bhi yeh add hogi, main automatically bhej dunga!",
            'searching' => "🔍 Dhoondh raha hoon... Zara wait karo"
        ],
        'english'=>[
            'welcome' => "🎬 Boss, which movie are you looking for?",
            'found' => "✅ Found it! Forwarding the movie...",
            'not_found' => "😔 This movie isn't available yet!\n\n📝 You can request it here: @EntertainmentTadka7860\n💾 Check backups: @ETBackup\n\n🔔 I'll send it automatically once it's added!",
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
    
    // Update search count
    if ($user_id) {
        $users_data = json_decode(file_get_contents(USERS_FILE), true);
        if (isset($users_data['users'][$user_id])) {
            $users_data['users'][$user_id]['search_count'] = ($users_data['users'][$user_id]['search_count'] ?? 0) + 1;
            file_put_contents(USERS_FILE, json_encode($users_data, JSON_PRETTY_PRINT));
        }
    }
    
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
        $help_msg .= "💬 Help: @EntertainmentTadka7860";
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
    $msg .= "📥 Total Downloads: " . ($stats['total_downloads'] ?? 0) . "\n";
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
    if (!file_exists(COMBINED_CSV)) {
        sendMessage($chat_id, "❌ CSV file not found.");
        return;
    }
    
    $handle = fopen(COMBINED_CSV, "r");
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
    
    $message .= "💾 File: " . COMBINED_CSV . "\n";
    $message .= "⏰ Last Updated: " . date('Y-m-d H:i:s', filemtime(COMBINED_CSV));
    
    sendMessage($chat_id, $message, null, 'HTML');
}

// ==============================
// Backups & daily digest
// ==============================
function auto_backup() {
    $backup_files = [CSV_FILE_1, CSV_FILE_2, CSV_FILE_3, PRIVATE_CSV, COMBINED_CSV, USERS_FILE, STATS_FILE, FAVORITES_FILE, DOWNLOAD_STATS, MOVIE_REQUESTS, USER_PREFERENCES];
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
    $h = fopen(COMBINED_CSV, "r");
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
            $wants_digest = get_user_preference($uid, 'daily_digest', false);
            if ($wants_digest) {
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
}

// ==============================
// Other commands
// ==============================
function check_date($chat_id) {
    if (!file_exists(COMBINED_CSV)) { sendMessage($chat_id, "⚠️ Abhi tak koi data save nahi hua."); return; }
    $date_counts = [];
    $h=fopen(COMBINED_CSV,'r'); if ($h!==FALSE) {
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
    if (!file_exists(COMBINED_CSV)) { sendMessage($chat_id,"⚠️ CSV file not found."); return; }
    $h = fopen(COMBINED_CSV,'r');
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
// CALLBACK HANDLERS
// ==============================
function handle_pagination_callback($chat_id, $message_id, $data, $type) {
    $page = (int)str_replace(['tu_prev_', 'tu_next_'], '', $data);
    
    if ($type === 'prev' && $page > 1) {
        $page--;
    } elseif ($type === 'next') {
        $page++;
    }
    
    totalupload_controller($chat_id, $page, $message_id);
}

function handle_view_page_callback($chat_id, $message_id, $data) {
    $page = (int)str_replace('tu_view_', '', $data);
    $all = get_all_movies_list();
    $pg = paginate_movies($all, $page);
    
    // Forward movies with premium style
    forward_page_movies($chat_id, $pg['slice']);
    
    // Update message
    editMessage($chat_id, ['message_id' => $message_id], "✅ Page {$page} movies sent successfully!", null, 'HTML');
}

function handle_stop_callback($chat_id, $message_id) {
    editMessage($chat_id, ['message_id' => $message_id], "🛑 Pagination stopped.\n\nUse /totalupload to start again.", null, 'HTML');
}

function handle_movie_callback($chat_id, $message_id, $movie_name, $user_id) {
    global $movie_messages;
    
    $movie_lower = strtolower($movie_name);
    if (isset($movie_messages[$movie_lower])) {
        $entries = $movie_messages[$movie_lower];
        $cnt = 0;
        
        // Progress message
        $progress_msg = sendMessage($chat_id, "⏳ Sending {$movie_name}...");
        
        foreach ($entries as $entry) {
            // Send with premium style and track download
            $success = deliver_item_premium($chat_id, $entry, $user_id);
            if ($success) $cnt++;
            usleep(300000); // 0.3 second delay
        }
        
        // Delete progress message
        deleteMessage($chat_id, $progress_msg['result']['message_id']);
        
        // Check if movie is in favorites
        $is_favorite = is_movie_in_favorites($user_id, $movie_name);
        $favorite_button = $is_favorite ? 
            ['text' => '❌ Remove Favorite', 'callback_data' => "remove_fav:{$movie_name}"] :
            ['text' => '⭐ Add to Favorites', 'callback_data' => "add_fav:{$movie_name}"];
        
        // Success message with premium style
        $success_msg = "✨ <b>SUCCESS!</b> ✨\n\n";
        $success_msg .= "🎬 <b>{$movie_name}</b>\n";
        $success_msg .= "📦 <b>{$cnt} files</b> sent successfully!\n\n";
        $success_msg .= "━━━━━━━━━━━━━━━━━━━━\n";
        $success_msg .= "🔍 Search again or use /totalupload";
        
        $keyboard = [
            'inline_keyboard' => [
                [$favorite_button],
                [
                    ['text' => '🔍 New Search', 'callback_data' => 'new_search'],
                    ['text' => '📋 Browse All', 'callback_data' => 'tu_prev_1']
                ]
            ]
        ];
        
        sendMessage($chat_id, $success_msg, $keyboard, 'HTML');
        update_user_points($user_id, 'found_movie');
        
    } else {
        $error_msg = "❌ <b>MOVIE NOT FOUND</b>\n\n";
        $error_msg .= "🎬 <b>{$movie_name}</b> is not available.\n\n";
        $error_msg .= "📩 Request it here: @EntertainmentTadka7860\n";
        $error_msg .= "💾 Check backups: @ETBackup";
        
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '📝 Request Movie', 'callback_data' => "request_movie:{$movie_name}"]
                ]
            ]
        ];
        
        sendMessage($chat_id, $error_msg, $keyboard, 'HTML');
    }
}

function shorten_movie_name($name, $max_length = 20) {
    if (strlen($name) <= $max_length) {
        return $name;
    }
    return substr($name, 0, $max_length - 3) . '...';
}

// ==============================
// ADMIN FUNCTIONS
// ==============================
function handle_broadcast($chat_id, $message) {
    if ($message['from']['id'] != OWNER_ID) {
        sendMessage($chat_id, "❌ Access denied. Owner only command.");
        return;
    }
    
    $parts = explode(' ', $message['text'], 2);
    if (count($parts) < 2) {
        sendMessage($chat_id, "❌ Usage: /broadcast [message]");
        return;
    }
    
    $broadcast_message = $parts[1];
    $users_data = json_decode(file_get_contents(USERS_FILE), true);
    $total_users = count($users_data['users'] ?? []);
    $success_count = 0;
    
    $progress_msg = sendMessage($chat_id, "📢 Broadcasting to {$total_users} users...\n✅ Success: 0/{$total_users}");
    
    foreach ($users_data['users'] as $user_id => $user) {
        try {
            sendMessage($user_id, $broadcast_message, null, 'HTML');
            $success_count++;
            
            // Update progress every 10 users
            if ($success_count % 10 === 0) {
                editMessage($chat_id, $progress_msg, "📢 Broadcasting to {$total_users} users...\n✅ Success: {$success_count}/{$total_users}");
            }
            
            usleep(200000); // 0.2 second delay
        } catch (Exception $e) {
            // Skip failed sends
        }
    }
    
    editMessage($chat_id, $progress_msg, "✅ Broadcast Complete!\n📊 Sent to {$success_count}/{$total_users} users");
}

function create_manual_backup($chat_id) {
    if ($chat_id != OWNER_ID) {
        sendMessage($chat_id, "❌ Access denied. Owner only command.");
        return;
    }
    
    auto_backup(); // Use existing backup function
    sendMessage($chat_id, "✅ Manual backup created successfully!");
}

function show_users_list($chat_id) {
    if ($chat_id != OWNER_ID) {
        sendMessage($chat_id, "❌ Access denied. Owner only command.");
        return;
    }
    
    $users_data = json_decode(file_get_contents(USERS_FILE), true);
    $total_users = count($users_data['users'] ?? []);
    
    $message = "👥 <b>USERS LIST</b>\n\n";
    $message .= "📊 Total Users: {$total_users}\n\n";
    
    $i = 1;
    foreach (array_slice($users_data['users'], 0, 15, true) as $user_id => $user) {
        $name = $user['first_name'] ?? 'Unknown';
        if (!empty($user['username'])) {
            $name .= " (@{$user['username']})";
        }
        $points = $user['points'] ?? 0;
        $message .= "{$i}. {$name} - {$points} points\n";
        $i++;
    }
    
    if ($total_users > 15) {
        $message .= "\n... and " . ($total_users - 15) . " more users";
    }
    
    sendMessage($chat_id, $message, null, 'HTML');
}

// ==============================
// CHANNEL MANAGEMENT
// ==============================
function show_channel_info($chat_id) {
    $channel_info = "📺 <b>OUR CHANNELS</b>\n\n";
    
    $channel_info .= "🍿 <b>Main Channel</b>\n";
    $channel_info .= "• @EntertainmentTadka786\n";
    $channel_info .= "• Latest movies & updates\n\n";
    
    $channel_info .= "💬 <b>Request & Help</b>\n";
    $channel_info .= "• @EntertainmentTadka7860\n";
    $channel_info .= "• Movie requests & support\n\n";
    
    $channel_info .= "💾 <b>Backup Channel</b>\n";
    $channel_info .= "• @ETBackup\n";
    $channel_info .= "• Backup movies & archives\n\n";
    
    $channel_info .= "━━━━━━━━━━━━━━━━━━━━\n";
    $channel_info .= "🎯 <b>Commands:</b>\n";
    $channel_info .= "• /channelstats - Channel statistics\n";
    $channel_info .= "• /checkcsv all - View all movies\n";
    $channel_info .= "• /recent - Recently added movies";
    
    sendMessage($chat_id, $channel_info, null, 'HTML');
}

function get_channel_stats($chat_id) {
    global $channel_names;
    
    $stats_text = "📊 <b>Multi-Channel Statistics</b>\n\n";
    
    $csv_files = [
        CSV_FILE_1 => CHANNEL_1_ID,
        CSV_FILE_2 => CHANNEL_2_ID,
        CSV_FILE_3 => CHANNEL_3_ID, 
        PRIVATE_CSV => PRIVATE_CHANNEL_ID
    ];
    
    $total_movies = 0;
    
    foreach ($csv_files as $csv_file => $channel_id) {
        $channel_name = $channel_names[$channel_id] ?? 'Unknown';
        $count = 0;
        
        if (file_exists($csv_file)) {
            $handle = fopen($csv_file, "r");
            if ($handle !== FALSE) {
                fgetcsv($handle); // skip header
                while (($row = fgetcsv($handle)) !== FALSE) {
                    if (!empty(trim($row[0] ?? ''))) $count++;
                }
                fclose($handle);
            }
        }
        
        $total_movies += $count;
        $stats_text .= "• {$channel_name}: **{$count}** movies\n";
    }
    
    $stats_text .= "\n🎯 <b>Total Across All Channels: {$total_movies} movies</b>";
    
    sendMessage($chat_id, $stats_text, null, 'HTML');
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

        if (in_array($chat_id, [CHANNEL_1_ID, CHANNEL_2_ID, CHANNEL_3_ID, PRIVATE_CHANNEL_ID])) {
            $text = '';
            $file_name = '';

            if (isset($message['caption'])) {
                $text = $message['caption'];
            } elseif (isset($message['text'])) {
                $text = $message['text'];
            } elseif (isset($message['document'])) {
                $file_name = $message['document']['file_name'];
                $text = $file_name;
            } elseif (isset($message['video'])) {
                $file_name = $message['video']['file_name'] ?? 'video.mp4';
                $text = $file_name;
            } else {
                $text = 'Uploaded Media - ' . date('d-m-Y H:i');
            }

            if (!empty(trim($text))) {
                // Auto-generate premium caption agar original caption nahi hai
                if (empty($message['caption']) && !empty($file_name)) {
                    $premium_caption = generate_dynamic_premium_caption(
                        pathinfo($file_name, PATHINFO_FILENAME),
                        "1080p HEVC WEB-DL",
                        "Hindi 2.0 + Telugu 5.1",
                        "English Subtitles", 
                        pathinfo($file_name, PATHINFO_EXTENSION)
                    );
                    
                    // Edit message with premium caption
                    editMessageCaption($chat_id, $message_id, $premium_caption);
                }
                
                append_movie_to_channel($text, $message_id, $chat_id, date('d-m-Y'), '');
            }
        }
    }

    if (isset($update['message'])) {
        $message = $update['message'];
        $chat_id = $message['chat']['id'];
        $user_id = $message['from']['id'];
        $text = isset($message['text']) ? $message['text'] : '';
        $chat_type = $message['chat']['type'] ?? 'private';

        // Rate limiting check
        if (!check_rate_limit($user_id, 'message')) {
            sendMessage($chat_id, get_rate_limit_message('message'));
            return;
        }

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
                'points' => 0,
                'search_count' => 0,
                'movies_found' => 0,
                'download_count' => 0
            ];
            $users_data['total_requests'] = ($users_data['total_requests'] ?? 0) + 1;
            file_put_contents(USERS_FILE, json_encode($users_data, JSON_PRETTY_PRINT));
            update_stats('total_users', 1);
            
            // Set default preferences for new users
            set_user_preference($user_id, 'notifications', true);
            set_user_preference($user_id, 'daily_digest', false);
            set_user_preference($user_id, 'language', 'english');
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
            // ✅ NEW COMMANDS ADD KARO
            elseif ($command == '/myprofile' || $command == '/profile') {
                show_user_profile($chat_id, $user_id);
            }
            elseif ($command == '/mystats' || $command == '/mystat') {
                show_user_stats($chat_id, $user_id);
            }
            elseif ($command == '/leaderboard' || $command == '/topusers') {
                show_leaderboard($chat_id);
            }
            elseif ($command == '/channels' || $command == '/channelinfo') {
                show_channel_info($chat_id);
            }
            elseif ($command == '/channelstats') {
                get_channel_stats($chat_id);
            }
            elseif ($command == '/recent' || $command == '/latest') {
                show_recent_movies($chat_id);
            }
            elseif ($command == '/random' || $command == '/suggest') {
                suggest_random_movie($chat_id);
            }
            elseif ($command == '/search' && isset($parts[1])) {
                $search_query = implode(' ', array_slice($parts, 1));
                show_search_filters($chat_id, $search_query);
            }
            elseif ($command == '/favorites' || $command == '/fav') {
                show_favorites($chat_id, $user_id);
            }
            elseif ($command == '/popular') {
                show_popular_movies($chat_id);
            }
            elseif ($command == '/myhistory' || $command == '/history') {
                show_download_history($chat_id, $user_id);
            }
            elseif ($command == '/request' && isset($parts[1])) {
                $movie_name = implode(' ', array_slice($parts, 1));
                handle_movie_request($chat_id, $user_id, $movie_name);
            }
            elseif ($command == '/requests') {
                show_top_requests($chat_id);
            }
            elseif ($command == '/settings') {
                show_user_settings($chat_id, $user_id);
            }
            elseif ($command == '/notifications') {
                toggle_notifications($chat_id, $user_id);
            }
            elseif ($command == '/digest') {
                toggle_daily_digest($chat_id, $user_id);
            }
            // ✅ ADMIN COMMANDS
            elseif ($command == '/stats' && $user_id == OWNER_ID) admin_stats($chat_id);
            elseif ($command == '/broadcast' && $user_id == OWNER_ID) {
                handle_broadcast($chat_id, $message);
            }
            elseif ($command == '/backup' && $user_id == OWNER_ID) {
                create_manual_backup($chat_id);
            }
            elseif ($command == '/users' && $user_id == OWNER_ID) {
                show_users_list($chat_id);
            }
            elseif ($command == '/start') {
                $welcome = "🎬 <b>Welcome to Entertainment Tadka!</b>\n\n";
                $welcome .= "📢 <b>How to use this bot:</b>\n";
                $welcome .= "• Simply type any movie name\n";
                $welcome .= "• Use English or Hindi\n";
                $welcome .= "• Partial names also work\n\n";
                $welcome .= "🔍 <b>Examples:</b>\n";
                $welcome .= "• kgf\n• pushpa\n• avengers\n• hindi movie\n\n";
                $welcome .= "🎯 <b>New Commands:</b>\n";
                $welcome .= "• /profile - Your profile\n";
                $welcome .= "• /mystats - Your statistics\n";
                $welcome .= "• /leaderboard - Top users\n";
                $welcome .= "• /recent - Latest movies\n";
                $welcome .= "• /random - Random suggestion\n";
                $welcome .= "• /search [name] - Search movies\n";
                $welcome .= "• /favorites - Your favorite movies\n";
                $welcome .= "• /request [name] - Request movies\n";
                $welcome .= "• /settings - Preferences\n\n";
                $welcome .= "📢 Join: @EntertainmentTadka786\n";
                $welcome .= "💬 Request/Help: @EntertainmentTadka7860\n";
                $welcome .= "💾 Backups: @ETBackup";
                sendMessage($chat_id, $welcome, null, 'HTML');
                update_user_points($user_id, 'daily_login');
            }
            elseif ($command == '/help') {
                $help = "🤖 <b>Entertainment Tadka Bot - Complete Guide</b>\n\n";
                
                $help .= "🎯 <b>Basic Commands:</b>\n";
                $help .= "• /start - Welcome message\n";
                $help .= "• /help - This help message\n";
                $help .= "• Type any movie name to search\n\n";
                
                $help .= "⭐ <b>User Features:</b>\n";
                $help .= "• /profile - Your profile & stats\n";
                $help .= "• /favorites - Your favorite movies\n";
                $help .= "• /myhistory - Download history\n";
                $help .= "• /settings - Preferences & notifications\n\n";
                
                $help .= "🔍 <b>Search & Discovery:</b>\n";
                $help .= "• /search [name] - Advanced search\n";
                $help .= "• /popular - Most downloaded movies\n";
                $help .= "• /recent - Recently added movies\n";
                $help .= "• /random - Random movie suggestion\n\n";
                
                $help .= "📋 <b>Movie Management:</b>\n";
                $help .= "• /request [name] - Request a movie\n";
                $help .= "• /requests - View top requests\n";
                $help .= "• /totalupload - Browse all movies\n";
                $help .= "• /checkdate - Upload statistics\n\n";
                
                $help .= "📺 <b>Channel Info:</b>\n";
                $help .= "• /channels - Our channels list\n";
                $help .= "• /channelstats - Statistics\n\n";
                
                $help .= "━━━━━━━━━━━━━━━━━━━━\n";
                $help .= "📢 <b>Our Channels:</b>\n";
                $help .= "🍿 Main: @EntertainmentTadka786\n";
                $help .= "💬 Help: @EntertainmentTadka7860\n";
                $help .= "💾 Backup: @ETBackup\n\n";
                
                $help .= "💡 <b>Pro Tip:</b> Use ⭐ favorites to save movies you love!";
                
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
        $user_id = $query['from']['id'];
        $data = $query['data'];
        $message_id = $message['message_id'];

        // Rate limiting for callbacks
        if (!check_rate_limit($user_id, 'callback')) {
            answerCallbackQuery($query['id'], get_rate_limit_message('callback'));
            return;
        }

        // Existing pagination callbacks
        if (strpos($data, 'tu_prev_') === 0) {
            handle_pagination_callback($chat_id, $message_id, $data, 'prev');
            answerCallbackQuery($query['id'], "📄 Page loaded");
        }
        elseif (strpos($data, 'tu_next_') === 0) {
            handle_pagination_callback($chat_id, $message_id, $data, 'next');
            answerCallbackQuery($query['id'], "📄 Page loaded");
        }
        elseif (strpos($data, 'tu_view_') === 0) {
            handle_view_page_callback($chat_id, $message_id, $data);
            answerCallbackQuery($query['id'], "🎬 Sending movies...");
        }
        elseif ($data === 'tu_stop') {
            handle_stop_callback($chat_id, $message_id);
            answerCallbackQuery($query['id'], "🛑 Stopped");
        }
        elseif ($data === 'current_page') {
            answerCallbackQuery($query['id'], "📋 You're on this page");
        }
        
        // ✅ NEW ADVANCED FEATURES CALLBACKS
        elseif (strpos($data, 'search_all:') === 0) {
            $query_text = str_replace('search_all:', '', $data);
            advanced_search($chat_id, $query_text, $user_id);
            answerCallbackQuery($query['id'], "🔍 Searching...");
        }
        elseif (strpos($data, 'search_filter:') === 0) {
            $parts = explode(':', $data);
            if (count($parts) >= 4) {
                $search_query = $parts[1];
                $filter_type = $parts[2];
                $filter_value = $parts[3];
                handle_search_with_filters($chat_id, $search_query, [$filter_type => $filter_value], $user_id);
            }
            answerCallbackQuery($query['id'], "🔍 Applying filters...");
        }
        elseif (strpos($data, 'add_fav:') === 0) {
            $movie_name = str_replace('add_fav:', '', $data);
            $added = add_to_favorites($user_id, $movie_name);
            if ($added) {
                answerCallbackQuery($query['id'], "⭐ Added to favorites!");
                // Update message with new button
                update_favorite_button($chat_id, $message_id, $movie_name, true);
            } else {
                answerCallbackQuery($query['id'], "⚠️ Already in favorites");
            }
        }
        elseif (strpos($data, 'remove_fav:') === 0) {
            $movie_name = str_replace('remove_fav:', '', $data);
            $removed = remove_from_favorites($user_id, $movie_name);
            if ($removed) {
                answerCallbackQuery($query['id'], "❌ Removed from favorites");
                // Update message with new button
                update_favorite_button($chat_id, $message_id, $movie_name, false);
            } else {
                answerCallbackQuery($query['id'], "⚠️ Not in favorites");
            }
        }
        elseif ($data === 'download_favorites') {
            download_favorites($chat_id, $user_id);
            answerCallbackQuery($query['id'], "📥 Downloading favorites...");
        }
        elseif ($data === 'clear_favorites') {
            clear_all_favorites($chat_id, $user_id);
            answerCallbackQuery($query['id'], "🗑️ Clearing favorites...");
        }
        elseif ($data === 'download_recent_fav') {
            download_recent_favorites($chat_id, $user_id);
            answerCallbackQuery($query['id'], "📥 Downloading recent...");
        }
        elseif (strpos($data, 'request_movie:') === 0) {
            $movie_name = str_replace('request_movie:', '', $data);
            handle_movie_request($chat_id, $user_id, $movie_name);
            answerCallbackQuery($query['id'], "📝 Requesting movie...");
        }
        elseif ($data === 'make_request') {
            sendMessage($chat_id, "📝 <b>Make a Movie Request</b>\n\nSend the movie name you want to request:\n\n<code>/request Movie Name</code>", null, 'HTML');
            answerCallbackQuery($query['id'], "📝 Ready for request");
        }
        elseif ($data === 'refresh_requests') {
            show_top_requests($chat_id);
            answerCallbackQuery($query['id'], "🔄 Refreshing...");
        }
        elseif ($data === 'toggle_notifications') {
            toggle_notifications($chat_id, $user_id);
            // Refresh settings
            show_user_settings($chat_id, $user_id);
            answerCallbackQuery($query['id'], "🔔 Notifications updated");
        }
        elseif ($data === 'toggle_digest') {
            toggle_daily_digest($chat_id, $user_id);
            // Refresh settings
            show_user_settings($chat_id, $user_id);
            answerCallbackQuery($query['id'], "📊 Digest updated");
        }
        elseif ($data === 'view_pref_stats') {
            show_user_stats($chat_id, $user_id);
            answerCallbackQuery($query['id'], "📈 Showing stats");
        }
        elseif ($data === 'reset_preferences') {
            reset_user_preferences($chat_id, $user_id);
            answerCallbackQuery($query['id'], "🔄 Preferences reset");
        }
        elseif ($data === 'change_language') {
            show_language_options($chat_id, $user_id);
            answerCallbackQuery($query['id'], "🌐 Language options");
        }
        elseif (strpos($data, 'set_lang:') === 0) {
            $language = str_replace('set_lang:', '', $data);
            set_user_preference($user_id, 'language', $language);
            sendMessage($chat_id, "✅ <b>Language Updated!</b>\n\n🌐 Language set to: " . ucfirst($language));
            answerCallbackQuery($query['id'], "🌐 Language updated");
        }
        elseif ($data === 'back_to_settings') {
            show_user_settings($chat_id, $user_id);
            answerCallbackQuery($query['id'], "⚙️ Back to settings");
        }
        elseif ($data === 'download_recent') {
            download_recent_history($chat_id, $user_id);
            answerCallbackQuery($query['id'], "📥 Downloading recent...");
        }
        elseif ($data === 'view_stats') {
            show_download_stats($chat_id, $user_id);
            answerCallbackQuery($query['id'], "📊 Showing stats");
        }
        elseif ($data === 'random_movie') {
            suggest_random_movie($chat_id);
            answerCallbackQuery($query['id'], "🎲 Another random movie!");
        }
        elseif ($data === 'new_search') {
            sendMessage($chat_id, "🔍 <b>Enter movie name to search:</b>", null, 'HTML');
            answerCallbackQuery($query['id'], "🔍 Ready for new search");
        }
        else {
            // Default movie search callback
            handle_movie_callback($chat_id, $message_id, $data, $user_id);
            answerCallbackQuery($query['id'], "🎬 Sending movie...");
        }
    }

    if (date('H:i') == '00:00') auto_backup();
    if (date('H:i') == '08:00') send_daily_digest();
}

// Manual save test function
if (isset($_GET['test_save'])) {
    function manual_save_to_csv($movie_name, $message_id, $channel_id = CHANNEL_1_ID) {
        $entry = [$movie_name, $message_id, date('d-m-Y'), '', $channel_id];
        $csv_file = get_channel_csv($channel_id);
        $handle = fopen($csv_file, "a");
        if ($handle !== FALSE) {
            fputcsv($handle, $entry);
            fclose($handle);
            @chmod($csv_file, 0600);
            
            // Update combined CSV
            update_combined_csv();
            return true;
        }
        return false;
    }
    
    manual_save_to_csv("Metro In Dino (2025)", 1924, CHANNEL_1_ID);
    manual_save_to_csv("Metro In Dino 2025 WebRip 480p x265 HEVC 10bit Hindi ESubs", 1925, CHANNEL_1_ID);
    manual_save_to_csv("Metro In Dino (2025) Hindi 720p HEVC HDRip x265 AAC 5.1 ESubs", 1926, CHANNEL_1_ID);
    manual_save_to_csv("Metro In Dino (2025) Hindi 720p HDRip x264 AAC 5.1 ESubs", 1927, CHANNEL_2_ID);
    manual_save_to_csv("Metro In Dino (2025) Hindi 1080p HDRip x264 AAC 5.1 ESubs", 1928, CHANNEL_3_ID);
    
    echo "✅ All 5 movies manually save ho gayi!<br>";
    echo "📊 <a href='?check_csv=1'>Check CSV</a> | ";
    echo "<a href='?setwebhook=1'>Reset Webhook</a>";
    exit;
}

// Check CSV content
if (isset($_GET['check_csv'])) {
    echo "<h3>CSV Content:</h3>";
    if (file_exists(COMBINED_CSV)) {
        $lines = file(COMBINED_CSV);
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
        echo "<p>Help Group: @EntertainmentTadka7860</p>";
        echo "<p>Backup Channel: @ETBackup</p>";
    }
    exit;
}

if (!isset($update) || !$update) {
    $stats = get_stats();
    $users_data = json_decode(file_get_contents(USERS_FILE), true);
    echo "<h1>🎬 Entertainment Tadka Bot - COMPLETE SYSTEM</h1>";
    echo "<p><strong>Telegram Channel:</strong> @EntertainmentTadka786</p>";
    echo "<p><strong>Help Group:</strong> @EntertainmentTadka7860</p>";
    echo "<p><strong>Backup Channel:</strong> @ETBackup</p>";
    echo "<p><strong>Status:</strong> ✅ Running with ALL FEATURES</p>";
    echo "<p><strong>Total Movies:</strong> " . ($stats['total_movies'] ?? 0) . "</p>";
    echo "<p><strong>Total Users:</strong> " . count($users_data['users'] ?? []) . "</p>";
    echo "<p><strong>Total Searches:</strong> " . ($stats['total_searches'] ?? 0) . "</p>";
    echo "<p><strong>Total Downloads:</strong> " . ($stats['total_downloads'] ?? 0) . "</p>";
    
    echo "<h3>🚀 ALL FEATURES ENABLED</h3>";
    echo "<ul>";
    echo "<li>✅ Rate Limiting System</li>";
    echo "<li>✅ Advanced Search with Filters</li>";
    echo "<li>✅ Download Tracking & Analytics</li>";
    echo "<li>✅ Favorites System</li>";
    echo "<li>✅ Movie Request System</li>";
    echo "<li>✅ Notification System</li>";
    echo "<li>✅ User Settings & Preferences</li>";
    echo "<li>✅ Multi-Channel Support</li>";
    echo "<li>✅ Premium Captions & UI</li>";
    echo "<li>✅ File Upload Bot</li>";
    echo "</ul>";
    
    echo "<h3>📋 COMPLETE COMMANDS LIST</h3>";
    echo "<p><strong>User Commands:</strong> /start, /help, /profile, /mystats, /leaderboard, /favorites, /myhistory, /popular, /recent, /random, /search, /request, /requests, /settings, /notifications, /digest, /channels, /channelstats, /checkdate, /totalupload</p>";
    echo "<p><strong>Admin Commands:</strong> /stats, /broadcast, /backup, /users</p>";
    
    echo "<h3>🔧 Quick Setup</h3>";
    echo "<p><a href='?setwebhook=1'>Set Webhook Now</a></p>";
    echo "<p><a href='?test_save=1'>Test Movie Save</a></p>";
    echo "<p><a href='?check_csv=1'>Check CSV Data</a></p>";
}
?>
