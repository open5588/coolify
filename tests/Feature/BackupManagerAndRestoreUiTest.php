<?php

use App\Actions\Database\StartDatabaseImport;
use App\Jobs\DatabaseBackupJob;
use App\Jobs\VolumeBackupJob;
use App\Livewire\BackupManager;
use App\Livewire\Project\Database\BackupExecutions;
use App\Livewire\Project\Database\ImportForm;
use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\LocalPersistentVolume;
use App\Models\Project;
use App\Models\S3Storage;
use App\Models\ScheduledDatabaseBackup;
use App\Models\ScheduledDatabaseBackupExecution;
use App\Models\ScheduledVolumeBackup;
use App\Models\ScheduledVolumeBackupExecution;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Models\User;
use App\Support\DatabaseImport\DatabaseImportSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
    Storage::fake();
    InstanceSettings::forceCreate([
        'id' => 0,
        'disable_two_step_confirmation' => true,
    ]);

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user, ['role' => 'admin']);
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->destination = StandaloneDocker::firstOrCreate(
        ['server_id' => $this->server->id, 'network' => 'coolify'],
        ['uuid' => (string) Str::uuid(), 'name' => 'docker']
    );
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);
    $this->database = StandalonePostgresql::create([
        'uuid' => (string) Str::uuid(),
        'name' => 'primary-postgres',
        'postgres_user' => 'postgres',
        'postgres_password' => 'password',
        'postgres_db' => 'app_db',
        'image' => 'postgres:17',
        'status' => 'running',
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);

    $this->s3 = S3Storage::create([
        'uuid' => (string) Str::uuid(),
        'name' => 'team-s3',
        'description' => 'Primary S3',
        'key' => 'key',
        'secret' => 'secret',
        'bucket' => 'coolify-backups',
        'region' => 'us-east-1',
        'endpoint' => 'https://s3.example.com',
        'is_usable' => true,
        'team_id' => $this->team->id,
    ]);

    $this->backupSchedule = ScheduledDatabaseBackup::create([
        'uuid' => (string) Str::uuid(),
        'team_id' => $this->team->id,
        'enabled' => true,
        'save_s3' => true,
        'frequency' => '0 0 * * *',
        'database_type' => $this->database->getMorphClass(),
        'database_id' => $this->database->id,
        's3_storage_id' => $this->s3->id,
        'dump_all' => false,
    ]);
});

class ImportFormExecutionTestComponent extends ImportForm
{
    public function mount($database = null, $server = null): void
    {
        $this->resourceId = $database->id;
        $this->resourceType = $database::class;
        $this->serverId = $server->id;
        $this->container = $database->uuid;
        $this->resourceUuid = $database->uuid;
        $this->resourceStatus = $database->status ?? '';
        $this->resourceDbType = $database->type();
        $this->loadAvailableExecutions();
        $this->initializeRestoreOptions();
    }

    public function render()
    {
        return view('livewire.project.database.import-form');
    }
}

test('database backup executions component restores a local backup execution in one click', function () {
    $execution = ScheduledDatabaseBackupExecution::create([
        'uuid' => (string) Str::uuid(),
        'database_name' => 'app_db',
        'filename' => '/data/coolify/backups/databases/app_db-1700000000.dmp',
        'status' => 'success',
        'size' => '2048',
        'scheduled_database_backup_id' => $this->backupSchedule->id,
        'local_storage_deleted' => false,
        's3_uploaded' => true,
        's3_storage_deleted' => false,
    ]);

    StartDatabaseImport::shouldRun()->once()
        ->withArgs(function ($resource, DatabaseImportSource $source, int $teamId): bool {
            return $resource->is($this->database)
                && $source->type === 'server'
                && $source->path === '/data/coolify/backups/databases/app_db-1700000000.dmp'
                && $source->dumpAll === false
                && $source->replaceExisting === true
                && $teamId === $this->team->id;
        })
        ->andReturn(Activity::create(['log_name' => 'default', 'description' => 'queued', 'properties' => ['status' => 'queued']]));

    Livewire::test(BackupExecutions::class, ['backup' => $this->backupSchedule])
        ->call('restoreBackup', $execution->id)
        ->assertNotDispatched('error')
        ->assertDispatched('databaserestore')
        ->assertDispatched('activityMonitor');
});

