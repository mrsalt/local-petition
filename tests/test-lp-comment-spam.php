<?php
require_once dirname(__DIR__) . '/_inc/lp-comment-spam.php';

$cases = [];
$cases[] = [
    'name' => 'detects cyrillic text',
    'comment' => [
        'comment_author' => 'Иван',
        'comment_author_email' => 'ivan@example.com',
        'comment_content' => 'Привет мир',
    ],
    'expected' => true,
];
$cases[] = [
    'name' => 'detects porn in content',
    'comment' => [
        'comment_author' => 'Alice',
        'comment_author_email' => 'alice@example.com',
        'comment_content' => 'Great porn content',
    ],
    'expected' => true,
];
$cases[] = [
    'name' => 'ignores normal comments',
    'comment' => [
        'comment_author' => 'Bob',
        'comment_author_email' => 'bob@example.com',
        'comment_content' => 'I support this initiative',
    ],
    'expected' => false,
];

foreach ($cases as $case) {
    $actual = lp_is_likely_spam_comment($case['comment']);
    if ($actual !== $case['expected']) {
        fwrite(STDERR, "FAIL {$case['name']}: expected {$case['expected']}, got {$actual}\n");
        exit(1);
    }
}

$comments = [
    [
        'comment_ID' => 10,
        'comment_author' => 'Alice',
        'comment_author_email' => 'alice@example.com',
        'comment_content' => 'porn',
    ],
    [
        'comment_ID' => 11,
        'comment_author' => 'Alice',
        'comment_author_email' => 'alice@example.com',
        'comment_content' => 'still likely',
    ],
    [
        'comment_ID' => 12,
        'comment_author' => 'Bob',
        'comment_author_email' => 'bob@example.com',
        'comment_content' => 'Привет',
    ],
];

$groups = lp_group_likely_spam_comments($comments);
if (count($groups) !== 2) {
    fwrite(STDERR, "FAIL grouping count expected 2, got " . count($groups) . "\n");
    exit(1);
}

if ($groups[0]['count'] !== 2 || $groups[0]['comment_author'] !== 'Alice') {
    fwrite(STDERR, "FAIL first group contents\n");
    exit(1);
}

fwrite(STDOUT, "All comment spam tests passed\n");
