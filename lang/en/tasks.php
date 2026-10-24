<?php

// Tasks ("+ → Task"): personal to-dos without a customer.
return [
    'title' => 'Task',
    'new' => 'New task',
    'edit' => 'Edit task',
    'description' => 'A to-do without a customer. It shows in the calendar and on My Jobs.',
    'fields' => [
        'title' => 'Title',
        'note' => 'Note',
        'task_date' => 'Date',
        'start_time' => 'From',
        'end_time' => 'To',
        'user_id' => 'For',
        'remind_minutes' => 'Reminder',
    ],
    'title_placeholder' => 'Call the supplier, pick up a part…',
    'all_day' => 'All day',
    'timed' => 'Time',
    'for_me' => 'Me',
    'reminders' => [
        'none' => 'No reminder',
        '0' => 'At the start',
        '15' => '15 minutes before',
        '60' => '1 hour before',
        '1440' => '1 day before',
    ],
    'save' => 'Save task',
    'delete' => 'Delete task',
    'confirm_delete' => 'Delete this task?',
    'mark_done' => 'Done',
    'mark_not_done' => 'Not done',
    'saved' => 'Task saved.',
    'deleted' => 'Task deleted.',
    'list' => 'Tasks',
    'for_person' => 'For :name',
    'reminder_title' => 'Reminder: :title',
    'reminder_body' => ':day · :time',
    'errors' => [
        'assignee' => 'Pick someone from your team.',
    ],
];