test('database backup executions component falls back to S3 when local backup was deleted', function () {
    $execution = ScheduledDatabaseBackupExecution::create([
        'uuid' => (string) Str::uuid(),
        'database_name' => 'all',
        'filename' => '/data/coolify/backups/databases/all-1700000000.sql.gz',
        'status' => 'success',
        'size' => '4096',
        'scheduled_database_backup_id' => $this->backupSchedule->id,
        'local_storage_deleted' => true,
        's3_uploaded' => true,
        's3_storage_deleted' => false,
    ]);

    StartDatabaseImport::shouldRun()->once()
        ->withArgs(function ($resource, DatabaseImportSource $source, int $teamId): bool {
            return $resource->is($this->database)
                && $source->type === 's3'
                && $source->path === '/data/coolify/backups/databases/all-1700000000.sql.gz'
                && $source->s3StorageUuid === (string) $this->s3->id
                && $source->dumpAll === true
                && $source->replaceExisting === false
                && $teamId === $this->team->id;
        })
        ->andReturn(Activity::create(['log_name' => 'default', 'description' => 'queued', 'properties' => ['status' => 'queued']]));

    Livewire::test(BackupExecutions::class, ['backup' => $this->backupSchedule])
        ->call('restoreBackup', $execution->id)
        ->assertNotDispatched('error')
        ->assertDispatched('databaserestore');
});

test('database backup executions component blocks restore for read-only team members', function () {
    $member = User::factory()->create();
    $this->team->members()->attach($member, ['role' => 'member']);

    $execution = ScheduledDatabaseBackupExecution::create([
        'uuid' => (string) Str::uuid(),
        'database_name' => 'app_db',
        'filename' => '/data/coolify/backups/databases/app_db.dmp',
        'status' => 'success',
        'scheduled_database_backup_id' => $this->backupSchedule->id,
        'local_storage_deleted' => false,
    ]);

    StartDatabaseImport::shouldRun()->never();

    $this->actingAs($member);
    session(['currentTeam' => $this->team]);

    Livewire::test(BackupExecutions::class, ['backup' => $this->backupSchedule])
        ->call('restoreBackup', $execution->id)
        ->assertNotDispatched('databaserestore');
});

test('import form loads recorded backup executions and restores from the selected execution', function () {
    $execution = ScheduledDatabaseBackupExecution::create([
        'uuid' => (string) Str::uuid(),
        'database_name' => 'app_db',
        'filename' => '/data/coolify/backups/databases/app_db-recorded.dmp',
        'status' => 'success',
        'size' => '1024',
        'scheduled_database_backup_id' => $this->backupSchedule->id,
        'local_storage_deleted' => false,
    ]);

    $stagedUpload = "upload/{$this->database->uuid}/restore";
    Storage::put($stagedUpload, 'stale upload');

    StartDatabaseImport::shouldRun()->once()
        ->withArgs(function ($resource, DatabaseImportSource $source, int $teamId): bool {
            return $resource->is($this->database)
                && $source->type === 'server'
                && $source->path === '/data/coolify/backups/databases/app_db-recorded.dmp'
                && $source->replaceExisting === true
                && $teamId === $this->team->id;
        })
        ->andReturn(Activity::create(['log_name' => 'default', 'description' => 'queued', 'properties' => ['status' => 'queued']]));

    Livewire::test(ImportFormExecutionTestComponent::class, [
        'database' => $this->database,
        'server' => $this->server,
    ])
        ->assertCount('availableExecutions', 1)
        ->set('replaceExisting', true)
        ->set('selectedExecutionId', $execution->id)
        ->call('restoreFromExecution')
        ->assertNotDispatched('error')
        ->assertDispatched('databaserestore');

    expect(Storage::exists($stagedUpload))->toBeFalse();
});

