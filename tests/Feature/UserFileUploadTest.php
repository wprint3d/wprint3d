<?php

namespace Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class UserFileUploadTest extends TestCase
{
    public function test_gcode_drag_upload_preserves_the_filename_extension(): void
    {
        Storage::fake('gcode');

        $response = $this->withoutMiddleware()->post('/api/user/file/upload', [
            'subDirectory' => '/jobs',
            'files' => [UploadedFile::fake()->createWithContent('calibration cube.gcode', "G90\nG1 X10 Y10\n")],
        ]);

        $response->assertOk()->assertJson(['calibration cube.gcode']);
        Storage::disk('gcode')->assertExists('jobs/calibration cube.gcode');
    }

    public function test_file_upload_rejects_non_gcode_files(): void
    {
        Storage::fake('gcode');

        $this->withoutMiddleware()
            ->withHeader('Accept', 'application/json')
            ->post('/api/user/file/upload', [
                'files' => [UploadedFile::fake()->createWithContent('model.stl', 'solid model')],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('files');

        Storage::disk('gcode')->assertMissing('model.stl');
    }

    public function test_gzip_gcode_upload_is_expanded_before_it_enters_the_print_queue(): void
    {
        Storage::fake('gcode');
        $gcode = "G90\nG1 X10 Y10\nM104 S0\n";

        $response = $this->withoutMiddleware()->post('/api/user/file/upload', [
            'subDirectory' => '/jobs',
            'files' => [UploadedFile::fake()->createWithContent('compressed.gcode.gz', gzencode($gcode))],
        ]);

        $response->assertOk()->assertJson(['compressed.gcode']);
        Storage::disk('gcode')->assertExists('jobs/compressed.gcode');
        $this->assertSame($gcode, Storage::disk('gcode')->get('jobs/compressed.gcode'));
        Storage::disk('gcode')->assertMissing('jobs/compressed.gcode.gz');
    }

    public function test_gzip_gcode_upload_enforces_the_expanded_size_limit(): void
    {
        Storage::fake('gcode');
        config()->set('filesystems.gcode_upload.max_expanded_bytes', 8);

        $this->withoutMiddleware()
            ->withHeader('Accept', 'application/json')
            ->post('/api/user/file/upload', [
                'files' => [UploadedFile::fake()->createWithContent('oversized.gcode.gz', gzencode("G90\nG1 X10 Y10\n"))],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('files');

        Storage::disk('gcode')->assertMissing('oversized.gcode');
    }
}
