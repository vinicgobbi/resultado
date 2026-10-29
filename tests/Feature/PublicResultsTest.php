<?php

use App\Enums\Office;
use App\Enums\State;
use App\Models\Candidate;
use App\Models\Election;
use App\Models\Municipality;
use App\Models\Result;
use App\Models\User;
use App\Services\MapGeometryBuilder;
use App\Services\Tse\ResultSynchronizer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    elections2026('2026-10-26 12:00:00');

    Storage::fake();
    Storage::put(MapGeometryBuilder::PATH, json_encode(['width' => 1000, 'height' => 1000, 'municipalities' => [], 'states' => []]));
});

/**
 * @param  array<int, int>  $votes
 */
function storeResult(string $scope, string $code, array $votes, int $election = 6258): Result
{
    arsort($votes);

    return Result::create([
        'election_code' => $election,
        'scope' => $scope,
        'scope_code' => $code,
        'sections_total' => 10,
        'sections_counted' => 5,
        'counted_percent' => 50,
        'votes' => $votes,
        'leading_candidate_id' => array_key_first($votes),
        'tse_updated_at' => '2026-10-25 20:00:00',
    ]);
}

test('home page shows the active round with national, state and map results', function () {
    [$first, $second] = Candidate::factory()->count(2)->create();
    Municipality::create(['tse_code' => '71072', 'ibge_code' => '3550308', 'state' => 'sp', 'name' => 'SÃO PAULO']);
    storeResult(Result::SCOPE_COUNTRY, 'br', [$first->id => 300, $second->id => 100]);
    storeResult(Result::SCOPE_STATE, 'sp', [$first->id => 10, $second->id => 30]);
    storeResult(Result::SCOPE_MUNICIPALITY, '71072', [$first->id => 1, $second->id => 3]);

    $this->get(route('home'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('results/index')
            ->where('election.code', 6258)
            ->where('election.round', 2)
            ->has('days', 2)
            ->where('days.0.code', 6258)
            ->where('days.1.code', 6257)
            ->where('summary.results.0', ['candidate_id' => $first->id, 'votes' => 300, 'percent' => 75])
            ->where('states.0.code', 'SP')
            ->where('states.0.leader_id', $second->id)
            ->has('candidates', 2)
            ->missing('map')
            ->loadDeferredProps(fn (Assert $reload) => $reload
                ->where('map.3550308', [$second->id, 75, 50])));
});

test('polling the version does not load the results', function () {
    [$first] = Candidate::factory()->count(1)->create();
    storeResult(Result::SCOPE_COUNTRY, 'br', [$first->id => 300]);

    $this->get(route('home'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('version', 0)
            ->has('summary')
            ->reloadOnly('version', fn (Assert $reload) => $reload
                ->where('version', 0)
                ->missing('summary')
                ->missing('candidates')
                ->missing('states')));
});

test('changing a candidate color invalidates the cached results', function () {
    $candidate = Candidate::factory()->create();

    $this->actingAs(User::factory()->create())
        ->patch(route('admin.candidates.update', $candidate), ['color' => '#aa00ff']);

    // Its election and the runoff.
    expect(ResultSynchronizer::version(6257))->toBe(1)
        ->and(ResultSynchronizer::version(6258))->toBe(1)
        ->and(ResultSynchronizer::version(6259))->toBe(0);
});

test('the map geometry is not sent again to a browser that has it', function () {
    $etag = $this->get(route('map.geometry'))
        ->assertOk()
        ->assertJsonPath('width', 1000)
        ->headers->get('ETag');

    $this->get(route('map.geometry'), ['If-None-Match' => $etag])
        ->assertStatus(304)
        ->assertContent('');
});

test('an election renders without synchronized results', function () {
    $this->get(route('elections.show', 6257))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('election.round', 1)
            ->where('summary', null)
            ->where('candidates', []));
});

test('old round links are sent to the election of that round', function () {
    $this->get('/?turno=1&cargo=governador&uf=rj')
        ->assertRedirect(route('elections.show', [6257, 'cargo' => 'governador', 'uf' => 'rj']));

    // The office belongs to the state election of the same day.
    $this->get(route('elections.show', [6257, 'cargo' => 'governador', 'uf' => 'rj']))
        ->assertRedirect(route('elections.show', [6259, 'cargo' => 'governador', 'uf' => 'rj']));
});

test('municipality endpoint returns its detailed result', function () {
    $candidate = Candidate::factory()->create(['color' => '#123456']);
    $municipality = Municipality::create(['tse_code' => '71072', 'ibge_code' => '3550308', 'state' => 'sp', 'name' => 'SÃO PAULO', 'is_capital' => true]);
    storeResult(Result::SCOPE_MUNICIPALITY, '71072', [$candidate->id => 50]);

    $this->getJson(route('municipalities.show', [6258, $municipality]))
        ->assertOk()
        ->assertJsonPath('state', 'SP')
        ->assertJsonPath('result.results.0.percent', 100)
        ->assertJsonPath('candidates.0.color', '#123456');

    $this->getJson(route('municipalities.show', [6258, '9999999']))->assertNotFound();
});

test('summary endpoint lists the leading candidates for notifications', function () {
    [$first, $second] = Candidate::factory()->count(2)->create();
    storeResult(Result::SCOPE_COUNTRY, 'br', [$first->id => 300, $second->id => 100]);

    $this->getJson(route('results.summary'))
        ->assertOk()
        ->assertJsonPath('counted_percent', 50)
        ->assertJsonPath('candidates.0.name', $first->ballot_name)
        ->assertJsonPath('candidates.1.percent', 25);
});

/**
 * @param  array<string, mixed>  $attributes
 */
function storeCountry(array $votes, array $attributes = []): void
{
    arsort($votes);

    Result::create([
        'election_code' => 6258,
        'scope' => Result::SCOPE_COUNTRY,
        'scope_code' => 'br',
        'sections_total' => 100,
        'sections_counted' => 99,
        'counted_percent' => 99,
        'electorate' => 1200,
        'electorate_counted' => 1100,
        'valid_votes' => array_sum($votes),
        'votes' => $votes,
        'leading_candidate_id' => array_key_first($votes),
        ...$attributes,
    ]);
}

test('a leader that cannot be caught is mathematically elected', function () {
    [$leader, $other] = Candidate::factory()->count(2)->create();
    // 1000 valid votes and 100 voters left: 700 > (1000 + 100) / 2.
    storeCountry([$leader->id => 700, $other->id => 300]);

    $this->get(route('home'))->assertInertia(fn (Assert $page) => $page
        ->where('summary.winners', [['candidate_id' => $leader->id, 'status' => 'mathematical']]));
});

test('a leader that can still be caught is not elected', function () {
    [$leader, $other] = Candidate::factory()->count(2)->create();
    // Lead of 40 votes with 100 voters left.
    storeCountry([$leader->id => 520, $other->id => 480]);

    $this->get(route('home'))->assertInertia(fn (Assert $page) => $page->where('summary.winners', []));
});

test('the candidate elected by the TSE is the winner', function () {
    [$leader, $other] = Candidate::factory()->count(2)->create();
    storeCountry([$leader->id => 520, $other->id => 480], ['elected_candidate_ids' => [$leader->id]]);

    $this->get(route('home'))->assertInertia(fn (Assert $page) => $page
        ->where('summary.winners', [['candidate_id' => $leader->id, 'status' => 'elected']]));

    $this->getJson(route('results.summary'))->assertJsonPath('winner', ['name' => $leader->ballot_name, 'status' => 'elected']);
});

test('winners are celebrated only in the latest election, until a week after its day', function () {
    $this->get(route('home'))->assertInertia(fn (Assert $page) => $page
        ->where('election.code', 6258)
        ->where('celebrateWinners', true));
    $this->get(route('elections.show', 6257))->assertInertia(fn (Assert $page) => $page->where('celebrateWinners', false));

    $this->travelTo('2026-11-01 23:59:00');
    $this->get(route('elections.show', 6258))->assertInertia(fn (Assert $page) => $page->where('celebrateWinners', true));

    $this->travelTo('2026-11-02 00:00:00');
    $this->get(route('elections.show', 6258))->assertInertia(fn (Assert $page) => $page->where('celebrateWinners', false));
});

test('a runoff is certain once not even the leader can reach a majority, with both places decided', function () {
    [$first, $second, $third] = Candidate::factory()->count(3)->create();
    // 1000 valid votes and 100 voters left: 2 × (450 + 100) is not more than 1000 + 100.
    storeCountry([$first->id => 450, $second->id => 400, $third->id => 150], ['election_code' => 6257]);

    $this->get(route('elections.show', 6257))->assertInertia(fn (Assert $page) => $page
        ->where('summary.winners', [])
        ->where('summary.runoff', ['status' => 'mathematical', 'candidate_ids' => [$first->id, $second->id]]));
});

test('the second place of a certain runoff stays open while the third can still pass', function () {
    [$first, $second, $third] = Candidate::factory()->count(3)->create();
    // The second leads the third by 20 votes with 100 voters left.
    storeCountry([$first->id => 440, $second->id => 290, $third->id => 270], ['election_code' => 6257]);

    $this->get(route('elections.show', 6257))->assertInertia(fn (Assert $page) => $page
        ->where('summary.runoff', ['status' => 'mathematical', 'candidate_ids' => [$first->id]]));
});

test('a runoff is not certain while the leader can still reach a majority', function () {
    [$first, $second] = Candidate::factory()->count(2)->create();
    storeCountry([$first->id => 520, $second->id => 480], ['election_code' => 6257]);

    $this->get(route('elections.show', 6257))->assertInertia(fn (Assert $page) => $page->where('summary.runoff', null));
});

test('the final count confirms the first two in the runoff', function () {
    [$first, $second, $third] = Candidate::factory()->count(3)->create();
    storeCountry([$first->id => 450, $second->id => 400, $third->id => 150], [
        'election_code' => 6257,
        'electorate_counted' => 1200,
        'sections_counted' => 100,
        'counted_percent' => 100,
        'is_final' => true,
    ]);

    $this->get(route('elections.show', 6257))->assertInertia(fn (Assert $page) => $page
        ->where('summary.runoff', ['status' => 'confirmed', 'candidate_ids' => [$first->id, $second->id]]));
});

test('the notification summary tells when a runoff is certain and who is in it', function () {
    $this->travelTo('2026-10-05 12:00:00');
    [$first, $second, $third] = Candidate::factory()->count(3)->create();
    storeCountry([$first->id => 440, $second->id => 290, $third->id => 270], ['election_code' => 6257]);

    $this->getJson(route('results.summary'))
        ->assertOk()
        ->assertJsonPath('round', 1)
        ->assertJsonPath('winner', null)
        ->assertJsonPath('runoff', ['status' => 'mathematical', 'names' => [$first->ballot_name]]);
});

test('the second round has no runoff', function () {
    [$first, $second, $third] = Candidate::factory()->count(3)->create();
    storeCountry([$first->id => 450, $second->id => 400, $third->id => 150]);

    $this->get(route('home'))->assertInertia(fn (Assert $page) => $page->where('summary.runoff', null));
});

test('the voters left fall back to turnout and abstentions', function () {
    [$leader, $other] = Candidate::factory()->count(2)->create();
    // Synced before electorate_counted existed: 1100 voters counted (1000 + 100).
    storeCountry([$leader->id => 700, $other->id => 300], ['electorate_counted' => 0, 'turnout' => 1000, 'abstentions' => 100]);

    $this->get(route('home'))->assertInertia(fn (Assert $page) => $page
        ->where('summary.winners', [['candidate_id' => $leader->id, 'status' => 'mathematical']]));
});

/**
 * @param  array<int, int>  $votes
 * @param  array<string, mixed>  $attributes
 */
function storeStateResult(Office $office, string $state, array $votes, array $attributes = []): Result
{
    arsort($votes);

    return Result::create([
        'election_code' => 6259,
        'office' => $office,
        'scope' => Result::SCOPE_STATE,
        'scope_code' => $state,
        'sections_total' => 100,
        'sections_counted' => 99,
        'counted_percent' => 99,
        'electorate' => 1200,
        'electorate_counted' => 1100,
        'valid_votes' => array_sum($votes),
        'votes' => $votes,
        'leading_candidate_id' => array_key_first($votes),
        ...$attributes,
    ]);
}

test('a state office shows the result of the chosen state and its municipalities', function () {
    [$first, $second] = Candidate::factory()->count(2)->running(Office::Governor, State::RioDeJaneiro)->create();
    $other = Candidate::factory()->running(Office::Governor, State::SaoPaulo)->create();
    Municipality::create(['tse_code' => '60011', 'ibge_code' => '3304557', 'state' => 'rj', 'name' => 'RIO DE JANEIRO']);
    Municipality::create(['tse_code' => '71072', 'ibge_code' => '3550308', 'state' => 'sp', 'name' => 'SÃO PAULO']);
    storeStateResult(Office::Governor, 'rj', [$first->id => 300, $second->id => 700]);
    storeStateResult(Office::Governor, 'sp', [$other->id => 100]);
    storeResult(Result::SCOPE_MUNICIPALITY, '60011', [$first->id => 3, $second->id => 1], 6259)->update(['office' => Office::Governor]);
    storeResult(Result::SCOPE_MUNICIPALITY, '71072', [$other->id => 1], 6259)->update(['office' => Office::Governor]);

    $this->get(route('elections.show', [6259, 'cargo' => 'governador', 'uf' => 'rj']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('office', 'governador')
            ->where('state', 'RJ')
            ->where('places.0.code', 'RJ')
            ->where('summary.results.0', ['candidate_id' => $second->id, 'votes' => 700, 'percent' => 70])
            ->where('summary.winners', [['candidate_id' => $second->id, 'status' => 'mathematical']])
            ->has('candidates', 2)
            ->where('states', [])
            ->loadDeferredProps(fn (Assert $reload) => $reload
                ->where('map', ['3304557' => [$first->id, 75, 50]])));

    $municipality = Municipality::query()->where('tse_code', '60011')->sole();
    $this->getJson(route('municipalities.show', ['election' => 6259, 'municipality' => $municipality, 'cargo' => 'governador']))
        ->assertOk()
        ->assertJsonPath('result.results.0.candidate_id', $first->id);
});

test('a state office shows nothing until the visitor picks a state', function () {
    $candidate = Candidate::factory()->running(Office::Governor, State::SaoPaulo)->create();
    storeStateResult(Office::Governor, 'sp', [$candidate->id => 100], ['elected_candidate_ids' => [$candidate->id]]);
    Municipality::create(['tse_code' => '71072', 'ibge_code' => '3550308', 'state' => 'sp', 'name' => 'SÃO PAULO']);
    storeResult(Result::SCOPE_MUNICIPALITY, '71072', [$candidate->id => 1], 6259)->update(['office' => Office::Governor]);

    foreach ([[], ['uf' => 'rj'], ['uf' => 'xx']] as $query) {
        $this->get(route('elections.show', [6259, 'cargo' => 'governador', ...$query]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('state', null)
                ->where('summary', null)
                ->where('candidates', [])
                ->where('places.0.code', 'SP')
                ->loadDeferredProps(fn (Assert $reload) => $reload->where('map', [])));
    }
});

test('an office that is not disputed in the round falls back to president', function () {
    $this->get(route('elections.show', [6258, 'cargo' => 'senador']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('office', 'presidente')
            ->where('offices', [
                ['value' => 'presidente', 'label' => 'Presidente', 'election' => 6258],
                ['value' => 'governador', 'label' => 'Governador', 'election' => 6260],
            ]));
});

test('senators that cannot be caught are mathematically elected', function () {
    [$first, $second, $third] = Candidate::factory()->count(3)->running(Office::Senator, State::SaoPaulo)->create();
    // 100 voters left: the second leads the third by 150, the first by 250.
    storeStateResult(Office::Senator, 'sp', [$first->id => 500, $second->id => 400, $third->id => 250], ['seats' => 2]);

    $this->get(route('elections.show', [6259, 'cargo' => 'senador', 'uf' => 'sp']))
        ->assertInertia(fn (Assert $page) => $page->where('summary.winners', [
            ['candidate_id' => $first->id, 'status' => 'mathematical'],
            ['candidate_id' => $second->id, 'status' => 'mathematical'],
        ]));
});

test('a senator still within reach is not elected', function () {
    [$first, $second, $third] = Candidate::factory()->count(3)->running(Office::Senator, State::SaoPaulo)->create();
    storeStateResult(Office::Senator, 'sp', [$first->id => 500, $second->id => 400, $third->id => 350], ['seats' => 2]);

    $this->get(route('elections.show', [6259, 'cargo' => 'senador', 'uf' => 'sp']))
        ->assertInertia(fn (Assert $page) => $page->where('summary.winners', [
            ['candidate_id' => $first->id, 'status' => 'mathematical'],
        ]));
});

test('deputies are only elected by the TSE and have no map', function () {
    [$elected, $leader] = Candidate::factory()->count(2)->running(Office::StateDeputy, State::DistritoFederal)->create();
    storeStateResult(Office::StateDeputy, 'df', [$leader->id => 900, $elected->id => 100], ['seats' => 24, 'valid_votes' => 2000]);

    $this->get(route('elections.show', [6259, 'cargo' => 'deputado-estadual', 'uf' => 'df']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('summary.winners', [])
            ->where('summary.results.0.percent', 45)
            ->where('offices.4.label', 'Deputado Distrital')
            ->where('mapAvailable', false));

    Result::query()->update(['elected_candidate_ids' => [$elected->id]]);
    Cache::flush();

    $this->get(route('elections.show', [6259, 'cargo' => 'deputado-estadual', 'uf' => 'df']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('summary.winners', [['candidate_id' => $elected->id, 'status' => 'elected']]));
});

test('candidates take the color of their party', function () {
    $candidate = Candidate::factory()->create(['party' => 'NOVO']);
    $unknown = Candidate::factory()->create(['party' => 'PARTIDO NOVÍSSIMO']);

    expect($candidate->displayColor())->toBe('#f47920')
        ->and($unknown->displayColor())->toBe(Candidate::FALLBACK_COLORS[$unknown->id % count(Candidate::FALLBACK_COLORS)]);
});

test('similar party colors in a race get distinct shades, keeping admin colors', function () {
    // PT and PDT are both red; PCdoB too, but its color was chosen in the admin.
    [$first, $second] = [
        Candidate::factory()->create(['party' => 'PT']),
        Candidate::factory()->create(['party' => 'PDT']),
    ];
    $custom = Candidate::factory()->create(['party' => 'PCDOB', 'color' => '#c4161c']);
    storeCountry([$first->id => 500, $second->id => 300, $custom->id => 200]);

    $this->get(route('home'))->assertInertia(function (Assert $page) use ($first, $custom) {
        $colors = collect($page->toArray()['props']['candidates'])->pluck('color', 'id');

        expect($colors[$first->id])->toBe('#c4161c')
            ->and($colors[$custom->id])->toBe('#c4161c')
            ->and($colors->unique())->toHaveCount(2);
    });
});

test('deputies keep the color of their party', function () {
    [$first, $second] = Candidate::factory()->count(2)->running(Office::FederalDeputy, State::SaoPaulo)->create(['party' => 'PT']);
    storeStateResult(Office::FederalDeputy, 'sp', [$first->id => 500, $second->id => 300]);

    $this->get(route('elections.show', [6259, 'cargo' => 'deputado-federal', 'uf' => 'sp']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('candidates.0.color', '#c4161c')
            ->where('candidates.1.color', '#c4161c'));
});

/**
 * @param  array<int, int>  $votes
 * @param  array<string, mixed>  $attributes
 */
function storeMunicipalResult(Office $office, string $municipality, array $votes, array $attributes = []): Result
{
    arsort($votes);

    return Result::create([
        'election_code' => 619,
        'office' => $office,
        'scope' => Result::SCOPE_MUNICIPALITY,
        'scope_code' => $municipality,
        'sections_total' => 100,
        'sections_counted' => 90,
        'counted_percent' => 90,
        'electorate' => 1000,
        'electorate_counted' => 900,
        'valid_votes' => array_sum($votes),
        'votes' => $votes,
        'leading_candidate_id' => array_key_first($votes),
        ...$attributes,
    ]);
}

test('a municipal office shows the result of the chosen municipality, without a map', function () {
    Election::factory()->municipal2024()->completed()->create();
    Municipality::create(['tse_code' => '71072', 'ibge_code' => '3550308', 'state' => 'sp', 'name' => 'SÃO PAULO']);
    [$first, $second] = Candidate::factory()->count(2)->running(Office::Mayor, State::SaoPaulo, '71072')->create();
    // 1000 voters, 900 counted: a lead of 300 cannot be caught by the 100 left.
    storeMunicipalResult(Office::Mayor, '71072', [$first->id => 500, $second->id => 200]);

    $this->get(route('elections.show', [619, 'uf' => 'sp']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('office', 'prefeito')
            ->where('state', 'SP')
            ->where('municipalities.0.code', '71072')
            ->where('municipality', null)
            ->where('summary', null));

    $this->get(route('elections.show', [619, 'uf' => 'sp', 'municipio' => '71072']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('municipality', ['code' => '71072', 'name' => 'SÃO PAULO'])
            ->where('summary.results.0.candidate_id', $first->id)
            ->where('summary.winners', [['candidate_id' => $first->id, 'status' => 'mathematical']])
            ->has('candidates', 2)
            ->where('mapAvailable', false));
});

test('mayors of large municipalities need more than half of the valid votes', function () {
    Election::factory()->municipal2024()->create();
    Municipality::create(['tse_code' => '71072', 'ibge_code' => '3550308', 'state' => 'sp', 'name' => 'SÃO PAULO']);
    [$first, $second, $third] = Candidate::factory()->count(3)->running(Office::Mayor, State::SaoPaulo, '71072')->create();
    $votes = [$first->id => 400_000, $second->id => 300_000, $third->id => 200_000];
    storeMunicipalResult(Office::Mayor, '71072', $votes, ['electorate' => 1_000_000, 'electorate_counted' => 990_000]);

    // A lead of 100,000 with 10,000 voters left, but no majority.
    $this->get(route('elections.show', [619, 'uf' => 'sp', 'municipio' => '71072']))
        ->assertInertia(fn (Assert $page) => $page->where('summary.winners', []));
});

test('the archive lists every election day, joining the elections of the same day', function () {
    Election::factory()->municipal2024()->completed()->create();

    $this->get(route('elections.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('elections/index')
            ->has('days', 3)
            ->where('days.0', [
                'code' => 6258,
                'year' => 2026,
                'round' => 2,
                'date' => '2026-10-25',
                'status' => 'apuracao',
                'offices' => ['Presidente', 'Governador'],
            ])
            ->where('days.1.code', 6257)
            ->where('days.1.offices', ['Presidente', 'Governador', 'Senador', 'Deputado Federal', 'Deputado Estadual'])
            ->where('days.2.code', 619)
            ->where('days.2.status', 'concluida')
            ->where('days.2.offices', ['Prefeito', 'Vereador']));
});

test('an election day is only complete when all of its elections are', function () {
    Election::query()->where('tse_code', 6258)->update(['completed_at' => now()]);

    $this->get(route('elections.index'))->assertInertia(fn (Assert $page) => $page->where('days.0.status', 'apuracao'));

    Election::query()->where('tse_code', 6260)->update(['completed_at' => now()]);

    $this->get(route('elections.index'))->assertInertia(fn (Assert $page) => $page->where('days.0.status', 'concluida'));
});

test('the icons are linked with their version, so browsers load new ones', function () {
    $this->get(route('elections.index'))
        ->assertOk()
        ->assertSee('/favicon.svg?v='.filemtime(public_path('favicon.svg')), escape: false)
        ->assertSee('/favicon.ico?v='.filemtime(public_path('favicon.ico')), escape: false);
});

test('the district councillors tab is only shown in Pernambuco, where Fernando de Noronha elects them', function () {
    Election::factory()->municipal2024()->completed()->create(['offices' => [Office::Mayor, Office::Councillor, Office::DistrictCouncillor]]);
    Municipality::create(['tse_code' => '71072', 'ibge_code' => '3550308', 'state' => 'sp', 'name' => 'SÃO PAULO']);
    Municipality::create(['tse_code' => '25313', 'ibge_code' => '2611606', 'state' => 'pe', 'name' => 'RECIFE']);
    $paulista = Candidate::factory()->running(Office::Mayor, State::SaoPaulo, '71072')->create();
    $pernambucano = Candidate::factory()->running(Office::Mayor, State::Pernambuco, '25313')->create();
    storeMunicipalResult(Office::Mayor, '71072', [$paulista->id => 500]);
    storeMunicipalResult(Office::Mayor, '25313', [$pernambucano->id => 500]);
    $offices = fn (array $query): array => $this->get(route('elections.show', [619, 'cargo' => 'prefeito', ...$query]))
        ->viewData('page')['props']['offices'];

    expect(array_column($offices([]), 'value'))->toBe(['prefeito', 'vereador'])
        ->and(array_column($offices(['uf' => 'sp']), 'value'))->toBe(['prefeito', 'vereador'])
        ->and(array_column($offices(['uf' => 'pe']), 'value'))->toBe(['prefeito', 'vereador', 'conselheiro-distrital'])
        ->and(Office::DistrictCouncillor->states())->toBe([State::Pernambuco]);
});
