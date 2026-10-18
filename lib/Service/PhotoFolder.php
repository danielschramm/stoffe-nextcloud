<?php

declare(strict_types=1);

namespace OCA\Stoffe\Service;

use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotPermittedException;
use OCP\Lock\LockedException;

/**
 * The user's "Stoffe" folder. Everything the app knows lives in here:
 * the photos (with their XMP data) and the small settings file.
 */
class PhotoFolder {
	public const NAME = 'Stoffe';

	public function __construct(
		private IRootFolder $rootFolder,
	) {
	}

	public function get(string $userId): Folder {
		$userFolder = $this->rootFolder->getUserFolder($userId);
		if (!$userFolder->nodeExists(self::NAME)) {
			try {
				return $userFolder->newFolder(self::NAME);
			} catch (LockedException|NotPermittedException $e) {
				// Another request is creating the folder right now.
				usleep(300_000);
				if (!$userFolder->nodeExists(self::NAME)) {
					throw $e;
				}
			}
		}
		$folder = $userFolder->get(self::NAME);
		if (!$folder instanceof Folder) {
			throw new NotPermittedException('"' . self::NAME . '" ist kein Ordner.');
		}
		return $folder;
	}
}
