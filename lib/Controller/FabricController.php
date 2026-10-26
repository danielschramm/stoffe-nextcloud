<?php

declare(strict_types=1);

namespace OCA\Stoffe\Controller;

use OCA\Stoffe\AppInfo\Application;
use OCA\Stoffe\Service\DetailService;
use OCA\Stoffe\Service\FabricService;
use OCA\Stoffe\Service\PhotoFolder;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Controller;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\IRequest;

class FabricController extends Controller {
	public function __construct(
		IRequest $request,
		private FabricService $service,
		private DetailService $details,
		private PhotoFolder $photoFolder,
		private ?string $userId,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	public function index(): JSONResponse {
		try {
			$folder = $this->photoFolder->get($this->userId);
		} catch (NotFoundException) {
			return new JSONResponse([
				'message' => 'Der Ordner „' . $this->photoFolder->getPath($this->userId) . '“ ist nicht mehr da. Bitte wähle einen anderen.',
				'folderMissing' => true,
			], Http::STATUS_NOT_FOUND);
		}
		return new JSONResponse([
			'folder' => $this->photoFolder->describe($this->userId, $folder),
			'fabrics' => $this->service->list($this->userId),
		]);
	}

	#[NoAdminRequired]
	public function upload(): JSONResponse {
		return $this->receiveUploads(fn (string $name, string $tmp, string $mime) => $this->service->upload($this->userId, $name, $tmp, $mime));
	}

	#[NoAdminRequired]
	public function update(int $id): JSONResponse {
		return $this->respond(fn () => $this->service->update($this->userId, $id, $this->request->getParams()));
	}

	#[NoAdminRequired]
	public function rename(int $id, string $title): JSONResponse {
		return $this->respond(fn () => $this->service->rename($this->userId, $id, $title));
	}

	#[NoAdminRequired]
	public function destroy(int $id): JSONResponse {
		return $this->respond(function () use ($id) {
			$this->service->delete($this->userId, $id);
			return [];
		});
	}

	#[NoAdminRequired]
	public function details(int $id): JSONResponse {
		return $this->respond(fn () => $this->details->list($this->service->getFile($this->userId, $id)));
	}

	#[NoAdminRequired]
	public function uploadDetails(int $id): JSONResponse {
		try {
			$main = $this->service->getFile($this->userId, $id);
		} catch (NotFoundException) {
			return new JSONResponse(['message' => 'Foto nicht gefunden.'], Http::STATUS_NOT_FOUND);
		}
		return $this->receiveUploads(fn (string $name, string $tmp, string $mime) => $this->details->upload($main, $name, $tmp, $mime));
	}

	#[NoAdminRequired]
	public function destroyDetail(int $id, int $detailId): JSONResponse {
		return $this->respond(function () use ($id, $detailId) {
			$this->details->delete($this->service->getFile($this->userId, $id), $detailId);
			return [];
		});
	}

	/**
	 * Hands each uploaded file of "photos[]" to $store and collects the results.
	 *
	 * @param callable(string, string, string): array<string,mixed> $store gets name, temp path and detected type
	 */
	private function receiveUploads(callable $store): JSONResponse {
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
				$uploaded[] = $store($file['name'], $file['tmp_name'], $mime);
			} catch (\Throwable $e) {
				$errors[] = ['name' => $file['name'], 'message' => $e->getMessage()];
			}
		}

		$status = $uploaded === [] ? Http::STATUS_BAD_REQUEST : Http::STATUS_CREATED;
		return new JSONResponse(['uploaded' => $uploaded, 'errors' => $errors], $status);
	}

	/**
	 * Maps the service's exceptions to readable error responses.
	 */
	private function respond(callable $action): JSONResponse {
		try {
			return new JSONResponse($action());
		} catch (NotFoundException) {
			return new JSONResponse(['message' => 'Foto nicht gefunden.'], Http::STATUS_NOT_FOUND);
		} catch (NotPermittedException $e) {
			return new JSONResponse(['message' => $e->getMessage() ?: 'Dafür fehlen dir die Rechte.'], Http::STATUS_FORBIDDEN);
		} catch (\InvalidArgumentException|\RuntimeException $e) {
			return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
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
