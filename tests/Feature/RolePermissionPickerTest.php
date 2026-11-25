<?php

namespace Tests\Feature;

use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// The roles page lists permissions grouped by area and resource, not as one
// flat list, so a permission must never fall out of the grouping.
class RolePermissionPickerTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_permission_is_in_exactly_one_row_and_none_is_left_in_other(): void
    {
        $this->seedPermissions();

        $ids = [];
        $sections = [];

        foreach (Role::permissionGroups() as $group) {
            $sections[] = $group['label'];

            foreach ($group['resources'] as $row) {
                foreach ($row['permissions'] as $permission) {
                    $ids[] = $permission['id'];
                }
            }
        }

        $this->assertCount(count(Role::PERMISSIONS), $ids);
        $this->assertSame($ids, array_values(array_unique($ids)));
        // A new permission without a section would land here: add its resource to
        // Role::PERMISSION_SECTIONS.
        $this->assertNotContains('Other', $sections);
    }

    public function test_a_row_holds_the_actions_of_one_resource(): void
    {
        $this->seedPermissions();

        $dishes = collect(Role::permissionGroups())
            ->flatMap(fn ($group) => $group['resources'])
            ->firstWhere('label', 'Dishes');

        $this->assertSame(['View', 'Create', 'Edit', 'Delete'], array_column($dishes['permissions'], 'action'));
    }

    public function test_the_roles_page_draws_one_checkbox_per_permission_in_each_drawer(): void
    {
        $response = $this->actingAs($this->superAdmin())->get(route('roles.index'));

        $response->assertOk();
        $response->assertSee('Dish masterlist');
        $response->assertSee('View only');

        // Create and edit drawer: one picker each.
        $this->assertSame(
            count(Role::PERMISSIONS) * 2,
            substr_count($response->getContent(), 'name="permissions[]"'),
        );
    }

    public function test_a_roles_record_carries_only_its_granted_permissions_grouped(): void
    {
        $admin = $this->superAdmin();
        $role = Role::create(['name' => 'Cook', 'guard_name' => 'web']);
        $role->givePermissionTo(['view dishes', 'edit dishes', 'view orders']);

        $groups = $this->actingAs($admin)->getJson(route('roles.edit', $role->id))->assertOk()->json('permission_groups');

        $this->assertSame(['Dish masterlist', 'Order sessions and orders'], array_column($groups, 'label'));
        $this->assertSame(['Dishes'], array_column($groups[0]['resources'], 'label'));
        $this->assertSame(['View', 'Edit'], array_column($groups[0]['resources'][0]['permissions'], 'action'));
        $this->assertSame([], Role::groupsOf([]));
    }
}
