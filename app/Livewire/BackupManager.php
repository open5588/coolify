<?php

namespace App\Livewire;

use App\Actions\Database\StartDatabaseImport;
use App\Jobs\DatabaseBackupJob;
use App\Jobs\VolumeBackupJob;
use App\Models\Application;
use App\Models\ScheduledDatabaseBackup;
use App\Models\ScheduledDatabaseBackupExecution;
use App\Models\ScheduledVolumeBackup;
use App\Models\ScheduledVolumeBackupExecution;
use App\Models\ServiceApplication;
use App\Models\ServiceDatabase;
use App\Models\StandaloneSqlite;
use App\Support\DatabaseImport\DatabaseImportException;
use App\Support\DatabaseImport\DatabaseImportSource;
use App\Traits\ListensToTeamChannel;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;
use Livewire\Component;

class BackupManager extends Component
{
    use AuthorizesRequests;
    use ListensToTeamChannel;

    #[Url(except: '')]
    public string $search = '';

    #[Url(as: 'type', except: 'all')]
    public string $typeFilter = 'all';

    #[Url(as: 'status', except: 'all')]
    public string $statusFilter = 'all';

    public ?int $activityId = null;

    public function getListeners(): array
    {
        return [
            'slideOverClosed' => 'resetActivityId',
            ...$this->teamChannelListeners([
                'BackupCreated' => '$refresh',
            ]),
        ];
    }

    public function resetActivityId(): void
    {
        $this->activityId = null;
    }

    public function setTypeFilter(string $type): void
    {
        if (in_array($type, ['all', 'database', 'storage'], true)) {
            $this->typeFilter = $type;
        }
    }

    public function setStatusFilter(string $status): void
    {
        if (in_array($status, ['all', 'success', 'failed', 'running'], true)) {
            $this->statusFilter = $status;
        }
    }

    public function backupNow(string $type, string $backupUuid): bool
    {
        $teamId = currentTeam()?->id;
        if ($teamId === null) {
            return false;
        }

        try {
            if ($type === 'database') {
                $schedule = ScheduledDatabaseBackup::query()
                    ->with('database')
                    ->where('team_id', $teamId)
                    ->whereUuid($backupUuid)
                    ->first();

                abort_unless($schedule && $schedule->database, 404);
                $this->authorize('manageBackups', $schedule->database);

                if (! str($schedule->database->status)->startsWith('running')) {
                    $this->dispatch('error', 'The database must be running to start a backup.');

                    return false;
                }

                DatabaseBackupJob::dispatch($schedule);
            } else {
                abort_unless($type === 'storage', 404);

                $schedule = ScheduledVolumeBackup::query()
                    ->with('backupable.resource')
                    ->where('team_id', $teamId)
                    ->whereUuid($backupUuid)
                    ->first();

                $targetResource = $schedule?->targetResource();
                abort_unless($schedule && $targetResource, 404);
                $this->authorize('update', $targetResource);

                VolumeBackupJob::dispatch($schedule);
            }

            $this->dispatch('success', 'Backup queued.');

            return true;
        } catch (\Throwable $e) {
            handleError($e, $this);

            return false;
        }
    }

