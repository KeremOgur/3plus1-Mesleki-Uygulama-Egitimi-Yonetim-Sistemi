<?php

// Verify the persistent localhost dataset through real API and session HTTP requests.
// No fixtures, migrations, matching runs or preference writes are performed.
use App\Domain\{Eligibility, Records};
use App\Models\User;
use Database\Seeders\ProgramIsolationDemoSeeder;
use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Support\Facades\{Artisan, DB, Http, Storage};

require dirname(__DIR__).'/backend/vendor/autoload.php';
$app = require dirname(__DIR__).'/backend/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (!$app->environment('local', 'testing')) {
    throw new RuntimeException('Demo doğrulaması yalnız local/testing ortamında çalışır.');
}
$base = 'http://127.0.0.1:8088';
$passed = 0;
$failed = 0;
function check(string $name, bool $condition): void
{
    global $passed, $failed;
    $condition ? $passed++ : $failed++;
    echo ($condition ? 'PASS ' : 'FAIL ').$name.PHP_EOL;
}
function ids(array $items): array
{
    $ids = array_column($items, 'id');
    sort($ids);
    return $ids;
}
function snapshot(): array
{
    $result = [];
    foreach (array_merge(array_keys(config('domain')), ['users', 'record_revisions', 'audit_events']) as $type) {
        $result[$type] = hash('sha256', DB::table($type)->orderBy('id')->get()->toJson());
    }
    $result['credentials'] = hash('sha256', Storage::disk('local')->get('demo-credentials.json'));
    return $result;
}

