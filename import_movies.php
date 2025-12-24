<?php
// import_movies.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>🎬 Entertainment Tadka - CSV Import Tool</h1>";
echo "<div style='padding: 20px; background: #f0f8ff; border-radius: 10px;'>";

// Database connection
try {
    $db = new SQLite3('movies.db');
    $db->enableExceptions(true);
    
    echo "<p style='color: green;'>✅ Connected to database: movies.db</p>";
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Database connection failed: " . htmlspecialchars($e->getMessage()) . "</p>";
    exit;
}

// Your CSV data - maine first 50 lines liye hain, baaki aap add kar sakte hain
$csv_data = <<<CSV
movie_name,message_id,date,channel_id
GUYS BACKUP CHANNEL JOIN KARO,3,21-12-2025,-1002964109368
Mandala Murders,4,23-10-2025,-1003251791991
Mandala Murders,5,23-10-2025,-1003251791991
Mandala Murders,6,23-10-2025,-1003251791991
Zebra 2024,7,29-10-2025,-1003251791991
Zebra 2024,8,29-10-2025,-1003251791991
Zebra 2024,9,29-10-2025,-1003251791991
Zebra 2024,10,29-10-2025,-1003251791991
Zebra 2024,11,29-10-2025,-1003251791991
Zebra 2024,12,29-10-2025,-1003251791991
Show Time 2025,13,30-10-2025,-1003251791991
Show Time 2025,14,30-10-2025,-1003251791991
Show Time 2025,15,30-10-2025,-1003251791991
Show Time 2025,16,30-10-2025,-1003251791991
Show Time 2025,17,30-10-2025,-1003251791991
Show Time 2025,18,30-10-2025,-1003251791991
Show Time 2025,19,30-10-2025,-1003251791991
Show Time 2025,20,01-11-2025,-1003251791991
Baahubali The Epic 2025,4,21-12-2025,-1003181705395
Baahubali The Epic 2025,5,21-12-2025,-1003181705395
Baahubali The Epic 2025,6,21-12-2025,-1003181705395
Baahubali The Epic 2025,7,21-12-2025,-1003181705395
The Taj Story 2025,8,21-12-2025,-1003181705395
The Taj Story 2025,9,21-12-2025,-1003181705395
The Taj Story 2025,10,21-12-2025,-1003181705395
The Taj Story 2025,11,21-12-2025,-1003181705395
The Taj Story 2025,12,21-12-2025,-1003181705395
Dhurandhar 2025,13,21-12-2025,-1003181705395
Dhurandhar 2025,14,21-12-2025,-1003181705395
Dhurandhar 2025,15,21-12-2025,-1003181705395
Dhurandhar 2025,16,21-12-2025,-1003181705395
Dhurandhar 2025,17,21-12-2025,-1003181705395
Dhurandhar 2025,18,21-12-2025,-1003181705395
Dhurandhar 2025,19,21-12-2025,-1003181705395
Kis Kisko Pyaar Karoon 2 2025,20,21-12-2025,-1003181705395
Kis Kisko Pyaar Karoon 2 2025,21,21-12-2025,-1003181705395
Kis Kisko Pyaar Karoon 2 2025,22,21-12-2025,-1003181705395
Kis Kisko Pyaar Karoon 2 2025,23,21-12-2025,-1003181705395
Kis Kisko Pyaar Karoon 2 2025,24,21-12-2025,-1003181705395
Akhanda 2 Thaandavam 2025,25,21-12-2025,-1003181705395
Akhanda 2 Thaandavam 2025,26,21-12-2025,-1003181705395
Akhanda 2 Thaandavam 2025,27,21-12-2025,-1003181705395
Akhanda 2 Thaandavam 2025,28,21-12-2025,-1003181705395
Akhanda 2 Thaandavam 2025,29,21-12-2025,-1003181705395
Akhanda 2 Thaandavam 2025,30,21-12-2025,-1003181705395
Akhanda 2 Thaandavam 2025,31,21-12-2025,-1003181705395
Avatar Fire and Ash (2025),32,21-12-2025,-1003181705395
Avatar Fire and Ash (2025),33,21-12-2025,-1003181705395
Avatar Fire and Ash (2025),34,21-12-2025,-1003181705395
Avatar Fire and Ash (2025),35,21-12-2025,-1003181705395
3BHK (2025),330,21-12-2025,-1002337293281
3BHK (2025),331,21-12-2025,-1002337293281
3BHK (2025),332,21-12-2025,-1002337293281
3BHK (2025),333,21-12-2025,-1002337293281
CSV;

// Check current count
$result = $db->query("SELECT COUNT(*) as count FROM movies");
$row = $result->fetchArray();
$initial_count = $row['count'];
echo "<p>📊 Initial movie count in database: <strong>$initial_count</strong></p>";

// Process CSV
$lines = explode("\n", $csv_data);
$header = null;
$imported = 0;
$skipped = 0;