    public function restoreDatabaseBackup(int $executionId, string $password = ''): bool|string
    {
        $teamId = currentTeam()?->id;
        if ($teamId === null) {
            return false;
        }

        $execution = ScheduledDatabaseBackupExecution::query()
            ->with(['scheduledDatabaseBackup.database', 'scheduledDatabaseBackup.s3'])
            ->whereKey($executionId)
            ->whereHas('scheduledDatabaseBackup', fn ($query) => $query->where('team_id', $teamId))
            ->first();

        if (! $execution || ! $execution->scheduledDatabaseBackup?->database) {
            $this->dispatch('error', 'Backup execution not found.');

            return false;
        }

        $schedule = $execution->scheduledDatabaseBackup;
        $database = $schedule->database;

        try {
            $this->authorize('update', $database);
            $this->authorize('manageBackups', $database);
        } catch (\Throwable $e) {
            handleError($e, $this);

            return false;
        }

        if (! verifyPasswordConfirmation($password, $this)) {
            return 'The provided password is incorrect.';
        }

        if ($execution->status !== 'success' || blank($execution->filename)) {
            $this->dispatch('error', 'Only completed backups with a valid file path can be restored.');

            return false;
        }

        $sourceType = null;
        $s3StorageId = null;

        if (! $execution->local_storage_deleted) {
            $sourceType = 'server';
        } elseif ($execution->s3_uploaded && ! $execution->s3_storage_deleted && $schedule->s3) {
            $sourceType = 's3';
            $s3StorageId = (string) $schedule->s3->id;
        } else {
            $this->dispatch('error', 'Backup archive is no longer available in local or S3 storage.');

            return false;
        }

        $dumpAll = (bool) $schedule->dump_all || $execution->database_name === 'all';
        $replaceExisting = ! $dumpAll;
        $sqliteDatabase = $database instanceof StandaloneSqlite
            ? $database->defaultRestoreFile($execution->database_name ?: $execution->filename)
            : null;

        try {
            $source = new DatabaseImportSource(
                type: $sourceType,
                path: $execution->filename,
                s3StorageUuid: $s3StorageId,
                dumpAll: $dumpAll,
                replaceExisting: $replaceExisting,
                sqliteDatabase: $sqliteDatabase,
            );

            $activity = StartDatabaseImport::run($database, $source, (int) $teamId);
            $this->activityId = $activity->id;
            $this->dispatch('activityMonitor', $activity->id);
            $this->dispatch('databaserestore');

            auditLog('ui.database.restore_started', [
                'team_id' => $teamId,
                'database_uuid' => $database->uuid,
                'database_name' => $database->name,
                'source' => $sourceType,
                'execution_id' => $execution->id,
                'replace_existing' => $replaceExisting,
            ]);

            $this->dispatch('info', 'Restoring database from backup. Progress will be shown in the activity monitor...');
        } catch (DatabaseImportException $e) {
            $this->dispatch('error', $e->getMessage());

            return false;
        } catch (\Throwable $e) {
            handleError($e, $this);

            return false;
        }

        return true;
    }

    public function deleteExecution(string $type, int $executionId, string $password = '', array $selectedActions = []): bool|string
    {
        $teamId = currentTeam()?->id;
        if ($teamId === null) {
            return false;
        }

        if ($type === 'database') {
            $execution = ScheduledDatabaseBackupExecution::query()
                ->with(['scheduledDatabaseBackup.database', 'scheduledDatabaseBackup.s3'])
                ->whereKey($executionId)
                ->whereHas('scheduledDatabaseBackup', fn ($query) => $query->where('team_id', $teamId))
                ->first();

            if (! $execution || ! $execution->scheduledDatabaseBackup?->database) {
                $this->dispatch('error', 'Backup execution not found.');

                return false;
            }

            try {
                $this->authorize('manageBackups', $execution->scheduledDatabaseBackup->database);
            } catch (\Throwable $e) {
                handleError($e, $this);

                return false;
            }

            if (! verifyPasswordConfirmation($password, $this)) {
                return 'The provided password is incorrect.';
            }

            try {
                $deleteFromS3 = in_array('delete_backup_s3', $selectedActions, true);

                if ($execution->filename && ! $execution->local_storage_deleted) {
                    $server = $execution->scheduledDatabaseBackup->server();
                    if (! $server) {
                        throw new \RuntimeException('The backup server is unavailable.');
                    }

                    deleteBackupsLocally($execution->filename, $server, throwError: true);
                }

                if ($deleteFromS3 && $execution->s3_uploaded && ! $execution->s3_storage_deleted) {
                    if (! $execution->scheduledDatabaseBackup->s3) {
                        throw new \RuntimeException('The S3 storage is unavailable.');
                    }

                    deleteBackupsS3($execution->filename, $execution->scheduledDatabaseBackup->s3);
                }

                $execution->delete();
                $this->dispatch('success', 'Backup deleted.');

                return true;
            } catch (\Throwable $e) {
                $this->dispatch('error', 'Failed to delete backup: '.$e->getMessage());

                return false;
            }
        }

        if ($type === 'storage') {
            $execution = ScheduledVolumeBackupExecution::query()
                ->with(['scheduledVolumeBackup.backupable.resource', 's3'])
                ->whereKey($executionId)
                ->whereHas('scheduledVolumeBackup', fn ($query) => $query->where('team_id', $teamId))
                ->first();

            $targetResource = $execution?->scheduledVolumeBackup?->targetResource();
            if (! $execution || ! $targetResource) {
                $this->dispatch('error', 'Backup execution not found.');

                return false;
            }

            try {
                $this->authorize('update', $targetResource);
            } catch (\Throwable $e) {
                handleError($e, $this);

                return false;
            }

            if (! verifyPasswordConfirmation($password, $this)) {
                return 'The provided password is incorrect.';
            }

            try {
                $deleteFromS3 = in_array('delete_backup_s3', $selectedActions, true);

                if ($execution->filename && ! $execution->local_storage_deleted) {
                    $server = $execution->scheduledVolumeBackup->server();
                    if (! $server) {
                        throw new \RuntimeException('The backup server is unavailable.');
                    }

                    deleteBackupsLocally($execution->filename, $server, throwError: true);
                }

                if ($deleteFromS3 && $execution->s3_uploaded && ! $execution->s3_storage_deleted) {
                    $s3 = $execution->s3 ?? $execution->scheduledVolumeBackup->s3;
                    if (! $s3) {
                        throw new \RuntimeException('The S3 storage is unavailable.');
                    }

                    deleteBackupsS3($execution->filename, $s3);
                }

                $execution->delete();
                $this->dispatch('success', 'Backup deleted.');

                return true;
            } catch (\Throwable $e) {
                $this->dispatch('error', 'Failed to delete backup: '.$e->getMessage());

                return false;
            }
        }

        return false;
    }

