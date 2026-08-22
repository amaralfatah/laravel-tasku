<?php

use App\Models\OrgUnit;
use App\Models\Project;
use App\Models\Workspace;
use App\Services\OrgStructureImporter;
use Illuminate\Support\Facades\Http;

/**
 * The SAP bridge is never hit for real here: `Http::fake()` stands in for the
 * `ZA_HRIS_ORGZ` CDS view, which in production is a ~6 MB flat edge list.
 */

/**
 * One edge of the CDS view.
 *
 * @return array<string, string>
 */
function cdsEdge(string $parentId, string $parentName, string $childId, string $childName): array
{
    return [
        'tanggal_mulai' => '20170101',
        'tanggal_selesai' => '99991231',
        'o1id' => $parentId,
        'o1text' => $parentName,
        'relasi' => 'B002 Top down, reports to',
        'o2id' => $childId,
        'o2text' => $childName,
        'send_date' => '2026-08-22',
        'send_time' => '15:09:17',
    ];
}

/**
 * The holding with two operating companies under it, plus one fragment SAP
 * sends no parent edge for — the shape the real view has.
 *
 * @return array<int, array<string, string>>
 */
function cdsSample(): array
{
    return [
        cdsEdge('10000000', 'PT PERKEBUNAN NUSANTARA I', '12100000', 'PT SUPPCO'),
        cdsEdge('12100000', 'PT SUPPCO', '12101194', 'REGIONAL 3'),
        cdsEdge('12101194', 'REGIONAL 3', '12101195', 'OPERATION'),
        cdsEdge('10000000', 'PT PERKEBUNAN NUSANTARA I', '11100974', 'PG SEM - UR. SIPIL DAN TRAKSI'),
        cdsEdge('11900221', 'KBN SEI MERANTI', '11900222', 'AFD I SEI MERANTI'),
    ];
}

function fakeCds(array $rows): void
{
    Http::fake([
        '*get_cds.php*' => Http::response($rows),
    ]);

    config([
        'services.sap.cds_url' => 'http://sap.test/dev/get_cds.php',
        'services.sap.user' => 'HO000401',
        'services.sap.pass' => 'secret',
    ]);
}

test('the whole view resolves into a depth-aware forest when nothing is trimmed', function () {
    $forest = app(OrgStructureImporter::class)->forest(cdsSample(), holdingId: null);

    expect($forest['nodes'])->toHaveCount(7)
        ->and($forest['roots'])->toBe(2)
        ->and($forest['max_depth'])->toBe(3)
        ->and($forest['dropped'])->toBe(0)
        ->and($forest['nodes']['10000000']['depth'])->toBe(0)
        ->and($forest['nodes']['12101195']['depth'])->toBe(3)
        ->and($forest['nodes']['12101195']['parent'])->toBe('12101194')
        ->and($forest['nodes']['11900221']['parent'])->toBeNull();
});

test('by default the holding is dropped and its children become the roots', function () {
    $forest = app(OrgStructureImporter::class)->forest(cdsSample());

    // PT SUPPCO and PG SEM rise to the top; the holding and the parentless
    // KBN SEI MERANTI fragment are gone.
    expect($forest['nodes'])->toHaveCount(4)
        ->and($forest['dropped'])->toBe(3)
        ->and($forest['roots'])->toBe(2)
        ->and($forest['max_depth'])->toBe(2)
        ->and($forest['nodes'])->not->toHaveKey('10000000')
        ->and($forest['nodes'])->not->toHaveKey('11900221')
        ->and($forest['nodes']['12100000']['parent'])->toBeNull()
        ->and($forest['nodes']['12100000']['depth'])->toBe(0)
        ->and($forest['nodes']['11100974']['parent'])->toBeNull()
        ->and($forest['nodes']['12101195']['depth'])->toBe(2)
        ->and($forest['nodes']['12101195']['parent'])->toBe('12101194');
});

test('an unknown holding id stops the import rather than emptying the tree', function () {
    app(OrgStructureImporter::class)->forest(cdsSample(), holdingId: '99999999');
})->throws(RuntimeException::class, '99999999');

