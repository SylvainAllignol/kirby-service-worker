<?php
use Kirby\Cms\Response;

Kirby::plugin('sylvainallignol/service-worker', [
	'options' => [
		'enabled' => true,
		'version' => null,
		'registerType' => 'external',
		'precache' => [],
		'offlineFallback' => null,
		'offlineSVG' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 200 200"><path fill="rgba(0,0,0,.2)" d="M0 0h200v200H0z"/><path fill="none" stroke="rgba(0,0,0,.8)" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M98.11 92.46A15 15 0 0 1 100 90a10 10 0 0 1 9.31 13.64m-5.75-.08A14.5 14.5 0 0 1 100 110a10 10 0 0 1-7.07-17.07M103.9 98.23A14.5 14.5 0 0 0 100 90a10 10 0 0 0-3.64.69m9.3 9.31H110m-2.93 7.07A10 10 0 0 1 100 110a14.5 14.5 0 0 1-3.56-13.55M90 100h10M90 90l20 20"/></svg>',
		'defaultStrategy' => 'networkFirst',
		'rules' => [
			[
				'urlPattern' => '\.(png|jpg|jpeg|svg|webp|avif|woff|woff2|eot|ttf|otf)$',
				'strategy'   => 'cacheFirst',
				'cacheName' => 'images'
			],
			[
				'urlPattern' => '\.(js|css)$',
				'strategy'   => 'cacheFirst',
				'cacheName' => 'assets'
			]

		]
	],
	'routes' => [
		[
			'pattern' => 'sw.js',
			'method'  => 'GET',
			'action'  => function () {

				$config = option('sylvainallignol.service-worker');

				if (!$config['enabled']) {
					return false;
				}

				return new Response(
					snippet('sw', ['config' => $config], true),
					'application/javascript',
					null,
					[
						'Cache-Control' => 'no-cache, no-store, must-revalidate',
						'Pragma' => 'no-cache',
						'Expires' => '0',
					]
				);
			}
		],
		[
			'pattern' => 'sw-register.js',
			'method'  => 'GET',
			'action'  => function () {
				$js = snippet('sw-register-js', [], true);
				return new Response(
					$js,
					'application/javascript'
				);
			}
		]
	],

	'snippets' => [
		'sw' => __DIR__ . '/snippets/sw.php',
		'sw-register' => __DIR__ . '/snippets/sw-register.php',
		'sw-register-js' => __DIR__ . '/snippets/sw-register-js.php',
	],
]);
