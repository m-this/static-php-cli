<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';
require __DIR__ . '/../../src/bootstrap.php';

$size = 1 << 20;
$runs = 20;
$full = 0;
$php = escapeshellarg(PHP_BINARY);
for ($i = 0; $i < $runs; ++$i) {
    [, $out] = cmd(false)->execWithResult("{$php} -r \"fwrite(STDERR, str_repeat('x', {$size}));\"", false);
    $len = strlen(implode("\n", $out));
    if ($len === $size) {
        ++$full;
    } else {
        echo "run {$i}: captured {$len} of {$size} bytes\n";
    }
}
echo "{$full}/{$runs} runs captured the full output\n";
exit($full === $runs ? 0 : 1);
