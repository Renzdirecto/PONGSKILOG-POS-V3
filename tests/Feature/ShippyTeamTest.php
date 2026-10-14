<?php

use App\Models\LandingTeamMember;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

const TEAM_GEMINI = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.1-flash-lite:generateContent';
const TEAM_GEMINI_KEY = 'test-gemini-key-2222';

beforeEach(function () {
    /** A question that reaches the network without a fake for it fails the test instead of calling Gemini. */
    Http::preventStrayRequests();
    config(['services.gemini.key' => TEAM_GEMINI_KEY]);
});

function teamAsk(string $message): TestResponse
{
    return test()->postJson(route('shippy.messages.store'), ['message' => $message]);
}

/** Shamarra Faustino's card as the migration publishes it: visible, with a bundled portrait. */
function shamarraRow(): array
{
    return ['name' => 'Shamarra Faustino', 'price' => 'Chief Operating Officer', 'img' => '/images/landing-v130-a82ec97c.webp', 'kind' => 'person'];
}

test('a question about a visible team member is answered at once from the published card, with the portrait', function (string $question, string $text) {
    teamAsk($question)->assertOk()
        ->assertJsonPath('reply.source', 'team')
        ->assertJsonPath('reply.text', $text)
        ->assertJsonPath('reply.items', [shamarraRow()])
        ->assertJsonPath('reply.actions.0', ['key' => 'team', 'label' => 'Meet the team', 'href' => '#team', 'external' => false]);

    Http::assertNothingSent();
})->with([
    'by first name' => ['Sino si Shamarra?', 'Si Shamarra Faustino ang Chief Operating Officer ng PONGSKILOG. Siya ang nag-aayos ng schedule, supplies at takbo ng kusina gabi-gabi.'],
    'with a typo' => ['sino po si shamara', 'Si Shamarra Faustino ang Chief Operating Officer ng PONGSKILOG. Siya ang nag-aayos ng schedule, supplies at takbo ng kusina gabi-gabi.'],
    'by role, in English' => ['Who is your COO?', "Shamarra Faustino is PONGSKILOG's Chief Operating Officer. Siya ang nag-aayos ng schedule, supplies at takbo ng kusina gabi-gabi."],
]);

test('asking for the team lists every visible card with a name, in the landing page order', function () {
    LandingTeamMember::query()->where('name', 'Sabrina Cheang')->update(['is_visible' => false]);

    $reply = teamAsk('Sino ang team niyo?')->assertOk()->assertJsonPath('reply.source', 'team')->json('reply');

    expect($reply['text'])->toBe('Ito ang team ng PONGSKILOG, sila ang nasa likod ng silog:')
        ->and(array_column($reply['items'], 'name'))->toBe(['Shamarra Faustino', 'Samantha Faustino']);
    Http::assertNothingSent();
});

test('a hidden card, a card without a name and anyone else are never described', function (string $question) {
    LandingTeamMember::query()->where('name', 'Shamarra Faustino')->update(['is_visible' => false]);

    $response = teamAsk($question)->assertOk()
        ->assertJsonPath('reply.source', 'fallback')
        ->assertJsonPath('reply.text', 'Wala akong info tungkol diyan. Message mo ang PONGSKILOG sa Facebook para sure.')
        ->assertJsonPath('reply.items', []);

    expect($response->getContent())->not->toContain('Shamarra')->not->toContain('a82ec97c');
    Http::assertNothingSent();
})->with([
    'a hidden member by name' => ['Sino si Shamarra?'],
    'a role whose card has no name yet' => ['Sino ang chef?'],
    'a person who is not on the team' => ['Sino si Juan?'],
]);

test('an uploaded portrait is shown through its versioned public route', function () {
    $member = LandingTeamMember::query()->where('name', 'Samantha Faustino')->sole();
    $member->forceFill(['image_path' => LandingTeamMember::UPLOADS.$member->id.'/portrait.webp'])->save();

    teamAsk('Sino si Samantha?')->assertOk()
        ->assertJsonPath('reply.items.0.img', '/landing-team/'.$member->id.'/photo?v='.md5(LandingTeamMember::UPLOADS.$member->id.'/portrait.webp'));
});

