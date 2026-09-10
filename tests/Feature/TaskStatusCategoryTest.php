<?php

use App\Enums\StatusCategory;
use App\Enums\TaskStatus;
use App\Models\Project;
use App\Models\Task;
use App\Support\TaskPresenter;

test('status category maps correctly to individual task statuses', function () {
    expect(StatusCategory::Todo->statuses())->toBe([TaskStatus::Todo])
        ->and(StatusCategory::Todo->statusValues())->toBe(['todo'])
        ->and(StatusCategory::InProgress->statuses())->toBe([TaskStatus::InProgress, TaskStatus::Review, TaskStatus::OnHold])
        ->and(StatusCategory::InProgress->statusValues())->toBe(['in_progress', 'review', 'on_hold'])
        ->and(StatusCategory::Done->statuses())->toBe([TaskStatus::Done, TaskStatus::Cancelled])
        ->and(StatusCategory::Done->statusValues())->toBe(['done', 'cancelled']);
});

test('task status category and helper methods work as expected', function () {
    expect(TaskStatus::Todo->category())->toBe(StatusCategory::Todo)
        ->and(TaskStatus::Todo->isTodo())->toBeTrue()
        ->and(TaskStatus::Todo->isDone())->toBeFalse()
        ->and(TaskStatus::InProgress->category())->toBe(StatusCategory::InProgress)
        ->and(TaskStatus::InProgress->isTodo())->toBeFalse()
        ->and(TaskStatus::InProgress->isDone())->toBeFalse()
        ->and(TaskStatus::Review->category())->toBe(StatusCategory::InProgress)
        ->and(TaskStatus::Review->isTodo())->toBeFalse()
        ->and(TaskStatus::Review->isDone())->toBeFalse()
        ->and(TaskStatus::OnHold->category())->toBe(StatusCategory::InProgress)
        ->and(TaskStatus::OnHold->isTodo())->toBeFalse()
        ->and(TaskStatus::OnHold->isDone())->toBeFalse()
        ->and(TaskStatus::Done->category())->toBe(StatusCategory::Done)
        ->and(TaskStatus::Done->isTodo())->toBeFalse()
        ->and(TaskStatus::Done->isDone())->toBeTrue()
        ->and(TaskStatus::Cancelled->category())->toBe(StatusCategory::Done)
        ->and(TaskStatus::Cancelled->isTodo())->toBeFalse()
        ->and(TaskStatus::Cancelled->isDone())->toBeTrue();
});

test('task presenter statusOptions includes category', function () {
    $options = TaskPresenter::statusOptions();

    expect($options)->toHaveCount(6)
        ->and($options[0])->toBe(['value' => 'todo', 'label' => 'To Do', 'category' => 'todo'])
        ->and($options[1])->toBe(['value' => 'in_progress', 'label' => 'In Progress', 'category' => 'in_progress'])
        ->and($options[2])->toBe(['value' => 'review', 'label' => 'In Review', 'category' => 'in_progress'])
        ->and($options[3])->toBe(['value' => 'on_hold', 'label' => 'On Hold', 'category' => 'in_progress'])
        ->and($options[4])->toBe(['value' => 'done', 'label' => 'Done', 'category' => 'done'])
        ->and($options[5])->toBe(['value' => 'cancelled', 'label' => 'Cancelled', 'category' => 'done']);
});

test('scopeNotDone and scopeOverdue exclude all done category tasks', function () {
    $project = Project::factory()->create();

    $todoTask = Task::factory()->for($project)->create([
        'status' => TaskStatus::Todo,
        'due_date' => now()->subDays(2),
    ]);

    $inProgressTask = Task::factory()->for($project)->create([
        'status' => TaskStatus::InProgress,
        'due_date' => now()->subDays(1),
    ]);

    $reviewTask = Task::factory()->for($project)->create([
        'status' => TaskStatus::Review,
        'due_date' => now()->subDays(1),
    ]);

    $onHoldTask = Task::factory()->for($project)->create([
        'status' => TaskStatus::OnHold,
        'due_date' => now()->subDays(1),
    ]);

    $doneTask = Task::factory()->for($project)->create([
        'status' => TaskStatus::Done,
        'due_date' => now()->subDays(3),
    ]);

    $cancelledTask = Task::factory()->for($project)->create([
        'status' => TaskStatus::Cancelled,
        'due_date' => now()->subDays(3),
    ]);

    $notDoneTasks = Task::query()->notDone()->pluck('id');
    expect($notDoneTasks)->toContain($todoTask->id, $inProgressTask->id, $reviewTask->id, $onHoldTask->id)
        ->and($notDoneTasks)->not->toContain($doneTask->id, $cancelledTask->id);

    $overdueTasks = Task::query()->overdue()->pluck('id');
    expect($overdueTasks)->toContain($todoTask->id, $inProgressTask->id, $reviewTask->id, $onHoldTask->id)
        ->and($overdueTasks)->not->toContain($doneTask->id, $cancelledTask->id);

    expect($doneTask->isOverdue())->toBeFalse()
        ->and($cancelledTask->isOverdue())->toBeFalse()
        ->and($todoTask->isOverdue())->toBeTrue()
        ->and($onHoldTask->isOverdue())->toBeTrue();
});
