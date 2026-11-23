<?php

use App\Support\Reviews\ReviewText;

/**
 * The review request text (owner's task of November 23): the customer's first name and the bold words.
 */
test('the first name is the first word, capitalised', function (string $name, string $expected) {
    expect(ReviewText::firstName($name))->toBe($expected);
})->with([
    ['Josh Miller', 'Josh'],
    ['josh', 'Josh'],
    ['JOSH', 'Josh'],
    ['  jOsH   miller ', 'Josh'],
    ['élodie', 'Élodie'],
    ['Al', 'Al'],
]);

test('an empty, too short or made-up name gives no first name', function (?string $name) {
    expect(ReviewText::firstName($name))->toBeNull();
})->with([null, '', '   ', 'J', '.', 'test', 'TEST user', '123', 'J0sh', 'Josh.', 'mr.', '-']);

test('only the words between <b> and </b> are bold; everything else is escaped', function () {
    $html = ReviewText::html("<b>Hey Josh, would you take 30 seconds</b> to help? https://g.page/r/x/review\n— Alex <script>", '#123456');

    expect($html)->toContain('<b>Hey Josh, would you take 30 seconds</b> to help?')
        ->toContain('<a href="https://g.page/r/x/review" style="color:#123456;">https://g.page/r/x/review</a>')
        ->toContain('<br>')
        ->toContain('&lt;script&gt;')
        ->and(substr_count($html, '<b>'))->toBe(1);
    // An unclosed tag stays as typed, not bold.
    expect(ReviewText::html('<b>Hey', '#000'))->toBe('&lt;b&gt;Hey');
});
