<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Club;
use App\Models\User;
use App\Models\ClubClient;
use App\Models\ClubGroup;
use App\Models\ClubGroupMember;
use App\Models\ClubGroupEnrollment;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ClientGroupsBlockTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_page_shows_group_with_remaining(): void
    {
        $club = Club::create(['name' => 'C', 'address' => 'A']);
        $admin = User::factory()->create(['role' => 'club_admin']);
        $admin->adminClubs()->attach($club->id);
        $client = ClubClient::create(['club_id' => $club->id, 'name' => 'Иван']);
        $group = ClubGroup::create(['club_id' => $club->id, 'name' => 'Утро']);
        $member = ClubGroupMember::create(['group_id' => $group->id, 'client_id' => $client->id]);
        ClubGroupEnrollment::create(['group_member_id' => $member->id, 'sessions' => 5, 'amount' => 5000]);

        $this->actingAs($admin)
            ->get(route('club.clients.index', ['selected' => $client->id]))
            ->assertOk()
            ->assertSee('Утро');
    }
}