try {
    $disk = Storage::disk('local');
    $credentials = json_decode($disk->get('demo-credentials.json'), true, 512, JSON_THROW_ON_ERROR);
    if (in_array('--check-existing', $argv, true)) {
        $baseline = json_decode($disk->get('program-isolation-baseline.json'), true, 512, JSON_THROW_ON_ERROR);
        $unchanged = true;
        foreach ($baseline['records'] as $type => $records) {
            foreach (DB::table($type)->whereIn('id', array_keys($records))->get() as $row) {
                $unchanged = $unchanged && $records[$row->id] === hash('sha256', json_encode($row));
                unset($records[$row->id]);
            }
            $unchanged = $unchanged && !$records;
        }
        $hashes = array_map(fn ($row) => hash('sha256', json_encode($row)), $credentials['accounts']);
        check('Existing records and credential entries preserved', $unchanged && !array_diff($baseline['credentials'], $hashes));
    }
    if (in_array('--check-idempotence', $argv, true)) {
        $before = snapshot();
        Artisan::call('db:seed', ['--class' => ProgramIsolationDemoSeeder::class, '--no-interaction' => true]);
        check('Second seed preserves all IDs, counts, revisions, users and credentials', $before === snapshot());
    }
    $environment = app()->environment();
    $rejected = false;
    try {
        app()->instance('env', 'production');
        (new ProgramIsolationDemoSeeder)->run();
    } catch (RuntimeException $e) {
        $rejected = str_contains($e->getMessage(), 'local/testing');
    } finally {
        app()->instance('env', $environment);
    }
    check('Production seed rejected before database/file access', $rejected);

    $graphs = [];
    foreach (ProgramIsolationDemoSeeder::PROGRAMS as $key => $definition) {
        $user = User::where('email', "ogrenci.$key@demo.mue.invalid")->firstOrFail();
        $program = Records::query('programs')->where('code', "DEMO-ISO-$key")->firstOrFail();
        $student = Records::query('students')->where('user_id', $user->id)->sole();
        $role = Records::query('role_assignments')->where('user_id', $user->id)->sole();
        $application = Records::query('applications')->where('student_id', $student->id)->sole();
        $offers = Records::query('offers')->where('program_id', $program->id)->orderBy('id')->get();
        $term = Records::get('academic_terms', $application->term_id);
        $policy = Records::query('matching_policies')->where('program_id', $program->id)->where('term_id', $term->id)->sole();
        $calendar = Records::query('term_programs')->where('program_id', $program->id)->where('term_id', $term->id)->sole();
        $companyIds = [];
        $siteIds = [];
        $ready = $user->active && $user->allowedEnvironment() && $program->name === $definition['program']
            && $student->program_id === $program->id && $student->term_id === $term->id && $student->state === 'aktif' && $student->academic_data['status'] === 'aktif'
            && $role->state === 'aktif' && $role->role === 'ogrenci' && $role->student_id === $student->id
            && $role->institution_id === $student->institution_id && $role->program_id === $program->id && $role->term_id === $term->id
            && now()->greaterThanOrEqualTo($role->valid_from) && (!$role->valid_until || now()->lessThanOrEqualTo($role->valid_until))
            && $application->state === 'uygun' && $application->eligibility === 'uygun' && $application->gpa_snapshot !== null
            && $application->program_id === $program->id && $application->institution_id === $student->institution_id
            && $offers->count() === 2 && $policy->state === 'onaylandi' && $calendar->semester === 4 && !$calendar->grouping_enabled;
        foreach ($offers as $offer) {
            $company = Records::get('companies', $offer->company_id);
            $site = Records::get('company_sites', $offer->site_id);
            $protocol = Records::get('protocols', $offer->protocol_id);
            $trainer = Records::get('trainer_qualifications', $offer->trainer_id);
            $assessment = Records::get('company_assessments', $offer->assessment_id);
            $companyIds[] = $company->id;
            $siteIds[] = $site->id;
            $ready = $ready && $offer->term_id === $term->id && $offer->state === 'ilan_edildi' && $offer->capacity > 0
                && $company->institution_id === $program->institution_id && in_array($company->legal_name, $definition['companies'], true)
                && $site->company_id === $company->id && $site->total_capacity >= $offer->capacity
                && $protocol->company_id === $company->id && $trainer->company_id === $company->id
                && $assessment->company_id === $company->id && $assessment->trainer_id === $trainer->id
                && app(Eligibility::class)->offerReasons($offer) === [];
            foreach ([$protocol->signed_document_id, $trainer->qualification_document_id, $trainer->ohs_document_id] as $id) {
                $document = Records::get('documents', $id);
                $ready = $ready && $disk->exists($document->object_key)
                    && hash('sha256', $disk->get($document->object_key)) === $document->sha256;
            }
        }
        check("$key: persistent records, scopes, capacity and OfferReasons", $ready && count(array_unique($companyIds)) === 2 && count(array_unique($siteIds)) === 2);
        $account = collect($credentials['accounts'])->firstWhere('email', $user->email);
        $graphs[$key] = compact('user', 'program', 'student', 'role', 'application', 'offers', 'term', 'policy', 'calendar', 'companyIds', 'account');
    }
    foreach ($graphs as $key => $g) {
        $token = null;
        $web = null;
        try {
            $login = Http::acceptJson()->timeout(30)->post("$base/api/v1/auth/login", ['email' => $g['user']->email, 'password' => $g['account']['password']]);
            check("$key: API credential login and unique scoped assignment", $login->ok() && count($login->json('assignments') ?? []) === 1 && $login->json('assignments.0.id') === $g['role']->id);
            $token = $login->json('token');
            if (!$token) {
                throw new RuntimeException("$key: API login failed (HTTP ".$login->status().').');
            }
            $api = Http::acceptJson()->withToken($token)->withHeaders(['X-Assignment-Id' => $g['role']->id])->timeout(30);
            $own = ids($g['offers']->toArray());
            $foreign = [];
            foreach ($graphs as $otherKey => $other) {
                if ($key !== $otherKey) {
                    $foreign = array_merge($foreign, $other['offers']->all());
                }
            }
            $list = $api->get("$base/api/v1/resources/offers");
            check("$key: own program offer list", $list->ok() && ids($list->json('items') ?? []) === $own);
            $hidden = !array_intersect(array_map(fn ($o) => $o->id, $foreign), ids($list->json('items') ?? []));
            foreach ($graphs as $otherKey => $other) {
                if ($otherKey === $key) continue;
                $filtered = $api->get("$base/api/v1/resources/offers", ['program_id' => $other['program']->id]);
                $hidden = $hidden && $filtered->ok() && $filtered->json('total') === 0 && $filtered->json('items') === [];
            }
            check("$key: both foreign programs hidden even with explicit filter", $hidden);
            $details = true;
            foreach ($g['offers'] as $offer) {
                $details = $details && $api->get("$base/api/v1/resources/offers/$offer->id")->ok();
            }
            check("$key: both own offer details accessible", $details);
            $denied = true;
            foreach ($foreign as $offer) {
                $denied = $denied && $api->get("$base/api/v1/resources/offers/$offer->id")->status() === 403;
            }
            check("$key: all four foreign offer details denied", $denied);
            $companies = $api->get("$base/api/v1/resources/companies");
            $expectedCompanies = $g['companyIds'];
            sort($expectedCompanies);
            check("$key: company list isolated", $companies->ok() && ids($companies->json('items') ?? []) === $expectedCompanies);
            $jar = new CookieJar;
            $web = Http::withOptions(['cookies' => $jar, 'allow_redirects' => false])->timeout(30);
            $form = $web->get("$base/giris");
            preg_match('/name="_token" value="([^"]+)"/', $form->body(), $match);
            $sessionLogin = $web->asForm()->post("$base/giris", ['_token' => html_entity_decode($match[1] ?? ''), 'email' => $g['user']->email, 'password' => $g['account']['password']]);
            $pages = $sessionLogin->status() === 302 && str_ends_with($sessionLogin->header('Location'), '/panel');
            foreach (['/panel/offers', '/panel/preferences'] as $page) {
                $pages = $pages && $web->get($base.$page)->ok();
            }
            check("$key: browser login and both manual portal URLs", $pages);
            $portal = Http::acceptJson()->withOptions(['cookies' => $jar, 'allow_redirects' => false])->timeout(30);
            $apps = $portal->get("$base/portal-api/v1/resources/applications");
            $offers = $portal->get("$base/portal-api/v1/resources/offers?state=ilan_edildi&limit=100");
            $prefs = $portal->get("$base/portal-api/v1/resources/preferences", ['application_id' => $g['application']->id, 'limit' => 100]);
            $resources = $apps->ok() && ids($apps->json('items') ?? []) === [$g['application']->id]
                && $offers->ok() && ids($offers->json('items') ?? []) === $own && $prefs->ok();
            $resources = $resources && $portal->get("$base/portal-api/v1/resources/students/{$g['student']->id}")->ok();
            foreach ($prefs->json('items') ?? [] as $preference) {
                $resources = $resources && in_array($preference['offer_id'], $own, true) && $preference['program_id'] === $g['program']->id;
            }
            foreach ($g['offers'] as $offer) {
                foreach (['companies' => $offer->company_id, 'company_sites' => $offer->site_id] as $resource => $id) {
                    $resources = $resources && $portal->get("$base/portal-api/v1/resources/$resource/$id")->ok();
                }
            }
            check("$key: preference screen API sources restricted to own program", $resources);
            $policy = $portal->get("$base/portal-api/v1/applications/{$g['application']->id}/preference-policy");
            check("$key: approved preference policy and open calendar", $policy->ok() && $policy->json('policy.id') === $g['policy']->id
                && $policy->json('calendar.state') === 'acik' && now()->greaterThanOrEqualTo($policy->json('calendar.preference_opens_at'))
                && now()->lessThan($policy->json('calendar.preference_deadline')));
            $page = $web->get("$base/panel/preferences");
            preg_match('/name="csrf-token" content="([^"]+)"/', $page->body(), $csrf);
            if (isset($csrf[1])) {
                $web->asForm()->post("$base/cikis", ['_token' => html_entity_decode($csrf[1])]);
            }
        } finally {
            if ($token) {
                Http::acceptJson()->withToken($token)->post("$base/api/v1/auth/logout");
            }
        }
    }
} catch (Throwable $e) {
    $failed++;
    // Do not dump requests, response bodies or credentials.
    echo 'FAIL '.$e->getMessage().PHP_EOL;
}
echo "TOTAL: $passed passed, $failed failed".PHP_EOL;
exit($failed ? 1 : 0);