    public function render(): View
    {
        $teamId = currentTeam()?->id;
        $schedules = $this->loadSchedules($teamId);
        $executions = $this->loadExecutions($teamId);

        $summary = [
            'schedules_total' => $schedules->count(),
            'schedules_enabled' => $schedules->where('enabled', true)->count(),
            'executions_total' => $executions->count(),
            'executions_success' => $executions->where('status', 'success')->count(),
            'executions_failed' => $executions->where('status', 'failed')->count(),
        ];

        $query = trim(mb_strtolower($this->search));

        $filteredSchedules = $schedules
            ->when($this->typeFilter !== 'all', fn (Collection $items) => $items->where('type', $this->typeFilter))
            ->when($query !== '', function (Collection $items) use ($query): Collection {
                return $items->filter(function (array $item) use ($query): bool {
                    return str_contains(mb_strtolower($item['target']), $query)
                        || str_contains(mb_strtolower($item['type_label']), $query)
                        || str_contains(mb_strtolower($item['frequency']), $query)
                        || str_contains(mb_strtolower((string) $item['storage_label']), $query);
                });
            })
            ->values();

        $filteredExecutions = $executions
            ->when($this->typeFilter !== 'all', fn (Collection $items) => $items->where('type', $this->typeFilter))
            ->when($this->statusFilter !== 'all', fn (Collection $items) => $items->where('status', $this->statusFilter))
            ->when($query !== '', function (Collection $items) use ($query): Collection {
                return $items->filter(function (array $item) use ($query): bool {
                    return str_contains(mb_strtolower($item['target']), $query)
                        || str_contains(mb_strtolower((string) $item['database_name']), $query)
                        || str_contains(mb_strtolower((string) $item['filename']), $query)
                        || str_contains(mb_strtolower($item['status']), $query);
                });
            })
            ->values();

        return view('livewire.backup-manager', [
            'schedules' => $filteredSchedules,
            'executions' => $filteredExecutions,
            'summary' => $summary,
        ]);
    }

