<?php
$code = file_get_contents('dashboard.php');
$lines = explode("\n", $code);
$brace = 0;
foreach ($lines as $i => $line) {
    $brace += substr_count($line, '{') - substr_count($line, '}');
    if ($brace < 0) {
        echo "Extra } at line " . ($i+1) . ": " . htmlspecialchars($line) . "<br>";
        $brace = 0;
    }
}
echo "Final brace count: $brace (should be 0)";
?>