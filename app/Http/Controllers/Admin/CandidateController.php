<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Office;
use App\Enums\State;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateCandidateRequest;
use App\Models\Candidate;
use App\Models\Election;
use App\Models\Municipality;
use App\Services\Tse\ResultSynchronizer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CandidateController extends Controller
{
    /**
     * List the candidates of an election, office and place (state, and
     * municipality for municipal offices) with their configured colors.
     */
    public function index(Request $request): Response
    {
        $request->validate([
            'eleicao' => ['nullable', 'integer'],
            'cargo' => ['nullable', Rule::enum(Office::class)],
            'uf' => ['nullable', Rule::enum(State::class)],
            'municipio' => ['nullable', 'string', 'max:5'],
        ]);

        // First rounds with candidates: runoffs keep the candidates of theirs.
        $elections = Election::query()
            ->where('round', 1)
            ->whereIn('tse_code', Candidate::query()->select('election_code')->distinct())
            ->get()
            ->sort(fn (Election $a, Election $b): int => [$b->date->getTimestamp(), $a->type->order()] <=> [$a->date->getTimestamp(), $b->type->order()])
            ->values();
        $election = $elections->firstWhere('tse_code', $request->integer('eleicao')) ?? $elections->first();
        $offices = $election === null ? collect() : $election->offices;
        $office = $offices->contains($request->enum('cargo', Office::class)) ? $request->enum('cargo', Office::class) : ($offices->first() ?? Office::President);
        $state = $office->isNationwide() ? null : ($request->enum('uf', State::class) ?? State::SaoPaulo);

        $candidates = Candidate::query()
            ->where('election_code', $election?->tse_code)
            ->where('office', $office)
            ->where('state', $state);

        $municipalities = $office->isMunicipal()
            ? Municipality::query()
                ->whereIn('tse_code', (clone $candidates)->select('municipality')->distinct())
                ->orderBy('name')
                ->get(['tse_code', 'name'])
                ->map(fn (Municipality $municipality): array => ['value' => $municipality->tse_code, 'label' => $municipality->name])
            : collect();
        $municipality = $office->isMunicipal()
            ? ($municipalities->firstWhere('value', $request->string('municipio')->toString())['value'] ?? $municipalities->first()['value'] ?? null)
            : null;

        return Inertia::render('admin/candidates/index', [
            'election' => $election?->tse_code,
            'elections' => $elections->map(fn (Election $option): array => ['value' => (string) $option->tse_code, 'label' => $option->name]),
            'office' => $office->value,
            'offices' => $offices->map(fn (Office $option): array => ['value' => $option->value, 'label' => $option->label()])->values(),
            'state' => $state?->value,
            'states' => array_map(
                fn (State $option): array => ['value' => $option->value, 'label' => $option->label()],
                $office->isNationwide() ? [] : $office->states(),
            ),
            'municipality' => $municipality,
            'municipalities' => $municipalities,
            'candidates' => $candidates
                ->when($office->isMunicipal(), fn ($query) => $query->where('municipality', $municipality))
                ->orderBy('number')
                ->get()
                ->map(fn (Candidate $candidate): array => [
                    ...$candidate->toPublicArray(),
                    'tse_name' => $candidate->ballot_name,
                    'custom_name' => $candidate->custom_ballot_name,
                    'custom_color' => $candidate->color,
                    'party_color' => $candidate->partyColor(),
                    'has_custom_photo' => $candidate->custom_photo !== null,
                ]),
        ]);
    }

    /**
     * Correct a candidate: the name shown, the photo (sent here when the TSE
     * has none or is blocking) and the color. The TSE data stays as
     * published, and the cached results of its elections are invalidated,
     * so the public pages show the change right away.
     */
    public function update(UpdateCandidateRequest $request, Candidate $candidate): RedirectResponse
    {
        $name = trim($request->string('ballot_name')->toString());
        $disk = Storage::disk(Candidate::PHOTO_DISK);
        $photo = $candidate->custom_photo;

        if ($request->hasFile('photo') || $request->boolean('remove_photo')) {
            if ($photo !== null) {
                $disk->delete($photo);
            }

            $photo = null;
        }

        if ($request->hasFile('photo')) {
            $file = $request->file('photo');
            // A new name each time: browsers keep a photo for a day.
            $stored = $file instanceof UploadedFile
                ? $file->storeAs(Candidate::CUSTOM_PHOTO_DIRECTORY, "{$candidate->tse_sequence}-".now()->getTimestamp().'.'.$file->extension(), Candidate::PHOTO_DISK)
                : false;

            if ($stored === false) {
                return back()->with('error', 'Não foi possível salvar a foto no servidor. Confira as permissões da pasta de fotos.');
            }

            $photo = $stored;
        }

        $candidate->update([
            'color' => $request->filled('color') ? strtolower($request->string('color')->toString()) : null,
            // The TSE name again, or none: no correction.
            'custom_ballot_name' => $name === '' || $name === $candidate->ballot_name ? null : $name,
            'custom_photo' => $photo,
        ]);

        $election = $candidate->election;

        foreach (array_filter([$election?->tse_code, $election?->second_round_code]) as $electionCode) {
            ResultSynchronizer::invalidate($electionCode);
        }

        return back()->with('success', "{$candidate->displayName()} atualizado.");
    }
}
