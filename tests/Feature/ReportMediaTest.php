<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Category;
use App\Models\IssueReport;
use App\Models\Location;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ReportMediaTest extends TestCase
{
    private User $resident;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->resident = User::factory()->create();
        Category::create(['name' => 'Road Damage', 'severity_weight' => 3]);
        $this->location = Location::create(['name' => 'Main Street', 'area_name' => 'Barangay One']);
    }

    public function test_resident_can_attach_several_photos_and_one_video(): void
    {
        $response = $this->submit([
            'images' => [
                $this->jpg('front.jpg'),
                $this->png('side.png'),
                $this->jpg('close.jpg'),
            ],
            'video' => $this->mp4(),
        ])->assertCreated();

        $report = IssueReport::find($response->json('id'));
        $files = $report->attachments;
        $this->assertCount(4, $files);
        $this->assertSame(['image/jpeg', 'image/png', 'image/jpeg', 'video/mp4'], $files->pluck('mime_type')->all());
        foreach ($files as $file) {
            $this->assertMatchesRegularExpression(Attachment::STORED_PATH, $file->file_path);
            $this->localDisk()->assertExists($file->file_path);
        }

        $listed = $this->actingAs($this->resident)->getJson('/api/reports')->assertOk()->json('reports.0');
        $this->assertSame(['image', 'image', 'image', 'video'], array_column($listed['attachments'], 'type'));
        $this->assertSame((string) $files->first()->attachment_id, $listed['attachmentId']);

        // The video streams with byte ranges, which phones need to play it.
        $video = $files->last();
        $this->actingAs($this->resident)->get("/api/attachments/{$video->attachment_id}")
            ->assertOk()
            ->assertHeader('Content-Type', 'video/mp4')
            ->assertHeader('Accept-Ranges', 'bytes');
        $this->actingAs($this->resident)->get("/api/attachments/{$video->attachment_id}", ['Range' => 'bytes=0-9'])
            ->assertStatus(206);

        // Another resident cannot open them.
        $this->actingAs(User::factory()->create())->get("/api/attachments/{$video->attachment_id}")->assertNotFound();
    }

    public function test_the_older_single_photo_field_still_works(): void
    {
        $this->submit(['image' => $this->jpg('one.jpg')])->assertCreated();

        $this->assertSame(1, Attachment::count());
    }

    public function test_limits_are_enforced(): void
    {
        $sixPhotos = array_map(fn ($i) => $this->jpg("p{$i}.jpg"), range(1, 6));
        $this->submit(['images' => $sixPhotos])->assertStatus(422)->assertJsonPath('message', 'Attach at most 5 photos.');

        $this->submit(['images' => [UploadedFile::fake()->create('big.jpg', 10241, 'image/jpeg')]])
            ->assertStatus(422)->assertJsonPath('message', 'Each photo must be 10 MB or less.');

        $this->submit(['video' => UploadedFile::fake()->create('big.mp4', 10241, 'video/mp4')])
            ->assertStatus(422)->assertJsonPath('message', 'The video must be 10 MB or less.');

        $this->submit([
            'images' => [UploadedFile::fake()->create('a.jpg', 9000, 'image/jpeg'), UploadedFile::fake()->create('b.jpg', 9000, 'image/jpeg')],
            'video' => UploadedFile::fake()->create('c.mp4', 9000, 'video/mp4'),
        ])->assertStatus(422)->assertJsonPath('message', 'All photos and the video together must be 25 MB or less.');

        $this->assertSame(0, IssueReport::count());
    }

    public function test_a_file_pretending_to_be_a_video_is_refused(): void
    {
        $fake = UploadedFile::fake()->createWithContent('clip.mp4', '<?php echo "not a video";');
        $this->submit(['video' => $fake])->assertStatus(422)->assertJsonPath('message', 'Upload the video as MP4, WebM, or MOV.');

        $this->submit(['images' => [UploadedFile::fake()->createWithContent('photo.jpg', 'plain text')]])
            ->assertStatus(422);
        $this->assertSame(0, Attachment::count());
    }

    public function test_video_metadata_is_removed_when_ffmpeg_is_available(): void
    {
        // A stand-in for ffmpeg that records its arguments and writes a "cleaned" file.
        $dir = sys_get_temp_dir().'/ffmpeg-test-'.bin2hex(random_bytes(4));
        mkdir($dir);
        $fake = $dir.'/ffmpeg';
        file_put_contents($fake, "#!/bin/sh\necho \"\$@\" > \"{$dir}/args\"\nfor last; do :; done\nprintf 'CLEANED-VIDEO' > \"\$last\"\n");
        chmod($fake, 0700);
        config(['security.uploads.video_ffmpeg' => $fake]);

        try {
            $this->submit(['video' => $this->mp4()])->assertCreated();

            $video = Attachment::query()->where('mime_type', 'video/mp4')->latest('attachment_id')->firstOrFail();
            $this->assertSame('CLEANED-VIDEO', Storage::disk('local')->get($video->file_path));
            $args = file_get_contents($dir.'/args');
            $this->assertStringContainsString('-map_metadata -1', $args);
            $this->assertStringContainsString('-c copy', $args);

            // A file ffmpeg cannot read is refused rather than stored with its metadata.
            file_put_contents($fake, "#!/bin/sh\nexit 1\n");
            $this->submit(['video' => $this->mp4()])
                ->assertStatus(422)
                ->assertJsonPath('message', 'The video could not be processed. Try a different video, or attach photos instead.');
        } finally {
            array_map('unlink', glob($dir.'/*'));
            rmdir($dir);
        }
    }

    public function test_mov_videos_are_stored_as_mp4_so_every_browser_can_play_them(): void
    {
        $mov = $this->realMov();
        config(['security.uploads.video_ffmpeg' => 'ffmpeg']);

        $this->submit(['video' => UploadedFile::fake()->createWithContent('clip.mov', $mov)])->assertCreated();

        $video = Attachment::query()->latest('attachment_id')->firstOrFail();
        $this->assertSame('video/mp4', $video->mime_type);
        $this->assertStringEndsWith('.mp4', $video->file_path);
        $this->assertSame('video/mp4', (new \finfo(FILEINFO_MIME_TYPE))->buffer(Storage::disk('local')->get($video->file_path)));
        $this->actingAs($this->resident)->get("/api/attachments/{$video->attachment_id}")
            ->assertOk()->assertHeader('Content-Type', 'video/mp4');
    }

    public function test_stored_mov_videos_can_be_converted_to_mp4(): void
    {
        $mov = $this->realMov();
        config(['security.uploads.video_ffmpeg' => 'ffmpeg']);
        $report = IssueReport::create([
            'tracking_code' => 'BR-TEST-MOV',
            'user_id' => $this->resident->user_id,
            'category_id' => Category::where('name', 'Road Damage')->value('category_id'),
            'description' => 'A large pothole near the corner.',
            'location_id' => $this->location->location_id,
            'status' => 'Submitted',
        ]);
        $oldPath = 'report-attachments/'.str_repeat('e', 64).'.mov';
        Storage::disk('local')->put($oldPath, $mov);
        $video = Attachment::create(['report_id' => $report->report_id, 'mime_type' => 'video/quicktime', 'file_path' => $oldPath]);

        $this->artisan('attachments:convert-mov', ['--dry-run' => true])->expectsOutput('MOV videos found: 1')->assertSuccessful();
        $this->localDisk()->assertExists($oldPath);

        $this->artisan('attachments:convert-mov')->expectsOutput('Converted 1 video(s) to MP4.')->assertSuccessful();

        $video->refresh();
        $this->assertSame('video/mp4', $video->mime_type);
        $this->assertStringEndsWith('.mp4', $video->file_path);
        $this->localDisk()->assertExists($video->file_path);
        $this->localDisk()->assertMissing($oldPath);
    }

    // A real one-second MOV clip made with ffmpeg; tests that need it are skipped without ffmpeg.
    private function realMov(): string
    {
        $file = tempnam(sys_get_temp_dir(), 'mov').'.mov';
        exec('ffmpeg -v error -y -f lavfi -i testsrc=size=64x48:rate=10 -t 1 -c:v mpeg4 '.escapeshellarg($file).' 2>&1', $output, $code);
        if ($code !== 0 || ! is_file($file)) {
            $this->markTestSkipped('ffmpeg is not installed.');
        }
        $bytes = file_get_contents($file);
        @unlink($file);

        return $bytes;
    }

    private function submit(array $files)
    {
        return $this->actingAs($this->resident)->post('/api/reports', [
            'category' => 'Road Damage',
            'location_id' => $this->location->location_id,
            'description' => 'A large pothole near the corner.',
            ...$files,
        ], ['Accept' => 'application/json']);
    }

    // Tiny real images (1 x 1 pixel), so the tests do not need the GD extension.
    private function jpg(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, base64_decode(
            '/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA='
        ));
    }

    private function png(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='
        ));
    }

    private function mp4(): UploadedFile
    {
        $bytes = "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom".str_repeat("\x00", 2048);

        return UploadedFile::fake()->createWithContent('clip.mp4', $bytes);
    }
}
