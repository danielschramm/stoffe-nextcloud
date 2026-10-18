<?php

declare(strict_types=1);

namespace OCA\Stoffe\Controller;

use OCA\Stoffe\AppInfo\Application;
use OCA\Stoffe\Service\FabricService;
use OCA\Stoffe\Service\SettingsService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

class SettingsController extends Controller {
	public function __construct(
		IRequest $request,
		private SettingsService $settings,
		private FabricService $fabrics,
		private ?string $userId,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	public function index(): JSONResponse {
		return new JSONResponse($this->state());
	}

	#[NoAdminRequired]
	public function addCategory(string $name): JSONResponse {
		$this->settings->setCategories($this->userId, [...$this->settings->getCategories($this->userId), $name]);
		return new JSONResponse($this->state());
	}

	#[NoAdminRequired]
	public function renameCategory(string $from, string $to): JSONResponse {
		try {
			$this->fabrics->renameCategory($this->userId, $from, $to);
		} catch (\InvalidArgumentException $e) {
			return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
		return new JSONResponse($this->state());
	}

	/**
	 * Removes the category from the selection list. Photos keep their value,
	 * so nothing is lost by accident; it can be re-added any time.
	 */
	#[NoAdminRequired]
	public function deleteCategory(string $name): JSONResponse {
		$categories = array_values(array_filter($this->settings->getCategories($this->userId), fn (string $c) => $c !== $name));
		$this->settings->setCategories($this->userId, $categories);
		return new JSONResponse($this->state());
	}

	/**
	 * @return array<string,mixed>
	 */
	private function state(): array {
		return [
			'categories' => $this->settings->getCategories($this->userId),
			'fabricTypes' => SettingsService::FABRIC_TYPES,
			'lastLocation' => $this->settings->getLastLocation($this->userId),
		];
	}
}
