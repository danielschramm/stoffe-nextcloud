<?php

declare(strict_types=1);

namespace OCA\Stoffe\Service;

use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\ICache;
use OCP\ICacheFactory;
use Psr\Log\LoggerInterface;

/**
 * Fabric photos are plain JPEG files (phone photos) in the collection folder
 * (see PhotoFolder); the file name is the fabric's title and the fabric
 * fields live only in the files' XMP data. Detail photos sit in a folder
 * named like the photo (see DetailService). The memory cache just
 * saves re-reading unchanged files and can be lost at any time.
 */
class FabricService {
	public const MIME = 'image/jpeg';
	private const CACHE_TTL = 30 * 24 * 3600;

	private ICache $cache;

	public function __construct(
		private PhotoFolder $photoFolder,
		private XmpService $xmp,
		private DetailService $details,
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
		$photos = [];
		$folders = [];
		foreach ($this->photoFolder->get($userId)->getDirectoryListing() as $node) {
			if ($node instanceof File && $node->getMimetype() === self::MIME) {
				$photos[] = $node;
			} elseif ($node instanceof Folder) {
				$folders[$node->getName()] = $node;
			}
		}
		$fabrics = [];
		foreach ($photos as $photo) {
			$details = $folders[DetailService::folderName($photo->getName())] ?? null;
			$fabrics[] = $this->serialize($photo, $this->readValues($photo), count($this->details->files($details)));
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
		if (!$folder->isCreatable()) {
			throw new NotPermittedException('In diesem Ordner darfst du keine Fotos hochladen.');
		}
		try {
			$file = $folder->newFile($folder->getNonExistingName(self::sanitizeName($originalName)), $content);
		} finally {
			if (is_resource($content)) {
				fclose($content);
			}
		}
		return $this->get($file);
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array<string,mixed>
	 */
	public function update(string $userId, int $id, array $input, bool $rememberLocation = true): array {
		$file = $this->getFile($userId, $id);
		if (!$file->isUpdateable()) {
			throw new NotPermittedException('Dieses Foto darfst du nur ansehen, nicht ändern.');
		}
		$values = $this->cleanValues($input);
		$file->putContent($this->xmp->write($file->getContent(), $values));
		$this->cache->set($this->cacheKey($file), $values, self::CACHE_TTL);

		if ($rememberLocation) {
			$this->settings->rememberLocation($userId, $values);
		}
		$categories = $this->settings->getCategories($userId);
		if ($values['category'] !== '' && !in_array($values['category'], $categories, true)) {
			try {
				$this->settings->setCategories($userId, [...$categories, $values['category']]);
			} catch (NotPermittedException $e) {
				// Read-only settings file in a shared folder: the photo keeps its category anyway.
				$this->logger->info('Could not add category to list', ['exception' => $e]);
			}
		}
		return $this->serialize($file, $values, count($this->details->files($this->details->find($file))));
	}

	/**
	 * Renames the photo; the file name (without extension) is the fabric's title.
	 * The folder with the detail photos is renamed along with it.
	 *
	 * @return array<string,mixed>
	 */
	public function rename(string $userId, int $id, string $title): array {
		$file = $this->getFile($userId, $id);
		$title = trim(preg_replace('/[\x00-\x1f\/\\\\:*?"<>|]+/u', ' ', $title) ?? '');
		$title = preg_replace('/\s+/u', ' ', $title) ?? '';
		if ($title === '' || $title[0] === '.') {
			throw new \InvalidArgumentException('Bitte einen Titel eingeben.');
		}
		if (mb_strlen($title) > 200) {
			throw new \InvalidArgumentException('Der Titel ist zu lang (höchstens 200 Zeichen).');
		}
		$extension = pathinfo($file->getName(), PATHINFO_EXTENSION) ?: 'jpg';
		$newName = $title . '.' . $extension;
		if ($newName === $file->getName()) {
			return $this->get($file);
		}
		$parent = $file->getParent();
		if (!$file->isUpdateable() || !$parent->isCreatable()) {
			throw new NotPermittedException('Dieses Foto darfst du nicht umbenennen.');
		}
		// A different spelling of the same name is fine, another photo's name is not.
		if (strcasecmp($newName, $file->getName()) !== 0 && $this->nameTaken($parent, $newName)) {
			throw new \InvalidArgumentException('Es gibt schon einen Stoff mit dem Titel „' . $title . '“.');
		}
		if (!$this->details->folderNameFree($parent, $newName, $file)) {
			throw new \InvalidArgumentException('Im Ordner gibt es schon einen Unterordner „' . $title . '“.');
		}
		$undoFolder = $this->details->rename($file, $newName);
		try {
			$renamed = $file->move($parent->getPath() . '/' . $newName);
		} catch (\Throwable $e) {
			$undoFolder();
			throw $e;
		}
		if (!$renamed instanceof File) {
			throw new \RuntimeException('Umbenennen fehlgeschlagen.');
		}
		return $this->get($renamed);
	}

	public function delete(string $userId, int $id): void {
		$file = $this->getFile($userId, $id);
		if (!$file->isDeletable()) {
			throw new NotPermittedException('Dieses Foto darfst du nicht löschen.');
		}
		// Details first: if they cannot go, the fabric stays complete.
		$this->details->deleteFolder($file);
		$file->delete();
	}

	/**
	 * @return array<string,mixed>
	 */
	private function get(File $file): array {
		return $this->serialize($file, $this->readValues($file), count($this->details->files($this->details->find($file))));
	}

	private function nameTaken(Folder $folder, string $name): bool {
		foreach ($folder->getDirectoryListing() as $node) {
			if (strcasecmp($node->getName(), $name) === 0) {
				return true;
			}
		}
		return false;
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

	public function getFile(string $userId, int $id): File {
		$node = $this->photoFolder->get($userId)->getFirstNodeById($id);
		if (!$node instanceof File) {
			throw new NotFoundException();
		}
		return $node;
	}

	/**
	 * A safe file name for an uploaded JPEG, always ending in .jpg/.jpeg so
	 * that the name of a photo never equals the name of its detail folder.
	 */
	public static function sanitizeName(string $name, string $fallback = 'stoff'): string {
		$name = trim(basename(str_replace('\\', '/', $name)));
		$name = preg_replace('/[\x00-\x1f\/:*?"<>|]/u', '_', $name) ?? '';
		if ($name === '' || $name[0] === '.') {
			return $fallback . '-' . date('Ymd-His') . '.jpg';
		}
		return preg_match('/\.jpe?g$/i', $name) ? $name : $name . '.jpg';
	}

	/**
	 * @param array<string,string> $values
	 * @return array<string,mixed>
	 */
	private function serialize(File $file, array $values, int $detailCount = 0): array {
		// Photos saved before a field existed simply don't have it yet.
		$values = [...array_fill_keys(XmpService::FIELDS, ''), ...$values];
		$values['remnant'] = $this->isTrue($values['remnant']) ? 'true' : 'false';
		return [
			'id' => $file->getId(),
			'name' => $file->getName(),
			'mime' => $file->getMimetype(),
			'size' => $file->getSize(),
			'mtime' => $file->getMTime(),
			'canEdit' => $file->isUpdateable(),
			'canDelete' => $file->isDeletable(),
			'detailCount' => $detailCount,
			...$values,
		];
	}
}