test('a longer question about a member goes to Gemini with the visible team only, and its card is attached', function () {
    LandingTeamMember::query()->where('name', 'Sabrina Cheang')->update(['is_visible' => false]);
    Http::fake([TEAM_GEMINI => Http::response(['candidates' => [['content' => ['parts' => [['text' => json_encode([
        'reply' => 'Si Shamarra ang nag-aayos ng schedule at supplies ng kusina.',
        'items' => ['Shamarra Faustino', 'Sabrina Cheang', 'Tapa Silog'],
    ])]]]]]])]);

    $reply = teamAsk('Ano ang ginagawa ni Shamarra tuwing gabi sa kusina?')->assertOk()->assertJsonPath('reply.source', 'ai')->json('reply');

    /** A hidden member named by Gemini is dropped; the dish keeps its menu card. */
    expect(array_column($reply['items'], 'name'))->toBe(['Tapa Silog', 'Shamarra Faustino'])
        ->and($reply['items'][1])->toBe(shamarraRow());
    Http::assertSent(function (Request $request): bool {
        $rules = $request->data()['systemInstruction']['parts'][0]['text'];

        return str_contains($rules, 'TEAM (the people on the website, name, role and what their card says): Shamarra Faustino, Chief Operating Officer: Siya ang nag-aayos')
            && str_contains($rules, 'Say who a person is only when TEAM in FACTS describe that exact person')
            && ! str_contains($rules, 'Sabrina');
    });
});

test('a name is found without its accents', function () {
    LandingTeamMember::query()->where('role_label', 'CHEF')->update(['name' => 'José Niño']);

    teamAsk('Sino si Nino?')->assertOk()->assertJsonPath('reply.source', 'team')->assertJsonPath('reply.items.0.name', 'José Niño');
    teamAsk('Who is Jose?')->assertOk()->assertJsonPath('reply.source', 'team')->assertJsonPath('reply.items.0.price', 'Head Chef');
});

test('a word one letter off a name is a name only in a question about someone', function () {
    LandingTeamMember::query()->where('role_label', 'CHEF')->update(['name' => 'Prince Santos']);
    Http::fake([TEAM_GEMINI => Http::response(['candidates' => [['content' => ['parts' => [['text' => json_encode(['reply' => 'Ang Bulalo ay ₱150.', 'items' => ['Bulalo']])]]]]]])]);

    test()->postJson(route('shippy.messages.store'), ['message' => 'Price?', 'history' => [
        ['role' => 'user', 'text' => 'May bulalo ba kayo?'], ['role' => 'bot', 'text' => 'Meron, nasa menu.'],
    ]])->assertOk()->assertJsonPath('reply.source', 'ai')->assertJsonPath('reply.items.0.name', 'Bulalo');
    /** A short word must match exactly; from five letters, one letter off is still the name. */
    teamAsk('Sino si Prin?')->assertOk()->assertJsonPath('reply.source', 'fallback');
    teamAsk('Sino si Prinse?')->assertOk()->assertJsonPath('reply.source', 'team')->assertJsonPath('reply.items.0.name', 'Prince Santos');
});

test('a longer question about a role on a visible card goes to Gemini with the team instead of "no info"', function () {
    Http::fake([TEAM_GEMINI => Http::response(['candidates' => [['content' => ['parts' => [['text' => json_encode([
        'reply' => 'Si Shamarra Faustino ang COO namin. Hindi ko alam kung mabait siya, pero siya ang nag-aayos ng kusina.',
        'items' => ['Shamarra Faustino'],
    ])]]]]]])]);

    teamAsk('Who is your Chief Operating Officer, is she nice?')->assertOk()
        ->assertJsonPath('reply.source', 'ai')
        ->assertJsonPath('reply.items', [shamarraRow()]);

    Http::assertSent(fn (Request $request): bool => str_contains($request->data()['systemInstruction']['parts'][0]['text'], 'Shamarra Faustino, Chief Operating Officer'));
});

test('a question about food sends no team names to Gemini', function () {
    Http::fake([TEAM_GEMINI => Http::response(['candidates' => [['content' => ['parts' => [['text' => json_encode(['reply' => 'Sulit ang Tapa Silog.', 'items' => ['Tapa Silog']])]]]]]])]);

    teamAsk('Masarap ba ang tapsilog ninyo?')->assertOk()->assertJsonPath('reply.source', 'ai');

    Http::assertSent(fn (Request $request): bool => ! str_contains($request->data()['systemInstruction']['parts'][0]['text'], 'Faustino'));
});
