<?php

namespace App\Console\Commands;

use App\Exceptions\ImportCollisionException;
use App\Models\BusinessSettings;
use App\Models\Professional;
use App\Models\Service;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Manual-only importer for the fictitious dados-teste-barber-booking fixture
 * pack. Never runs from boot/seed — see docs/desenvolvimento.md, "Importação
 * de dados de teste fictícios", for the full rationale and usage.
 */
#[Signature('import:test-data {--path=storage/app/test-data : Directory containing servicos.csv and profissionais.csv} {--dry-run : Validate and show the plan without writing anything} {--remove : Remove only the records previously created by this importer, per the manifest}')]
#[Description('Import the fictitious services/professionals test-data CSV pack (manual only, idempotent).')]
class ImportTestData extends Command
{
    private string $manifestPath = 'storage/app/test-data-import-manifest.json';

    public function handle(): int
    {
        $basePath = base_path($this->option('path'));
        $manifestPath = base_path($this->manifestPath);

        if ($this->option('remove')) {
            return $this->handleRemove($manifestPath);
        }

        $servicesCsv = $this->readCsv("{$basePath}/servicos.csv");
        $professionalsCsv = $this->readCsv("{$basePath}/profissionais.csv");

        if ($servicesCsv === null || $professionalsCsv === null) {
            return self::FAILURE;
        }

        $errors = [];
        $services = $this->validateServices($servicesCsv, $errors);
        $professionals = $this->validateProfessionals($professionalsCsv, array_column($services, null, 'test_code'), $errors);

        if ($errors !== []) {
            $this->error('Validação falhou — nada foi importado:');
            foreach ($errors as $error) {
                $this->line("  - {$error}");
            }

            return self::FAILURE;
        }

        $this->info('Validação OK.');
        $this->line('Previsto: '.count($services).' serviços, '.count($professionals).' profissionais.');

        $manifest = $this->loadManifest($manifestPath);
        $currentIdentity = $this->databaseIdentity();

        if ($manifest !== null && $manifest['database'] !== $currentIdentity) {
            $this->error(
                'O manifesto existente ('.$manifestPath.') foi criado para outro banco de destino '.
                '('.json_encode($manifest['database']).') e o alvo atual é '.json_encode($currentIdentity).'. '.
                'Abortando — confirme o banco correto antes de repetir a importação.'
            );

            return self::FAILURE;
        }

        $manifest ??= ['database' => $currentIdentity, 'services' => [], 'professionals' => []];

        if ($this->option('dry-run')) {
            $this->line('--dry-run: nenhuma escrita será feita.');
            $this->reportPlan($services, $professionals, $manifest);

            return self::SUCCESS;
        }

        try {
            $result = DB::transaction(function () use ($services, $professionals, $manifest) {
                // Lock the single business_settings row first, before any
                // other business read/write — see
                // docs/planejamento-barbearia-mvp.md section 5. No
                // per-professional lock is added.
                BusinessSettings::query()->lockForUpdate()->first();

                return $this->importAll($services, $professionals, $manifest);
            });
        } catch (ImportCollisionException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->saveManifest($manifestPath, $result['manifest']);

        $this->info('Importação concluída.');
        $this->line('Serviços criados: '.count($result['createdServices']));
        foreach ($result['createdServices'] as $code => $id) {
            $this->line("  - {$code} -> id {$id}");
        }
        $this->line('Profissionais criados: '.count($result['createdProfessionals']));
        foreach ($result['createdProfessionals'] as $code => $id) {
            $this->line("  - {$code} -> id {$id}");
        }
        if ($result['skipped'] !== []) {
            $this->line('Já importados anteriormente (ignorados nesta execução): '.implode(', ', $result['skipped']));
        }

        return self::SUCCESS;
    }

    /**
     * @return array<int, array<string, string>>|null
     */
    private function readCsv(string $path): ?array
    {
        if (! is_file($path)) {
            $this->error("Arquivo não encontrado: {$path}");

            return null;
        }

        $handle = fopen($path, 'r');
        $header = fgetcsv($handle);
        // Strip a UTF-8 BOM from the first header cell, if present (the
        // fixture pack is documented as "CSV UTF-8 com BOM").
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);

        $rows = [];
        while (($row = fgetcsv($handle)) !== false) {
            if ($row === [null] || $row === false) {
                continue;
            }
            $rows[] = array_combine($header, $row);
        }
        fclose($handle);

        return $rows;
    }

