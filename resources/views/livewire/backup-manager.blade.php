<div class="application-settings-form w-full">
    <x-slot:title>
        Backups | Coolify
    </x-slot>

    <header class="mb-5 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div class="min-w-0">
            <h1 class="truncate text-[24px]! leading-7! font-semibold! tracking-tight!">Backup Manager</h1>
            <p class="mt-1 text-[13px] text-neutral-500 dark:text-fg-dim">
                Manage database and storage backup schedules, monitor execution history, and restore databases across your workspace.
            </p>
        </div>
        <div class="flex flex-wrap items-center gap-2 shrink-0">
            <a {{ wireNavigate() }} href="{{ route('storage.index') }}" class="button">
                <x-reicon name="storages" class="size-3.5" />
                S3 Storage
            </a>
        </div>
    </header>

    {{-- Summary cards --}}
    <div class="mb-6 grid grid-cols-2 gap-3 sm:grid-cols-4">
        <div class="rounded-xl border border-neutral-200 bg-white p-3.5 shadow-sm dark:border-white/[0.08] dark:bg-white/[0.05]">
            <p class="text-[11px] font-medium text-neutral-500 dark:text-fg-faint">Active schedules</p>
            <p class="mt-1 text-[20px] font-semibold text-black dark:text-fg">
                {{ $summary['schedules_enabled'] }} <span class="text-[12px] font-normal text-neutral-400 dark:text-fg-faint">/ {{ $summary['schedules_total'] }}</span>
            </p>
        </div>
        <div class="rounded-xl border border-neutral-200 bg-white p-3.5 shadow-sm dark:border-white/[0.08] dark:bg-white/[0.05]">
            <p class="text-[11px] font-medium text-neutral-500 dark:text-fg-faint">Recent executions</p>
            <p class="mt-1 text-[20px] font-semibold text-black dark:text-fg">{{ $summary['executions_total'] }}</p>
        </div>
        <div class="rounded-xl border border-neutral-200 bg-white p-3.5 shadow-sm dark:border-white/[0.08] dark:bg-white/[0.05]">
            <p class="text-[11px] font-medium text-neutral-500 dark:text-fg-faint">Successful backups</p>
            <p class="mt-1 text-[20px] font-semibold text-emerald-600 dark:text-emerald-400">{{ $summary['executions_success'] }}</p>
        </div>
        <div class="rounded-xl border border-neutral-200 bg-white p-3.5 shadow-sm dark:border-white/[0.08] dark:bg-white/[0.05]">
            <p class="text-[11px] font-medium text-neutral-500 dark:text-fg-faint">Failed backups</p>
            <p class="mt-1 text-[20px] font-semibold {{ $summary['executions_failed'] > 0 ? 'text-red-600 dark:text-red-400' : 'text-black dark:text-fg' }}">
                {{ $summary['executions_failed'] }}
            </p>
        </div>
    </div>

    {{-- Search & Filters --}}
    <div class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div class="relative w-full sm:max-w-xs">
            <x-reicon name="search"
                class="pointer-events-none absolute top-1/2 left-3 size-3.5 -translate-y-1/2 text-neutral-400 dark:text-fg-faint" />
            <input type="search" wire:model.live.debounce.200ms="search"
                placeholder="Search targets, files, or schedules..."
                class="input h-9! w-full pl-8! pr-3! text-[12px]!" />
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <div class="inline-flex rounded-lg border border-neutral-200 bg-white p-0.5 dark:border-white/[0.08] dark:bg-white/[0.04]">
                @foreach (['all' => 'All types', 'database' => 'Databases', 'storage' => 'Storage'] as $key => $label)
                    <button type="button" wire:click="setTypeFilter('{{ $key }}')"
                        class="rounded-md px-2.5 py-1 text-[11px] font-medium transition-colors {{ $typeFilter === $key ? 'bg-neutral-900 text-white dark:bg-white/[0.14] dark:text-fg' : 'text-neutral-600 hover:text-black dark:text-fg-dim dark:hover:text-fg' }}">
                        {{ $label }}
                    </button>
                @endforeach
            </div>

            <div class="inline-flex rounded-lg border border-neutral-200 bg-white p-0.5 dark:border-white/[0.08] dark:bg-white/[0.04]">
                @foreach (['all' => 'All statuses', 'success' => 'Success', 'failed' => 'Failed', 'running' => 'Running'] as $key => $label)
                    <button type="button" wire:click="setStatusFilter('{{ $key }}')"
                        class="rounded-md px-2.5 py-1 text-[11px] font-medium transition-colors {{ $statusFilter === $key ? 'bg-neutral-900 text-white dark:bg-white/[0.14] dark:text-fg' : 'text-neutral-600 hover:text-black dark:text-fg-dim dark:hover:text-fg' }}">
                        {{ $label }}
                    </button>
                @endforeach
            </div>
        </div>
    </div>

    <div class="flex flex-col gap-6">
        {{-- Backup Schedules Section --}}
        <x-application.settings-section title="Backup schedules"
            helper="Configured scheduled backups for databases, persistent volumes, and directories." flush>
            @if ($schedules->isEmpty())
                <x-empty size="sm" title="No backup schedules found"
                    description="Create a database or persistent volume backup schedule on a resource to manage it here."
                    icon-name="calendar" />
            @else
                <div class="data-table relative w-full overflow-x-auto">
                    <div class="data-table-header grid min-w-[780px] grid-cols-[minmax(180px,1.5fr)_100px_120px_130px_110px_120px_140px]">
                        <span>Resource</span>
                        <span>Type</span>
                        <span>Frequency</span>
                        <span>Destination</span>
                        <span>Status</span>
                        <span>Last run</span>
                        <span class="text-right">Actions</span>
                    </div>
                    @foreach ($schedules as $schedule)
                        @php
                            $lastStatusType = match ($schedule['last_status']) {
                                'success' => 'success',
                                'failed' => 'error',
                                'running' => 'warning',
                                default => 'neutral',
                            };
                            $lastStatusLabel = $schedule['last_status']
                                ? str($schedule['last_status'])->headline()
                                : ($schedule['enabled'] ? 'Scheduled' : 'Disabled');
                        @endphp
                        <div wire:key="schedule-{{ $schedule['id'] }}"
                            class="data-table-row grid min-w-[780px] grid-cols-[minmax(180px,1.5fr)_100px_120px_130px_110px_120px_140px] items-center text-[12px]">
                            <div class="min-w-0 pr-3">
                                @if ($schedule['schedule_url'])
                                    <a {{ wireNavigate() }} href="{{ $schedule['schedule_url'] }}"
                                        class="truncate font-semibold text-black hover:underline dark:text-fg block">
                                        {{ $schedule['target'] }}
                                    </a>
                                @else
                                    <span class="truncate font-semibold text-black dark:text-fg block">{{ $schedule['target'] }}</span>
                                @endif
                            </div>
                            <div>
                                <x-status-badge :status="$schedule['type_label']" type="neutral" />
                            </div>
                            <div class="font-mono text-[11px] text-neutral-600 dark:text-fg-dim">
                                {{ $schedule['frequency'] }}
                            </div>
                            <div class="truncate text-[11px] text-neutral-600 dark:text-fg-dim">
                                {{ $schedule['storage_label'] }}
                            </div>
                            <div>
                                <x-status-badge :status="$lastStatusLabel" :type="$lastStatusType" />
                            </div>
                            <div class="text-[11px] text-neutral-600 dark:text-fg-dim">
                                {{ $schedule['last_run_at'] ? $schedule['last_run_at']->diffForHumans() : 'Never' }}
                            </div>
                            <div class="flex items-center justify-end gap-1.5">
                                @if ($schedule['can_trigger'])
                                    <button type="button"
                                        wire:click="backupNow('{{ $schedule['type'] }}', '{{ $schedule['uuid'] }}')"
                                        class="icon-button shrink-0" title="Backup now" aria-label="Backup now">
                                        <x-reicon name="play-circle" class="size-3.5" />
                                    </button>
                                @endif
                                @if ($schedule['import_url'])
                                    <a {{ wireNavigate() }} href="{{ $schedule['import_url'] }}"
                                        class="icon-button shrink-0" title="Import or restore custom backup"
                                        aria-label="Import or restore custom backup">
                                        <x-reicon name="time-back" class="size-3.5" />
                                    </a>
                                @endif
                                @if ($schedule['schedule_url'])
                                    <a {{ wireNavigate() }} href="{{ $schedule['schedule_url'] }}"
                                        class="icon-button shrink-0" title="Open schedule settings"
                                        aria-label="Open schedule settings">
                                        <x-reicon name="sliders" class="size-3.5" />
                                    </a>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </x-application.settings-section>

        {{-- Backup Executions Section --}}
        <x-application.settings-section title="Recent backup executions"
            helper="Inspect generated backup archives, restore databases in one click, download archives, or clean up old runs."
            flush>
            @if ($executions->isEmpty())
                <x-empty size="sm" title="No backup executions found"
                    description="Backup archives will appear here once a scheduled or manual backup runs."
                    icon-name="browser-terminal" />
            @else
                <div class="data-table relative w-full overflow-x-auto">
                    <div class="data-table-header grid min-w-[860px] grid-cols-[minmax(160px,1.3fr)_90px_minmax(180px,1.5fr)_100px_110px_85px_130px_110px]">
                        <span>Resource</span>
                        <span>Type</span>
                        <span>Backup path</span>
                        <span>Status</span>
                        <span>Created</span>
                        <span>Size</span>
                        <span>Availability</span>
                        <span class="text-right">Actions</span>
                    </div>
                    @foreach ($executions as $execution)
                        @php
                            $statusType = match ($execution['status']) {
                                'success' => $execution['s3_uploaded'] === false ? 'warning' : 'success',
                                'failed' => 'error',
                                'running' => 'warning',
                                default => 'neutral',
                            };
                            $statusLabel = match ($execution['status']) {
                                'success' => $execution['s3_uploaded'] === false ? 'S3 warning' : 'Success',
                                'running' => 'In progress',
                                'failed' => 'Failed',
                                default => str($execution['status'])->headline(),
                            };

                            $executionCheckboxes = [];
                            $deleteActions = [];
                            if (! $execution['local_storage_deleted']) {
                                $deleteActions[] = 'This backup will be permanently deleted from local storage.';
                            }
                            if ($execution['s3_uploaded'] === true && ! $execution['s3_storage_deleted']) {
                                $executionCheckboxes[] = [
                                    'id' => 'delete_backup_s3',
                                    'label' => 'Delete the selected backup permanently from S3 Storage',
                                ];
                            }
                            if (empty($deleteActions)) {
                                $deleteActions[] = 'This backup execution record will be deleted.';
                            }
                        @endphp
                        <div wire:key="execution-{{ $execution['row_key'] }}"
                            class="data-table-row grid min-w-[860px] grid-cols-[minmax(160px,1.3fr)_90px_minmax(180px,1.5fr)_100px_110px_85px_130px_110px] items-center text-[12px]">
                            <div class="min-w-0 pr-2">
                                <span class="truncate font-semibold text-black dark:text-fg block" title="{{ $execution['target'] }}">
                                    {{ $execution['target'] }}
                                </span>
                                @if ($execution['database_name'])
                                    <span class="truncate text-[11px] text-neutral-500 dark:text-fg-faint block">
                                        {{ $execution['database_name'] }}
                                    </span>
                                @endif
                            </div>
                            <div>
                                <x-status-badge :status="$execution['type_label']" type="neutral" />
                            </div>
                            <div class="flex min-w-0 items-center gap-1 pr-2">
                                @if ($execution['filename'])
                                    <code class="select-all truncate font-mono text-[11px] text-neutral-600 dark:text-fg-dim"
                                        title="{{ $execution['filename'] }}">{{ $execution['filename'] }}</code>
                                    <x-copy-button :value="$execution['filename']" label="Copy backup path" />
                                @else
                                    <span class="text-[11px] text-neutral-400 dark:text-fg-faint">N/A</span>
                                @endif
                            </div>
                            <div>
                                <x-status-badge :status="$statusLabel" :type="$statusType" />
                            </div>
                            <div class="text-[11px] text-neutral-600 dark:text-fg-dim">
                                {{ $execution['created_at'] ? $execution['created_at']->diffForHumans() : 'N/A' }}
                            </div>
                            <div class="text-[11px] text-neutral-600 dark:text-fg-dim">
                                {{ $execution['size'] ? formatBytes((int) $execution['size']) : '-' }}
                            </div>
                            <div class="flex flex-wrap items-center gap-1">
                                <x-status-badge label="Local"
                                    :status="$execution['local_storage_deleted'] ? 'Deleted' : 'Available'"
                                    :type="$execution['local_storage_deleted'] ? 'neutral' : 'success'" />
                                @if ($execution['s3_uploaded'] !== null)
                                    <x-status-badge label="S3"
                                        :status="$execution['s3_storage_deleted']
                                            ? 'Deleted'
                                            : ($execution['s3_uploaded'] ? 'Available' : 'Failed')"
                                        :type="$execution['s3_storage_deleted']
                                            ? 'neutral'
                                            : ($execution['s3_uploaded'] ? 'success' : 'error')" />
                                @endif
                            </div>
                            <div class="flex items-center justify-end gap-1">
                                @if ($execution['can_restore'])
                                    <x-modal-confirmation title="Confirm Database Restore?" isErrorButton
                                        submitAction="restoreDatabaseBackup({{ $execution['id'] }})"
                                        :actions="[
                                            'Restore '.$execution['target'].' from '.$execution['restore_source_label'].' ('.$execution['filename'].').',
                                            'Existing database objects in the target database will be overwritten.',
                                        ]"
                                        confirmationText="{{ $execution['filename'] }}"
                                        confirmationLabel="Enter the backup filename to confirm restore."
                                        shortConfirmationLabel="Backup Filename">
                                        <x-slot:trigger>
                                            <button type="button" class="icon-button shrink-0"
                                                title="Restore database from this backup"
                                                aria-label="Restore database from this backup">
                                                <x-reicon name="time-back" class="size-3.5" />
                                            </button>
                                        </x-slot:trigger>
                                    </x-modal-confirmation>
                                @endif

                                @if ($execution['download_url'])
                                    <a href="{{ $execution['download_url'] }}" target="_blank" rel="noopener"
                                        class="icon-button shrink-0" title="Download backup"
                                        aria-label="Download backup">
                                        <x-reicon name="upload" class="size-3.5 rotate-180" />
                                    </a>
                                @endif

                                @if ($execution['can_delete'])
                                    <x-modal-confirmation title="Confirm Backup Deletion?" isErrorButton
                                        submitAction="deleteExecution('{{ $execution['type'] }}', {{ $execution['id'] }})"
                                        :checkboxes="$executionCheckboxes" :actions="$deleteActions"
                                        confirmationText="{{ $execution['filename'] ?: $execution['uuid'] }}"
                                        confirmationLabel="Enter the backup filename to confirm."
                                        shortConfirmationLabel="Backup Filename">
                                        <x-slot:trigger>
                                            <button type="button"
                                                class="icon-button shrink-0 text-red-500 hover:text-red-600 dark:text-red-400 dark:hover:text-red-300"
                                                title="Delete backup" aria-label="Delete backup">
                                                <x-reicon name="trash" class="size-3.5" />
                                            </button>
                                        </x-slot:trigger>
                                    </x-modal-confirmation>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </x-application.settings-section>
    </div>

    <x-process-dialog @databaserestore.window="processDialogOpen = true" closeWithX size="xl">
        <x-slot:title>Database Restore Output</x-slot:title>
        <x-slot:content>
            <div class="flex h-full min-h-0 flex-col" wire:ignore>
                <livewire:activity-monitor wire:key="backup-manager-restore-monitor" header="Logs" fullHeight />
            </div>
        </x-slot:content>
    </x-process-dialog>
</div>
