<?php

namespace App\Controllers;

use CodeIgniter\HTTP\ResponseInterface;

class SecureFiles extends BaseController
{
    public function receipt(int $fileId): ResponseInterface
    {
        $file = service('privateFileStorageService')->resolve($fileId);
        if ($file === null) {
            return $this->response->setStatusCode(404)->setBody('File not found.');
        }

        $metadata = $file['metadata'];
        return $this->response
            ->setHeader('Content-Type', (string) ($metadata['mime_type'] ?? 'application/octet-stream'))
            ->setHeader('Content-Disposition', 'inline; filename="' . addslashes((string) ($metadata['original_filename'] ?? 'receipt')) . '"')
            ->setBody((string) file_get_contents($file['path']));
    }
}
