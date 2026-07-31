<?php

namespace Tests\Feature;

use App\Models\Certificate;
use App\Models\Club;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CertificateTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): array
    {
        $club = Club::factory()->create();
        $admin = User::factory()->create(['role' => 'club_admin']);
        $admin->adminClubs()->attach($club->id);
        return [$admin, $club];
    }

    public function test_index_lists_certificates(): void
    {
        [$admin, $club] = $this->admin();

        $this->actingAs($admin)
            ->get(route('club.certificates.index'))
            ->assertOk();
    }

    public function test_store_named_certificate_generates_unique_number(): void
    {
        [$admin, $club] = $this->admin();

        $this->actingAs($admin)
            ->post(route('club.certificates.store'), [
                'type' => 'named',
                'recipient_name' => 'Иванов Иван',
                'title' => 'За участие',
            ])
            ->assertRedirect(route('club.certificates.index'));

        $cert = Certificate::where('club_id', $club->id)->first();
        $this->assertNotNull($cert);
        $this->assertSame('named', $cert->type);
        $this->assertSame('Иванов Иван', $cert->recipient_name);
        $this->assertNotEmpty($cert->number);
        $this->assertStringStartsWith('CERT-' . $club->id . '-', $cert->number);
    }

    public function test_named_requires_recipient_name(): void
    {
        [$admin, $club] = $this->admin();

        $this->actingAs($admin)
            ->post(route('club.certificates.store'), ['type' => 'named'])
            ->assertSessionHasErrors('recipient_name');

        $this->assertSame(0, Certificate::count());
    }

    public function test_store_generic_certificate_has_no_name(): void
    {
        [$admin, $club] = $this->admin();

        $this->actingAs($admin)
            ->post(route('club.certificates.store'), ['type' => 'generic'])
            ->assertRedirect();

        $cert = Certificate::first();
        $this->assertSame('generic', $cert->type);
        $this->assertNull($cert->recipient_name);
    }

    public function test_numbers_are_unique_across_many(): void
    {
        [$admin, $club] = $this->admin();

        for ($i = 0; $i < 20; $i++) {
            $this->actingAs($admin)->post(route('club.certificates.store'), ['type' => 'generic']);
        }

        $this->assertSame(20, Certificate::count());
        $this->assertSame(20, Certificate::distinct('number')->count('number'));
    }

    public function test_cannot_view_other_clubs_certificate(): void
    {
        [$admin, $club] = $this->admin();
        $otherClub = Club::factory()->create();
        $foreign = Certificate::create([
            'club_id' => $otherClub->id,
            'type' => 'generic',
            'number' => Certificate::generateNumber($otherClub->id),
        ]);

        $this->actingAs($admin)
            ->get(route('club.certificates.show', $foreign))
            ->assertForbidden();
    }
}
