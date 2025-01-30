<?php

namespace App\Http\Controllers;

use App\Enums\SortingMode;
use App\Models\File;
use App\Models\User;
use BenSampo\Enum\Exceptions\InvalidEnumMemberException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class FilesController extends Controller
{

    private User              $user;
    private FilesystemAdapter $disk;

    public function __construct()
    {
        $this->middleware(function (Request $request, $next) {
            $this->user = $request->user();

            return $next($request);
        });

        $this->disk = Storage::disk('gcode');
    }

    public function sortingModes() {
        return SortingMode::asArray();
    }

    /**
     * index
     * 
     * @throws InvalidEnumMemberException
     *
     * @param  mixed $request
     * @return array
     */
    public function index(Request $request): array {
        $request->validate([
            'subPath'   => [ 'sometimes' ],
            'sortBy'    => [ 'sometimes' ]
        ]);

        $subPath = $request->input('subPath');
        $sortBy  = $request->input('sortBy');

        if ($sortBy === null) {
            $sortBy = SortingMode::NAME_ASCENDING;
        }

        $sortBy = (int) $sortBy;

        // Tries to build an instance or fails with an exception.
        SortingMode::fromValue($sortBy);

        $files       = $this->disk->files($subPath);
        $directories = array_map(
            function ($item) use ($subPath) {
                return Str::replace($subPath . '/', '', $item);
            },
            $this->disk->directories($subPath)
        );

        if (
            $sortBy == SortingMode::NAME_ASCENDING
            ||
            $sortBy == SortingMode::NAME_DESCENDING
        ) {
            natsort($files);

            if ($sortBy == SortingMode::NAME_DESCENDING) {
                $files = array_reverse($files);
            }
        } else if ($sortBy == SortingMode::DATE_ASCENDING) {
            usort($files, function ($fileA, $fileB) use ($subPath) {
                return
                    $this->disk->lastModified( $subPath . '/' . $fileA )
                    >
                    $this->disk->lastModified( $subPath . '/' . $fileB );
            });
        } else if ($sortBy == SortingMode::DATE_DESCENDING) {
            usort($files, function ($fileA, $fileB) use ($subPath) {
                return
                    $this->disk->lastModified( $subPath . '/' . $fileA )
                    <
                    $this->disk->lastModified( $subPath . '/' . $fileB );
            });
        }

        $printer = $this->user->getActivePrinter('activeFile');

        $files = Arr::map($files, function ($path) {
            return [
                'name'   => $path,
                'prints' => File::where('path', $path)->first()?->prints ?? 0
            ];
        });

        return [
            'directories'    => $directories,
            'files'          => array_values($files),
            'activeFilePath' => $printer ? $printer->activeFile : null
        ];
    }

}
