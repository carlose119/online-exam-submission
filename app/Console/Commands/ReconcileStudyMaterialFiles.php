<?php

namespace App\Console\Commands;

use App\Services\StudyMaterialFileReconciliation;
use Illuminate\Console\Command;

class ReconcileStudyMaterialFiles extends Command
{
    protected $signature = 'materials:reconcile
                            {--delete : Delete orphaned managed files}
                            {--force : Allow deletion in production}';

    protected $description = 'Find or delete orphaned managed study material files';

    public function handle(StudyMaterialFileReconciliation $reconciliation): int
    {
        $delete = (bool) $this->option('delete');
        $this->line('Mode: '.($delete ? 'delete' : 'dry-run'));

        if ($delete && app()->isProduction() && ! $this->option('force')) {
            $this->error('Deletion in production requires --force. No files were changed.');

            return self::FAILURE;
        }

        $counts = $reconciliation->reconcile($delete);
        $this->line(sprintf(
            'Summary: scanned=%d active=%d orphaned=%d deleted=%d skipped=%d failed=%d',
            ...array_values($counts),
        ));

        return $counts['failed'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
