<?php

declare(strict_types=1);

namespace OCA\Stoffe\Controller;

use OCA\Stoffe\AppInfo\Application;
use OCA\Stoffe\Service\FabricService;
use OCA\Stoffe\Service\PhotoFolder;
use OCA\Stoffe\Service\SettingsService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\IRequest;

class SettingsController extends Controller {
	public function __construct(
		IRequest $request,
		private SettingsService $settings,
		private FabricService $fabrics,
		private PhotoFolder $photoFolder,
		private ?string $userId,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	public function index(): JSONResponse {
		return $this->respond(fn () => null);
	}

	#[NoAdminRequired]
	public function addCategory(string $name): JSONResponse {
		return $this->respond(fn () => $this->settings->setCategories($this->userId, [...$this->settings->getCategories($this->userId), $name]));
	}

	#[NoAdminRequired]
	public function renameCategory(string $from, string $to): JSONResponse {
		return $this->respond(fn () => $this->fabrics->renameCategory($this->userId, $from, $to));
	}

	/**
	 * Removes the category from the selection list. Photos keep their value,
	 * so nothing is lost by accident; it can be re-added any time.
	 */
	#[NoAdminRequired]
	public function deleteCategory(string $name): JSONResponse {
		return $this->respond(fn () => $this->settings->setCategories(
			$this->userId,
			array_values(array_filter($this->settings->getCategories($this->userId), fn (string $c) => $c !== $name)),
		));
	}

	#[NoAdminRequired]
	public function folders(): JSONResponse {
		return new JSONResponse([
			'current' => $this->photoFolder->getPath($this->userId),
			'folders' => $this->photoFolder->candidates($this->userId),
		]);
	}

	#[NoAdminRequired]
	public function setFolder(string $path): JSONResponse {
		try {
			$folder = $this->photoFolder->setPath($this->userId, $path);
		} catch (NotFoundException) {
			return new JSONResponse(['message' => 'Diesen Ordner gibt es nicht.'], Http::STATUS_NOT_FOUND);
		}
		return new JSONResponse($this->photoFolder->describe($this->userId, $folder));
	}

	/**
	 * Runs a change and answers with the new state, or a readable error.
	 */
	private function respond(callable $change): JSONResponse {
		try {
			$change();
			return new JSONResponse([
				'categories' => $this->settings->getCategories($this->userId),
				'fabricTypes' => SettingsService::FABRIC_TYPES,
				'lastLocation' => $this->settings->getLastLocation($this->userId),
			]);
		} catch (NotPermittedException) {
			return new JSONResponse(['message' => 'In diesem Ordner darfst du die Kategorien nicht ändern.'], Http::STATUS_FORBIDDEN);
		} catch (NotFoundException) {
			return new JSONResponse(['message' => 'Der Ordner ist nicht mehr da. Bitte wähle einen anderen.'], Http::STATUS_NOT_FOUND);
		} catch (\InvalidArgumentException $e) {
			return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}
}