    /**
     * @param  array<int, array<string, string>>  $rows
     * @param  array<int, string>  $errors
     * @return array<int, array{test_code: string, name: string, description: ?string, duration_minutes: int, price: string, is_active: bool}>
     */
    private function validateServices(array $rows, array &$errors): array
    {
        $seenCodes = [];
        $services = [];

        foreach ($rows as $i => $row) {
            $line = $i + 2;
            $code = trim($row['test_code'] ?? '');

            if ($code === '') {
                $errors[] = "servicos.csv linha {$line}: test_code ausente.";

                continue;
            }
            if (isset($seenCodes[$code])) {
                $errors[] = "servicos.csv linha {$line}: test_code \"{$code}\" duplicado.";

                continue;
            }
            $seenCodes[$code] = true;

            $name = trim($row['name'] ?? '');
            if ($name === '') {
                $errors[] = "servicos.csv {$code}: name ausente.";
            }

            $duration = $row['duration_minutes'] ?? '';
            if (! preg_match('/^\d+$/', (string) $duration) || (int) $duration < 1 || (int) $duration > 1440) {
                $errors[] = "servicos.csv {$code}: duration_minutes inválido (\"{$duration}\").";
            }

            $price = trim($row['price'] ?? '');
            if (! preg_match('/^\d{1,8}(\.\d{1,2})?$/', $price)) {
                $errors[] = "servicos.csv {$code}: price inválido (\"{$price}\").";
            }

            $isActiveRaw = trim($row['is_active'] ?? '');
            if (! in_array($isActiveRaw, ['0', '1'], true)) {
                $errors[] = "servicos.csv {$code}: is_active deve ser 0 ou 1 (\"{$isActiveRaw}\").";
            }

            $services[] = [
                'test_code' => $code,
                'name' => $name,
                'description' => ($row['description'] ?? '') === '' ? null : trim($row['description']),
                'duration_minutes' => (int) $duration,
                'price' => $price,
                'is_active' => $isActiveRaw === '1',
            ];
        }

        return $services;
    }

    /**
     * @param  array<int, array<string, string>>  $rows
     * @param  array<string, array<string, mixed>>  $servicesByCode
     * @param  array<int, string>  $errors
     * @return array<int, array{test_code: string, name: string, description: ?string, is_active: bool, service_codes: array<int, string>}>
     */
    private function validateProfessionals(array $rows, array $servicesByCode, array &$errors): array
    {
        $seenCodes = [];
        $professionals = [];

        foreach ($rows as $i => $row) {
            $line = $i + 2;
            $code = trim($row['test_code'] ?? '');

            if ($code === '') {
                $errors[] = "profissionais.csv linha {$line}: test_code ausente.";

                continue;
            }
            if (isset($seenCodes[$code])) {
                $errors[] = "profissionais.csv linha {$line}: test_code \"{$code}\" duplicado.";

                continue;
            }
            $seenCodes[$code] = true;

            $name = trim($row['name'] ?? '');
            if ($name === '') {
                $errors[] = "profissionais.csv {$code}: name ausente.";
            }

            $isActiveRaw = trim($row['is_active'] ?? '');
            if (! in_array($isActiveRaw, ['0', '1'], true)) {
                $errors[] = "profissionais.csv {$code}: is_active deve ser 0 ou 1 (\"{$isActiveRaw}\").";
            }

            $serviceCodesRaw = trim($row['service_codes'] ?? '');
            $serviceCodes = $serviceCodesRaw === '' ? [] : explode('|', $serviceCodesRaw);

            foreach ($serviceCodes as $serviceCode) {
                if (! isset($servicesByCode[$serviceCode])) {
                    $errors[] = "profissionais.csv {$code}: service_codes referencia \"{$serviceCode}\", que não existe em servicos.csv.";
                }
            }
            if (count($serviceCodes) !== count(array_unique($serviceCodes))) {
                $errors[] = "profissionais.csv {$code}: service_codes tem referências duplicadas.";
            }

            $professionals[] = [
                'test_code' => $code,
                'name' => $name,
                'description' => ($row['description'] ?? '') === '' ? null : trim($row['description']),
                'is_active' => $isActiveRaw === '1',
                'service_codes' => $serviceCodes,
            ];
        }

        return $professionals;
    }

