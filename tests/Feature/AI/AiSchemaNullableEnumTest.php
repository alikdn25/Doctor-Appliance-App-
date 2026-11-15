<?php

use App\Services\AI\Assistant\AssistantTools;
use App\Services\AI\Problems\ProblemFinder;
use App\Services\AI\QuickBookingParser;
use App\Services\AI\Review\AppReviewer;
use App\Support\Ai\BookingTextParser;

/**
 * Every JSON schema sent to the AI: a field that may be null and has an enum must list null in the enum, or the
 * structured answer can never be null and the API rejects or distorts it.
 */

/**
 * Paths of nullable fields with an enum, and the ones of them that miss null.
 *
 * @param  array<string, mixed>|list<mixed>  $node
 * @param  list<string>  $checked
 * @param  list<string>  $missing
 */
function walkAiSchema(array $node, string $path, array &$checked, array &$missing): void
{
    if (array_key_exists('enum', $node)) {
        $types = (array) ($node['type'] ?? []);
        $nullable = in_array('null', $types, true) || ($node['nullable'] ?? false) === true;

        if ($nullable) {
            $checked[] = $path;
            if (! in_array(null, (array) $node['enum'], true)) {
                $missing[] = $path;
            }
        }
    }

    foreach ($node as $key => $child) {
        if (is_object($child)) {
            $child = (array) $child;
        }
        if (is_array($child)) {
            walkAiSchema($child, $path.'.'.$key, $checked, $missing);
        }
    }
}

dataset('ai schemas', fn () => [
    'booking text parser' => [BookingTextParser::schema()],
    'quick booking parser' => [QuickBookingParser::schema()],
    'problem finder' => [ProblemFinder::schema()],
    'app review (inconvenient)' => [AppReviewer::schema()],
    'assistant tools' => [array_map(fn (array $tool) => $tool['inputSchema'], AssistantTools::definitions())],
]);

test('nullable fields with an enum list null in the enum', function (array $schema) {
    $checked = [];
    $missing = [];
    walkAiSchema($schema, '$', $checked, $missing);

    expect($missing)->toBe([]);
})->with('ai schemas');

test('the check sees the appliance type of both booking parsers', function () {
    foreach ([BookingTextParser::schema(), QuickBookingParser::schema()] as $schema) {
        $checked = [];
        $missing = [];
        walkAiSchema($schema, '$', $checked, $missing);

        expect($checked)->toContain('$.properties.appliance_type')
            ->and($schema['properties']['appliance_type']['enum'])->toContain(null);
    }
});

test('the check catches a nullable enum without null', function () {
    $checked = [];
    $missing = [];
    walkAiSchema(['properties' => ['kind' => ['type' => ['string', 'null'], 'enum' => ['a', 'b']]]], '$', $checked, $missing);

    expect($missing)->toBe(['$.properties.kind']);
});
