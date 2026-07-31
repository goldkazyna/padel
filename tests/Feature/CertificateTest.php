<?php

namespace Tests\Feature;

use App\Models\Certificate;
use App\Models\CertificateTemplate;
use App\Models\Club;
use App\Models\ClubClient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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
                'value_type' => 'amount',
                'amount' => 5000,
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
            ->post(route('club.certificates.store'), ['type' => 'generic', 'value_type' => 'amount', 'amount' => 3000])
            ->assertRedirect();

        $cert = Certificate::first();
        $this->assertSame('generic', $cert->type);
        $this->assertNull($cert->recipient_name);
    }

    public function test_numbers_are_unique_across_many(): void
    {
        [$admin, $club] = $this->admin();

        for ($i = 0; $i < 20; $i++) {
            $this->actingAs($admin)->post(route('club.certificates.store'), ['type' => 'generic', 'value_type' => 'amount', 'amount' => 1000]);
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

    public function test_named_links_selected_client(): void
    {
        [$admin, $club] = $this->admin();
        $client = ClubClient::create(['club_id' => $club->id, 'name' => 'Иван Клиент', 'phone' => '77010000000']);

        $this->actingAs($admin)->post(route('club.certificates.store'), [
            'type' => 'named',
            'recipient_name' => 'Иван Клиент',
            'client_id' => $client->id,
            'value_type' => 'amount',
            'amount' => 5000,
        ])->assertRedirect();

        $cert = Certificate::first();
        $this->assertSame($client->id, $cert->client_id);
        $this->assertSame('Иван Клиент', $cert->recipient_name);
    }

    public function test_foreign_client_id_is_ignored(): void
    {
        [$admin, $club] = $this->admin();
        $otherClub = Club::factory()->create();
        $foreignClient = ClubClient::create(['club_id' => $otherClub->id, 'name' => 'Чужой', 'phone' => '77020000000']);

        $this->actingAs($admin)->post(route('club.certificates.store'), [
            'type' => 'named',
            'recipient_name' => 'Чужой',
            'client_id' => $foreignClient->id,
            'value_type' => 'amount',
            'amount' => 5000,
        ])->assertRedirect();

        $cert = Certificate::first();
        $this->assertNull($cert->client_id);          // чужой клиент не привязан
        $this->assertSame('Чужой', $cert->recipient_name); // но ФИО осталось
    }

    public function test_client_search_endpoint_finds_by_name(): void
    {
        [$admin, $club] = $this->admin();
        ClubClient::create(['club_id' => $club->id, 'name' => 'Пётр Поиск', 'phone' => '77015550000']);

        $this->actingAs($admin)
            ->getJson(route('club.clients.search', ['q' => 'Пётр', 'field' => 'name']))
            ->assertOk()
            ->assertJsonFragment(['name' => 'Пётр Поиск']);
    }

    public function test_amount_certificate_stores_amount_only(): void
    {
        [$admin, $club] = $this->admin();

        $this->actingAs($admin)->post(route('club.certificates.store'), [
            'type' => 'generic', 'value_type' => 'amount', 'amount' => 15000,
        ])->assertRedirect();

        $cert = Certificate::first();
        $this->assertSame('amount', $cert->value_type);
        $this->assertSame(15000, $cert->amount);
        $this->assertNull($cert->hours);
        $this->assertSame('15 000 ₸', $cert->valueLabel());
    }

    public function test_hours_certificate_stores_hours_only(): void
    {
        [$admin, $club] = $this->admin();

        $this->actingAs($admin)->post(route('club.certificates.store'), [
            'type' => 'generic', 'value_type' => 'hours', 'hours' => 2,
        ])->assertRedirect();

        $cert = Certificate::first();
        $this->assertSame('hours', $cert->value_type);
        $this->assertSame(2, $cert->hours);
        $this->assertNull($cert->amount);
        $this->assertSame('2 часа', $cert->valueLabel());
    }

    public function test_tournament_certificate_stores_tournaments_only(): void
    {
        [$admin, $club] = $this->admin();

        $this->actingAs($admin)->post(route('club.certificates.store'), [
            'type' => 'generic', 'value_type' => 'tournament', 'tournaments' => 1,
        ])->assertRedirect();

        $cert = Certificate::first();
        $this->assertSame('tournament', $cert->value_type);
        $this->assertSame(1, $cert->tournaments);
        $this->assertNull($cert->amount);
        $this->assertNull($cert->hours);
        $this->assertSame('1 турнир', $cert->valueLabel());
    }

    public function test_tournament_required_when_value_type_tournament(): void
    {
        [$admin, $club] = $this->admin();

        $this->actingAs($admin)->post(route('club.certificates.store'), [
            'type' => 'generic', 'value_type' => 'tournament',
        ])->assertSessionHasErrors('tournaments');
    }

    public function test_amount_required_when_value_type_amount(): void
    {
        [$admin, $club] = $this->admin();

        $this->actingAs($admin)->post(route('club.certificates.store'), [
            'type' => 'generic', 'value_type' => 'amount',
        ])->assertSessionHasErrors('amount');

        $this->assertSame(0, Certificate::count());
    }

    public function test_show_renders_template(): void
    {
        [$admin, $club] = $this->admin();
        $this->actingAs($admin)->post(route('club.certificates.store'), [
            'type' => 'named', 'recipient_name' => 'Пётр Петров',
            'value_type' => 'hours', 'hours' => 2,
        ]);
        $cert = Certificate::first();

        $this->actingAs($admin)
            ->get(route('club.certificates.show', $cert))
            ->assertOk()
            ->assertSee('Пётр Петров')
            ->assertSee($cert->number);
    }

    public function test_design_page_loads_and_creates_default_template(): void
    {
        [$admin, $club] = $this->admin();

        $this->actingAs($admin)->get(route('club.certificates.design'))->assertOk();

        $this->assertDatabaseHas('certificate_templates', [
            'club_id' => $club->id, 'is_default' => true,
        ]);
    }

    public function test_design_update_saves_colors_and_texts(): void
    {
        [$admin, $club] = $this->admin();

        $this->actingAs($admin)->post(route('club.certificates.design.update'), [
            'heading' => 'Диплом',
            'subtitle_named' => 'Вручается',
            'subtitle_generic' => 'Выдан',
            'body_text' => 'за победу',
            'background_color' => '#ffffff',
            'accent_color' => '#ff0000',
            'border_color' => '#00ff00',
            'text_color' => '#0000ff',
            'orientation' => 'portrait',
        ])->assertRedirect(route('club.certificates.design'));

        $tpl = CertificateTemplate::defaultForClub($club->id);
        $this->assertSame('Диплом', $tpl->heading);
        $this->assertSame('#ff0000', $tpl->accent_color);
        $this->assertSame('portrait', $tpl->orientation);
    }

    public function test_number_uses_custom_prefix_from_template(): void
    {
        [$admin, $club] = $this->admin();

        // Задаём префикс через конструктор.
        $this->actingAs($admin)->post(route('club.certificates.design.update'), [
            'number_prefix' => 'padelhills',
            'heading' => 'Сертификат', 'subtitle_named' => 'a', 'subtitle_generic' => 'b',
            'background_color' => '#ffffff', 'accent_color' => '#ff0000',
            'border_color' => '#00ff00', 'text_color' => '#0000ff', 'orientation' => 'landscape',
        ])->assertRedirect();

        // Создаём сертификат — номер с новым префиксом.
        $this->actingAs($admin)->post(route('club.certificates.store'), [
            'type' => 'generic', 'value_type' => 'amount', 'amount' => 5000,
        ])->assertRedirect();

        $cert = Certificate::first();
        $this->assertStringStartsWith('padelhills-' . $club->id . '-', $cert->number);
    }

    public function test_design_rejects_bad_prefix(): void
    {
        [$admin, $club] = $this->admin();

        $this->actingAs($admin)->post(route('club.certificates.design.update'), [
            'number_prefix' => 'bad prefix!', // пробел и «!» запрещены
            'heading' => 'X', 'subtitle_named' => 'a', 'subtitle_generic' => 'b',
            'background_color' => '#ffffff', 'accent_color' => '#ff0000',
            'border_color' => '#00ff00', 'text_color' => '#0000ff', 'orientation' => 'landscape',
        ])->assertSessionHasErrors('number_prefix');
    }

    public function test_design_rejects_bad_color(): void
    {
        [$admin, $club] = $this->admin();

        $this->actingAs($admin)->post(route('club.certificates.design.update'), [
            'heading' => 'X', 'subtitle_named' => 'a', 'subtitle_generic' => 'b',
            'background_color' => 'red', // не hex
            'accent_color' => '#ff0000', 'border_color' => '#00ff00', 'text_color' => '#0000ff',
            'orientation' => 'landscape',
        ])->assertSessionHasErrors('background_color');
    }

    public function test_design_uploads_logo(): void
    {
        Storage::fake('public');
        [$admin, $club] = $this->admin();

        $this->actingAs($admin)->post(route('club.certificates.design.update'), [
            'heading' => 'Сертификат', 'subtitle_named' => 'a', 'subtitle_generic' => 'b',
            'background_color' => '#ffffff', 'accent_color' => '#ff0000',
            'border_color' => '#00ff00', 'text_color' => '#0000ff', 'orientation' => 'landscape',
            'logo' => UploadedFile::fake()->image('logo.png', 200, 80),
        ])->assertRedirect();

        $tpl = CertificateTemplate::defaultForClub($club->id);
        $this->assertNotNull($tpl->logo_path);
        Storage::disk('public')->assertExists($tpl->logo_path);
    }
}
