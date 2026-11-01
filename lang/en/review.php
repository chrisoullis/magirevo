<?php

return [
    'errors' => [
        'too_many_submissions' => 'You have too many recipes waiting for review, or have submitted a lot today. Please wait for a decision or try again tomorrow.',
    ],
    'mail' => [
        'submitted_subject' => 'A recipe is waiting for review',
        'submitted_line' => '":title" was submitted for public review.',
        'review_action' => 'Review recipe',
        'approved_subject' => 'Your recipe was approved',
        'approved_line' => '":title" was approved and is now public.',
        'view_action' => 'View recipe',
        'rejected_subject' => 'Your recipe needs changes',
        'rejected_line' => '":title" was not approved yet. It is back in your drafts.',
        'reason' => 'Reviewer note: :reason',
        'edit_action' => 'Edit recipe',
    ],
];
