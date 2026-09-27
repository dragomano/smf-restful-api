<?php

declare(strict_types=1);

use Testo\Application\Config\ApplicationConfig;
use Testo\Codecov\CodecovPlugin;
use Testo\Codecov\Config\CoverageLevel;
use Testo\Codecov\Report\CloverReport;

return new ApplicationConfig(
	src: ['src'],
	plugins: [
		new CodecovPlugin(
			level: CoverageLevel::Line,
			reports: [
				new CloverReport(__DIR__ . '/coverage/clover.xml', 'SMF RESTful API'),
			],
		),
	],
);