    private function loadSchedules(?int $teamId): Collection
    {
        if ($teamId === null) {
            return collect();
        }

        $user = auth()->user();

        $databaseSchedules = ScheduledDatabaseBackup::query()
            ->with(['database', 'latest_log', 's3'])
            ->withCount('executions')
            ->where('team_id', $teamId)
            ->latest()
            ->get()
            ->map(function (ScheduledDatabaseBackup $schedule) use ($user): ?array {
                $database = $schedule->database;
                if (! $database || ($user && ! $user->can('view', $database))) {
                    return null;
                }

                return [
                    'id' => 'database:'.$schedule->id,
                    'uuid' => $schedule->uuid,
                    'type' => 'database',
                    'type_label' => 'Database',
                    'target' => $database->human_name ?: $database->name,
                    'frequency' => $schedule->frequency,
                    'enabled' => (bool) $schedule->enabled,
                    'save_s3' => (bool) $schedule->save_s3,
                    'storage_label' => $schedule->save_s3 && $schedule->s3 ? $schedule->s3->name : 'Local only',
                    'executions_count' => $schedule->executions_count,
                    'last_status' => $schedule->latest_log?->status,
                    'last_run_at' => $schedule->latest_log?->created_at,
                    'resource' => $database,
                    'can_trigger' => $user ? $user->can('manageBackups', $database) : false,
                    'schedule_url' => $this->databaseScheduleUrl($schedule, $database),
                    'import_url' => $this->databaseImportUrl($database),
                ];
            })
            ->filter();

        $volumeSchedules = ScheduledVolumeBackup::query()
            ->with(['backupable.resource', 'latestExecution', 's3'])
            ->withCount('executions')
            ->where('team_id', $teamId)
            ->latest()
            ->get()
            ->map(function (ScheduledVolumeBackup $schedule) use ($user): ?array {
                $resource = $schedule->targetResource();
                if (! $resource || ($user && ! $user->can('view', $resource))) {
                    return null;
                }

                return [
                    'id' => 'storage:'.$schedule->id,
                    'uuid' => $schedule->uuid,
                    'type' => 'storage',
                    'type_label' => $schedule->targetType(),
                    'target' => ($resource->human_name ?: $resource->name).' ('.$schedule->targetName().')',
                    'frequency' => $schedule->frequency,
                    'enabled' => (bool) $schedule->enabled,
                    'save_s3' => (bool) $schedule->save_s3,
                    'storage_label' => $schedule->save_s3 && $schedule->s3 ? $schedule->s3->name : 'Local only',
                    'executions_count' => $schedule->executions_count,
                    'last_status' => $schedule->latestExecution?->status,
                    'last_run_at' => $schedule->latestExecution?->created_at,
                    'resource' => $resource,
                    'can_trigger' => $user ? $user->can('update', $resource) : false,
                    'schedule_url' => $this->volumeScheduleUrl($schedule, $resource),
                    'import_url' => null,
                ];
            })
            ->filter();

        return $databaseSchedules->concat($volumeSchedules)->values();
    }

    private function loadExecutions(?int $teamId): Collection
    {
        if ($teamId === null) {
            return collect();
        }

        $user = auth()->user();

        $databaseExecutions = ScheduledDatabaseBackupExecution::query()
            ->with(['scheduledDatabaseBackup.database', 'scheduledDatabaseBackup.s3'])
            ->whereHas('scheduledDatabaseBackup', fn ($query) => $query->where('team_id', $teamId))
            ->orderByDesc('created_at')
            ->limit(100)
            ->get()
            ->map(function (ScheduledDatabaseBackupExecution $execution) use ($user): ?array {
                $schedule = $execution->scheduledDatabaseBackup;
                $database = $schedule?->database;
                if (! $schedule || ! $database || ($user && ! $user->can('view', $database))) {
                    return null;
                }

                $canRestore = $execution->status === 'success'
                    && filled($execution->filename)
                    && (! $execution->local_storage_deleted
                        || ($execution->s3_uploaded === true && ! $execution->s3_storage_deleted && $schedule->s3 !== null));

                return [
                    'row_key' => 'database:'.$execution->id,
                    'id' => $execution->id,
                    'uuid' => $execution->uuid,
                    'type' => 'database',
                    'type_label' => 'Database',
                    'target' => $database->human_name ?: $database->name,
                    'database_name' => $execution->database_name ?: ($database->name ?? 'database'),
                    'filename' => $execution->filename,
                    'status' => $execution->status,
                    'size' => $execution->size,
                    'created_at' => $execution->created_at,
                    'finished_at' => $execution->finished_at,
                    'local_storage_deleted' => (bool) $execution->local_storage_deleted,
                    's3_uploaded' => $execution->s3_uploaded,
                    's3_storage_deleted' => (bool) $execution->s3_storage_deleted,
                    'can_restore' => $canRestore && ($user ? $user->can('update', $database) : false),
                    'can_delete' => $user ? $user->can('manageBackups', $database) : false,
                    'restore_source_label' => ! $execution->local_storage_deleted ? 'local server storage' : 'S3 storage',
                    'download_url' => $execution->status === 'success' && ! $execution->local_storage_deleted
                        ? route('download.backup', $execution->id)
                        : null,
                    'resource' => $database,
                ];
            })
            ->filter();

        $volumeExecutions = ScheduledVolumeBackupExecution::query()
            ->with(['scheduledVolumeBackup.backupable.resource', 's3'])
            ->whereHas('scheduledVolumeBackup', fn ($query) => $query->where('team_id', $teamId))
            ->orderByDesc('created_at')
            ->limit(100)
            ->get()
            ->map(function (ScheduledVolumeBackupExecution $execution) use ($user): ?array {
                $schedule = $execution->scheduledVolumeBackup;
                $resource = $schedule?->targetResource();
                if (! $schedule || ! $resource || ($user && ! $user->can('view', $resource))) {
                    return null;
                }

                return [
                    'row_key' => 'storage:'.$execution->id,
                    'id' => $execution->id,
                    'uuid' => $execution->uuid,
                    'type' => 'storage',
                    'type_label' => $schedule->targetType(),
                    'target' => ($resource->human_name ?: $resource->name).' ('.$schedule->targetName().')',
                    'database_name' => $schedule->targetName(),
                    'filename' => $execution->filename,
                    'status' => $execution->status,
                    'size' => $execution->size,
                    'created_at' => $execution->created_at,
                    'finished_at' => $execution->finished_at,
                    'local_storage_deleted' => (bool) $execution->local_storage_deleted,
                    's3_uploaded' => $execution->s3_uploaded,
                    's3_storage_deleted' => (bool) $execution->s3_storage_deleted,
                    'can_restore' => false,
                    'can_delete' => $user ? $user->can('update', $resource) : false,
                    'restore_source_label' => null,
                    'download_url' => $execution->status === 'success' && ! $execution->local_storage_deleted
                        ? route('download.volume-backup', $execution->id)
                        : null,
                    'resource' => $resource,
                ];
            })
            ->filter();

        return $databaseExecutions
            ->concat($volumeExecutions)
            ->sortByDesc(fn (array $item) => $item['created_at']?->getTimestamp() ?? 0)
            ->values();
    }

