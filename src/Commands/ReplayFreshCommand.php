<?php

namespace Pikant\LaravelEasyHttpFake\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Pikant\LaravelEasyHttpFake\ReplayStorage;

class ReplayFreshCommand extends Command
{
    public $signature = 'replay:fresh
        {--test= : Delete fakes for a specific test description}
        {--file= : Delete fakes for a specific test file}
        {--url= : Delete fakes matching a URL pattern}
        {--shared= : Delete shared fakes by name}';

    public $description = 'Delete stored HTTP replay files to force re-recording';

    public function handle(): int
    {
        $storage = new ReplayStorage;
        $basePath = $storage->getBasePath();

        if ($this->option('shared')) {
            $dir = $storage->getSharedDirectory($this->option('shared'));
            $this->deleteDirectory($dir);

            return self::SUCCESS;
        }

        if ($this->option('url')) {
            $this->deleteByUrlPattern($basePath, $this->option('url'));

            return self::SUCCESS;
        }

        if ($this->option('file')) {
            $this->deleteByFile($basePath, $this->option('file'));

            return self::SUCCESS;
        }

        if ($this->option('test')) {
            $this->deleteByTestName($basePath, $this->option('test'));

            return self::SUCCESS;
        }

        // No options — delete everything
        $this->deleteDirectory($basePath);

        return self::SUCCESS;
    }

    protected function deleteDirectory(string $dir): void
    {
        if (File::isDirectory($dir)) {
            File::deleteDirectory($dir);
            $this->info("Deleted: {$dir}");
        } else {
            $this->warn("Directory not found: {$dir}");
        }
    }

    protected function deleteByUrlPattern(string $basePath, string $pattern): void
    {
        if (! File::isDirectory($basePath)) {
            $this->warn("No replay directory found at: {$basePath}");

            return;
        }

        $count = 0;

        foreach (File::allFiles($basePath) as $file) {
            if ($file->getExtension() !== 'json') {
                continue;
            }

            $content = File::get($file->getPathname());
            $data = json_decode($content, true);

            if (! is_array($data) || ! isset($data['request']['url'])) {
                continue;
            }

            $regex = str_replace('\*', '.*', preg_quote($pattern, '#'));
            if (preg_match('#'.$regex.'#', $data['request']['url'])) {
                File::delete($file->getPathname());
                $count++;
            }
        }

        $this->info("Deleted {$count} replay file(s) matching URL pattern: {$pattern}");
    }

    protected function deleteByFile(string $basePath, string $file): void
    {
        // Extract relative path from test file path
        $file = str_replace('.php', '', $file);
        $file = str_replace('tests/', '', $file);
        $file = str_replace('tests\\', '', $file);

        $targetDir = $basePath.DIRECTORY_SEPARATOR.$file;

        if (File::isDirectory($targetDir)) {
            File::deleteDirectory($targetDir);
            $this->info("Deleted replays for file: {$file}");
        } else {
            $this->warn("No replays found for file: {$file}");
        }
    }

    protected function deleteByTestName(string $basePath, string $testName): void
    {
        $sanitized = (string) preg_replace('/[^a-zA-Z0-9_\-]/', '_', $testName);
        $count = 0;

        if (! File::isDirectory($basePath)) {
            $this->warn("No replay directory found at: {$basePath}");

            return;
        }

        foreach (File::directories($basePath) as $dir) {
            foreach (File::directories($dir) as $testFileDir) {
                $testDir = $testFileDir.DIRECTORY_SEPARATOR.$sanitized;
                if (File::isDirectory($testDir)) {
                    File::deleteDirectory($testDir);
                    $count++;
                }
            }
        }

        $this->info("Deleted {$count} replay directory(ies) for test: {$testName}");
    }
}