test('rows carrying another relation are skipped, not imported', function () {
    $rows = cdsSample();
    $rows[] = ['o1id' => '10000000', 'o1text' => 'A', 'relasi' => 'A002 Reports to', 'o2id' => '99999999', 'o2text' => 'B'];

    $forest = app(OrgStructureImporter::class)->forest($rows);

    expect($forest['skipped'])->toBe(1)
        ->and($forest['nodes'])->not->toHaveKey('99999999');
});

test('a loop in the export is cut instead of hanging the import', function () {
    $forest = app(OrgStructureImporter::class)->forest([
        cdsEdge('1', 'Satu', '2', 'Dua'),
        cdsEdge('2', 'Dua', '3', 'Tiga'),
        cdsEdge('3', 'Tiga', '1', 'Satu'),
    ], holdingId: null);

    expect($forest['cycles'])->toBeGreaterThan(0)
        ->and($forest['roots'])->toBe(1)
        ->and($forest['nodes'])->toHaveCount(3);
});

test('the command writes the whole forest with correct paths and depths', function () {
    fakeCds(cdsSample());

    $workspace = Workspace::factory()->create();

    $this->artisan('tasku:import-org-structure', ['--workspace' => $workspace->id])
        ->assertSuccessful();

    $units = OrgUnit::withoutGlobalScopes()->where('workspace_id', $workspace->id)->get()->keyBy('external_id');

    expect($units)->toHaveCount(4)
        ->and($units)->not->toHaveKey('10000000')
        ->and($units)->not->toHaveKey('11900221');

    $suppco = $units['12100000'];
    $regional = $units['12101194'];
    $operation = $units['12101195'];

    expect($suppco->parent_id)->toBeNull()
        ->and($suppco->depth)->toBe(0)
        ->and($suppco->type)->toBe('company')
        ->and($suppco->path)->toBe('/'.$suppco->id.'/')
        ->and($regional->parent_id)->toBe($suppco->id)
        ->and($regional->path)->toBe('/'.$suppco->id.'/'.$regional->id.'/')
        ->and($operation->depth)->toBe(2)
        ->and($operation->type)->toBe('division')
        ->and($operation->path)->toBe('/'.$suppco->id.'/'.$regional->id.'/'.$operation->id.'/')
        ->and($units['11100974']->parent_id)->toBeNull();
});

test('prune removes units a previous import created that SAP no longer sends', function () {
    Http::fake([
        '*get_cds.php*' => Http::sequence()
            // First run keeps the whole view, so the fragments land too.
            ->push(cdsSample())
            ->push(cdsSample()),
    ]);

    config([
        'services.sap.cds_url' => 'http://sap.test/dev/get_cds.php',
        'services.sap.user' => 'HO000401',
        'services.sap.pass' => 'secret',
    ]);

    $workspace = Workspace::factory()->create();
    $units = fn () => OrgUnit::withoutGlobalScopes()->where('workspace_id', $workspace->id);

    $this->artisan('tasku:import-org-structure', [
        '--workspace' => $workspace->id,
        '--all' => true,
    ])->assertSuccessful();

    expect($units()->count())->toBe(7);

    $this->artisan('tasku:import-org-structure', [
        '--workspace' => $workspace->id,
        '--prune' => true,
    ])->assertSuccessful();

    expect($units()->count())->toBe(4)
        ->and($units()->whereNull('parent_id')->pluck('external_id')->sort()->values()->all())
        ->toBe(['11100974', '12100000']);
});

