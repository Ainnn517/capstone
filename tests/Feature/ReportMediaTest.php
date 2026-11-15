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
            Storage::disk('local')->assertExists($file->file_path);
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
