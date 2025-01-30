<?php

namespace App\Http\Controllers;

use App\Enums\DataType;
use App\Exceptions\InitializationException;
use App\Models\Configuration;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class ConfigurationController extends Controller
{

    public function getDataTypes(): array {
        return DataType::asArray();
    }

    public function index(): array {
        return Configuration::all()->mapWithKeys(function($config) {
            return [ $config->key => $config ];
        })->toArray();
    }

    public function get(string $key): mixed {
        return Configuration::get($key);
    }

    public function update(string $key, Request $request): mixed {
        $value = $request->input('value');

        if (Configuration::set($key, $value)) {
            return response('', Response::HTTP_NO_CONTENT);
        }

        return response('', Response::HTTP_INTERNAL_SERVER_ERROR);
    }

    public function getEnum(string $className): array {
        $classPath = $className;

        if (!Str::contains($className, '\\')) {
            $classPath = "App\\Enums\\$className";
        }

        if (!class_exists($classPath)) {
            throw new InitializationException("No such enum class: $className");
        }

        return $classPath::asSelectArray();
    }

    public function getEnumConstants(string $className): array {
        $classPath = $className;

        if (!Str::contains($className, '\\')) {
            $classPath = "App\\Enums\\$className";
        }

        if (!class_exists($classPath)) {
            throw new InitializationException("No such enum class: $className");
        }

        return $classPath::asArray();
    }

    public function listEnums(Request $request): array {
        $request->validate([ 'classes' => 'required|array' ]);

        return collect($request->input('classes'))->mapWithKeys(function($className) {
            return [ $className => $this->getEnum($className) ];
        })->toArray();
    }

    public function wsConfig(): array {
        return [
            'appKey' => env('PUSHER_APP_KEY'),
            'port'   => env('EXTERNAL_WEB_SOCKET_PORT')
        ];
    }

    public function recorderOptions(): array {
        return [
            'resolutions' => config('app.recorder_output_resolutions'),
            'framerates'  => config('app.recorder_output_framerates')
        ];
    }

}
