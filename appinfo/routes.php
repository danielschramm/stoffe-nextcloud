<?php

declare(strict_types=1);

return [
	'routes' => [
		['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],
		['name' => 'fabric#index', 'url' => '/api/fabrics', 'verb' => 'GET'],
		['name' => 'fabric#upload', 'url' => '/api/fabrics', 'verb' => 'POST'],
		['name' => 'fabric#update', 'url' => '/api/fabrics/{id}', 'verb' => 'PUT'],
		['name' => 'fabric#destroy', 'url' => '/api/fabrics/{id}', 'verb' => 'DELETE'],
		['name' => 'settings#index', 'url' => '/api/settings', 'verb' => 'GET'],
		['name' => 'settings#renameCategory', 'url' => '/api/categories/rename', 'verb' => 'POST'],
		['name' => 'settings#addCategory', 'url' => '/api/categories', 'verb' => 'POST'],
		['name' => 'settings#deleteCategory', 'url' => '/api/categories/delete', 'verb' => 'POST'],
	],
];
