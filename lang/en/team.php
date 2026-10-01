<?php

return [
    'title' => 'Team',
    'description' => 'People who work in this company and their roles.',
    'add' => 'Add team member',
    'add_description' => 'New people get an email invitation to set their password. People who already have an account are simply added to this company.',
    'send_invitation' => 'Add and send invitation',
    'added' => 'Team member added.',
    'updated' => 'Team member updated.',
    'removed' => 'Team member removed from the company.',
    'invited' => 'Invited',
    'resend_invitation' => 'Resend invitation',
    'invitation_resent' => 'Invitation sent again.',
    'confirm_remove' => 'Remove :name from this company?',
    'all_brands' => 'All brands',
    'brands_hint' => 'Leave all unchecked to give access to every brand.',

    'fields' => [
        'name' => 'Full name',
        'email' => 'Email',
        'role' => 'Role',
        'brands' => 'Brands',
        'is_active' => 'Active (can sign in to this company)',
    ],

    'errors' => [
        'already_member' => 'This person is already a member of the company.',
        'super_admin' => 'Platform administrators cannot be added to a company.',
        'last_owner' => 'The company must keep at least one active Owner.',
    ],
];
