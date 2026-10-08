<?php

declare(strict_types=1);

namespace App\Storage;

use App\Service\FileTooLargeException;
use Psr\Http\Message\UploadedFileInterface;
use RuntimeException;
use Throwable;

use function bin2hex;
use function is_dir;
use function is_file;
use function mkdir;
use function preg_replace;
use function rtrim;
use function str_replace;
use function strlen;
use function substr;
use function trim;
use function unlink;

final readonly class LocalFileStorage
{
    public function __construct(private string $rootPath) {}

    /**
     * @return array{key: string, name: string, size: int, mimeType: string}
     */
    public function store(UploadedFileInterface $upload): array
    {
        $name = $this->cleanName($upload->getClientFilename() ?? '');
        if ($name === '') {
            throw new RuntimeException('The uploaded file has no valid filename.');
        }

        $key = bin2hex(random_bytes(16));
        $directory = $this->directoryFor($key);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create the file storage directory.');
        }

        $path = $this->pathFor($key);
        $upload->moveTo($path);
        try {
            $size = filesize($path);
            if ($size === false) {
                throw new RuntimeException('Unable to read the stored file size.');
            }

            return [
                'key' => $key,
                'name' => $name,
                'size' => $size,
                'mimeType' => FileContentType::forFilename($name),
            ];
        } catch (Throwable $exception) {
            $this->delete($key);
            throw $exception;
        }
    }

    public function pathFor(string $key): string
    {
        if (preg_match('/\A[a-f0-9]{32}\z/', $key) !== 1) {
            throw new RuntimeException('Invalid storage key.');
        }

        return $this->directoryFor($key) . DIRECTORY_SEPARATOR . substr($key, 2);
    }

    public function delete(string $key): void
    {
        $path = $this->pathFor($key);
        if (is_file($path) && !unlink($path)) {
            throw new RuntimeException('Unable to remove the stored file.');
        }
    }

    /**
     * @param resource|string $contents
     */
    public function storeContents(string $name, mixed $contents): array
    {
        $name = $this->cleanName($name);
        if ($name === '') {
            throw new RuntimeException('The uploaded file has no valid filename.');
        }
        $key = bin2hex(random_bytes(16));
        $directory = $this->directoryFor($key);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create the file storage directory.');
        }

        try {
            $size = $this->writeContents($this->pathFor($key), $contents);
            return [
                'key' => $key,
                'name' => $name,
                'size' => $size,
                'mimeType' => FileContentType::forFilename($name),
            ];
        } catch (Throwable $exception) {
            $this->delete($key);
            throw $exception;
        }
    }

    /**
     * @param resource|string $contents
     */
    public function replaceContents(string $key, mixed $contents): int
    {
        return $this->writeContents($this->pathFor($key), $contents);
    }

    private function directoryFor(string $key): string
    {
        return rtrim($this->rootPath, '\\/') . DIRECTORY_SEPARATOR . substr($key, 0, 2);
    }

    private function cleanName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '');

        return strlen($name) <= 255 ? $name : substr($name, 0, 255);
    }

    /**
     * @param resource|string $contents
     */
    private function writeContents(string $path, mixed $contents): int
    {
        if (!is_resource($contents) && !is_string($contents)) {
            throw new RuntimeException('File contents must be a string or readable stream.');
        }
        if (is_string($contents) && strlen($contents) > 104857600) {
            throw new FileTooLargeException();
        }

        $input = is_resource($contents) ? $contents : fopen('php://temp', 'w+b');
        if (!is_resource($input)) {
            throw new RuntimeException('Unable to open file contents.');
        }
        $closeInput = !is_resource($contents);
        if ($closeInput) {
            if (fwrite($input, $contents) !== strlen($contents)) {
                fclose($input);
                throw new RuntimeException('Unable to buffer file contents.');
            }
            rewind($input);
        }

        $temporaryPath = $path . '.tmp-' . bin2hex(random_bytes(8));
        $output = fopen($temporaryPath, 'xb');
        if ($output === false) {
            if ($closeInput) {
                fclose($input);
            }
            throw new RuntimeException('Unable to create temporary storage file.');
        }

        $size = 0;
        try {
            while (!feof($input)) {
                $chunk = fread($input, min(1048576, 104857601 - $size));
                if ($chunk === false) {
                    throw new RuntimeException('Unable to read file contents.');
                }
                $length = strlen($chunk);
                if ($length === 0) {
                    break;
                }
                $size += $length;
                if ($size > 104857600) {
                    throw new FileTooLargeException();
                }
                if (fwrite($output, $chunk) !== $length) {
                    throw new RuntimeException('Unable to write file contents.');
                }
            }
            if (!fflush($output)) {
                throw new RuntimeException('Unable to flush file contents.');
            }
        } catch (Throwable $exception) {
            fclose($output);
            if ($closeInput) {
                fclose($input);
            }
            unlink($temporaryPath);
            throw $exception;
        }

        fclose($output);
        if ($closeInput) {
            fclose($input);
        }

        if (PHP_OS_FAMILY === 'Windows' && is_file($path)) {
            $backupPath = $path . '.bak-' . bin2hex(random_bytes(8));
            if (!rename($path, $backupPath)) {
                unlink($temporaryPath);
                throw new RuntimeException('Unable to prepare the existing file for replacement.');
            }
            if (!rename($temporaryPath, $path)) {
                if (!rename($backupPath, $path)) {
                    throw new RuntimeException('Unable to replace or restore the stored file.');
                }
                unlink($temporaryPath);
                throw new RuntimeException('Unable to finalize stored file replacement.');
            }
            if (!unlink($backupPath)) {
                throw new RuntimeException('The file was replaced but its temporary backup could not be removed.');
            }
        } elseif (!rename($temporaryPath, $path)) {
            unlink($temporaryPath);
            throw new RuntimeException('Unable to finalize stored file.');
        }

        return $size;
    }
}
