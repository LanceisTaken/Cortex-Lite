<?php

namespace App\Console\Commands;

use App\Actions\PcGamingWiki\EnrichPendingGamesAction;
use App\Services\PcGamingWikiClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class EnrichGameMetadataCommand extends Command
{
    protected $signature = 'games:enrich-metadata {--limit=20}';

    protected $description = 'Enrich pending Steam games with PCGamingWiki graphics metadata.';

    public function handle(EnrichPendingGamesAction $action, PcGamingWikiClient $client): int
    {
        $limit = max(1, (int) $this->option('limit'));

        // Multi-write per-game orchestration lives in the action; this command stays operational glue.
        $result = $action->execute($limit, $client);

        Log::info('PCGamingWiki metadata enrichment completed.', $result);
        $this->info(sprintf(
            'Metadata enrichment complete: %d enriched, %d missing, %d skipped.',
            $result['enriched'],
            $result['missing'],
            $result['skipped'],
        ));

        return self::SUCCESS;
    }
}
