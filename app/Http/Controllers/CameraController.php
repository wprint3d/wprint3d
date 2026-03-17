<?php

namespace App\Http\Controllers;

use App\Models\Camera;
use App\Support\CameraRuntimeMetadata;

use App\Rules\IsValidObjectID;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;

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

    /**
     * Patch camera encoding metadata, rewrite the nginx cameras.conf entry for
     * this camera, and reload nginx.  This repairs stale DB records created by
     * older images that did not persist captureEncoding / streamsMjpeg, fixing
     * the resulting 502 without requiring a container restart.
     */
    public function refreshStream(string $id, Request $request): Camera {
        validator(
            data:   [ 'id' => $id ],
            rules:  [ 'id' => [ 'required', new IsValidObjectID ] ]
        )->validate();

        $request->validate([
            'captureEncoding' => 'required|string|in:MJPG,YUYV',
        ]);

        $camera = Camera::find($id);

        if (! $camera) {
            throw ValidationException::withMessages([ 'id' => 'No such camera' ]);
        }

        $captureEncoding = strtoupper($request->get('captureEncoding'));

        $camera->captureEncoding = $captureEncoding;
        $camera->streamsMjpeg    = CameraRuntimeMetadata::supportsSoftwareMjpeg($captureEncoding);
        $camera->supportsMjpeg   = $captureEncoding === 'MJPG';
        $camera->save();

        $this->rewriteCameraConfEntry($camera);

        return $camera;
    }

    private function rewriteCameraConfEntry(Camera $camera): void
    {
        $confPath = '/var/www/proxy/internal/cameras.conf';

        if (! file_exists($confPath)) {
            return;
        }

        $uuid        = machineUUID();
        $prefix      = $camera->requiresLibCamera ? 'csi' : 'uvc';
        $locationKey = "/video/{$uuid}/{$prefix}/{$camera->index}";

        $meta = CameraRuntimeMetadata::normalize($camera->toArray());

        // Only yv-streamer-software cameras need a rewrite here; hardware MJPEG
        // and libcamera routes are managed by the streamer container.
        if (! $meta['streamsMjpeg'] || $camera->requiresLibCamera) {
            return;
        }

        [$resolution, $framerate] = array_pad(explode('@', (string) ($camera->format ?? '')), 2, '');
        $yvPort = env('YV_STREAMER_SOFTWARE_PORT', 8080);

        $newBlock =
            "\nlocation {$locationKey} {" .
            "\n\tproxy_pass            http://yv-streamer-software:{$yvPort}/{$camera->_id}/;" .
            "\n\tproxy_set_header Host \$host;" .
            "\n\tproxy_set_header X-Node {$camera->node};" .
            "\n\tproxy_set_header X-Resolution {$resolution};" .
            "\n\tproxy_set_header X-Framerate {$framerate};" .
            "\n\tproxy_set_header X-Capture-Encoding {$camera->captureEncoding};" .
            "\n\tproxy_buffering      off;" .
            "\n\tproxy_ignore_headers X-Accel-Buffering;" .
            "\n\tinclude               nginxconfig.io/proxy.conf;" .
            "\n}";

        $current = file_get_contents($confPath);
        $escaped = preg_quote($locationKey, '/');
        $updated = preg_replace('/\nlocation\s+' . $escaped . '\s*\{[^}]*\}/', $newBlock, $current);

        if ($updated === $current) {
            $updated = $current . $newBlock . "\n";
        }

        file_put_contents($confPath, $updated);

        // Reload nginx in every proxy container.
        $list = new Process(['docker', 'ps', '--filter', 'name=proxy', '--format', '{{ .ID }}']);
        $list->run();

        foreach (array_filter(explode("\n", trim($list->getOutput()))) as $cid) {
            (new Process(['docker', 'exec', '-t', $cid, 'nginx', '-s', 'reload']))->run();
        }
    }

}
