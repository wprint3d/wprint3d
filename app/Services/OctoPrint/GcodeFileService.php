<?php

namespace App\Services\OctoPrint;

use App\Exceptions\PrintJobException;
use App\Models\File;
use App\Models\Printer;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

class GcodeFileService
{
    private FilesystemAdapter $disk;

    public function __construct()
    {
        $this->disk = Storage::disk('gcode');
    }

    public function normalizePath(string $path): string
    {
        $decoded = rawurldecode(rawurldecode($path));
        $decoded = str_replace('\\', '/', trim($decoded));

        if ($decoded === '' || str_contains($decoded, "\0") || str_starts_with($decoded, '/')) {
            throw new HttpException(400, 'Invalid file path.');
        }

        $segments = explode('/', $decoded);

        if (in_array('..', $segments, true) || in_array('.', $segments, true)) {
            throw new HttpException(400, 'Invalid file path.');
        }

        return implode('/', array_filter($segments, fn ($segment) => $segment !== ''));
    }

    public function sanitizeUploadName(string $name): string
    {
        $name = Str::ascii(basename(str_replace('\\', '/', $name)));
        $name = preg_replace('/[^A-Za-z0-9._-]+/', '_', $name) ?: 'cura-upload.gcode';
        $name = trim($name, '._-');

        if ($name === '') {
            $name = 'cura-upload.gcode';
        }

        if (! in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), ['gcode', 'gco', 'g'], true)) {
            $name .= '.gcode';
        }

        return Str::limit($name, 180, '');
    }

    public function store(UploadedFile $file): string
    {
        $path = $this->sanitizeUploadName($file->getClientOriginalName());

        if (Printer::where('activeFile', $path)->exists()) {
            throw new PrintJobException('file_active', 'An active print is using this file.');
        }

        $stream = fopen($file->getRealPath(), 'r');

        if (! $stream || ! $this->disk->put($path, $stream)) {
            throw new HttpException(500, 'The G-code file could not be stored.');
        }

        if (is_resource($stream)) {
            fclose($stream);
        }

        $fileModel = File::where('path', $path)->first() ?: new File;
        $fileModel->path = $path;
        $fileModel->save();

        return $path;
    }

    public function delete(string $path): void
    {
        $path = $this->normalizePath($path);

        if (Printer::where('activeFile', $path)->exists()) {
            throw new PrintJobException('file_active', 'An active print is using this file.');
        }

        if (! $this->disk->exists($path)) {
            throw new HttpException(404, 'File not found.');
        }

        $this->disk->delete($path);
        File::where('path', $path)->delete();
    }

    public function exists(string $path): bool
    {
        return $this->disk->exists($this->normalizePath($path));
    }

    public function file(string $path): array
    {
        $path = $this->normalizePath($path);

        if (! $this->disk->exists($path)) {
            throw new HttpException(404, 'File not found.');
        }

        return [
            'name' => basename($path),
            'path' => $path,
            'origin' => 'local',
            'size' => $this->disk->size($path),
            'date' => $this->disk->lastModified($path),
            'type' => 'machinecode',
            'typePath' => ['machinecode', 'gcode'],
            'refs' => [
                'resource' => url('/api/files/local/'.str_replace('%2F', '/', rawurlencode($path))),
                'download' => url('/api/files/local/'.str_replace('%2F', '/', rawurlencode($path))),
            ],
        ];
    }

    public function all(): array
    {
        return array_values(array_map(fn (string $path) => $this->file($path), $this->disk->allFiles()));
    }
}
