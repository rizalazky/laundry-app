<?php

namespace Tests\Feature;

use App\Enums\CourierTaskStatus;
use App\Filament\App\Resources\CourierTasks\Pages\ListCourierTasks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CourierTaskListTabsTest extends TestCase
{
    use RefreshDatabase;

    public function test_courier_task_list_exposes_each_workflow_status_tab(): void
    {
        $tabs = (new ListCourierTasks())->getTabs();

        $this->assertArrayHasKey('all', $tabs);

        foreach (CourierTaskStatus::cases() as $status) {
            $this->assertArrayHasKey($status->value, $tabs);
        }
    }

    public function test_courier_task_list_keeps_all_tasks_as_the_default_tab(): void
    {
        $page = new ListCourierTasks();

        $this->assertSame('all', $page->getDefaultActiveTab());
    }
}
