<?php

declare(strict_types=1);

namespace OCA\Stoffe\Service;

use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;

/**
 * Detail photos of a fabric (e.g. close-ups of single motifs) are JPEG files
 * in a folder next to the main photo that has the main photo's name without
 * the extension: "Rosen.jpg" keeps its details in "Rosen/". The folder is
 * created with the first detail photo and follows the main photo when it is
 * renamed or deleted. Like everything else, it needs no database.
 */
class DetailService {
	public static function folderName(string $fileName): string {
		return pathinfo($fileName, PATHINFO_FILENAME);
	}

	/**
	 * The detail folder of a main photo, if there is one.
	 */
	public function find(File $main): ?Folder {
		$name = self::folderName($main->getName());
		if ($name === '') {
			return null;
		}
		try {
			$node = $main->getParent()->get($name);
		} catch (NotFoundException) {
			return null;
		}
		return $node instanceof Folder ? $node : null;
	}

	/**
	 * @return list<File>
	 */
	public function files(?Folder $folder): array {
		if ($folder === null) {
			return [];
		}
		$files = array_values(array_filter(
			$folder->getDirectoryListing(),
			fn ($node) => $node instanceof File && $node->getMimetype() === FabricService::MIME,
		));
		usort($files, fn (File $a, File $b) => strnatcasecmp($a->getName(), $b->getName()));
		return $files;
	}

	/**
	 * @return array{details:list<array<string,mixed>>,canUpload:bool}
	 */
	public function list(File $main): array {
		$folder = $this->find($main);
		return [
			'details' => array_map(fn (File $file) => $this->serialize($file), $this->files($folder)),
			'canUpload' => $folder !== null ? $folder->isCreatable() : $main->getParent()->isCreatable(),
		];
	}

	/**
	 * @return array<string,mixed>
	 */
	public function upload(File $main, string $originalName, string $tmpPath, string $mime): array {
		if ($mime !== FabricService::MIME) {
			throw new \InvalidArgumentException('Nur JPEG-Fotos sind erlaubt.');
		}
		$folder = $this->find($main) ?? $this->create($main);
		if (!$folder->isCreatable()) {
			throw new NotPermittedException('Zu diesem Stoff darfst du keine Detailbilder hochladen.');
		}
		$content = fopen($tmpPath, 'rb');
		if ($content === false) {
			throw new \RuntimeException('Upload konnte nicht gelesen werden.');
		}
		try {
			$file = $folder->newFile($folder->getNonExistingName(FabricService::sanitizeName($originalName, 'detail')), $content);
		} finally {
			if (is_resource($content)) {
				fclose($content);
			}
		}
		return $this->serialize($file);
	}

	public function delete(File $main, int $detailId): void {
		$folder = $this->find($main);
		$node = $folder?->getFirstNodeById($detailId);
		// Only photos directly in this fabric's detail folder.
		if (!$node instanceof File || $node->getParent()->getId() !== $folder->getId()) {
			throw new NotFoundException();
		}
		if (!$node->isDeletable()) {
			throw new NotPermittedException('Dieses Detailbild darfst du nicht löschen.');
		}
		$node->delete();
	}

	/**
	 * Moves the detail folder along when the main photo gets a new name.
	 * Returns a function that undoes the move.
	 *
	 * @return callable(): void
	 */
	public function rename(File $main, string $newFileName): callable {
		$folder = $this->find($main);
		$newName = self::folderName($newFileName);
		if ($folder === null || $newName === $folder->getName()) {
			return static function (): void {
			};
		}
		if (!$folder->isUpdateable() || !$folder->isDeletable()) {
			throw new NotPermittedException('Den Ordner mit den Detailbildern darfst du nicht umbenennen.');
		}
		$oldPath = $folder->getPath();
		$moved = $folder->move($folder->getParent()->getPath() . '/' . $newName);
		return static function () use ($moved, $oldPath): void {
			$moved->move($oldPath);
		};
	}

	/**
	 * Puts the detail folder into the trash bin together with the main photo.
	 */
	public function deleteFolder(File $main): void {
		$folder = $this->find($main);
		if ($folder === null) {
			return;
		}
		if (!$folder->isDeletable()) {
			throw new NotPermittedException('Den Ordner mit den Detailbildern darfst du nicht löschen.');
		}
		$folder->delete();
	}

	/**
	 * Whether a name is free for the detail folder of a photo called $fileName.
	 */
	public function folderNameFree(Folder $parent, string $fileName, ?File $current = null): bool {
		$name = self::folderName($fileName);
		$own = $current !== null ? $this->find($current) : null;
		foreach ($parent->getDirectoryListing() as $node) {
			if (strcasecmp($node->getName(), $name) === 0 && $node->getId() !== $own?->getId()) {
				return false;
			}
		}
		return true;
	}

	private function create(File $main): Folder {
		$parent = $main->getParent();
		if (!$parent->isCreatable()) {
			throw new NotPermittedException('Zu diesem Stoff darfst du keine Detailbilder hochladen.');
		}
		$name = self::folderName($main->getName());
		if ($name === '' || $parent->nodeExists($name)) {
			throw new \RuntimeException('Neben dem Foto liegt schon eine Datei namens „' . $name . '“.');
		}
		return $parent->newFolder($name);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function serialize(File $file): array {
		return [
			'id' => $file->getId(),
			'name' => $file->getName(),
			'size' => $file->getSize(),
			'mtime' => $file->getMTime(),
			'canDelete' => $file->isDeletable(),
		];
	}
}
