<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Club;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ClubPaymentDocsTest extends TestCase
{
    use RefreshDatabase;

    public function test_online_payment_toggle_saved(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);
        $club = Club::create(['name' => 'C', 'address' => 'A']);

        $this->actingAs($admin)
            ->put(route('admin.clubs.update', $club), [
                'name' => 'C',
                'address' => 'A',
                'online_payment_enabled' => 1,
            ])
            ->assertRedirect();
        $this->assertTrue((bool) $club->fresh()->online_payment_enabled);

        // Снятый чекбокс не приходит в запросе → должно стать false
        $this->actingAs($admin)
            ->put(route('admin.clubs.update', $club), [
                'name' => 'C',
                'address' => 'A',
            ])
            ->assertRedirect();
        $this->assertFalse((bool) $club->fresh()->online_payment_enabled);
    }

    public function test_invalid_doc_type_rejected(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);
        $club = Club::create(['name' => 'C', 'address' => 'A']);

        $bad = UploadedFile::fake()->create('malware.exe', 10, 'application/octet-stream');

        $this->actingAs($admin)
            ->put(route('admin.clubs.update', $club), [
                'name' => 'C',
                'address' => 'A',
                'offer_agreement' => $bad,
            ])
            ->assertSessionHasErrors('offer_agreement');

        $this->assertNull($club->fresh()->offer_agreement);
    }

    public function test_doc_upload_stores_path(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);
        $club = Club::create(['name' => 'C', 'address' => 'A']);

        $pdf = UploadedFile::fake()->create('offer.pdf', 100, 'application/pdf');

        $this->actingAs($admin)
            ->put(route('admin.clubs.update', $club), [
                'name' => 'C',
                'address' => 'A',
                'offer_agreement' => $pdf,
            ])
            ->assertRedirect();

        $path = $club->fresh()->offer_agreement;
        $this->assertNotNull($path);
        $this->assertStringContainsString('/club_docs/' . $club->id . '-offer_agreement.', $path);

        // Чистим созданный файл, чтобы не засорять public/
        $full = public_path(ltrim($path, '/'));
        if (is_file($full)) {
            @unlink($full);
        }
    }
}