echo "<h3>📥 Importing Movies...</h3>";
echo "<div style='max-height: 300px; overflow-y: auto; border: 1px solid #ddd; padding: 10px; background: white;'>";

foreach ($lines as $index => $line) {
    $line = trim($line);
    if (empty($line)) continue;
    
    // Skip empty lines
    if ($index === 0) {
        $header = str_getcsv($line);
        continue;
    }
    
    $data = str_getcsv($line);
    
    // Validate data
    if (count($data) < 4) {
        echo "<span style='color: orange;'>⚠️ Line $index: Invalid data - " . htmlspecialchars($line) . "</span><br>";
        $skipped++;
        continue;
    }
    
    $movie_name = trim($data[0]);
    $message_id = intval(trim($data[1]));
    $date = trim($data[2]);
    $channel_id = trim($data[3]);
    
    // Skip if already exists
    $check_stmt = $db->prepare("SELECT id FROM movies WHERE movie_name = ? AND message_id = ? AND channel_id = ?");
    $check_stmt->bindValue(1, $movie_name, SQLITE3_TEXT);
    $check_stmt->bindValue(2, $message_id, SQLITE3_INTEGER);
    $check_stmt->bindValue(3, $channel_id, SQLITE3_TEXT);
    $check_result = $check_stmt->execute();
    
    if ($check_result->fetchArray()) {
        echo "<span style='color: blue;'>⏭️ Skipped (exists): $movie_name</span><br>";
        $skipped++;
        continue;
    }
    
    // Insert into database
    try {
        $stmt = $db->prepare("INSERT INTO movies (movie_name, message_id, date, channel_id, file_type, quality, language, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bindValue(1, $movie_name, SQLITE3_TEXT);
        $stmt->bindValue(2, $message_id, SQLITE3_INTEGER);
        $stmt->bindValue(3, $date, SQLITE3_TEXT);
        $stmt->bindValue(4, $channel_id, SQLITE3_TEXT);
        $stmt->bindValue(5, 'video', SQLITE3_TEXT);
        $stmt->bindValue(6, 'HD', SQLITE3_TEXT);
        $stmt->bindValue(7, 'Hindi', SQLITE3_TEXT);
        $stmt->bindValue(8, date('Y-m-d H:i:s'), SQLITE3_TEXT);
        
        $stmt->execute();
        
        echo "<span style='color: green;'>✅ Added: $movie_name (ID: $message_id)</span><br>";
        $imported++;
        
    } catch (Exception $e) {
        echo "<span style='color: red;'>❌ Error adding $movie_name: " . htmlspecialchars($e->getMessage()) . "</span><br>";
    }
}

echo "</div>";

// Final count
$result = $db->query("SELECT COUNT(*) as count FROM movies");
$row = $result->fetchArray();
$final_count = $row['count'];

echo "<div style='margin-top: 20px; padding: 15px; background: #e8f5e9; border: 2px solid #4caf50; border-radius: 5px;'>";
echo "<h2>🎉 IMPORT COMPLETE!</h2>";
echo "<p><strong>Initial Count:</strong> $initial_count movies</p>";
echo "<p><strong>Imported:</strong> $imported new movies</p>";
echo "<p><strong>Skipped:</strong> $skipped (already existed)</p>";
echo "<p><strong>Final Count:</strong> $final_count movies in database</p>";
echo "</div>";

// Show sample data
echo "<h3>📋 Sample Movies in Database:</h3>";
$sample_result = $db->query("SELECT movie_name, date, channel_id FROM movies ORDER BY id DESC LIMIT 10");
echo "<table border='1' cellpadding='8' cellspacing='0' style='border-collapse: collapse; width: 100%;'>";
echo "<tr style='background: #4caf50; color: white;'><th>Movie Name</th><th>Date</th><th>Channel ID</th></tr>";

while ($row = $sample_result->fetchArray(SQLITE3_ASSOC)) {
    echo "<tr>";
    echo "<td>" . htmlspecialchars($row['movie_name']) . "</td>";
    echo "<td>" . htmlspecialchars($row['date']) . "</td>";
    echo "<td>" . htmlspecialchars($row['channel_id']) . "</td>";
    echo "</tr>";
}
echo "</table>";

// Close database
$db->close();

// Quick test links
echo "<div style='margin-top: 20px; padding: 15px; background: #fff3cd; border: 1px solid #ffc107; border-radius: 5px;'>";
echo "<h3>🔧 Quick Test Links:</h3>";
echo "<p><a href='/?check_db=1' target='_blank'>📊 Check Database</a> | ";
echo "<a href='/' target='_blank'>🏠 Home Page</a> | ";
echo "<a href='https://api.telegram.org/bot8315381064:AAGk0FGVGmB8j5SjpBvW3rD3_kQHe_hyOWU/getMe' target='_blank'>🤖 Bot Info</a></p>";
echo "</div>";

echo "</div>"; // Closing div
?>
