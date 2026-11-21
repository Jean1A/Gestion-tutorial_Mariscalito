<?php

return [
    'name' => 'MARISCAL CASTILLA',
    'privacy_contact' => 'chvca.jhan.5@gmail.com',
    'policy_version' => '2026-10-30-v3',
    'notification_channels' => ['database', 'mail'],
    'legacy_attendance_enabled' => false,
    'backup_binary' => env('SCHOOL_BACKUP_BINARY'),
    'backup_enabled' => env('SCHOOL_BACKUP_ENABLED', false),
    'role_permission_ceiling' => [
        'administrator' => ['users.manage', 'academics.manage', 'students.manage', 'students.view', 'attendance.view', 'settings.manage', 'courses.manage', 'family.manage', 'tutors.coordinate'],
        'coordinator' => ['users.manage', 'academics.manage', 'students.manage', 'students.view', 'attendance.view', 'communications.send', 'reports.view', 'courses.manage', 'family.manage', 'tutors.coordinate'],
        'tutor' => ['students.view', 'attendance.view', 'attendance.record', 'communications.send', 'courses.record'],
        'assistant' => ['students.view', 'attendance.view', 'attendance.record', 'courses.record'],
        'guardian' => ['students.view', 'attendance.view', 'communications.respond'],
        'teacher' => ['courses.record'],
        'student' => [],
    ],
];
