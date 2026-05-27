<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Club;
use App\Models\ClubClient;
use App\Models\User;
use App\Services\ClubClientImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class ClubClientImportTest extends TestCase
{
    use RefreshDatabase;

    private function makeXlsx(array $rows): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        foreach ($rows as $i => $row) {
            foreach ($row as $j => $val) {
                $sheet->setCellValueByColumnAndRow($j + 1, $i + 1, $val);
            }
        }
        $tmp = tempnam(sys_get_temp_dir(), 'imp') . '.xlsx';
        (new Xlsx($spreadsheet))->save($tmp);
        return $tmp;
    }

    public function test_imports_clients_and_skips_no_phone_and_duplicates(): void
    {
        $club = Club::create(['name' => 'C', 'address' => 'A']);
        $file = $this->makeXlsx([
            ['ФИО', 'Номер карты', 'Теги', 'Телефон 1', 'E-mail 1'],
            ['Иван Иванов', 'FB001', '', '+7 (777) 111-22-33', 'ivan@test.kz'],
            ['Мария Петрова', 'FB002', '', '77772223344', ''],
            ['Без Телефона', 'FB003', '', '', 'orphan@test.kz'],
            ['Иван Дубль', 'FB099', '', '77771112233', ''],
        ]);

        $report = (new ClubClientImporter())->import($club->id, $file);

        $this->assertTrue($report['success']);
        $this->assertSame(2, $report['created']);
        $this->assertSame(0, $report['updated']);
        $this->assertCount(1, $report['skipped_no_phone']);
        $this->assertCount(1, $report['skipped_duplicate']);

        $ivan = ClubClient::where('club_id', $club->id)->where('phone', '77771112233')->first();
        $this->assertNotNull($ivan);
        $this->assertSame('Иван Иванов', $ivan->name);
        $this->assertSame('ivan@test.kz', $ivan->email);
        $this->assertSame('FB001', $ivan->card_number);

        $this->assertSame('Без Телефона', $report['skipped_no_phone'][0]['name']);
        $this->assertSame('Иван Дубль', $report['skipped_duplicate'][0]['name']);

        @unlink($file);
    }

    public function test_existing_client_is_updated_not_duplicated(): void
    {
        $club = Club::create(['name' => 'C', 'address' => 'A']);
        ClubClient::create(['club_id' => $club->id, 'name' => 'Старый Имя', 'phone' => '77771112233']);

        $file = $this->makeXlsx([
            ['ФИО', 'Номер карты', 'Теги', 'Телефон 1', 'E-mail 1'],
            ['Новый Имя', 'FB777', '', '77771112233', 'new@test.kz'],
        ]);

        $report = (new ClubClientImporter())->import($club->id, $file);
        $this->assertSame(0, $report['created']);
        $this->assertSame(1, $report['updated']);
        $this->assertSame(1, ClubClient::where('club_id', $club->id)->count());

        $client = ClubClient::where('club_id', $club->id)->first();
        $this->assertSame('Старый Имя', $client->name);
        $this->assertSame('new@test.kz', $client->email);
        $this->assertSame('FB777', $client->card_number);

        @unlink($file);
    }

    public function test_admin_can_access_import_form_and_upload(): void
    {
        $club = Club::create(['name' => 'C', 'address' => 'A']);
        $club->update(['features_enabled' => ['clients']]);
        $admin = User::factory()->create(['role' => 'club_admin']);
        $admin->adminClubs()->attach($club->id);

        $this->actingAs($admin)
            ->get(route('club.clients.import.form'))
            ->assertOk();

        $file = $this->makeXlsx([
            ['ФИО', 'Телефон 1'],
            ['Тест Тестов', '77001112233'],
        ]);

        $this->actingAs($admin)
            ->post(route('club.clients.import'), [
                'file' => new UploadedFile($file, 'clients.xlsx', null, null, true),
            ])
            ->assertRedirect(route('club.clients.import.form'));

        $this->assertSame(1, ClubClient::where('club_id', $club->id)->count());

        @unlink($file);
    }

    public function test_phone_normalization(): void
    {
        $importer = new ClubClientImporter();
        $this->assertSame('77771112233', $importer->normalizePhone('+7 (777) 111-22-33'));
        $this->assertSame('77771112233', $importer->normalizePhone('87771112233'));
        $this->assertSame('77771112233', $importer->normalizePhone('7771112233'));
        $this->assertSame('79835231053', $importer->normalizePhone('79835231053'));
        $this->assertSame('905441449997', $importer->normalizePhone('905441449997'));
        $this->assertSame('', $importer->normalizePhone(''));
    }
}
