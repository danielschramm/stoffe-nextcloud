<?php

declare(strict_types=1);

namespace OCA\Stoffe\Controller;

use OCA\Stoffe\AppInfo\Application;
use OCA\Stoffe\Service\FabricService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Controller;
use OCP\Files\NotFoundException;
use OCP\IRequest;

class FabricController extends Controller {
	public function __construct(
		IRequest $request,
		private FabricService $service,
		private ?string $userId,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	public function index(): JSONResponse {
		return new JSONResponse($this->service->list($this->userId));
	}

	#[NoAdminRequired]
	public function upload(): JSONResponse {
		$files = $this->normalizeUploads($this->request->getUploadedFile('photos'));
		if ($files === []) {
			return new JSONResponse(['message' => 'Keine Datei empfangen.'], Http::STATUS_BAD_REQUEST);
		}

		$uploaded = [];
		$errors = [];
		foreach ($files as $file) {
			if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
				$errors[] = ['name' => $file['name'], 'message' => 'Upload fehlgeschlagen (Code ' . $file['error'] . ').'];
				continue;
			}
			// Trust the detected content type, not the one sent by the browser.
			$mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']) ?: '';
			try {
				$uploaded[] = $this->service->upload($this->userId, $file['name'], $file['tmp_name'], $mime);
			} catch (\Throwable $e) {
				$errors[] = ['name' => $file['name'], 'message' => $e->getMessage()];
			}
		}

		$status = $uploaded === [] ? Http::STATUS_BAD_REQUEST : Http::STATUS_CREATED;
		return new JSONResponse(['uploaded' => $uploaded, 'errors' => $errors], $status);
	}

	#[NoAdminRequired]
	public function update(int $id): JSONResponse {
		try {
			return new JSONResponse($this->service->update($this->userId, $id, $this->request->getParams()));
		} catch (NotFoundException) {
			return new JSONResponse(['message' => 'Foto nicht gefunden.'], Http::STATUS_NOT_FOUND);
		} catch (\InvalidArgumentException|\RuntimeException $e) {
			return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}

	#[NoAdminRequired]
	public function destroy(int $id): JSONResponse {
		try {
			$this->service->delete($this->userId, $id);
		} catch (NotFoundException) {
			return new JSONResponse(['message' => 'Foto nicht gefunden.'], Http::STATUS_NOT_FOUND);
		}
		return new JSONResponse([], Http::STATUS_NO_CONTENT);
	}

	/**
	 * Turns PHP's $_FILES layout for "photos[]" into a list of single files.
	 *
	 * @return list<array{name:string,tmp_name:string,error:int}>
	 */
	private function normalizeUploads(?array $raw): array {
		if ($raw === null || !isset($raw['name'])) {
			return [];
		}
		if (!is_array($raw['name'])) {
			return [['name' => (string)$raw['name'], 'tmp_name' => (string)$raw['tmp_name'], 'error' => (int)$raw['error']]];
		}
		$files = [];
		foreach ($raw['name'] as $i => $name) {
			$files[] = ['name' => (string)$name, 'tmp_name' => (string)$raw['tmp_name'][$i], 'error' => (int)$raw['error'][$i]];
		}
		return $files;
	}
}