    /**
     * @param  array<int, array<string, mixed>>  $services
     * @param  array<int, array<string, mixed>>  $professionals
     * @param  array{database: array<string, mixed>, services: array<string, int>, professionals: array<string, int>}  $manifest
     * @return array{manifest: array<string, mixed>, createdServices: array<string, int>, createdProfessionals: array<string, int>, skipped: array<int, string>}
     *
     * @throws ImportCollisionException
     */
    private function importAll(array $services, array $professionals, array $manifest): array
    {
        $createdServices = [];
        $createdProfessionals = [];
        $skipped = [];

        foreach ($services as $service) {
            $code = $service['test_code'];
            $existingId = $manifest['services'][$code] ?? null;

            if ($existingId !== null) {
                $row = Service::query()->find($existingId);
                if ($row === null || $row->name !== $service['name']) {
                    throw new ImportCollisionException(
                        "O manifesto aponta {$code} para o serviço id {$existingId}, mas ele não existe mais ".
                        'ou foi renomeado — interrompendo para não reaproveitar um registro incerto.'
                    );
                }
                $skipped[] = $code;

                continue;
            }

            $collision = Service::query()->where('name', $service['name'])->first();
            if ($collision !== null) {
                throw new ImportCollisionException(
                    "Já existe um serviço chamado \"{$service['name']}\" (id {$collision->id}) que não foi ".
                    "criado por esta importação ({$code}) — interrompendo para não tomar posse de um cadastro existente."
                );
            }

            $row = Service::create([
                'name' => $service['name'],
                'description' => $service['description'],
                'duration_minutes' => $service['duration_minutes'],
                'price' => $service['price'],
                'is_active' => $service['is_active'],
            ]);
            $createdServices[$code] = $row->id;
            $manifest['services'][$code] = $row->id;
        }

        $serviceIdByCode = array_merge($manifest['services'], $createdServices);

        foreach ($professionals as $professional) {
            $code = $professional['test_code'];
            $existingId = $manifest['professionals'][$code] ?? null;

            if ($existingId !== null) {
                $row = Professional::query()->find($existingId);
                if ($row === null || $row->name !== $professional['name']) {
                    throw new ImportCollisionException(
                        "O manifesto aponta {$code} para o profissional id {$existingId}, mas ele não existe mais ".
                        'ou foi renomeado — interrompendo para não reaproveitar um registro incerto.'
                    );
                }
                $skipped[] = $code;

                continue;
            }

            $collision = Professional::query()->where('name', $professional['name'])->first();
            if ($collision !== null) {
                throw new ImportCollisionException(
                    "Já existe um profissional chamado \"{$professional['name']}\" (id {$collision->id}) que não foi ".
                    "criado por esta importação ({$code}) — interrompendo para não tomar posse de um cadastro existente."
                );
            }

            $row = Professional::create([
                'name' => $professional['name'],
                'description' => $professional['description'],
                'is_active' => $professional['is_active'],
            ]);
            $serviceIds = array_map(fn (string $serviceCode) => $serviceIdByCode[$serviceCode], $professional['service_codes']);
            $row->services()->attach($serviceIds);

            $createdProfessionals[$code] = $row->id;
            $manifest['professionals'][$code] = $row->id;
        }

        return [
            'manifest' => $manifest,
            'createdServices' => $createdServices,
            'createdProfessionals' => $createdProfessionals,
            'skipped' => $skipped,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $services
     * @param  array<int, array<string, mixed>>  $professionals
     * @param  array{services: array<string, int>, professionals: array<string, int>}  $manifest
     */
    private function reportPlan(array $services, array $professionals, array $manifest): void
    {
        $newServices = array_filter($services, fn (array $s) => ! isset($manifest['services'][$s['test_code']]));
        $newProfessionals = array_filter($professionals, fn (array $p) => ! isset($manifest['professionals'][$p['test_code']]));

        $this->line('Seriam criados: '.count($newServices).' serviços, '.count($newProfessionals).' profissionais.');
        $this->line(
            'Já importados anteriormente (seriam ignorados): '.
            (count($services) + count($professionals) - count($newServices) - count($newProfessionals))
        );
    }

    private function handleRemove(string $manifestPath): int
    {
        $manifest = $this->loadManifest($manifestPath);

        if ($manifest === null) {
            $this->error("Nenhum manifesto encontrado em {$manifestPath} — nada para remover.");

            return self::FAILURE;
        }

        $currentIdentity = $this->databaseIdentity();
        if ($manifest['database'] !== $currentIdentity) {
            $this->error(
                'O manifesto foi criado para outro banco de destino ('.json_encode($manifest['database']).
                ') e o alvo atual é '.json_encode($currentIdentity).'. Abortando — nada foi removido.'
            );

            return self::FAILURE;
        }

        $professionalIds = array_values($manifest['professionals']);
        $serviceIds = array_values($manifest['services']);

        $professionals = Professional::query()->whereIn('id', $professionalIds)->get();
        $services = Service::query()->whereIn('id', $serviceIds)->get();

        foreach ($professionals as $professional) {
            $workingHoursCount = $professional->workingHours()->count();
            $blocksCount = $professional->scheduleBlocks()->count();
            if ($workingHoursCount > 0 || $blocksCount > 0) {
                $this->line(
                    "Profissional \"{$professional->name}\" (id {$professional->id}) tem {$workingHoursCount} ".
                    "período(s) de expediente e {$blocksCount} bloqueio(s) — serão removidos em cascata pela FK."
                );
            }
        }

        DB::transaction(function () use ($professionals, $services) {
            BusinessSettings::query()->lockForUpdate()->first();

            foreach ($professionals as $professional) {
                $professional->delete();
            }
            foreach ($services as $service) {
                $service->delete();
            }
        });

        @unlink($manifestPath);

        $this->info('Removidos: '.$professionals->count().' profissionais, '.$services->count().' serviços.');
        $this->line('Manifesto removido.');

        return self::SUCCESS;
    }

    /**
     * @return array{connection: string, name: string, host: string, port: string}
     */
    private function databaseIdentity(): array
    {
        return [
            'connection' => config('database.default'),
            'name' => DB::connection()->getDatabaseName(),
            'host' => (string) config('database.connections.mysql.host'),
            'port' => (string) config('database.connections.mysql.port'),
        ];
    }

    /**
     * @return array{database: array<string, mixed>, services: array<string, int>, professionals: array<string, int>}|null
     */
    private function loadManifest(string $path): ?array
    {
        if (! is_file($path)) {
            return null;
        }

        $decoded = json_decode(file_get_contents($path), true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    private function saveManifest(string $path, array $manifest): void
    {
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), recursive: true);
        }

        file_put_contents($path, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
}
