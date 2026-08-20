<?php

declare(strict_types=1);

namespace Hygo\ApiWaypoint\Console;

use Hygo\ApiWaypoint\Support\SchemaRepository;
use Illuminate\Console\Command;

class SchemaCommand extends Command
{
    protected $signature = 'waypoint:schema
        {--output= : Write the document to this path instead of stdout}
        {--pretty : Pretty-print the JSON}
        {--clear : Clear the cached document and exit}';

    protected $description = 'Compile the API waypoint schema document.';

    public function handle(SchemaRepository $schemas): int
    {
        if ($this->option('clear')) {
            $schemas->clear();
            $this->components->info('Cleared the cached waypoint document.');

            return self::SUCCESS;
        }

        $document = $schemas->fresh();

        $json = (string) json_encode(
            $document->toArray(),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            | ($this->option('pretty') ? JSON_PRETTY_PRINT : 0)
        );

        $output = $this->option('output');

        if (! is_string($output) || $output === '') {
            $this->line($json);

            return self::SUCCESS;
        }

        $directory = dirname($output);

        if (! is_dir($directory) && ! mkdir($directory, 0o755, true) && ! is_dir($directory)) {
            $this->components->error("Could not create [{$directory}].");

            return self::FAILURE;
        }

        // A trailing newline keeps the file diff-friendly and git-clean.
        file_put_contents($output, $json.PHP_EOL);

        $this->components->info(sprintf(
            'Wrote %d endpoints and %d data objects to [%s]. Hash %s.',
            count($document->endpoints()),
            count($document->dataObjects()),
            $output,
            (string) $document->schemaHash(),
        ));

        return self::SUCCESS;
    }
}
