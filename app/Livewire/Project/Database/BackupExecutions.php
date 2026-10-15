<?php

namespace App\Livewire\Project\Database;

use App\Actions\Database\StartDatabaseImport;
use App\Models\ScheduledDatabaseBackup;
use App\Models\ServiceDatabase;
use App\Models\StandaloneSqlite;
use App\Support\DatabaseImport\DatabaseImportException;
use App\Support\DatabaseImport\DatabaseImportSource;
use App\Traits\ListensToTeamChannel;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Livewire\Component;

class BackupExecutions extends Component
{
    use AuthorizesRequests;
    use ListensToTeamChannel;

    public ?ScheduledDatabaseBackup $backup = null;

    public $database;

    public ?Collection $executions;

    public int $executions_count = 0;

    public int $skip = 0;

    public int $defaultTake = 10;

    public bool $showNext = false;

    public bool $showPrev = false;

    public int $currentPage = 1;

    public $setDeletableBackup;

    public $delete_backup_s3 = false;

    public $delete_backup_sftp = false;

    public ?int $activityId = null;

    public function getListeners(): array
    {
        return [
            'slideOverClosed' => 'resetActivityId',
            ...$this->teamChannelListeners([
                'BackupCreated' => 'refreshBackupExecutions',
            ]),
        ];
    }

    public function resetActivityId(): void
    {
        $this->activityId = null;
    }

    public function cleanupFailed()
    {
        try {
            $this->authorize('manageBackups', $this->database);
            if ($this->backup) {
                $this->backup->executions()->where('status', 'failed')->delete();
                $this->refreshBackupExecutions();
                $this->dispatch('success', 'Failed backups cleaned up.');
            }
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function cleanupDeleted()
    {
        try {
            $this->authorize('manageBackups', $this->database);
            if ($this->backup) {
                $deletedCount = $this->backup->executions()->where('local_storage_deleted', true)->count();
                if ($deletedCount > 0) {
                    $this->backup->executions()->where('local_storage_deleted', true)->delete();
                    $this->refreshBackupExecutions();
                    $this->dispatch('success', "Cleaned up {$deletedCount} backup entries deleted from local storage.");
                } else {
                    $this->dispatch('info', 'No backup entries found that are deleted from local storage.');
                }
            }
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function deleteBackup($executionId, $password, $selectedActions = [])
    {
        try {
            $this->authorize('manageBackups', $this->database);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }

        if (! verifyPasswordConfirmation($password, $this)) {
            return 'The provided password is incorrect.';
        }

        $execution = $this->backup->executions()->where('id', $executionId)->first();
        if (is_null($execution)) {
            $this->dispatch('error', 'Backup execution not found.');

            return;
        }

        try {
            $deleteFromS3 = in_array('delete_backup_s3', $selectedActions, true);

            if ($execution->filename && ! $execution->local_storage_deleted) {
                $server = $this->backup->server();
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
            $this->delete_backup_s3 = false;
            $this->dispatch('success', 'Backup deleted.');
            $this->refreshBackupExecutions();
        } catch (\Exception $e) {
            $this->dispatch('error', 'Failed to delete backup: '.$e->getMessage());

            return false;
        }

        return true;
    }

    public function restoreBackup($executionId, string $password = ''): bool|string
    {
        try {
            $this->authorize('update', $this->database);
            $this->authorize('manageBackups', $this->database);
        } catch (\Throwable $e) {
            handleError($e, $this);

            return false;
        }

        if (! verifyPasswordConfirmation($password, $this)) {
            return 'The provided password is incorrect.';
        }

        $execution = $this->backup?->executions()->where('id', $executionId)->first();
        if (is_null($execution)) {
            $this->dispatch('error', 'Backup execution not found.');

            return false;
        }

        if ($execution->status !== 'success' || blank($execution->filename)) {
            $this->dispatch('error', 'Only completed backups with a valid file path can be restored.');

            return false;
        }

        $sourceType = null;
        $s3StorageId = null;

        if (! $execution->local_storage_deleted) {
            $sourceType = 'server';
        } elseif ($execution->s3_uploaded && ! $execution->s3_storage_deleted && $this->backup->s3) {
            $sourceType = 's3';
            $s3StorageId = (string) $this->backup->s3->id;
        } else {
            $this->dispatch('error', 'Backup archive is no longer available in local or S3 storage.');

            return false;
        }

        $dumpAll = (bool) $this->backup->dump_all || $execution->database_name === 'all';
        $replaceExisting = ! $dumpAll;
        $sqliteDatabase = $this->database instanceof StandaloneSqlite
            ? $this->database->defaultRestoreFile($execution->database_name ?: $execution->filename)
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

            $activity = StartDatabaseImport::run($this->database, $source, (int) $this->databaseTeamId());
            $this->activityId = $activity->id;
            $this->dispatch('activityMonitor', $activity->id);
            $this->dispatch('databaserestore');

            auditLog('ui.database.restore_started', [
                'team_id' => $this->databaseTeamId(),
                'database_uuid' => $this->database->uuid,
                'database_name' => $this->database->name,
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

    private function databaseTeamId(): ?int
    {
        return $this->database?->team()?->id ?? $this->backup?->team_id;
    }

    public function download_file($exeuctionId)
    {
        return redirect()->route('download.backup', $exeuctionId);
    }

    public function refreshBackupExecutions(): void
    {
        $this->loadExecutions();
    }

    public function reloadExecutions()
    {
        $this->loadExecutions();
    }

    public function previousPage(?int $take = null)
    {
        if ($take) {
            $this->skip = $this->skip - $take;
        }
        $this->skip = $this->skip - $this->defaultTake;
        if ($this->skip < 0) {
            $this->showPrev = false;
            $this->skip = 0;
        }
        $this->updateCurrentPage();
        $this->loadExecutions();
    }

    public function nextPage(?int $take = null)
    {
        if ($take) {
            $this->skip = $this->skip + $take;
        }
        $this->showPrev = true;
        $this->updateCurrentPage();
        $this->loadExecutions();
    }

    private function loadExecutions()
    {
        if ($this->backup && $this->backup->exists) {
            ['executions' => $executions, 'count' => $count] = $this->backup->executionsPaginated($this->skip, $this->defaultTake);
            $this->executions = $executions;
            $this->executions_count = $count;
        } else {
            $this->executions = collect([]);
            $this->executions_count = 0;
        }
        $this->showMore();
    }

    private function showMore()
    {
        if ($this->executions->count() !== 0) {
            $this->showNext = true;
            if ($this->executions->count() < $this->defaultTake) {
                $this->showNext = false;
            }

            return;
        }
    }

    private function updateCurrentPage()
    {
        $this->currentPage = intval($this->skip / $this->defaultTake) + 1;
    }

    public function mount(ScheduledDatabaseBackup $backup)
    {
        $this->backup = $backup;
        $this->database = $backup->database;
        $this->updateCurrentPage();
        $this->loadExecutions();
    }

    public function server()
    {
        if ($this->database) {
            $server = null;

            if ($this->database instanceof ServiceDatabase) {
                $server = $this->database->service->destination->server;
            } elseif ($this->database->destination && $this->database->destination->server) {
                $server = $this->database->destination->server;
            }
            if ($server) {
                return $server;
            }
        }

        return null;
    }

    public function render()
    {
        return view('livewire.project.database.backup-executions');
    }
}
