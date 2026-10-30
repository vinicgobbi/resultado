<?php

use App\Enums\Office;
use App\Enums\State;
use App\Models\Candidate;
use App\Models\Election;
use App\Models\Municipality;
use App\Models\PartyColor;
use App\Models\User;
use App\Services\Tse\ResultSynchronizer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

/** A real 1x1 PNG: generating one would need the GD extension. */
const PNG_PIXEL = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

beforeEach(fn () => elections2026());

test('guests are redirected to the login page', function () {
    $candidate = Candidate::factory()->create();

    $this->get(route('admin.candidates.index'))->assertRedirect(route('login'));
    $this->patch(route('admin.candidates.update', $candidate), ['color' => '#000000'])->assertRedirect(route('login'));
});

test('admins can change a candidate color', function () {
    $candidate = Candidate::factory()->create();

    $this->actingAs(User::factory()->create())
        ->patch(route('admin.candidates.update', $candidate), ['color' => '#AA00FF'])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    expect($candidate->fresh()->color)->toBe('#aa00ff');
});

test('clearing the color restores the automatic color', function () {
    $candidate = Candidate::factory()->create(['color' => '#aa00ff']);

    $this->actingAs(User::factory()->create())
        ->patch(route('admin.candidates.update', $candidate), ['color' => ''])
        ->assertSessionHasNoErrors();

    expect($candidate->fresh()->color)->toBeNull();
});

test('colors must be hexadecimal', function () {
    $candidate = Candidate::factory()->create();

    $this->actingAs(User::factory()->create())
        ->patch(route('admin.candidates.update', $candidate), ['color' => 'red'])
        ->assertSessionHasErrors(['color' => 'Informe uma cor no formato #RRGGBB.']);
});

