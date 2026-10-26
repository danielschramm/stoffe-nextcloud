<?php

declare(strict_types=1);

namespace OCA\Stoffe\Service;

use OCA\Stoffe\AppInfo\Application;
use OCP\Files\File;
use OCP\IConfig;

/**
 * The category list lives in a JSON file inside the photo folder, so it is
 * backed up, synced and shared together with the photos. If the file is
 * missing, the defaults are used. The last storage location is a personal
 * convenience and kept as a user preference, so people sharing a folder
 * don't overwrite each other's.
 */
class SettingsService {
	public const FILE_NAME = '.stoffe.json';
	public const DEFAULT_CATEGORIES = ['Hosen', 'Unterwäsche', 'T-Shirts', 'Kinder'];

	public const FABRIC_TYPES = [
		'Baumwolle', 'Batist', 'Bouclé', 'Brokat', 'Bündchen', 'Canvas', 'Chiffon', 'Cord', 'Crêpe',
		'Damast', 'Denim / Jeans', 'Doubleface', 'Feincord', 'Filz', 'Flanell', 'Fleece', 'French Terry',
		'Frottee', 'Gabardine', 'Georgette', 'Interlock', 'Jacquard', 'Jersey', 'Kunstfell', 'Kunstleder',
		'Leder', 'Leinen', 'Leinen-Baumwolle', 'Loden', 'Lycra', 'Lyocell / Tencel', 'Modal', 'Molton',
		'Musselin', 'Netzstoff', 'Nicki', 'Oilskin', 'Organza', 'Outdoorstoff', 'Piqué', 'Polyester',
		'Popeline', 'Ripsband', 'Samt', 'Satin', 'Seide', 'Softshell', 'Spitze', 'Steppstoff', 'Strick',
		'Sweat', 'Taft', 'Tüll', 'Tweed', 'Twill', 'Viskose', 'Wachstuch', 'Waffelpiqué', 'Walk',
		'Wolle', 'Sonstiges',
	];

	public function __construct(
		private PhotoFolder $photoFolder,
		private IConfig $config,
	) {
	}

	/**
	 * @return list<string>
	 */
	public function getCategories(string $userId): array {
		$list = $this->load($userId)['categories'] ?? null;
		return is_array($list) ? array_values(array_filter($list, 'is_string')) : self::DEFAULT_CATEGORIES;
	}

	/**
	 * @param list<string> $categories
	 */
	public function setCategories(string $userId, array $categories): void {
		$clean = [];
		foreach ($categories as $category) {
			$category = trim($category);
			if ($category !== '' && !in_array($category, $clean, true)) {
				$clean[] = $category;
			}
		}
		natcasesort($clean);
		$this->store($userId, ['categories' => array_values($clean)]);
	}

	/**
	 * @return array{room:string,cabinet:string,shelf:string}
	 */
	public function getLastLocation(string $userId): array {
		$raw = json_decode($this->config->getUserValue($userId, Application::APP_ID, 'lastLocation', ''), true);
		if (!is_array($raw)) {
			// Older versions kept it in the folder's settings file.
			$raw = $this->load($userId)['lastLocation'] ?? [];
		}
		return [
			'room' => (string)($raw['room'] ?? ''),
			'cabinet' => (string)($raw['cabinet'] ?? ''),
			'shelf' => (string)($raw['shelf'] ?? ''),
		];
	}

	/**
	 * @param array<string,string> $values
	 */
	public function rememberLocation(string $userId, array $values): void {
		$location = [
			'room' => $values['room'] ?? '',
			'cabinet' => $values['cabinet'] ?? '',
			'shelf' => $values['shelf'] ?? '',
		];
		if (implode('', $location) !== '' && $location !== $this->getLastLocation($userId)) {
			$this->config->setUserValue($userId, Application::APP_ID, 'lastLocation', json_encode($location, JSON_UNESCAPED_UNICODE));
		}
	}

	/**
	 * @return array<string,mixed>
	 */
	private function load(string $userId): array {
		$folder = $this->photoFolder->get($userId);
		if (!$folder->nodeExists(self::FILE_NAME)) {
			return [];
		}
		$file = $folder->get(self::FILE_NAME);
		$data = $file instanceof File ? json_decode($file->getContent(), true) : null;
		return is_array($data) ? $data : [];
	}

	/**
	 * @param array<string,mixed> $changes
	 */
	private function store(string $userId, array $changes): void {
		$data = [...$this->load($userId), ...$changes];
		if (!isset($data['categories'])) {
			$data['categories'] = self::DEFAULT_CATEGORIES;
		}
		$json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
		$folder = $this->photoFolder->get($userId);
		if ($folder->nodeExists(self::FILE_NAME)) {
			$folder->get(self::FILE_NAME)->putContent($json);
		} else {
			$folder->newFile(self::FILE_NAME, $json);
		}
	}
}
