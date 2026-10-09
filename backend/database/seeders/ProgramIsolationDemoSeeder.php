<?php

namespace Database\Seeders;

use App\Domain\{Eligibility, Records};
use App\Models\DomainRecord;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\{DB, Hash, Storage, Validator};
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

class ProgramIsolationDemoSeeder extends Seeder
{
    public const PROGRAMS = [
        'bilgisayar' => ['department' => 'DEMO Bilişim Bölümü', 'program' => 'DEMO Bilgisayar Programcılığı', 'companies' => ['DEMO Yazılım Teknolojileri A.Ş.', 'DEMO Veri Sistemleri Ltd.']],
        'elektrik' => ['department' => 'DEMO Elektrik Bölümü', 'program' => 'DEMO Elektrik Programı', 'companies' => ['DEMO Elektrik Otomasyon A.Ş.', 'DEMO Enerji Sistemleri Ltd.']],
        'makine' => ['department' => 'DEMO Makine Bölümü', 'program' => 'DEMO Makine Programı', 'companies' => ['DEMO Makine Üretim A.Ş.', 'DEMO CNC Teknolojileri Ltd.']],
    ];

    private function record(string $type, array $identity, array $data): DomainRecord
    {
        // Existing records, including manually entered preferences, are never reset.
        if ($existing = Records::query($type)->where($identity)->first()) {
            return $existing;
        }
        $data = array_merge($identity, $data);
        $rules = array_map(fn ($field) => $field['rules'], config("domain.$type.fields"));
        Validator::make($data, $rules)->validate();
        return Records::create($type, $data);
    }

    private function studentUser(string $key, array &$credentials): User
    {
        $email = "ogrenci.$key@demo.mue.invalid";
        $subject = "mue-demo:program-isolation:$key";
        $account = collect($credentials['accounts'])->firstWhere('email', $email);
        $user = User::where('email', $email)->first();
        if ($user) {
            if ($user->external_subject !== $subject || !$account || !Hash::check($account['password'] ?? '', $user->password)) {
                throw new \RuntimeException("Demo hesabı/credential çakışması: $email. Mevcut parola değiştirilmedi.");
            }
            return $user;
        }
        if ($account) {
            throw new \RuntimeException("Credential dosyasında sahipsiz demo hesabı var: $email.");
        }
        $password = Str::password(32);
        $user = User::create(['name' => 'DEMO '.self::PROGRAMS[$key]['program'].' Öğrencisi', 'email' => $email, 'password' => $password, 'active' => true]);
        $user->forceFill(['external_subject' => $subject])->save();
        $credentials['accounts'][] = ['role' => 'ogrenci', 'email' => $email, 'password' => $password, 'program' => self::PROGRAMS[$key]['program']];
        return $user;
    }

    private function document(array $scope, string $targetType, string $targetId, string $key): DomainRecord
    {
        // Synthetic metadata; no production document, signature or scanner is used.
        $objectKey = "demo/program-isolation/$key.txt";
        $bytes = "DEMO — sentetik belge; resmî imza, kurum kararı veya gerçek yeterlilik belgesi değildir.\n$key\n";
        $disk = Storage::disk('local');
        if (!$disk->exists($objectKey) && !$disk->put($objectKey, $bytes, 'private')) {
            throw new \RuntimeException('Sentetik demo belgesi kaydedilemedi.');
        }
        return $this->record('documents', $scope + ['object_key' => $objectKey], [
            'target_type' => $targetType, 'target_id' => $targetId, 'original_name' => "DEMO-$key.txt",
            'mime' => 'text/plain', 'size_bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes),
            'scan_status' => 'temiz', 'classification' => 'kurum_ici', 'retention_start_event' => 'sentetik_demo',
            'retention_until' => now()->addYears(5)->toDateString(), 'legal_hold' => false, 'state' => 'kayitli',
        ]);
    }

