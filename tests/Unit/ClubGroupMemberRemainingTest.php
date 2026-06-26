<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\Club;
use App\Models\ClubClient;
use App\Models\ClubGroup;
use App\Models\ClubGroupMember;
use App\Models\ClubGroupEnrollment;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ClubGroupMemberRemainingTest extends TestCase
{
    use RefreshDatabase;

    public function test_remaining_equals_bought_minus_charged(): void
    {
        $club = Club::create(['name' => 'C', 'address' => 'A']);
        $client = ClubClient::create(['club_id' => $club->id, 'name' => 'Иван']);
        $group = ClubGroup::create(['club_id' => $club->id, 'name' => 'G', 'price_per_session' => 1000]);
        $member = ClubGroupMember::create(['group_id' => $group->id, 'client_id' => $client->id]);
        ClubGroupEnrollment::create(['group_member_id' => $member->id, 'sessions' => 8, 'amount' => 8000]);

        $this->assertSame(8, $member->fresh()->remaining);
    }

    public function test_is_frozen_on_respects_period_inclusive(): void
    {
        $club = Club::create(['name' => 'C', 'address' => 'A']);
        $client = ClubClient::create(['club_id' => $club->id, 'name' => 'Иван']);
        $group = ClubGroup::create(['club_id' => $club->id, 'name' => 'G', 'price_per_session' => 1000]);
        $member = ClubGroupMember::create(['group_id' => $group->id, 'client_id' => $client->id]);
        \App\Models\ClubGroupMemberFreeze::create([
            'group_member_id' => $member->id,
            'freeze_from' => '2026-07-10',
            'freeze_until' => '2026-07-20',
        ]);

        $this->assertTrue($member->isFrozenOn('2026-07-10'), 'граница начала включительно');
        $this->assertTrue($member->isFrozenOn('2026-07-15'));
        $this->assertTrue($member->isFrozenOn('2026-07-20'), 'граница конца включительно');
        $this->assertFalse($member->isFrozenOn('2026-07-09'));
        $this->assertFalse($member->isFrozenOn('2026-07-21'));
    }
}
