<?php
// Database connection
$db = new SQLite3('movies.db');

$movies = [
    ['Metro In Dino 2025', 1001, '24-12-2025', '-1003181705395', '1080p', 'Hindi'],
    ['Metro In Dino', 1002, '24-12-2025', '-1003181705395', '720p', 'Hindi'],
    ['KGF Chapter 2', 1003, '24-12-2025', '-1003181705395', '1080p', 'Hindi'],
    ['Pushpa', 1004, '24-12-2025', '-1003181705395', '1080p', 'Hindi'],
    ['Animal', 1005, '24-12-2025', '-1003181705395', '1080p', 'Hindi'],
    ['Jawan', 1006, '24-12-2025', '-1003181705395', '1080p', 'Hindi'],
    ['Pathaan', 1007, '24-12-2025', '-1003181705395', '1080p', 'Hindi'],
    ['Salaar', 1008, '24-12-2025', '-1003181705395', '1080p', 'Hindi']
];

foreach ($movies as $movie) {
    $stmt = $db->prepare("INSERT INTO movies (movie_name, message_id, date, channel_id, file_type, quality, language) VALUES (?, ?, ?, ?, 'video', ?, ?)");
    $stmt->bindValue(1, $movie[0], SQLITE3_TEXT);
    $stmt->bindValue(2, $movie[1], SQLITE3_INTEGER);
    $stmt->bindValue(3, $movie[2], SQLITE3_TEXT);
    $stmt->bindValue(4, $movie[3], SQLITE3_TEXT);
    $stmt->bindValue(5, $movie[4], SQLITE3_TEXT);
    $stmt->bindValue(6, $movie[5], SQLITE3_TEXT);
    $stmt->execute();
}

echo "<h2>✅ Movies Added Successfully!</h2>";
echo "<p>Total movies added: " . count($movies) . "</p>";

// Check
$result = $db->query("SELECT COUNT(*) as count FROM movies");
$row = $result->fetchArray();
echo "<p>Total in database: " . $row['count'] . "</p>";

$db->close();
?>
