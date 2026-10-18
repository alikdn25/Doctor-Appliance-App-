<?php

/*
 * Migrations that drop or rename tables or columns, or delete rows, approved by the owner (Alex).
 * Add a migration here only with the owner's written permission, with the date and what it removes.
 * See App\Support\Database\MigrationGuard.
 */
return [
    '2026_10_07_000100_internationalize' => 'Applied before this rule (Oct 7, 2026): province → region, gst_number → tax_number; no data lost.',
    '2026_10_26_000100_drop_checklists' => 'Approved by the owner in chat on Oct 16, 2026 ("Удалить таблицы"): drops checklist_templates and job_checklist_items with their data (UX-REDESIGN §20). Back up before deploying.',
];
