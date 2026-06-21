<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class ClubCardScheduleTest extends TestCase
{
    public function test_cards_charge_due_is_not_scheduled(): void
    {
        Artisan::call('schedule:list');
        $output = Artisan::output();

        // sanity: расписание вообще загрузилось
        $this->assertStringContainsString('tournaments:process-moderation', $output);
        // авто-списание карт снято
        $this->assertStringNotContainsString('cards:charge-due', $output);
    }
}