    public function run(): void
    {
        // Demo login already supports local/testing only. Never loosen that core rule.
        if (!app()->environment('local', 'testing')) {
            throw new \RuntimeException('Program izolasyon demosu yalnız local/testing ortamında çalışır.');
        }
        $disk = Storage::disk('local');
        DB::transaction(function () use ($disk) {
            DB::select('SELECT pg_advisory_xact_lock(?)', [2026100901]);
            Records::auditContext(null, null, 'Kalıcı sentetik program izolasyonu demosu; resmî akademik karar değildir.');
            $original = $disk->exists('demo-credentials.json') ? $disk->get('demo-credentials.json') : null;
            $credentials = $original === null ? ['notice' => 'Yalnız local/testing demo ortamı.', 'accounts' => []] : json_decode($original, true, 512, JSON_THROW_ON_ERROR);
            if (!isset($credentials['accounts']) || !is_array($credentials['accounts'])) {
                throw new \RuntimeException('Mevcut demo credential biçimi geçersiz; dosya değiştirilmedi.');
            }
            $institution = $this->record('institutions', ['code' => 'DEMO-MUE'], ['name' => 'DEMO — Sentetik MYO']);
            $scope = ['institution_id' => $institution->id];
            $existingProgram = Records::query('programs')->where($scope)->where('code', 'DEMO-ISO-bilgisayar')->first();
            $existingOffer = $existingProgram ? Records::query('offers')->where('program_id', $existingProgram->id)->first() : null;
            $term = $existingOffer ? Records::get('academic_terms', $existingOffer->term_id) : Records::query('academic_terms')->where($scope)->where('code', 'like', 'DEMO-%')
                ->where('state', 'acik')->where('preference_opens_at', '<=', now())->where('preference_deadline', '>', now())
                ->orderByDesc('starts_on')->first();
            if (!$term) {
                $start = today()->startOfWeek();
                $end = $start->copy()->addWeeks(14)->addDays(4);
                $term = $this->record('academic_terms', $scope + ['code' => 'DEMO-IZOLASYON-'.today()->format('Y-m-d')], [
                    'starts_on' => $start->toDateString(), 'ends_on' => $end->toDateString(),
                    'application_deadline' => now()->addWeek(), 'preference_opens_at' => now()->subDay(), 'preference_deadline' => now()->addWeeks(2),
                    'academic_year_end' => $end->copy()->addMonths(5)->toDateString(), 'next_year_starts_on' => $end->copy()->addMonths(8)->toDateString(),
                    'pass_threshold' => 60, 'letter_grade_rules' => [['minimum' => 60, 'letter' => 'CC'], ['minimum' => 0, 'letter' => 'FF']],
                    'holiday_dates' => [], 'state' => 'acik',
                ]);
            }
            foreach (self::PROGRAMS as $key => $definition) {
                $department = $this->record('departments', $scope + ['code' => "DEMO-ISO-$key"], ['name' => $definition['department']]);
                $program = $this->record('programs', $scope + ['code' => "DEMO-ISO-$key"], [
                    'department_id' => $department->id, 'name' => $definition['program'], 'active_student_count' => 1,
                    'ects' => 30, 'workplace_hours' => 600, 'independent_hours' => 300, 'weeks' => 15,
                ]);
                $programScope = $scope + ['program_id' => $program->id];
                $termScope = $programScope + ['term_id' => $term->id];
                $user = $this->studentUser($key, $credentials);
                $student = $this->record('students', ['user_id' => $user->id], $termScope + [
                    'student_no' => "DEMO-ISO-$key", 'gpa' => 3.2, 'gpa_scale' => 4, 'graduation_exception' => false,
                    'academic_source' => 'DEMO — sentetik akademik kayıt; OBS kullanılmadı', 'academic_fetched_at' => now(),
                    'academic_data' => ['demo' => true, 'semester' => 4, 'status' => 'aktif'], 'state' => 'aktif',
                ]);
                $studentScope = $termScope + ['student_id' => $student->id];
                $this->record('role_assignments', ['user_id' => $user->id, 'role' => 'ogrenci'], $studentScope + [
                    'valid_from' => now()->subDay(), 'state' => 'aktif',
                ]);
                $this->record('applications', ['student_id' => $student->id, 'term_id' => $term->id], $programScope + [
                    'district' => 'DEMO Merkez', 'transport_declaration' => 'DEMO — ulaşım beyanı tercih kaydı sırasında girilecektir.',
                    'course_conditions' => ['demo' => true, 'academic_status' => 'aktif'], 'gpa_snapshot' => 3.2,
                    'gpa_source' => 'DEMO — sentetik akademik kayıt', 'gpa_recorded_at' => now(), 'eligibility' => 'uygun',
                    'eligibility_reason' => 'DEMO — sentetik uygun başvuru; resmî karar değildir.', 'state' => 'uygun',
                ]);
                $boardDoc = $this->document($programScope, 'programs', $program->id, "$key-politika");
                $this->record('term_programs', $termScope, [
                    'active_student_count' => 1, 'semester' => 4, 'grouping_enabled' => false, 'board_document_id' => $boardDoc->id,
                    'cohort_rule' => 'DEMO — yalnız sentetik program öğrencisi.',
                    'preference_opens_at' => $term->preference_opens_at, 'preference_deadline' => $term->preference_deadline,
                ]);
                $this->record('matching_policies', $termScope + ['revision' => 1], [
                    'preference_weight' => .75, 'transport_weight' => .25, 'max_preferences' => 2,
                    'transport_rules' => [['max_minutes' => 30, 'score' => 100], ['max_minutes' => 60, 'score' => 70], ['score' => 40]],
                    'seed' => "demo-program-isolation-$key", 'time_limit_seconds' => 120, 'decision_document_id' => $boardDoc->id, 'state' => 'onaylandi',
                ]);
                foreach ($definition['companies'] as $index => $name) {
                    $tag = "$key-".($index + 1);
                    $company = $this->record('companies', $scope + ['tax_no' => "DEMO-$tag"], [
                        'legal_name' => $name, 'organization_type' => 'ozel', 'sector' => $definition['program'], 'personnel_count' => 20,
                        'contact_name' => "DEMO $tag Yetkilisi", 'contact_title' => 'DEMO işletme yetkilisi', 'email' => "isletme.$tag@demo.mue.invalid",
                        'phone' => '000 000 00 00', 'address' => 'DEMO — sentetik adres; gerçek işyeri değildir.', 'state' => 'aktif',
                    ]);
                    $companyScope = $scope + ['company_id' => $company->id];
                    $site = $this->record('company_sites', $companyScope + ['name' => "DEMO $tag Şubesi"], [
                        'province' => 'DEMO İl', 'district' => 'DEMO Merkez', 'address' => 'DEMO — sentetik eğitim şubesi.',
                        'shuttle' => true, 'meal' => true, 'capacity_type' => 'ortak', 'total_capacity' => 5, 'hazard_class' => 'az_tehlikeli',
                    ]);
                    $email = "egitici.$tag@demo.mue.invalid";
                    $trainerUser = User::where('email', $email)->first();
                    if ($trainerUser && $trainerUser->external_subject !== "mue-demo:program-isolation:trainer:$tag") {
                        throw new \RuntimeException("Demo eğitici hesabı çakışması: $email.");
                    }
                    if (!$trainerUser) {
                        $trainerUser = User::create(['name' => "DEMO $tag Eğiticisi", 'email' => $email, 'password' => Str::password(32), 'active' => false]);
                        $trainerUser->forceFill(['external_subject' => "mue-demo:program-isolation:trainer:$tag"])->save();
                    }
                    $qualificationDoc = $this->document($companyScope, 'companies', $company->id, "$tag-yeterlilik");
                    $ohsDoc = $this->document($companyScope, 'companies', $company->id, "$tag-isg");
                    $protocolDoc = $this->document($companyScope, 'companies', $company->id, "$tag-protokol");
                    $trainer = $this->record('trainer_qualifications', $companyScope + ['site_id' => $site->id, 'user_id' => $trainerUser->id], [
                        'field' => $definition['program'], 'degree' => 'lisans', 'experience_years' => 5,
                        'qualification_document_id' => $qualificationDoc->id, 'ohs_document_id' => $ohsDoc->id, 'certificates' => [],
                        'employment_started_on' => $term->starts_on, 'duty_title' => 'DEMO mesleki eğitici', 'unit' => "DEMO $tag Eğitim Birimi",
                        'backup_trainer' => false, 'declaration_accepted' => true, 'state' => 'onaylandi',
                        'education_records' => [['institution' => 'DEMO Eğitim Kurumu', 'field' => $definition['program'], 'degree' => 'lisans']],
                        'experience_records' => [['institution' => $name, 'duty' => 'DEMO eğitici', 'started_on' => today()->subYears(5)->toDateString(), 'duration' => '5 yıl (sentetik)']],
                    ]);
                    $protocol = $this->record('protocols', $companyScope + ['number' => "DEMO-ISO-$tag"], [
                        'signed_document_id' => $protocolDoc->id, 'signed_on' => $term->starts_on, 'valid_from' => $term->starts_on,
                        'valid_until' => $term->ends_on, 'ip_rights_agreement' => 'DEMO — sentetik protokol metadata kaydı.', 'state' => 'aktif',
                    ]);
                    $this->record('protocol_scopes', ['protocol_id' => $protocol->id, 'site_id' => $site->id, 'program_id' => $program->id], $companyScope);
                    $assessment = $this->record('company_assessments', $companyScope + ['site_id' => $site->id, 'program_id' => $program->id, 'trainer_id' => $trainer->id], [
                        'program_suitable' => true, 'technical_infrastructure' => true, 'qualified_trainer' => true,
                        'ohs_suitable' => true, 'physical_conditions' => true, 'activity_diversity' => true, 'onsite_inspected' => true,
                        'explanations' => ['demo' => 'Sentetik onaylı değerlendirme; gerçek yerinde inceleme değildir.'],
                        'valid_from' => $term->starts_on, 'valid_until' => $term->ends_on, 'state' => 'onaylandi',
                    ]);
                    $offer = $this->record('offers', $termScope + ['site_id' => $site->id], [
                        'company_id' => $company->id, 'trainer_id' => $trainer->id, 'protocol_id' => $protocol->id,
                        'assessment_id' => $assessment->id, 'capacity' => 5, 'interview_required' => false, 'state' => 'ilan_edildi',
                        'duties' => 'DEMO — '.$definition['program'].' alanında gözetimli sentetik eğitim görevleri.',
                        'transport_information' => 'DEMO — sentetik şube; ulaşım süresini tercih kaydında beyan edin.',
                    ]);
                    if ($reasons = app(Eligibility::class)->offerReasons($offer)) {
                        throw new \RuntimeException("Demo teklif uygun değil ($tag): ".implode(' ', $reasons));
                    }
                }
            }
            // Append accounts without altering existing entries; use an atomic private-file replacement.
            if ($original === null || $credentials !== json_decode($original, true, 512, JSON_THROW_ON_ERROR)) {
                $temporary = 'demo-credentials.'.Str::uuid().'.tmp';
                try {
                    if (!$disk->put($temporary, json_encode($credentials, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n", 'private')) {
                        throw new \RuntimeException('Demo credential dosyası güvenli biçimde yazılamadı.');
                    }
                    if (PHP_OS_FAMILY === 'Windows') {
                        $owner = getenv('USERDOMAIN').'\\'.getenv('USERNAME');
                        (new Process(['icacls', $disk->path($temporary), '/inheritance:r', '/grant:r', $owner.':(F)', '*S-1-5-18:(F)', '*S-1-5-32-544:(F)']))->mustRun();
                    } elseif (!chmod($disk->path($temporary), 0600)) {
                        throw new \RuntimeException('Demo credential dosyası izinleri sınırlandırılamadı.');
                    }
                    if (!rename($disk->path($temporary), $disk->path('demo-credentials.json'))) {
                        throw new \RuntimeException('Demo credential dosyası değiştirilemedi.');
                    }
                } finally {
                    $disk->delete($temporary);
                }
            }
        });
        $this->command?->info('Kalıcı program izolasyonu demosu hazır. Parolalar: storage/app/private/demo-credentials.json');
    }
}
