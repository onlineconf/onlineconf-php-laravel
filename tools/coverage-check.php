<?php

declare(strict_types=1);

/*
 * Fails when the clover report shows less than 100% line coverage.
 * Usage: php tools/coverage-check.php coverage/clover.xml
 */

$file = $argv[1] ?? 'coverage/clover.xml';
$xml = simplexml_load_file($file);
if ($xml === false || !isset($xml->project->metrics)) {
    fwrite(STDERR, "cannot read clover report {$file}\n");
    exit(2);
}

$metrics = $xml->project->metrics;
$covered = (int) $metrics['coveredstatements'];
$total = (int) $metrics['statements'];
printf("Line coverage: %d/%d (%.2f%%)\n", $covered, $total, $total === 0 ? 100 : $covered / $total * 100);

if ($covered !== $total) {
    $files = $xml->xpath('//file');
    foreach (is_array($files) ? $files : [] as $node) {
        $m = $node->metrics;
        if ((int) $m['coveredstatements'] !== (int) $m['statements']) {
            printf("  %s: %d/%d\n", (string) $node['name'], (int) $m['coveredstatements'], (int) $m['statements']);
        }
    }
    exit(1);
}
