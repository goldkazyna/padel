<?php

namespace Tests\Feature;

use App\Models\Certificate;
use App\Models\Club;
use App\Models\ClubCard;
use App\Models\ClubCardType;
use App\Models\ClubClient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Поиск клиента по телефону при бронировании.
 *
 * Номера в базе лежат вперемешку. Сравнение по концу строки ломалось на
 * форматированных: «+7 707 889 50 22» заканчивается на «50 22», а не на
 * десять цифр подряд — и клиент с клубной картой просто не находился.
 */
class ClientPhoneLookupTest extends TestCase
{
    use RefreshDatabase;

    private Club $club;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->club = Club::create(['name' => 'Клуб', 'address' => 'А']);
        $this->admin = User::factory()->create(['role' => 'club_admin']);
        $this->admin->adminClubs()->attach($this->club->id);
    }

    private function clientWithCard(string $storedPhone): ClubClient
    {
        $client = ClubClient::create([
            'club_id' => $this->club->id,
            'name' => 'Павел Крижановский',
            'phone' => $storedPhone,
        ]);

        $type = ClubCardType::create([
            'club_id' => $this->club->id,
            'name' => 'Signature Members Card 15 часов',
            'kind' => 'visits',
            'nominal' => 15,
            'price' => 300000,
        ]);

        ClubCard::create([
            'club_id' => $this->club->id,
            'club_client_id' => $client->id,
            'club_card_type_id' => $type->id,
            'code' => 'SMC000129',
            'balance' => 15,
            'initial_balance' => 15,
            'status' => 'active',
            'expires_at' => now()->addMonths(3),
        ]);

        return $client;
    }

    /** @return array<string, array<int, string>> */
    public static function phoneFormats(): array
    {
        return [
            'без форматирования' => ['77078895022'],
            'с пробелами' => ['+7 707 889 50 22'],
            'скобки и дефисы' => ['+7 (707) 889-50-22'],
            'без кода страны' => ['7078895022'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('phoneFormats')]
    public function test_card_is_found_whatever_the_stored_format(string $storedPhone): void
    {
        $this->clientWithCard($storedPhone);

        $this->actingAs($this->admin)
            ->getJson(route('club.cards.forClient', ['phone' => '+77078895022']))
            ->assertOk()
            ->assertJsonCount(1, 'cards')
            ->assertJsonPath('cards.0.code', 'SMC000129');
    }

    /** Клиента могли завести дважды — карта найдётся на любой из записей. */
    public function test_card_is_found_on_a_duplicate_client(): void
    {
        // Пустышка без карты создана первой — раньше поиск останавливался на ней.
        ClubClient::create([
            'club_id' => $this->club->id,
            'name' => 'Павел К.',
            'phone' => '+7 707 889 50 22',
        ]);
        $this->clientWithCard('77078895022');

        $this->actingAs($this->admin)
            ->getJson(route('club.cards.forClient', ['phone' => '77078895022']))
            ->assertOk()
            ->assertJsonCount(1, 'cards');
    }

    public function test_certificate_is_found_whatever_the_format(): void
    {
        $client = ClubClient::create([
            'club_id' => $this->club->id,
            'name' => 'Павел Крижановский',
            'phone' => '+7 (707) 889-50-22',
        ]);

        Certificate::create([
            'club_id' => $this->club->id,
            'client_id' => $client->id,
            'type' => Certificate::TYPE_NAMED,
            'recipient_name' => 'Павел Крижановский',
            'value_type' => 'amount',
            'amount' => 50000,
            'number' => 'CERT-1',
        ]);

        $this->actingAs($this->admin)
            ->getJson(route('club.certificates.forClient', ['phone' => '77078895022']))
            ->assertOk()
            ->assertJsonCount(1, 'certificates');
    }

    /** Чужой номер не должен цеплять всех подряд. */
    public function test_unknown_phone_finds_nothing(): void
    {
        $this->clientWithCard('77078895022');

        $this->actingAs($this->admin)
            ->getJson(route('club.cards.forClient', ['phone' => '77009998877']))
            ->assertOk()
            ->assertJsonCount(0, 'cards');
    }
}
