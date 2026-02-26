<?php
$type = option('sylvainallignol.service-worker.registerType', 'inline');
if ($type === 'external') {
	echo '<script defer src="' . url('sw-register.js') . '"></script>' . PHP_EOL;
	return;
} else {
	$js = snippet('sw-register-js', [], true);
	echo '<script defer>' . PHP_EOL . $js . PHP_EOL . '</script>' . PHP_EOL;
}
