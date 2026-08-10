<?php

namespace App\Http\Controllers;

use App\Models\Camera;
use App\Models\File;
use App\Models\Material;
use App\Models\Printer;
use App\Models\User;
use App\Rules\IsValidObjectID;
use App\Services\PrintExecutionState;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotificationCollection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use MongoDB\BSON\ObjectId;
use stdClass;

class UserController extends Controller
{
    private ?User $user;

    public function __construct()
    {
        $this->middleware(function (Request $request, $next) {
            $this->user = $request->user();

            return $next($request);
        });
    }

    public function get(): User
    {
        return $this->user;
    }

    public function getSettings(): array
    {
        return data_get($this->user, 'settings', new stdClass);
    }

    public function updateSettings(Request $request): User
    {
        $request->validate([
            'settings' => 'required|array',
            'settings.recording' => 'required|array',
            'settings.recording.enabled' => 'required|boolean',
            'settings.recording.resolution' => 'required|string',
            'settings.recording.framerate' => 'required|integer',
            'settings.recording.captureInterval' => 'required|numeric',
        ]);

        $this->user->settings = $request->get('settings');
        $this->user->save();

        return $this->user;
    }

    public function materials(): Collection
    {
        return $this->user->materials()->get();
    }

    public function addMaterial(Request $request): Material
    {
        $request->validate([
            'name' => 'required|string',
            'temperatures' => 'required|array',
            'temperatures.hotend' => 'required|integer',
            'temperatures.bed' => 'required|integer',
        ], [
            'temperatures.hotend.required' => __('server.validation.hotend_required'),
            'temperatures.hotend.integer' => __('server.validation.hotend_integer'),
            'temperatures.bed.required' => __('server.validation.bed_required'),
            'temperatures.bed.integer' => __('server.validation.bed_integer'),
        ]);

        if ($this->user->materials()->where('name', $request->get('name'))->exists()) {
            throw ValidationException::withMessages(['name' => __('server.materials.duplicate_name')]);
        }

        return $this->user->materials()->create([
            'name' => $request->get('name'),
            'temperatures' => [
                'hotend' => $request->get('temperatures')['hotend'],
                'bed' => $request->get('temperatures')['bed'],
            ],
        ]);
    }

    public function updateMaterial(string $id, Request $request): Material
    {
        validator(
            data: ['id' => $id],
            rules: ['id' => ['required', new IsValidObjectID]]
        )->validate();

        $request->validate([
            'name' => 'required|string',
            'temperatures' => 'required|array',
            'temperatures.hotend' => 'required|integer',
            'temperatures.bed' => 'required|integer',
        ], [
            'temperatures.hotend.required' => __('server.validation.hotend_required'),
            'temperatures.hotend.integer' => __('server.validation.hotend_integer'),
            'temperatures.bed.required' => __('server.validation.bed_required'),
            'temperatures.bed.integer' => __('server.validation.bed_integer'),
        ]);

        $material = $this->user->materials()->find($id);

        if (! $material) {
            throw ValidationException::withMessages(['id' => __('server.materials.not_found')]);
        }

        $material->name = $request->get('name');
        $material->temperatures = [
            'hotend' => $request->get('temperatures')['hotend'],
            'bed' => $request->get('temperatures')['bed'],
        ];

        $material->save();

        return $material;
    }

    public function deleteMaterial(string $id): void
    {
        validator(
            data: ['id' => $id],
            rules: ['id' => ['required', new IsValidObjectID]]
        )->validate();

        $material = $this->user->materials()->find($id);

        if (! $material) {
            throw ValidationException::withMessages(['id' => __('server.materials.not_found')]);
        }

        $material->delete();
    }

    public function getActivePrinterId(): ?string
    {
        $printer = $this->user->getActivePrinter('_id');

        if ($printer) {
            return (string) $printer->_id;
        }

        return $this->user->getActivePrinterId();
    }

    public function setActivePrinterId(Request $request): mixed
    {
        $request->validate([
            'id' => ['required', new IsValidObjectID],
        ]);

        $printer = Printer::find($request->get('id'));

        if (! $printer) {
            throw ValidationException::withMessages(['id' => __('server.printers.not_found')]);
        }

        return [
            'saved' => $this->user->setActivePrinterId($printer->_id),
        ];
    }

    public function getActivePrinterStatus(): ?array
    {
        $printer = $this->user->getActivePrinter('_id', 'activeFile', 'hasActiveJob');

        if (! $printer) {
            return null;
        }

        $result = [
            'statistics' => $printer->getStatistics(),
            'lastSeen' => $printer->getLastSeen(),
            'isPaused' => ! $printer->isRunning(),
            'isReconnecting' => app(PrintExecutionState::class)
                ->isReconnecting((string) $printer->_id),
            'thresholdSecs' => env('PRINTER_LAST_SEEN_ONLINE_THRESHOLD_SECS'),
            'connectionStatus' => $printer->getConnectionStatus(),
            'connectionDiagnostic' => $printer->getConnectionDiagnostic(),
        ];

        if ($printer->hasActivePrintJob()) {
            $result['isPrinting'] = true;
            $result['layer'] = $printer->getCurrentLayer();
        }

        return $result;
    }

    public function getActivePrinterConsole(): ?string
    {
        $printer = $this->user->getActivePrinter('_id');

        if (! $printer) {
            return null;
        }

        return $printer->getConsole();
    }

