<?php

use App\Console\Commands\AiSchemaSmokeCommand;
use App\Models\Company;
use App\Services\AI\AiFeature;
use App\Services\AI\Assistant\AssistantTools;
use App\Services\AI\BookingEditor;
use App\Services\AI\PartFinder;
use App\Services\AI\Problems\ProblemFinder;
use App\Services\AI\QuickBookingParser;
use App\Services\AI\ReceiptReader;
use App\Services\AI\Review\AppReviewer;
use App\Services\AI\Sms\PlateReader;
use App\Services\AI\Sms\SmsTriage;
use App\Services\AI\VoiceCloser;
use App\Support\Ai\BookingTextParser;
use Tests\Fakes\FakeAiTransport;

/**
 * Every JSON schema sent to the AI, as the Anthropic API accepts it: no node may combine "enum" with an array
 * "type" (the API rejects it), and no enum may contain null. Missing values are "" or "unknown" and become null in
 * the app.
 */

/**
 * Paths of the nodes the API would reject.
 *
 * @param  array<string, mixed>|list<mixed>  $node
 * @param  list<string>  $bad
 */
function walkAiSchema(array $node, string $path, array &$bad): void
{
    if (array_key_exists('enum', $node) && is_array($node['enum'])) {
        if (is_array($node['type'] ?? null)) {
            $bad[] = $path.' (enum with a type array)';
        }
        if (in_array(null, $node['enum'], true)) {
            $bad[] = $path.' (null in enum)';
        }
    }

    foreach ($node as $key => $child) {
        if (is_object($child)) {
            $child = (array) $child;
        }
        if (is_array($child)) {
            walkAiSchema($child, $path.'.'.$key, $bad);
        }
    }
}

dataset('ai schemas', fn () => [
    'booking text parser' => [BookingTextParser::schema()],
    'quick booking parser' => [QuickBookingParser::schema()],
    'problem finder' => [ProblemFinder::schema()],
    'app review' => [AppReviewer::schema()],
    'sms triage' => [SmsTriage::schema()],
    'rating plate' => [PlateReader::schema()],
    'booking quick edit' => [BookingEditor::schema()],
    'close by voice' => [VoiceCloser::schema()],
    'part number' => [PartFinder::schema()],
    'receipt' => [ReceiptReader::schema()],
    'assistant tools' => [array_map(fn (array $tool) => $tool['inputSchema'], AssistantTools::definitions())],
]);

test('no schema node combines enum with a type array or lists null in an enum', function (array $schema) {
    $bad = [];
    walkAiSchema($schema, '$', $bad);

    expect($bad)->toBe([]);
})->with('ai schemas');

test('the smoke command covers every AI feature with the same schemas', function () {
    $requests = AiSchemaSmokeCommand::requests();

    expect(array_keys($requests))->toEqualCanonicalizing(array_map(fn (AiFeature $f) => $f->value, AiFeature::cases()));

    foreach ($requests as [$schema, $tools]) {
        $bad = [];
        walkAiSchema(['schema' => $schema ?? [], 'tools' => $tools], '$', $bad);
        expect($bad)->toBe([]);
    }
});

test('the walk catches an enum with a type array and null in an enum, at any depth', function () {
    $bad = [];
    walkAiSchema(['properties' => [
        'kind' => ['type' => ['string', 'null'], 'enum' => ['a', 'b']],
        'list' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => (object) ['x' => ['type' => 'string', 'enum' => ['a', null]]]]],
    ]], '$', $bad);

    expect($bad)->toBe(['$.properties.kind (enum with a type array)', '$.properties.list.items.properties.x (null in enum)']);
});

test('appliance type "unknown" and empty strings from the AI become null in the filled form', function () {
    $company = Company::factory()->create(['country' => 'CA']);
    $this->actingAs(memberOf($company));
    config(['ai.key' => 'test']);
    FakeAiTransport::install(fn () => [
        'first_name' => 'Jane', 'last_name' => 'Doe', 'company_name' => '', 'phone' => '778-555-0142', 'email' => '',
        'job_address' => ['line1' => '45 Oak Ave', 'unit' => '', 'city' => 'Burnaby', 'region' => '', 'postal_code' => ''],
        'billing_address' => ['line1' => '', 'unit' => '', 'city' => '', 'region' => '', 'postal_code' => ''],
        'occupant_name' => '', 'occupant_phone' => '', 'access_notes' => '',
        'appliance_type' => 'unknown', 'appliance_brand' => '', 'problem' => 'Not working, makes a loud noise',
    ]);

    $this->postJson(route('jobs.parse-message'), ['text' => 'Jane Doe 778-555-0142, 45 Oak Ave, Burnaby. Not working, makes a loud noise'])
        ->assertOk()
        ->assertJsonPath('source', 'ai')
        ->assertJsonPath('fields.appliance_type', null)
        ->assertJsonPath('fields.company_name', null)
        ->assertJsonPath('fields.email', null)
        ->assertJsonPath('fields.job_address.unit', null)
        ->assertJsonPath('fields.billing_address', null)
        ->assertJsonPath('fields.occupant_name', null)
        ->assertJsonPath('fields.phone', '+17785550142');
});
