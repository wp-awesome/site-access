<?php
/**
 * Runs every `*-test.php` beside this file in its OWN process.
 *
 * Not a stylistic choice: the host fallback scope is a PHP constant, and a constant cannot be
 * redefined. Proving "an unrecognised stored value resolves to the host fallback" for more than one
 * fallback therefore requires more than one process. One process per file also means a fatal in one
 * test cannot mask the rest.
 */

declare(strict_types=1);

$files = glob(__DIR__ . '/*-test.php') ?: [];
sort($files);

// FINDING NO TESTS IS A FAILURE, NOT A CLEAN RUN.
//
// This package is consumed as a Git submodule, and the ordinary way a submodule ends up empty is a
// plain `git clone` without `--recurse-submodules`. Reporting "0/0 passed" and exiting 0 in that
// state would let a consuming project's gate go green over an empty directory — which is precisely
// the silent failure this package was written to remove, relocated one level down.
if ([] === $files) {
	fwrite(STDERR, sprintf(
		"wp-awesome: no test files found in %s.\n"
		. "This usually means the submodule is present but uninitialised. Run:\n"
		. "  git submodule update --init --recursive\n",
		__DIR__
	));

	exit(1);
}

$failed = 0;

foreach ($files as $file) {
	$output = [];
	$status = 0;
	exec(sprintf('%s %s 2>&1', escapeshellarg(PHP_BINARY), escapeshellarg($file)), $output, $status);

	$name = basename($file);

	if (0 === $status) {
		printf("ok    %s\n", $name);
		continue;
	}

	$failed++;
	printf("FAIL  %s\n", $name);
	foreach ($output as $line) {
		printf("        %s\n", $line);
	}
}

printf("----  %d/%d passed\n", count($files) - $failed, count($files));

exit($failed > 0 ? 1 : 0);