test('prune keeps a retired unit that still holds a project', function () {
    Http::fake([
        '*get_cds.php*' => Http::sequence()
            ->push(cdsSample())
            ->push(cdsSample()),
    ]);

    config([
        'services.sap.cds_url' => 'http://sap.test/dev/get_cds.php',
        'services.sap.user' => 'HO000401',
        'services.sap.pass' => 'secret',
    ]);

    $workspace = Workspace::factory()->create();

    $this->artisan('tasku:import-org-structure', [
        '--workspace' => $workspace->id,
        '--all' => true,
    ])->assertSuccessful();

    $fragment = OrgUnit::withoutGlobalScopes()
        ->where('workspace_id', $workspace->id)
        ->where('external_id', '11900222')
        ->firstOrFail();

    Project::factory()->in($fragment)->create();

    $this->artisan('tasku:import-org-structure', [
        '--workspace' => $workspace->id,
        '--prune' => true,
    ])->assertSuccessful();

    // The unit holding the project survives, and so does its parent, because
    // deleting it would orphan a live subtree.
    expect(OrgUnit::withoutGlobalScopes()->where('workspace_id', $workspace->id)->whereKey($fragment->id)->exists())
        ->toBeTrue();
});

test('a second import updates in place instead of duplicating the tree', function () {
    $renamed = cdsSample();
    $renamed[0]['o2text'] = 'PT SUPPCO NUSANTARA';

    // One stub with two queued responses: the second run sees the rename.
    Http::fake([
        '*get_cds.php*' => Http::sequence()
            ->push(cdsSample())
            ->push($renamed),
    ]);

    config([
        'services.sap.cds_url' => 'http://sap.test/dev/get_cds.php',
        'services.sap.user' => 'HO000401',
        'services.sap.pass' => 'secret',
    ]);

    $workspace = Workspace::factory()->create();

    $this->artisan('tasku:import-org-structure', ['--workspace' => $workspace->id])->assertSuccessful();

    $before = OrgUnit::withoutGlobalScopes()->where('workspace_id', $workspace->id)->pluck('id', 'external_id');

    $this->artisan('tasku:import-org-structure', ['--workspace' => $workspace->id])->assertSuccessful();

    $after = OrgUnit::withoutGlobalScopes()->where('workspace_id', $workspace->id)->get()->keyBy('external_id');

    expect($after)->toHaveCount(4)
        ->and($after['12100000']->id)->toBe($before['12100000'])
        ->and($after['12100000']->name)->toBe('PT SUPPCO NUSANTARA');
});

test('units created by hand are left alone by the import', function () {
    fakeCds(cdsSample());

    $workspace = Workspace::factory()->create();
    $manual = OrgUnit::factory()->for($workspace)->create(['name' => 'Divisi Internal']);

    $this->artisan('tasku:import-org-structure', ['--workspace' => $workspace->id])->assertSuccessful();

    $manual->refresh();

    expect($manual->external_id)->toBeNull()
        ->and($manual->name)->toBe('Divisi Internal')
        ->and($manual->path)->toBe('/'.$manual->id.'/');
});

test('a dry run reports the shape without writing anything', function () {
    fakeCds(cdsSample());

    $workspace = Workspace::factory()->create();

    $this->artisan('tasku:import-org-structure', ['--workspace' => $workspace->id, '--dry-run' => true])
        ->expectsOutputToContain('4 unit dipakai')
        ->assertSuccessful();

    expect(OrgUnit::withoutGlobalScopes()->where('workspace_id', $workspace->id)->count())->toBe(0);
});

test('the import stops when the bridge answers with an error', function () {
    Http::fake(['*get_cds.php*' => Http::response('nope', 500)]);

    config([
        'services.sap.cds_url' => 'http://sap.test/dev/get_cds.php',
        'services.sap.user' => 'HO000401',
        'services.sap.pass' => 'secret',
    ]);

    $workspace = Workspace::factory()->create();

    $this->artisan('tasku:import-org-structure', ['--workspace' => $workspace->id])->assertFailed();

    expect(OrgUnit::withoutGlobalScopes()->where('workspace_id', $workspace->id)->count())->toBe(0);
});

test('the import stops when SAP credentials are missing', function () {
    config([
        'services.sap.cds_url' => 'http://sap.test/dev/get_cds.php',
        'services.sap.user' => null,
        'services.sap.pass' => null,
    ]);

    $workspace = Workspace::factory()->create();

    $this->artisan('tasku:import-org-structure', ['--workspace' => $workspace->id])->assertFailed();
});