test('backup manager lists team-scoped schedules and executions and isolates other teams', function () {
    $execution = ScheduledDatabaseBackupExecution::create([
        'uuid' => (string) Str::uuid(),
        'database_name' => 'app_db',
        'filename' => '/data/coolify/backups/databases/team-own-backup.dmp',
        'status' => 'success',
        'size' => '8192',
        'scheduled_database_backup_id' => $this->backupSchedule->id,
        'local_storage_deleted' => false,
    ]);

    $otherTeam = Team::factory()->create();
    $otherServer = Server::factory()->create(['team_id' => $otherTeam->id]);
    $otherDest = StandaloneDocker::firstOrCreate(
        ['server_id' => $otherServer->id, 'network' => 'coolify'],
        ['uuid' => (string) Str::uuid(), 'name' => 'docker-other']
    );
    $otherProject = Project::factory()->create(['team_id' => $otherTeam->id]);
    $otherEnv = Environment::factory()->create(['project_id' => $otherProject->id]);
    $otherDb = StandalonePostgresql::create([
        'uuid' => (string) Str::uuid(),
        'name' => 'foreign-postgres',
        'postgres_user' => 'postgres',
        'postgres_password' => 'password',
        'postgres_db' => 'foreign_db',
        'image' => 'postgres:17',
        'status' => 'running',
        'environment_id' => $otherEnv->id,
        'destination_id' => $otherDest->id,
        'destination_type' => $otherDest->getMorphClass(),
    ]);
    $otherSchedule = ScheduledDatabaseBackup::create([
        'uuid' => (string) Str::uuid(),
        'team_id' => $otherTeam->id,
        'enabled' => true,
        'frequency' => '0 0 * * *',
        'database_type' => $otherDb->getMorphClass(),
        'database_id' => $otherDb->id,
    ]);
    $otherExecution = ScheduledDatabaseBackupExecution::create([
        'uuid' => (string) Str::uuid(),
        'database_name' => 'foreign_db',
        'filename' => '/data/coolify/backups/databases/foreign-backup.dmp',
        'status' => 'success',
        'scheduled_database_backup_id' => $otherSchedule->id,
        'local_storage_deleted' => false,
    ]);

    Livewire::test(BackupManager::class)
        ->assertSee('primary-postgres')
        ->assertSee('/data/coolify/backups/databases/team-own-backup.dmp')
        ->assertDontSee('foreign-postgres')
        ->assertDontSee('/data/coolify/backups/databases/foreign-backup.dmp')
        ->call('restoreDatabaseBackup', $otherExecution->id)
        ->assertDispatched('error', 'Backup execution not found.')
        ->assertNotDispatched('databaserestore');

    StartDatabaseImport::shouldRun()->once()
        ->withArgs(fn ($resource, DatabaseImportSource $source): bool => $resource->is($this->database) && $source->path === $execution->filename)
        ->andReturn(Activity::create(['log_name' => 'default', 'description' => 'queued', 'properties' => ['status' => 'queued']]));

    Livewire::test(BackupManager::class)
        ->call('restoreDatabaseBackup', $execution->id)
        ->assertNotDispatched('error')
        ->assertDispatched('databaserestore');
});

test('backup manager triggers manual database and storage backups', function () {
    Bus::fake([DatabaseBackupJob::class, VolumeBackupJob::class]);

    $application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        'ports_exposes' => '3000',
    ]);
    $volume = LocalPersistentVolume::create([
        'name' => 'app-uploads',
        'mount_path' => '/app/uploads',
        'resource_type' => $application->getMorphClass(),
        'resource_id' => $application->id,
    ]);
    $volumeSchedule = ScheduledVolumeBackup::create([
        'uuid' => (string) Str::uuid(),
        'team_id' => $this->team->id,
        'backupable_type' => $volume->getMorphClass(),
        'backupable_id' => $volume->id,
        'frequency' => '0 2 * * *',
        'enabled' => true,
    ]);
    ScheduledVolumeBackupExecution::create([
        'uuid' => (string) Str::uuid(),
        'scheduled_volume_backup_id' => $volumeSchedule->id,
        'status' => 'success',
        'filename' => '/data/coolify/backups/volumes/app-uploads.tar.gz',
        'size' => '1024',
        'local_storage_deleted' => false,
    ]);

    Livewire::test(BackupManager::class)
        ->assertSee('app-uploads')
        ->call('backupNow', 'database', $this->backupSchedule->uuid)
        ->assertDispatched('success', 'Backup queued.')
        ->call('backupNow', 'storage', $volumeSchedule->uuid)
        ->assertDispatched('success', 'Backup queued.');

    Bus::assertDispatched(DatabaseBackupJob::class);
    Bus::assertDispatched(VolumeBackupJob::class);
});
