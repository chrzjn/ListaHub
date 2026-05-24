<?php
$lines = file('inv_history.php');
foreach ($lines as $i => $line) {
    if (stripos($line, 'selling_price') !== false || stripos($line, 'total_price') !== false) {
        echo ($i+1) . ": " . htmlspecialchars($line) . "<br>";
    }
}