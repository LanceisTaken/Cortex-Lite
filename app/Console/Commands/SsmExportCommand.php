<?php

namespace App\Console\Commands;

use App\Support\ShellEnv;
use Aws\Ssm\SsmClient;
use Illuminate\Console\Command;

class SsmExportCommand extends Command
{
    protected $signature = 'ssm:export';

    protected $description = 'Print Parameter Store secrets as shell export lines.';

    public function handle(SsmClient $ssm): int
    {
        $path = config('services.ssm.path');
        $nextToken = null;

        do {
            $args = [
                'Path' => $path,
                'WithDecryption' => true,
                'Recursive' => true,
            ];

            if ($nextToken !== null) {
                $args['NextToken'] = $nextToken;
            }

            $result = $ssm->getParametersByPath($args);

            foreach ($result['Parameters'] ?? [] as $param) {
                $this->line(ShellEnv::export(basename($param['Name']), $param['Value']));
            }

            $nextToken = $result['NextToken'] ?? null;
        } while ($nextToken !== null);

        return self::SUCCESS;
    }
}