    private function databaseScheduleUrl(ScheduledDatabaseBackup $schedule, Model $database): ?string
    {
        try {
            if ($database instanceof ServiceDatabase) {
                $service = $database->service;
                $environment = $service?->environment;
                $project = $environment?->project;
                if ($service && $environment && $project) {
                    return route('project.service.database.backup.show', [
                        'project_uuid' => $project->uuid,
                        'environment_uuid' => $environment->uuid,
                        'service_uuid' => $service->uuid,
                        'stack_service_uuid' => $database->uuid,
                        'backup_uuid' => $schedule->uuid,
                    ]);
                }

                return null;
            }

            $environment = $database->environment ?? null;
            $project = $environment?->project;
            if ($environment && $project) {
                return route('project.database.backup.execution', [
                    'project_uuid' => $project->uuid,
                    'environment_uuid' => $environment->uuid,
                    'database_uuid' => $database->uuid,
                    'backup_uuid' => $schedule->uuid,
                ]);
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
    }

    private function databaseImportUrl(Model $database): ?string
    {
        try {
            if ($database instanceof ServiceDatabase) {
                $service = $database->service;
                $environment = $service?->environment;
                $project = $environment?->project;
                if ($service && $environment && $project) {
                    return route('project.service.import-backup.database', [
                        'project_uuid' => $project->uuid,
                        'environment_uuid' => $environment->uuid,
                        'service_uuid' => $service->uuid,
                        'stack_service_uuid' => $database->uuid,
                    ]);
                }

                return null;
            }

            $environment = $database->environment ?? null;
            $project = $environment?->project;
            if ($environment && $project) {
                return route('project.database.import-backup', [
                    'project_uuid' => $project->uuid,
                    'environment_uuid' => $environment->uuid,
                    'database_uuid' => $database->uuid,
                ]);
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
    }

    private function volumeScheduleUrl(ScheduledVolumeBackup $schedule, Model $resource): ?string
    {
        try {
            if ($resource instanceof Application) {
                $environment = $resource->environment;
                $project = $environment?->project;
                if ($environment && $project) {
                    return route('project.application.backup.show', [
                        'project_uuid' => $project->uuid,
                        'environment_uuid' => $environment->uuid,
                        'application_uuid' => $resource->uuid,
                        'backup_uuid' => $schedule->uuid,
                    ]);
                }

                return null;
            }

            if ($resource instanceof ServiceApplication || $resource instanceof ServiceDatabase) {
                $service = $resource->service;
                $environment = $service?->environment;
                $project = $environment?->project;
                if ($service && $environment && $project) {
                    return route('project.service.volume-backups.show', [
                        'project_uuid' => $project->uuid,
                        'environment_uuid' => $environment->uuid,
                        'service_uuid' => $service->uuid,
                        'backup_uuid' => $schedule->uuid,
                    ]);
                }
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
    }
}
