<?php

declare(strict_types=1);

use PerfectConsign\Bootstrap\RegisterFeatures;

require dirname(__DIR__) . '/vendor/autoload.php';

$featureDirectory = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Feature';
$classes = RegisterFeatures::buildManifest($featureDirectory);
$path = RegisterFeatures::manifestPath($featureDirectory);
fwrite(STDOUT, 'wrote ' . count($classes) . ' controllers to ' . $path . PHP_EOL);
