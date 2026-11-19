<?php

namespace App\Console\Commands;

use App\Services\AI\AiFeature;
use App\Services\AI\AiTransport;
use App\Services\AI\Assistant\AssistantTools;
use App\Services\AI\BookingEditor;
use App\Services\AI\PartFinder;
use App\Services\AI\Problems\ProblemFinder;
use App\Services\AI\QuickBookingParser;
use App\Services\AI\ReceiptReader;
use App\Services\AI\Review\AppReviewer;
use App\Services\AI\Sms\NudgeWriter;
use App\Services\AI\Sms\PlateReader;
use App\Services\AI\Sms\SmsTriage;
use App\Services\AI\Sms\Translator;
use App\Services\AI\TaskParser;
use App\Services\AI\VoiceCloser;
use App\Support\Ai\BookingTextParser;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

/**
 * Sends one real, minimal request per AI feature to the live Anthropic API with the feature's exact schema or tools,
 * and prints OK or the API's error. It catches schemas the API rejects. Never run in CI: it needs the real key and
 * costs a few tokens. Requests are not written to the AI request log and do not count toward a company's limit.
 */
#[Signature('ai:schema-smoke {--model=claude-haiku-5-5 : Model used for every request} {--max-tokens=256 : Output limit per request}')]
#[Description('Send one minimal live request per AI feature and print OK or the API error')]
class AiSchemaSmokeCommand extends Command
{
    public function handle(AiTransport $transport): int
    {
        if ((string) config('ai.key') === '') {
            $this->error('ANTHROPIC_API_KEY is not set.');

            return self::FAILURE;
        }

        $failed = 0;

        foreach (self::requests() as $feature => [$schema, $tools, $text]) {
            try {
                $response = $transport->send([
                    'model' => (string) $this->option('model'),
                    'system' => 'This is a connectivity test. Answer as briefly as the format allows.',
                    'messages' => [['role' => 'user', 'content' => $text]],
                    'tools' => $tools,
                    'schema' => $schema,
                    'max_tokens' => (int) $this->option('max-tokens'),
                    'effort' => null,
                ]);
                $this->line(sprintf('%-15s OK (stop: %s, tokens in/out: %d/%d)', $feature, $response->stopReason, $response->usage['input_tokens'] ?? 0, $response->usage['output_tokens'] ?? 0));
            } catch (Throwable $e) {
                $failed++;
                $this->line(sprintf('%-15s FAIL %s', $feature, $e->getMessage()));
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Feature => [schema, tools, user text], one per AiFeature.
     *
     * @return array<string, array{0: array<string, mixed>|null, 1: list<array<string, mixed>>, 2: string}>
     */
    public static function requests(): array
    {
        return [
            AiFeature::QuickBooking->value => [QuickBookingParser::schema(), [], 'John Smith 604-555-0123, fridge not cooling'],
            AiFeature::PasteMessage->value => [BookingTextParser::schema(), [], 'John Smith 604-555-0123, 123 Main St, Vancouver. Fridge not cooling'],
            AiFeature::Assistant->value => [null, AssistantTools::definitions(), 'Say hi in one word.'],
            AiFeature::FindProblems->value => [ProblemFinder::schema(), [], '{"business": [], "technical": []}'],
            AiFeature::AppReview->value => [AppReviewer::schema(), [], '{"summary": {}, "previous_suggestions": []}'],
            AiFeature::SmsTriage->value => [SmsTriage::schema(), [], 'New message from the customer: Can you come tomorrow instead?'],
            AiFeature::SmsPhoto->value => [PlateReader::schema(), [], 'No photo: answer that it is not a rating plate.'],
            AiFeature::BookingEdit->value => [BookingEditor::schema(), [], 'Booking now: {"date": "2026-11-20"}. Change: move to tomorrow'],
            AiFeature::VoiceClose->value => [VoiceCloser::schema(), [], 'Replaced the drain pump, 180 dollars, paid by card.'],
            AiFeature::Receipt->value => [ReceiptReader::schema(), [], 'No photo: return an empty receipt.'],
            AiFeature::PartLookup->value => [PartFinder::schema(), [], 'Appliance: LG washer model WM3400CW. Part needed: drain pump.'],
            AiFeature::Translate->value => [Translator::schema(), [], 'Можем приехать в четверг с 10 до 12'],
            AiFeature::TaskParse->value => [TaskParser::schema(), [], 'завтра купить тэн в Marcone на Kingsway'],
            AiFeature::SmsNudge->value => [NudgeWriter::schema(), [], "Conversation:\nCompany: Can we come Thursday 10-12?\n\nCustomer's first name: Jane"],
        ];
    }
}
