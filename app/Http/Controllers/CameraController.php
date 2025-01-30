<?php

namespace App\Http\Controllers;

use App\Models\Camera;

use App\Rules\IsValidObjectID;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CameraController extends Controller
{

    public function index(): Collection { return Camera::get(); }

    public function get(string $id): Camera {
        validator(
            data:   [ 'id' => $id ],
            rules:  [ 'id' => [ 'required', new IsValidObjectID ] ]
        )->validate();

        return Camera::find($id);
    }

    public function delete(string $id): void {
        validator(
            data:   [ 'id' => $id ],
            rules:  [ 'id' => [ 'required', new IsValidObjectID ] ]
        )->validate();

        $camera = Camera::find($id);

        if ($camera->connected) {
            throw ValidationException::withMessages([ 'id' => 'Cannot delete a connected camera' ]);
        }

        $camera->delete();
    }

    public function update(string $id, Request $request): Camera {
        validator(
            data:   [ 'id' => $id ],
            rules:  [ 'id' => [ 'required', new IsValidObjectID ] ]
        )->validate();

        $request->validate([ 'format' => 'sometimes|string' ]);

        $camera = Camera::find($id);

        if (!$camera) {
            throw ValidationException::withMessages([ 'id' => 'No such camera' ]);
        }

        $nextFormat = $request->get('format');

        if (!in_array($nextFormat, $camera->availableFormats)) {
            throw ValidationException::withMessages([ 'format' => 'The camera doesn\'t support this format' ]);
        }

        $camera->format = $nextFormat;
        $camera->save();

        return $camera;
    }

    public function enable(string $id): Camera {
        validator(
            data:   [ 'id' => $id ],
            rules:  [ 'id' => [ 'required', new IsValidObjectID ] ]
        )->validate();

        $camera = Camera::find($id);

        $camera->enabled = true;
        $camera->save();

        return $camera;
    }

    public function disable(string $id): Camera {
        validator(
            data:   [ 'id' => $id ],
            rules:  [ 'id' => [ 'required', new IsValidObjectID ] ]
        )->validate();

        $camera = Camera::find($id);

        $camera->enabled = false;
        $camera->save();

        return $camera;
    }

}
