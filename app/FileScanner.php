<?php
final class FileScanner
{
    private string $publicRoot;

    public function __construct(string $publicRoot)
    {
        $this->publicRoot = rtrim($publicRoot, DIRECTORY_SEPARATOR);
    }

    public function scan(array $machine): array
    {
        if (($machine['kind'] ?? '') !== 'folder') {
            throw new RuntimeException('La macchina selezionata non è di tipo cartella');
        }

        $uploadPath = trim((string)($machine['upload_path'] ?? ''));
        if ($uploadPath === '') {
            throw new RuntimeException('Cartella upload non configurata');
        }

        $baseDir = $this->safePath($uploadPath);
        if (!is_dir($baseDir)) {
            if (!mkdir($baseDir, 0775, true) && !is_dir($baseDir)) {
                throw new RuntimeException('Impossibile creare la cartella upload');
            }
        }

        $pdo = Db::pdo();
        $created = 0;
        $updated = 0;
        $files = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($baseDir, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $fileInfo) {
            if (!$fileInfo->isFile()) {
                continue;
            }
            $filename = $fileInfo->getFilename();
            if ($filename === '.gitkeep' || strpos($filename, '.') === 0) {
                continue;
            }
            $fullPath = $fileInfo->getPathname();
            $relative = str_replace('\\', '/', substr($fullPath, strlen($baseDir) + 1));
            $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            $size = (int)$fileInfo->getSize();
            $modified = date('Y-m-d H:i:s', (int)$fileInfo->getMTime());
            $hash = @sha1_file($fullPath) ?: null;

            $stmt = $pdo->prepare('SELECT id, size_bytes, modified_at, file_hash FROM job_files WHERE machine_id = ? AND relative_path = ?');
            $stmt->execute([(int)$machine['id'], $relative]);
            $existing = $stmt->fetch();

            if ($existing) {
                if ((int)$existing['size_bytes'] !== $size || $existing['modified_at'] !== $modified || $existing['file_hash'] !== $hash) {
                    $up = $pdo->prepare('UPDATE job_files SET file_name=?, extension=?, size_bytes=?, modified_at=?, file_hash=?, detected_at=NOW() WHERE id=?');
                    $up->execute([$filename, $extension, $size, $modified, $hash, (int)$existing['id']]);
                    $updated++;
                }
            } else {
                $ins = $pdo->prepare('INSERT INTO job_files (machine_id, relative_path, file_name, extension, size_bytes, modified_at, file_hash) VALUES (?, ?, ?, ?, ?, ?, ?)');
                $ins->execute([(int)$machine['id'], $relative, $filename, $extension, $size, $modified, $hash]);
                $created++;
            }

            $files[] = $relative;
        }

        return ['created' => $created, 'updated' => $updated, 'seen' => count($files)];
    }

    private function safePath(string $relative): string
    {
        $relative = trim(str_replace('\\', '/', $relative), '/');
        if ($relative === '' || strpos($relative, '..') !== false || preg_match('#^[a-zA-Z]:/#', $relative)) {
            throw new RuntimeException('Percorso upload non valido. Usa un percorso relativo dentro public/.');
        }
        return $this->publicRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    }
}
