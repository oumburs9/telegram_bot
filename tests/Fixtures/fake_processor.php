<?php

$output = null;

foreach ($argv as $arg) {
    if (str_starts_with($arg, '--output=')) {
        $output = substr($arg, 9);
    }
}

if (! is_string($output) || $output === '') {
    fwrite(STDERR, "Missing --output argument\n");
    exit(1);
}

if (! is_dir($output) && ! mkdir($output, 0775, true) && ! is_dir($output)) {
    fwrite(STDERR, "Could not create output directory\n");
    exit(1);
}

$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8Xw8AAoMBgA9W9RMAAAAASUVORK5CYII=');
$pdf = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";

file_put_contents($output.'/normal.png', $png);
file_put_contents($output.'/mirror.png', $png);
file_put_contents($output.'/a4_color.pdf', $pdf);
file_put_contents($output.'/a4_gray.pdf', $pdf);

exit(0);