test('admins see the candidates of the chosen office and state', function () {
    $president = Candidate::factory()->create();
    $governor = Candidate::factory()->running(Office::Governor, State::RioDeJaneiro)->create();
    Candidate::factory()->running(Office::Governor, State::SaoPaulo)->create();
    $admin = User::factory()->create();

    $this->actingAs($admin)
        ->get(route('admin.candidates.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('office', 'presidente')
            ->has('candidates', 1)
            ->where('candidates.0.id', $president->id));

    $this->actingAs($admin)
        ->get(route('admin.candidates.index', ['eleicao' => 6259, 'cargo' => 'governador', 'uf' => 'rj']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('state', 'rj')
            ->has('candidates', 1)
            ->where('candidates.0.id', $governor->id));
});

test('admins see the councillors of the chosen municipality', function () {
    Election::factory()->municipal2024()->create();
    Municipality::create(['tse_code' => '71072', 'ibge_code' => '3550308', 'state' => 'sp', 'name' => 'SÃO PAULO']);
    Municipality::create(['tse_code' => '62138', 'ibge_code' => '3509502', 'state' => 'sp', 'name' => 'CAMPINAS']);
    $campinas = Candidate::factory()->running(Office::Councillor, State::SaoPaulo, '62138')->create();
    Candidate::factory()->running(Office::Councillor, State::SaoPaulo, '71072')->create();

    $this->actingAs(User::factory()->create())
        ->get(route('admin.candidates.index', ['eleicao' => 619, 'cargo' => 'vereador', 'uf' => 'sp', 'municipio' => '62138']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('municipality', '62138')
            ->where('municipalities', [['value' => '62138', 'label' => 'CAMPINAS'], ['value' => '71072', 'label' => 'SÃO PAULO']])
            ->has('candidates', 1)
            ->where('candidates.0.id', $campinas->id));
});

test('admins correct the ballot name, and the sync keeps the correction', function () {
    $candidate = Candidate::factory()->create(['ballot_name' => 'ZE DA SILVA']);
    $version = ResultSynchronizer::version(6257);

    $this->actingAs(User::factory()->create())
        ->patch(route('admin.candidates.update', $candidate), ['ballot_name' => ' Zé da Silva '])
        ->assertSessionHasNoErrors();

    expect($candidate->fresh()->toPublicArray()['name'])->toBe('Zé da Silva')
        ->and(ResultSynchronizer::version(6257))->toBe($version + 1);

    // The sync writes the TSE name again.
    Candidate::query()->upsert([[...$candidate->only(['tse_sequence', 'office', 'number', 'full_name', 'party']), 'ballot_name' => 'ZE DA SILVA']], ['tse_sequence'], ['ballot_name']);

    expect($candidate->fresh()->toPublicArray()['name'])->toBe('Zé da Silva');
});

test('the TSE name again, or none, removes the correction', function (string $name) {
    $candidate = Candidate::factory()->create(['ballot_name' => 'ZE DA SILVA', 'custom_ballot_name' => 'Zé']);

    $this->actingAs(User::factory()->create())
        ->patch(route('admin.candidates.update', $candidate), ['ballot_name' => $name]);

    expect($candidate->fresh()->custom_ballot_name)->toBeNull();
})->with(['none' => [''], 'the TSE name' => ['ZE DA SILVA']]);

test('admins send a photo, shown instead of the TSE one, and can remove it', function () {
    Http::preventStrayRequests();
    Storage::fake(Candidate::PHOTO_DISK);
    $candidate = Candidate::factory()->create();
    $admin = User::factory()->create();

    $this->actingAs($admin)
        ->post(route('admin.candidates.update', $candidate), ['_method' => 'PATCH', 'photo' => UploadedFile::fake()->createWithContent('foto.png', base64_decode(PNG_PIXEL))])
        ->assertSessionHasNoErrors();

    $photo = $candidate->fresh()->custom_photo;
    expect($photo)->toStartWith(Candidate::CUSTOM_PHOTO_DIRECTORY.'/');
    Storage::disk(Candidate::PHOTO_DISK)->assertExists($photo);
    // Served without asking the TSE.
    $this->get(route('candidates.photo', $candidate))->assertOk();

    // Deleting the TSE photos keeps the ones sent here.
    $this->artisan('fotos:apagar', ['--force' => true]);
    Storage::disk(Candidate::PHOTO_DISK)->assertExists($photo);

    $this->actingAs($admin)
        ->patch(route('admin.candidates.update', $candidate), ['remove_photo' => true])
        ->assertSessionHasNoErrors();

    expect($candidate->fresh()->custom_photo)->toBeNull();
    Storage::disk(Candidate::PHOTO_DISK)->assertMissing($photo);
});

test('only images are accepted as photos', function () {
    Storage::fake(Candidate::PHOTO_DISK);
    $candidate = Candidate::factory()->create();

    $this->actingAs(User::factory()->create())
        ->post(route('admin.candidates.update', $candidate), ['_method' => 'PATCH', 'photo' => UploadedFile::fake()->create('foto.pdf', 10, 'application/pdf')])
        ->assertSessionHasErrors('photo');

    expect($candidate->fresh()->custom_photo)->toBeNull();
});

test('admins choose the color of a party, over its default, and can go back to it', function () {
    config(['partidos.cores' => ['PT' => '#CC0000']]);
    $candidate = Candidate::factory()->create(['party' => 'PT', 'color' => null]);
    Candidate::factory()->create(['party' => 'NOVO']);
    $admin = User::factory()->create();

    $this->actingAs($admin)
        ->get(route('admin.parties.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/parties/index')
            ->where('parties', [
                ['party' => 'NOVO', 'candidates' => 1, 'default_color' => null, 'custom_color' => null],
                ['party' => 'PT', 'candidates' => 1, 'default_color' => '#cc0000', 'custom_color' => null],
            ]));

    $version = ResultSynchronizer::version(6258);

    $this->actingAs($admin)
        ->put(route('admin.parties.update'), ['party' => 'pt', 'color' => '#AA0000'])
        ->assertSessionHasNoErrors();

    expect($candidate->fresh()->displayColor())->toBe('#aa0000')
        ->and(ResultSynchronizer::version(6258))->toBe($version + 1);

    $this->actingAs($admin)
        ->put(route('admin.parties.update'), ['party' => 'PT', 'color' => null])
        ->assertSessionHasNoErrors();

    expect(PartyColor::query()->count())->toBe(0)
        ->and($candidate->fresh()->displayColor())->toBe('#cc0000');
});

test('guests cannot change the party colors', function () {
    $this->get(route('admin.parties.index'))->assertRedirect(route('login'));
    $this->put(route('admin.parties.update'), ['party' => 'PT', 'color' => '#000000'])->assertRedirect(route('login'));

    expect(PartyColor::query()->count())->toBe(0);
});
