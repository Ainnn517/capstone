<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\IssueReport;
use App\Models\Location;
use App\Models\User;
use Tests\TestCase;

// Browsers send each line break in a form as \r\n but count it as one character in the
// text box, so the 500-character limit must count it the same way.
class ReportDescriptionLengthTest extends TestCase
{
    private User $resident;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resident = User::factory()->create();
        Category::create(['name' => 'Road Damage', 'severity_weight' => 3]);
        $this->location = Location::create(['name' => 'Main Street', 'area_name' => 'Barangay One']);
    }

    private function submit(string $description)
    {
        return $this->actingAs($this->resident)->post('/api/reports', [
            'category' => 'Road Damage',
            'location_id' => $this->location->location_id,
            'description' => $description,
        ], ['Accept' => 'application/json']);
    }

    public function test_line_breaks_count_as_one_character(): void
    {
        // 493 visible characters + 7 line breaks = 500 as the text box counts them,
        // but 507 if each \r\n were counted as two.
        $lines = array_fill(0, 8, str_repeat('a', 61));
        $lines[7] = str_repeat('a', 493 - 61 * 7);
        $description = implode("\r\n", $lines);
        $this->assertSame(507, strlen($description));

        $this->submit($description)->assertCreated();

        $stored = IssueReport::firstOrFail()->description;
        $this->assertSame(500, mb_strlen($stored));
        $this->assertStringNotContainsString("\r", $stored);
    }

    public function test_more_than_500_characters_is_still_refused(): void
    {
        $this->submit(str_repeat('a', 501))->assertUnprocessable();
    }
}