    public function getActivePrinterCameras(): Collection
    {
        $printer = $this->user->getActivePrinter();

        if (! $printer) {
            return collect();
        }

        return Camera::where('enabled', true)->whereRaw([
            '_id' => [
                '$in' => Arr::map($printer->cameras, function ($cameraId) {
                    return new ObjectId($cameraId);
                }),
            ],
        ])->get();
    }

    public function deleteFile(Request $request): void
    {
        $request->validate(['fileName' => 'required|string']);

        $path = $request->get('fileName');
        $subDirectory = $request->get('subDirectory');

        if ($subDirectory) {
            $path = "{$subDirectory}/{$path}";
        }

        if (Printer::where('activeFile', $path)->exists()) {
            throw ValidationException::withMessages(['fileName' => __('server.files.in_use')]);
        }

        Storage::disk('gcode')->delete($path);

        $file = File::where('path', $path)->first();

        if ($file) {
            $file->delete();
        }
    }

    public function renameFile(Request $request)
    {
        $request->validate([
            'oldName' => 'required|string',
            'newName' => 'required|string',
        ]);

        $oldName = $request->get('oldName');
        $newName = $request->get('newName');

        if ($oldName === $newName) {
            return;
        }

        $subDirectory = $request->get('subDirectory');

        if ($subDirectory) {
            $oldName = "{$subDirectory}/{$oldName}";
            $newName = "{$subDirectory}/{$newName}";
        }

        $disk = Storage::disk('gcode');

        if ($disk->exists($newName)) {
            throw ValidationException::withMessages(['newName' => __('server.files.already_exists')]);
        }

        $didMove = $disk->move($oldName, $newName);

        if (! $didMove) {
            throw ValidationException::withMessages(['oldName' => __('server.files.rename_failed')]);
        }

        $file = File::where('fileName', $oldName)->first();

        if ($file) {
            $file->path = $newName;
            $file->save();
        }
    }

    public function uploadFile(Request $request)
    {
        $request->validate([
            'files' => 'required|array',
            'files.*' => 'required|file',
        ]);

        $disk = Storage::disk('gcode');

        $subDirectory = $request->get('subDirectory');

        $uploadedFiles = [];

        foreach ($request->file('files') as $file) {
            $baseName = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);

            $storedFileName = "{$subDirectory}/{$baseName}";

            if ($disk->exists($storedFileName)) {
                throw ValidationException::withMessages(['files' => __('server.files.upload_already_exists')]);
            }

            $disk->put($storedFileName, $file->get());

            $uploadedFiles[] = $baseName;
        }

        return $uploadedFiles;
    }

    public function createDirectory(Request $request)
    {
        $request->validate([
            'name' => 'required|string',
            'subDirectory' => 'nullable|string',
        ]);

        $subDirectory = $request->get('subDirectory');

        $name = $request->get('name');

        if ($subDirectory) {
            $name = "{$subDirectory}/{$name}";
        }

        $disk = Storage::disk('gcode');

        if ($disk->exists($name)) {
            throw ValidationException::withMessages(['name' => __('server.directories.already_exists')]);
        }

        $disk->makeDirectory($name);
    }

    public function deleteDirectory(Request $request)
    {
        $request->validate([
            'name' => 'required|string',
            'subDirectory' => 'nullable|string',
        ]);

        $subDirectory = $request->get('subDirectory');

        $name = $request->get('name');

        if ($subDirectory) {
            $name = "{$subDirectory}/{$name}";
        }

        $disk = Storage::disk('gcode');

        if (! $disk->exists($name)) {
            throw ValidationException::withMessages(['name' => __('server.directories.not_found')]);
        }

        if (count($disk->files($name))) {
            throw ValidationException::withMessages(['name' => __('server.directories.not_empty')]);
        }

        $disk->deleteDirectory($name);
    }

    public function logout(Request $request)
    {
        $request->session()->invalidate();
    }

    public function updatePassword(Request $request)
    {
        $request->validate([
            'currentPassword' => 'required|string',
            'newPassword' => 'required|string|min:8',
            'repeatPassword' => 'required|string|same:newPassword',
            'logoutOtherDevices' => 'required|boolean',
        ]);

        $currentPassword = $request->get('currentPassword');
        $newPassword = $request->get('newPassword');

        if (! Hash::check($currentPassword, $this->user->password)) {
            throw ValidationException::withMessages(['currentPassword' => __('server.password.current_password_mismatch')]);
        }

        if ($currentPassword === $newPassword) {
            throw ValidationException::withMessages(['newPassword' => __('server.password.must_be_different')]);
        }

        if ($request->get('logoutOtherDevices')) {
            Auth::logoutOtherDevices($currentPassword);
        }

        $this->user->password = Hash::make($newPassword);
        $this->user->firstLogin = false;
        $this->user->save();

        Auth::login($this->user);
    }

    public function getNotifications(): DatabaseNotificationCollection
    {
        return $this->user->notifications;
    }

    public function markManyNotificationsAsRead(Request $request)
    {
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'required|string',
        ]);

        $ids = $request->get('ids');

        $this->user->notifications()->byIds($ids)->each(function ($notification) {
            $notification->markAsRead();
        });
    }

    public function markNotificationAsRead(string $id)
    {
        $notification = $this->user->notifications()->byId($id);

        if (! $notification) {
            throw ValidationException::withMessages(['id' => __('server.notifications.not_found')]);
        }

        $notification->markAsRead();
    }

    public function deleteNotification(string $id)
    {
        $notification = $this->user->notifications()->byId($id);

        if (! $notification) {
            throw ValidationException::withMessages(['id' => __('server.notifications.not_found')]);
        }

        $notification->delete();
    }
}
