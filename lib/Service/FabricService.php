<?php

declare(strict_types=1);

namespace OCA\Stoffe\Service;

use OCP\Files\File;
use OCP\Files\NotFoundException;
use OCP\ICache;
use OCP\ICacheFactory;
use Psr\Log\LoggerInterface;

/**
 * Fabric photos are plain JPEG files (phone photos) in the user's "Stoffe" folder; the
 * fabric fields live only in the files' XMP data. The memory cache just
 * saves re-reading unchanged files and can be lost at any time.
 */
class FabricService {
	public const MIME = 'image/jpeg';
	private const CACHE_TTL = 30 * 24 * 3600;

	private ICache $cache;

	public function __construct(
		private PhotoFolder $photoFolder,
		private XmpService $xmp,
		private SettingsService $settings,
		private LoggerInterface $logger,
		ICacheFactory $cacheFactory,
	) {
		$this->cache = $cacheFactory->createLocal('stoffe-xmp-v2');
	}

	/**
	 * @return list<array<string,mixed>>
	 */
	public function list(string $userId): array {
		$fabrics = [];
		foreach ($this->photoFolder->get($userId)->getDirectoryListing() as $node) {
			if ($node instanceof File && $node->getMimetype() === self::MIME) {
				$fabrics[] = $this->serialize($node, $this->readValues($node));
			}
		}
		usort($fabrics, fn (array $a, array $b) => $b['mtime'] <=> $a['mtime']);
		return $fabrics;
	}

	/**
	 * @return array<string,mixed>
	 */
	public function upload(string $userId, string $originalName, string $tmpPath, string $mime): array {
		if ($mime !== self::MIME) {
			throw new \InvalidArgumentException('Nur JPEG-Fotos sind erlaubt.');
		}
		$content = fopen($tmpPath, 'rb');
		if ($content === false) {
			throw new \RuntimeException('Upload konnte nicht gelesen werden.');
		}
		$folder = $this->photoFolder->get($userId);
		try {
			$file = $folder->newFile($folder->getNonExistingName($this->sanitizeName($originalName)), $content);
		} finally {
			if (is_resource($content)) {
				fclose($content);
			}
		}
		return $this->serialize($file, $this->readValues($file));
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array<string,mixed>
	 */
	public function update(string $userId, int $id, array $input, bool $rememberLocation = true): array {
		$file = $this->getFile($userId, $id);
		$values = $this->cleanValues($input);
		$file->putContent($this->xmp->write($file->getContent(), $values));
		$this->cache->set($this->cacheKey($file), $values, self::CACHE_TTL);

		if ($rememberLocation) {
			$this->settings->rememberLocation($userId, $values);
		}
		$categories = $this->settings->getCategories($userId);
		if ($values['category'] !== '' && !in_array($values['category'], $categories, true)) {
			$this->settings->setCategories($userId, [...$categories, $values['category']]);
		}
		return $this->serialize($file, $values);
	}

	public function delete(string $userId, int $id): void {
		$this->getFile($userId, $id)->delete();
	}

	/**
	 * Renames a category in the list and in every photo that uses it.
	 */
	public function renameCategory(string $userId, string $from, string $to): void {
		$to = trim($to);
		if ($to === '') {
			throw new \InvalidArgumentException('Der Name darf nicht leer sein.');
		}
		$this->settings->setCategories($userId, array_map(fn (string $c) => $c === $from ? $to : $c, $this->settings->getCategories($userId)));

		foreach ($this->list($userId) as $fabric) {
			if ($fabric['category'] !== $from) {
				continue;
			}
			try {
				$this->update($userId, $fabric['id'], [...$fabric, 'category' => $to], false);
			} catch (\Throwable $e) {
				$this->logger->warning('Could not rename category in file ' . $fabric['id'], ['exception' => $e]);
			}
		}
	}

	/**
	 * @return array<string,string>
	 */
	private function readValues(File $file): array {
		$empty = array_fill_keys(XmpService::FIELDS, '');
		$key = $this->cacheKey($file);
		$cached = $this->cache->get($key);
		if (is_array($cached)) {
			return $cached;
		}
		try {
			$stream = $file->fopen('rb');
			$values = $this->xmp->read($stream) ?? $empty;
			fclose($stream);
		} catch (\Throwable $e) {
			$this->logger->warning('Could not read XMP from file ' . $file->getId(), ['exception' => $e]);
			return $empty;
		}
		$this->cache->set($key, $values, self::CACHE_TTL);
		return $values;
	}

	private function cacheKey(File $file): string {
		return $file->getId() . '-' . $file->getMTime() . '-' . $file->getSize();
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array<string,string>
	 */
	private function cleanValues(array $input): array {
		$values = [];
		foreach (XmpService::FIELDS as $field) {
			$value = $input[$field] ?? '';
			$value = is_scalar($value) ? trim((string)$value) : '';
			$values[$field] = $field === 'comment' ? mb_substr($value, 0, 5000) : mb_substr(preg_replace('/\s+/u', ' ', $value) ?? '', 0, 255);
		}
		if ($values['price'] !== '') {
			$price = str_replace(',', '.', preg_replace('/[^\d,.]/', '', $values['price']) ?? '');
			if (!is_numeric($price)) {
				throw new \InvalidArgumentException('Der Preis ist keine gültige Zahl.');
			}
			$values['price'] = number_format((float)$price, 2, '.', '');
		}
		foreach (['length' => 'Die Länge', 'width' => 'Die Breite'] as $field => $label) {
			if ($values[$field] !== '') {
				$number = str_replace(',', '.', preg_replace('/[^\d,.]/', '', $values[$field]) ?? '');
				if (!is_numeric($number)) {
					throw new \InvalidArgumentException($label . ' ist keine gültige Zahl.');
				}
				$values[$field] = rtrim(rtrim(number_format((float)$number, 1, '.', ''), '0'), '.');
			}
		}
		$values['remnant'] = $this->isTrue($values['remnant']) ? 'true' : 'false';
		return $values;
	}

	private function isTrue(string $value): bool {
		return in_array(strtolower($value), ['true', '1', 'ja', 'yes', 'on'], true);
	}

	private function getFile(string $userId, int $id): File {
		$node = $this->photoFolder->get($userId)->getFirstNodeById($id);
		if (!$node instanceof File) {
			throw new NotFoundException();
		}
		return $node;
	}

	private function sanitizeName(string $name): string {
		$name = trim(basename(str_replace('\\', '/', $name)));
		$name = preg_replace('/[\x00-\x1f\/:*?"<>|]/u', '_', $name) ?? '';
		return $name === '' || $name[0] === '.' ? 'stoff-' . date('Ymd-His') . '.jpg' : $name;
	}

	/**
	 * @param array<string,string> $values
	 * @return array<string,mixed>
	 */
	private function serialize(File $file, array $values): array {
		// Photos saved before a field existed simply don't have it yet.
		$values = [...array_fill_keys(XmpService::FIELDS, ''), ...$values];
		$values['remnant'] = $this->isTrue($values['remnant']) ? 'true' : 'false';
		return [
			'id' => $file->getId(),
			'name' => $file->getName(),
			'mime' => $file->getMimetype(),
			'size' => $file->getSize(),
			'mtime' => $file->getMTime(),
			...$values,
		];
	}
}
