<?php

declare(strict_types=1);

namespace OCA\Stoffe\Service;

use OCA\Stoffe\AppInfo\Application;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\IConfig;
use OCP\Lock\LockedException;
use OCP\Share\IManager as IShareManager;
use OCP\Share\IShare;

/**
 * The folder holding a fabric collection: the photos (with their XMP data)
 * and the small settings file. By default this is the user's own "Stoffe"
 * folder; any other folder can be chosen, including folders other users
 * shared. The choice is only a user preference: if it is lost, the app
 * falls back to "Stoffe" and no fabric data is affected.
 */
class PhotoFolder {
	public const DEFAULT_PATH = '/Stoffe';

	public function __construct(
		private IRootFolder $rootFolder,
		private IConfig $config,
		private IShareManager $shareManager,
	) {
	}

	public function getPath(string $userId): string {
		return $this->config->getUserValue($userId, Application::APP_ID, 'folder', self::DEFAULT_PATH);
	}

	/**
	 * @throws NotFoundException if a chosen folder no longer exists (e.g. share removed)
	 */
	public function get(string $userId): Folder {
		$path = $this->getPath($userId);
		$userFolder = $this->rootFolder->getUserFolder($userId);
		if ($path === self::DEFAULT_PATH && !$userFolder->nodeExists(ltrim($path, '/'))) {
			try {
				return $userFolder->newFolder(ltrim($path, '/'));
			} catch (LockedException|NotPermittedException $e) {
				// Another request is creating the folder right now.
				usleep(300_000);
				if (!$userFolder->nodeExists(ltrim($path, '/'))) {
					throw $e;
				}
			}
		}
		$folder = $userFolder->get(ltrim($path, '/'));
		if (!$folder instanceof Folder) {
			throw new NotFoundException('"' . $path . '" ist kein Ordner.');
		}
		return $folder;
	}

	public function setPath(string $userId, string $path): Folder {
		$path = '/' . trim($path, '/');
		$node = $this->rootFolder->getUserFolder($userId)->get(ltrim($path, '/'));
		if (!$node instanceof Folder) {
			throw new NotFoundException('"' . $path . '" ist kein Ordner.');
		}
		$this->config->setUserValue($userId, Application::APP_ID, 'folder', $path);
		return $node;
	}

	/**
	 * @return array{path:string,name:string,owner:string,ownerName:string,shared:bool,canUpload:bool,canEdit:bool}
	 */
	public function describe(string $userId, Folder $folder): array {
		$userFolder = $this->rootFolder->getUserFolder($userId);
		$owner = $folder->getOwner();
		return [
			'path' => $userFolder->getRelativePath($folder->getPath()) ?? '/',
			'name' => $folder->getName(),
			'owner' => $owner?->getUID() ?? $userId,
			'ownerName' => $owner?->getDisplayName() ?? $userId,
			'shared' => $owner !== null && $owner->getUID() !== $userId,
			'canUpload' => $folder->isCreatable(),
			'canEdit' => $folder->isUpdateable(),
		];
	}

	/**
	 * Folders the user can switch to: their own "Stoffe" folder, the current
	 * one and folders shared with them that look like a fabric collection
	 * (named "Stoffe…" or holding a settings file).
	 *
	 * @return list<array<string,mixed>>
	 */
	public function candidates(string $userId): array {
		$userFolder = $this->rootFolder->getUserFolder($userId);
		$folders = [];
		$add = function (?Folder $folder) use (&$folders, $userId) {
			if ($folder !== null) {
				$info = $this->describe($userId, $folder);
				$folders[$info['path']] = $info;
			}
		};

		try {
			$add($this->get($userId));
		} catch (\Throwable) {
			// Chosen folder is gone; the default is offered below.
		}
		if ($userFolder->nodeExists(ltrim(self::DEFAULT_PATH, '/'))) {
			$default = $userFolder->get(ltrim(self::DEFAULT_PATH, '/'));
			$add($default instanceof Folder ? $default : null);
		}

		foreach ([IShare::TYPE_USER, IShare::TYPE_GROUP] as $type) {
			foreach ($this->shareManager->getSharedWith($userId, $type, null, -1) as $share) {
				if ($share->getNodeType() !== 'folder') {
					continue;
				}
				$node = $userFolder->getFirstNodeById($share->getNodeId());
				if ($node instanceof Folder
					&& (stripos($node->getName(), 'stoff') !== false || $node->nodeExists(SettingsService::FILE_NAME))) {
					$add($node);
				}
			}
		}
		return array_values($folders);
	}
}
